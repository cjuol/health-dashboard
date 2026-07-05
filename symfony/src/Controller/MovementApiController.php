<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Endpoint que consume la app Android (HC Movimiento).
 *
 * Contrato: POST /api/v1/health/movement con Bearer token y un array de
 * buckets de 15 min. Upsert idempotente por (bucket_start, origin): la app
 * reenvía siempre el último día completo y aquí solo se refresca, nunca
 * se duplica.
 *
 * Tras un POST correcto se dispara (fire-and-forget) la ingesta de Garmin
 * en el sidecar: así cuando abres la app, el dashboard recibe a la vez el
 * movimiento del móvil y los datos frescos de Garmin. El sidecar aplica
 * lock y cooldown, así que dispararlo de más es inocuo.
 */
final class MovementApiController
{
    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'MOVEMENT_API_TOKEN')]
        private readonly string $apiToken,
        #[Autowire(env: 'SIDECAR_URL')]
        private readonly string $sidecarUrl,
        #[Autowire(env: 'SIDECAR_TOKEN')]
        private readonly string $sidecarToken,
    ) {
    }

    #[Route('/api/v1/health/movement', name: 'api_movement', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Auth por Bearer con comparación en tiempo constante.
        $auth = $request->headers->get('Authorization', '');
        if ('' === $this->apiToken
            || !str_starts_with($auth, 'Bearer ')
            || !hash_equals($this->apiToken, substr($auth, 7))
        ) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !\is_array($payload['buckets'] ?? null)) {
            return new JsonResponse(['error' => 'cuerpo inválido: se espera {device, buckets[]}'], 422);
        }

        $device = \is_string($payload['device'] ?? null) ? mb_substr($payload['device'], 0, 64) : null;

        $sql = <<<'SQL'
            INSERT INTO hc_movement_bucket
                (bucket_start, bucket_end, origin, steps, distance_m, floors, device)
            VALUES
                (:bucket_start, :bucket_end, :origin, :steps, :distance_m, :floors, :device)
            ON CONFLICT (bucket_start, origin) DO UPDATE SET
                bucket_end  = EXCLUDED.bucket_end,
                steps       = EXCLUDED.steps,
                distance_m  = EXCLUDED.distance_m,
                floors      = EXCLUDED.floors,
                device      = EXCLUDED.device,
                received_at = now()
            SQL;

        $upserted = 0;
        $this->db->beginTransaction();
        try {
            foreach ($payload['buckets'] as $b) {
                if (!isset($b['bucket_start'], $b['bucket_end'], $b['origin'])) {
                    continue; // bucket malformado: se ignora sin tumbar el lote
                }
                $this->db->executeStatement($sql, [
                    'bucket_start' => $b['bucket_start'],   // ISO-8601 con offset → timestamptz
                    'bucket_end'   => $b['bucket_end'],
                    'origin'       => mb_substr((string) $b['origin'], 0, 191),
                    'steps'        => (int) ($b['steps'] ?? 0),
                    'distance_m'   => isset($b['distance_m']) ? (float) $b['distance_m'] : null,
                    'floors'       => isset($b['floors']) ? (int) $b['floors'] : null,
                    'device'       => $device,
                ]);
                ++$upserted;
            }
            // Registrar el último contacto de la app (visible en el dashboard).
            $this->db->executeStatement(
                "INSERT INTO sync_state (key, value_ts) VALUES ('hc_app_last_sync', now())
                 ON CONFLICT (key) DO UPDATE SET value_ts = now()"
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->triggerGarminSync();

        return new JsonResponse(['upserted' => $upserted]);
    }

    /**
     * Fire-and-forget: pide al sidecar que ingiera Garmin. El sidecar responde
     * 202 al instante y trabaja en background; cualquier fallo aquí (sidecar
     * caído, timeout) se ignora para no penalizar nunca el sync del móvil.
     */
    private function triggerGarminSync(): void
    {
        try {
            $this->http->request('POST', rtrim($this->sidecarUrl, '/').'/sync-garmin', [
                'headers' => ['X-Auth-Token' => $this->sidecarToken],
                'timeout' => 3,
            ])->getStatusCode();
        } catch (\Throwable) {
            // silencioso a propósito: la ingesta tiene el cron como red de seguridad
        }
    }
}
