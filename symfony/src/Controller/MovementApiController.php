<?php

namespace App\Controller;

use App\Service\MovementBucketValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
    // Límites de saneamiento: el cliente Android manda como mucho un día
    // completo en buckets de 15 min (96 buckets), así que 5000 da margen de
    // sobra sin dejar la puerta abierta a lotes arbitrariamente grandes.
    private const MAX_BODY_BYTES = 2 * 1024 * 1024;
    private const MAX_BUCKETS = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $http,
        private readonly MovementBucketValidator $validator,
        #[Autowire(service: 'limiter.movement_api')]
        private readonly RateLimiterFactory $movementLimiter,
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
        // La comprobación del token (barata: solo compara cabeceras, no
        // toca el body ni la BD) va ANTES del rate limit para poder separar
        // la población que consume cada cupo. Detrás de un proxy inverso o
        // túnel (Cloudflare Tunnel, ver README) no hay `trusted_proxies`
        // configurado, así que getClientIp() siempre devuelve la IP del
        // proxy: si midiéramos "por IP" a secas, TODO el tráfico —incluida
        // la app real— compartiría un único cupo global, y a un atacante le
        // bastaría mandar tokens erróneos para agotarlo y dejar a la app
        // real bloqueada con 429. Separando la clave según el resultado del
        // token, el cupo generoso de la app real ('app', 60/min) queda
        // protegido de esa saturación; el cupo de quien no tiene token
        // válido ('anon:<ip>') sigue colapsando en un único bucket
        // compartido detrás del proxy, pero eso solo perjudica a quien
        // intenta fuerza bruta, nunca a la app.
        $auth = $request->headers->get('Authorization', '');
        $tokenValid = '' !== $this->apiToken
            && str_starts_with($auth, 'Bearer ')
            && hash_equals($this->apiToken, substr($auth, 7));

        $limiterKey = $tokenValid ? 'app' : 'anon:'.($request->getClientIp() ?? 'unknown');
        $limit = $this->movementLimiter->create($limiterKey)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfterSeconds = max(0, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(
                ['error' => 'demasiadas peticiones, reintenta más tarde'],
                429,
                ['Retry-After' => (string) $retryAfterSeconds],
            );
        }

        // El 401 se devuelve DESPUÉS de que el límite haya aceptado la
        // petición: así el token inválido sigue consumiendo cupo (del
        // bucket 'anon') y no se convierte en un oráculo gratuito para
        // probar credenciales a fuerza bruta sin gastar límite.
        if (!$tokenValid) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        // El Content-Type puede traer parámetros ("; charset=utf-8"): solo
        // nos importa el mime type.
        $mimeType = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0]));
        if ('application/json' !== $mimeType) {
            return new JsonResponse(['error' => 'se requiere Content-Type: application/json'], 415);
        }

        // Comprobación temprana por cabecera, ANTES de bufferizar el body
        // entero con getContent(): así un cliente que anuncia un
        // Content-Length superior al límite se rechaza sin gastar memoria
        // leyéndolo. El strlen() de después queda como red de seguridad
        // (Content-Length ausente o falseado a la baja).
        $contentLength = $request->headers->get('Content-Length');
        if (null !== $contentLength && (int) $contentLength > self::MAX_BODY_BYTES) {
            return new JsonResponse(['error' => 'cuerpo demasiado grande (máximo 2 MB)'], 413);
        }

        $body = $request->getContent();
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['error' => 'cuerpo demasiado grande (máximo 2 MB)'], 413);
        }

        $payload = json_decode($body, true);
        if (!\is_array($payload) || !\is_array($payload['buckets'] ?? null)) {
            return new JsonResponse(['error' => 'cuerpo inválido: se espera {device, buckets[]}'], 422);
        }

        if (\count($payload['buckets']) > self::MAX_BUCKETS) {
            return new JsonResponse(['error' => 'demasiados buckets en el lote (máximo 5000)'], 413);
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
        $rejected = [];
        $this->db->beginTransaction();
        try {
            foreach ($payload['buckets'] as $b) {
                if (!\is_array($b)) {
                    $rejected[] = ['bucket_start' => null, 'reason' => 'el bucket no es un objeto válido'];
                    continue;
                }

                $result = $this->validator->validate($b);
                if (!$result['ok']) {
                    // Bucket inválido: no se upsertea, pero tampoco se descarta
                    // en silencio — se reporta con el motivo para que la app
                    // pueda decidir si reintenta o descarta el dato.
                    $rejected[] = [
                        'bucket_start' => $b['bucket_start'] ?? null,
                        'reason' => $result['reason'],
                    ];
                    continue;
                }

                $data = $result['data'];
                $this->db->executeStatement($sql, [
                    'bucket_start' => $data['bucket_start'],
                    'bucket_end'   => $data['bucket_end'],
                    'origin'       => $data['origin'],
                    'steps'        => $data['steps'],
                    'distance_m'   => $data['distance_m'],
                    'floors'       => $data['floors'],
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

        return new JsonResponse(['upserted' => $upserted, 'rejected' => $rejected]);
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
