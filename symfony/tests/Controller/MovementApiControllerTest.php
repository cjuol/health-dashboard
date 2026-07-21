<?php

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests funcionales del endpoint de ingesta de movimiento. Usan la base
 * health_test (ver bin/test.sh) y el token fijo MOVEMENT_API_TOKEN que el
 * propio script inyecta como variable de entorno.
 */
final class MovementApiControllerTest extends WebTestCase
{
    private const TOKEN = 'test-movement-token';
    private const ENDPOINT = '/api/v1/health/movement';

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        // createClient() arranca el kernel; hacerlo aquí (y no dentro de
        // cada test) evita el "kernel booted twice" de WebTestCase.
        $this->client = static::createClient();

        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM hc_movement_bucket');
        $connection->executeStatement('DELETE FROM sync_state');
    }

    private function post(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array|string $body, array $server = []): void
    {
        $content = \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body;

        $client->request(
            'POST',
            self::ENDPOINT,
            server: array_merge([
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
                'CONTENT_TYPE' => 'application/json',
            ], $server),
            content: $content,
        );
    }

    private function countBuckets(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM hc_movement_bucket');
    }

    public function testHappyPathUpsertsAndIsIdempotentOnRepost(): void
    {
        $client = $this->client;
        $payload = [
            'device' => 'pixel-test',
            'buckets' => [[
                'bucket_start' => '2024-01-01T10:00:00+00:00',
                'bucket_end' => '2024-01-01T10:15:00+00:00',
                'origin' => 'phone',
                'steps' => 200,
                'distance_m' => 150.25,
                'floors' => 2,
            ]],
        ];

        $this->post($client, $payload);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['upserted']);
        self::assertSame([], $data['rejected']);
        self::assertSame(1, $this->countBuckets());

        // Reenvío idéntico: mismo resultado, sin duplicar filas.
        $this->post($client, $payload);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['upserted']);
        self::assertSame(1, $this->countBuckets());
    }

    public function testMixedBatchUpsertsOnlyValidBucketsAndReportsReasons(): void
    {
        $client = $this->client;
        $payload = [
            'buckets' => [
                [ // válido
                    'bucket_start' => '2024-01-01T10:00:00+00:00',
                    'bucket_end' => '2024-01-01T10:15:00+00:00',
                    'origin' => 'phone',
                    'steps' => 50,
                ],
                [ // fecha sin offset
                    'bucket_start' => '2024-01-01T11:00:00',
                    'bucket_end' => '2024-01-01T11:15:00+00:00',
                    'origin' => 'phone',
                ],
                [ // desalineado a la rejilla de 15 min
                    'bucket_start' => '2024-01-01T12:01:00+00:00',
                    'bucket_end' => '2024-01-01T12:16:00+00:00',
                    'origin' => 'phone',
                ],
                [ // steps negativo
                    'bucket_start' => '2024-01-01T13:00:00+00:00',
                    'bucket_end' => '2024-01-01T13:15:00+00:00',
                    'origin' => 'phone',
                    'steps' => -5,
                ],
            ],
        ];

        $this->post($client, $payload);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['upserted']);
        self::assertCount(3, $data['rejected']);
        foreach ($data['rejected'] as $entry) {
            self::assertArrayHasKey('bucket_start', $entry);
            self::assertArrayHasKey('reason', $entry);
            self::assertNotSame('', $entry['reason']);
        }
        self::assertSame(1, $this->countBuckets());
    }

    public function testUnauthorizedWithWrongToken(): void
    {
        $client = $this->client;
        $this->post($client, ['buckets' => []], ['HTTP_AUTHORIZATION' => 'Bearer token-incorrecto']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('unauthorized', $data['error']);
    }

    public function testUnprocessableWhenBucketsIsMissing(): void
    {
        $client = $this->client;
        $this->post($client, ['device' => 'pixel-test']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
    }

    public function testUnsupportedMediaTypeWithWrongContentType(): void
    {
        $client = $this->client;
        $this->post($client, json_encode(['buckets' => []]), ['CONTENT_TYPE' => 'text/plain']);

        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $client->getResponse()->getStatusCode());
    }

    public function testContentTypeWithCharsetParameterIsAccepted(): void
    {
        $client = $this->client;
        $this->post($client, ['buckets' => []], ['CONTENT_TYPE' => 'application/json; charset=utf-8']);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function testPayloadTooLargeWithOversizedBucketCount(): void
    {
        $client = $this->client;
        $buckets = array_fill(0, 5001, [
            'bucket_start' => 'invalid',
            'bucket_end' => 'invalid',
            'origin' => 'phone',
        ]);

        $this->post($client, ['buckets' => $buckets]);

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $client->getResponse()->getStatusCode());
    }
}
