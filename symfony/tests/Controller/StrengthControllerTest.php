<?php

namespace App\Tests\Controller;

use App\Security\AppUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests funcionales del editor de fuerza (overlay sobre garmin_strength_set,
 * ver db/11_strength_overrides.sql). Usa un activity_id de marcador fuera del
 * rango de cualquier id real de Garmin y limpia sus propias filas en
 * tearDown vía ON DELETE CASCADE desde garmin_activity.
 */
final class StrengthControllerTest extends WebTestCase
{
    private const ACTIVITY_ID = 900000101;
    private const TEST_USERNAME = 'strength-test-user';

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $this->db = $db;

        $this->db->executeStatement('DELETE FROM app_user WHERE username = :u', ['u' => self::TEST_USERNAME]);

        $this->db->insert('garmin_activity', [
            'activity_id' => self::ACTIVITY_ID,
            'activity_type' => 'strength_training',
            'activity_name' => 'controller-test fuerza',
            'start_time' => '2031-07-05 08:00:00+02',
            'duration_s' => 1800,
            'is_strength' => true,
        ]);
        $this->db->insert('garmin_strength_set', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 1,
            'exercise_name' => 'Press banca',
            'reps' => 8,
            'weight_kg' => 60.0,
            'set_type' => 'ACTIVE',
        ]);
        $this->db->insert('garmin_strength_set', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 2,
            'exercise_name' => 'Sentadilla',
            'reps' => 5,
            'weight_kg' => 100.0,
            'set_type' => 'ACTIVE',
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM garmin_activity WHERE activity_id = :id', ['id' => self::ACTIVITY_ID]);
        $this->db->executeStatement('DELETE FROM app_user WHERE username = :u', ['u' => self::TEST_USERNAME]);
        parent::tearDown();
    }

    private function loginTestUser(): void
    {
        $id = (int) $this->db->fetchOne(
            "INSERT INTO app_user (username, password_hash, display_name) VALUES (:u, 'x', 'Test') RETURNING id",
            ['u' => self::TEST_USERNAME],
        );

        $this->client->loginUser(new AppUser($id, self::TEST_USERNAME, 'x', 'Test', null));
    }

    /**
     * El CsrfTokenManager necesita una sesión de la petición en curso: no se
     * puede generar el token fuera de un request activo (SessionNotFoundException).
     * Se extrae del formulario ya renderizado en /fuerza/{id}, igual que
     * haría un navegador real.
     *
     * El selector se limita a #fuerza-form porque base.html.twig también
     * renderiza un input[name="_token"] en el formulario de sync de la nav
     * (intención CSRF "sync-garmin"): sin el scope, el filtro coge el primer
     * "_token" del documento (el de la nav) en vez del de este formulario.
     */
    private function csrfTokenFromEditor(): string
    {
        $crawler = $this->client->request('GET', sprintf('/fuerza/%d', self::ACTIVITY_ID));

        return $crawler->filter('#fuerza-form input[name="_token"]')->attr('value');
    }

    private function overrideRow(int $setOrder): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT * FROM strength_set_override WHERE activity_id = :id AND set_order = :order',
            ['id' => self::ACTIVITY_ID, 'order' => $setOrder],
        );
    }

    public function testAnonymousPostToSaveRedirectsToLoginAndDoesNotApply(): void
    {
        $this->client->request('POST', sprintf('/fuerza/%d/guardar', self::ACTIVITY_ID), [
            '_token' => 'irrelevant',
            'sets' => [1 => ['weight_kg' => '999']],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertFalse($this->overrideRow(1));
    }

    public function testAnonymousCannotReachEditorRoutes(): void
    {
        $this->client->request('GET', '/fuerza');
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', sprintf('/fuerza/%d', self::ACTIVITY_ID));
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSaveOnlyStoresValuesThatDifferFromGarmin(): void
    {
        $this->loginTestUser();

        $this->client->request('POST', sprintf('/fuerza/%d/guardar', self::ACTIVITY_ID), [
            '_token' => $this->csrfTokenFromEditor(),
            'sets' => [
                // Serie 1: nombre y peso IGUALES a Garmin, reps distinto -> solo reps se guarda.
                1 => ['exercise_name' => 'Press banca', 'reps' => '10', 'weight_kg' => '60'],
                // Serie 2: todo idéntico a Garmin -> no debe crear fila de override.
                2 => ['exercise_name' => 'Sentadilla', 'reps' => '5', 'weight_kg' => '100'],
            ],
        ]);

        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());

        $row1 = $this->overrideRow(1);
        self::assertIsArray($row1);
        self::assertSame(10, (int) $row1['reps']);
        self::assertNull($row1['exercise_name']);
        self::assertNull($row1['weight_kg']);

        self::assertFalse($this->overrideRow(2), 'Sin diferencias con Garmin, no debe existir fila de override.');
    }

    public function testSaveWithRirRpeAndNotesEnrichesWithoutGarminComparison(): void
    {
        $this->loginTestUser();

        $this->client->request('POST', sprintf('/fuerza/%d/guardar', self::ACTIVITY_ID), [
            '_token' => $this->csrfTokenFromEditor(),
            'sets' => [
                1 => ['exercise_name' => 'Press banca', 'reps' => '8', 'weight_kg' => '60', 'rir' => '2', 'rpe' => '8.5', 'notes' => 'buena sensación'],
            ],
        ]);

        $row = $this->overrideRow(1);
        self::assertIsArray($row);
        self::assertNull($row['exercise_name']);
        self::assertNull($row['reps']);
        self::assertNull($row['weight_kg']);
        self::assertEqualsWithDelta(2.0, (float) $row['rir'], 0.001);
        self::assertEqualsWithDelta(8.5, (float) $row['rpe'], 0.001);
        self::assertSame('buena sensación', $row['notes']);
    }

    public function testRevertDeletesOverrideRow(): void
    {
        $this->loginTestUser();
        $this->db->insert('strength_set_override', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 1,
            'reps' => 12,
        ]);
        self::assertIsArray($this->overrideRow(1));

        $this->client->request('POST', sprintf('/fuerza/%d/series/1/revertir', self::ACTIVITY_ID), [
            '_token' => $this->csrfTokenFromEditor(),
        ]);

        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->overrideRow(1));
    }

    public function testShowRejectsNonStrengthActivityWith404(): void
    {
        $this->loginTestUser();
        $nonStrengthId = self::ACTIVITY_ID + 1;
        // is_strength con type explícito: PDO_PGSQL representa un booleano
        // `false` sin bindear como PARAM_BOOL con una cadena vacía, que
        // Postgres rechaza ("invalid input syntax for type boolean").
        $this->db->insert(
            'garmin_activity',
            [
                'activity_id' => $nonStrengthId,
                'activity_type' => 'running',
                'activity_name' => 'controller-test carrera',
                'start_time' => '2031-07-05 09:00:00+02',
                'is_strength' => false,
            ],
            ['is_strength' => ParameterType::BOOLEAN],
        );

        try {
            $this->client->request('GET', sprintf('/fuerza/%d', $nonStrengthId));
            self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        } finally {
            $this->db->executeStatement('DELETE FROM garmin_activity WHERE activity_id = :id', ['id' => $nonStrengthId]);
        }
    }
}
