<?php

namespace App\Tests\Repository;

use App\Repository\HealthRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests de HealthRepository contra health_test (misma base que el resto de
 * la suite). Usa marcadores únicos ('repo-test-*' en origin/device y fechas
 * fuera de los rangos que usan otros tests) para no interferir con datos que
 * dejen otros ficheros de test en la misma base compartida, y limpia sus
 * propias filas en tearDown.
 */
final class HealthRepositoryTest extends KernelTestCase
{
    private const TEST_DAY = '2031-05-10';

    private Connection $db;
    private HealthRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->db = $db;
        $this->repo = new HealthRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement("DELETE FROM hc_movement_bucket WHERE origin LIKE 'repo-test-%'");
        parent::tearDown();
    }

    public function testDailyStepsBySourceAggregatesPhoneDistance(): void
    {
        $day = self::TEST_DAY;
        $this->db->insert('hc_movement_bucket', [
            'bucket_start' => $day.' 08:00:00+02',
            'bucket_end' => $day.' 08:15:00+02',
            'origin' => 'repo-test-a',
            'steps' => 500,
            'distance_m' => 350.5,
            'device' => 'repo-test-device',
        ]);
        $this->db->insert('hc_movement_bucket', [
            'bucket_start' => $day.' 08:15:00+02',
            'bucket_end' => $day.' 08:30:00+02',
            'origin' => 'repo-test-b',
            'steps' => 300,
            'distance_m' => 200.25,
            'device' => 'repo-test-device',
        ]);

        $rows = $this->repo->dailyStepsBySource(
            new \DateTimeImmutable($day),
            new \DateTimeImmutable($day),
        );

        self::assertCount(1, $rows);
        self::assertSame($day, $rows[0]['day']);
        self::assertSame(800, (int) $rows[0]['phone_steps']);
        self::assertEqualsWithDelta(550.75, (float) $rows[0]['phone_distance_m'], 0.001);
    }

    public function testDeviceDiagnosticsGroupsByDeviceAndCountsRecentBuckets(): void
    {
        $recent = new \DateTimeImmutable('-1 day');
        $old = new \DateTimeImmutable('-30 days');

        // Dispositivo alpha: 2 buckets dentro de los últimos 7 días.
        $this->db->insert('hc_movement_bucket', [
            'bucket_start' => $recent->format('Y-m-d H:i:sP'),
            'bucket_end' => $recent->modify('+15 minutes')->format('Y-m-d H:i:sP'),
            'origin' => 'repo-test-diag-a1',
            'steps' => 10,
            'device' => 'repo-test-alpha',
        ]);
        $this->db->insert('hc_movement_bucket', [
            'bucket_start' => $recent->modify('+15 minutes')->format('Y-m-d H:i:sP'),
            'bucket_end' => $recent->modify('+30 minutes')->format('Y-m-d H:i:sP'),
            'origin' => 'repo-test-diag-a2',
            'steps' => 10,
            'device' => 'repo-test-alpha',
        ]);

        // Dispositivo beta: solo un bucket fuera de la ventana de 7 días.
        $this->db->insert('hc_movement_bucket', [
            'bucket_start' => $old->format('Y-m-d H:i:sP'),
            'bucket_end' => $old->modify('+15 minutes')->format('Y-m-d H:i:sP'),
            'origin' => 'repo-test-diag-b1',
            'steps' => 5,
            'device' => 'repo-test-beta',
        ]);

        $diagnostics = $this->repo->deviceDiagnostics();

        self::assertArrayHasKey('devices', $diagnostics);
        self::assertArrayHasKey('sync', $diagnostics);
        self::assertIsArray($diagnostics['sync']);

        $byDevice = array_column($diagnostics['devices'], null, 'device');

        self::assertArrayHasKey('repo-test-alpha', $byDevice);
        self::assertArrayHasKey('repo-test-beta', $byDevice);
        self::assertSame(2, (int) $byDevice['repo-test-alpha']['buckets_7d']);
        self::assertSame(0, (int) $byDevice['repo-test-beta']['buckets_7d']);
        self::assertNotNull($byDevice['repo-test-alpha']['last_received']);
        self::assertNotNull($byDevice['repo-test-beta']['last_received']);
    }
}
