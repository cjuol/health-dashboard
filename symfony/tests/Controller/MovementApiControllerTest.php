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

        // El rate limiter (config/packages/rate_limiter.yaml) usa el pool
        // dedicado cache.rate_limiter (no cache.app — Symfony crea uno
        // propio para el componente rate-limiter), que es de filesystem y
        // persiste ENTRE procesos/tests (ver bin/test.sh, que por eso limpia
        // la caché antes de cada ejecución completa de la suite). Sin este
        // clear() aquí, los tests de esta clase consumen acumulativamente el
        // mismo cupo 'app' (15/min en when@test) porque no es por IP — si se
        // ejecuta la clase dos veces en el mismo minuto (p.ej. con
        // `phpunit --filter`, que se salta el clear de bin/test.sh), el
        // cupo puede agotarse a mitad de suite y provocar 429 espurios.
        static::getContainer()->get('cache.rate_limiter')->clear();

        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM hc_movement_bucket');
        $connection->executeStatement('DELETE FROM sync_state');
    }

    private function post(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array|string $body, array $server = []): void
    {
        $content = \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body;

        // El rate limiter cuenta por IP (REMOTE_ADDR): cada test usa la suya
        // propia (ver testXxx) para no compartir cupo entre tests distintos.
        $client->request(
            'POST',
            self::ENDPOINT,
            server: array_merge([
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.10',
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

        $server = ['REMOTE_ADDR' => '203.0.113.11'];

        $this->post($client, $payload, $server);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['upserted']);
        self::assertSame([], $data['rejected']);
        self::assertSame(1, $this->countBuckets());

        // Reenvío idéntico: mismo resultado, sin duplicar filas.
        $this->post($client, $payload, $server);
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
                [ // steps fuera del rango de la columna INTEGER: no debe
                  // provocar un 500 ni tirar abajo el resto del lote.
                    'bucket_start' => '2024-01-01T14:00:00+00:00',
                    'bucket_end' => '2024-01-01T14:15:00+00:00',
                    'origin' => 'phone',
                    'steps' => 9_999_999_999,
                ],
            ],
        ];

        $this->post($client, $payload, ['REMOTE_ADDR' => '203.0.113.12']);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['upserted']);
        self::assertCount(4, $data['rejected']);
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
        $this->post($client, ['buckets' => []], ['HTTP_AUTHORIZATION' => 'Bearer token-incorrecto', 'REMOTE_ADDR' => '203.0.113.13']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('unauthorized', $data['error']);
    }

    public function testUnprocessableWhenBucketsIsMissing(): void
    {
        $client = $this->client;
        $this->post($client, ['device' => 'pixel-test'], ['REMOTE_ADDR' => '203.0.113.14']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
    }

    public function testUnsupportedMediaTypeWithWrongContentType(): void
    {
        $client = $this->client;
        $this->post($client, json_encode(['buckets' => []]), ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '203.0.113.15']);

        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $client->getResponse()->getStatusCode());
    }

    public function testContentTypeWithCharsetParameterIsAccepted(): void
    {
        $client = $this->client;
        $this->post($client, ['buckets' => []], ['CONTENT_TYPE' => 'application/json; charset=utf-8', 'REMOTE_ADDR' => '203.0.113.16']);

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

        $this->post($client, ['buckets' => $buckets], ['REMOTE_ADDR' => '203.0.113.17']);

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $client->getResponse()->getStatusCode());
    }

    public function testPayloadTooLargeWithFakeContentLengthHeaderIsRejectedBeforeReadingBody(): void
    {
        $client = $this->client;

        // Content-Length mentiroso: el body real es minúsculo, pero la
        // cabecera declara más de 2 MB. El controlador debe rechazarlo
        // consultando la cabecera ANTES de llamar a getContent() (ver
        // MovementApiController), así que ni hace falta mandar 2 MB de
        // body real para comprobarlo.
        $this->post($client, '{"buckets":[]}', [
            'CONTENT_LENGTH' => (string) (3 * 1024 * 1024),
            'REMOTE_ADDR' => '203.0.113.22',
        ]);

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $client->getResponse()->getStatusCode());
    }

    public function testTooManyRequestsReturns429WithRetryAfterHeader(): void
    {
        $client = $this->client;
        // Token inválido a propósito: así ejercitamos la población 'anon'
        // (una IP sin token válido intentando fuerza bruta), que es la que
        // de verdad puede saturarse detrás de un proxy inverso. Cada 401
        // sigue consumiendo cupo (no es un oráculo gratuito), así que tras
        // agotar el límite de test (config/packages/rate_limiter.yaml,
        // when@test: 15/min) la siguiente petición debe rebotar con 429.
        $server = ['HTTP_AUTHORIZATION' => 'Bearer token-incorrecto', 'REMOTE_ADDR' => '203.0.113.18'];

        for ($i = 0; $i < 15; ++$i) {
            $this->post($client, ['buckets' => []], $server);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        }

        $this->post($client, ['buckets' => []], $server);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        self::assertGreaterThanOrEqual(0, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testAnonFloodingDoesNotConsumeValidTokenBucket(): void
    {
        $client = $this->client;

        // Saturamos por completo el cupo 'anon' de esta IP (misma factory
        // y mismo límite que el de la app, 15/min en test). Antes de este
        // fix la clave era compartida por IP a secas: esto habría agotado
        // también el cupo de la app real. Con la clave separada, no debe
        // afectarle.
        $anonServer = ['HTTP_AUTHORIZATION' => 'Bearer token-incorrecto', 'REMOTE_ADDR' => '203.0.113.19'];
        for ($i = 0; $i < 16; ++$i) {
            $this->post($client, ['buckets' => []], $anonServer);
        }
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());

        // Petición legítima (token válido, clave 'app'): debe seguir
        // aceptándose con normalidad pese al flood anónimo anterior.
        $this->post($client, ['buckets' => []], ['REMOTE_ADDR' => '203.0.113.20']);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }
}
