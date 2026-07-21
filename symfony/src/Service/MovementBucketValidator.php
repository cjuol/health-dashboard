<?php

namespace App\Service;

/**
 * Valida y normaliza un bucket crudo recibido en POST /api/v1/health/movement.
 *
 * Separado del controlador para poder testearlo sin arrancar el kernel:
 * no depende de la base de datos ni de HTTP, solo de los datos ya
 * decodificados del JSON.
 */
final class MovementBucketValidator
{
    private const BUCKET_GRID_SECONDS = 900; // 15 minutos

    // Límites de las columnas destino en hc_movement_bucket: INTEGER
    // (steps, floors) y NUMERIC(10,2) (distance_m). Sin este tope, un valor
    // fuera de rango pasa la validación y revienta en el INSERT — con toda
    // la transacción del lote haciendo rollback y el cliente recibiendo un
    // 500 sin ningún upsert, peor que descartar solo ese bucket.
    private const MAX_INT32 = 2_147_483_647;
    private const MAX_NUMERIC_10_2 = 99_999_999.99;

    // Formatos ISO-8601 estrictos aceptados para bucket_start/bucket_end:
    // con y sin fracción de segundo (de ancho variable, ver
    // ISO_DATETIME_PATTERN). DateTimeImmutable::createFromFormat() en vez
    // del constructor lenient del propio DateTimeImmutable (ver
    // parseOffsetDateTime()).
    private const ISO_DATETIME_FORMAT = 'Y-m-d\TH:i:sP';
    private const ISO_DATETIME_FORMAT_WITH_FRACTION = 'Y-m-d\TH:i:s.uP';

    // Forma general de un ISO-8601 con offset explícito (Z o ±HH:MM) y
    // fracción de segundo opcional de 1 a 9 dígitos: cubre desde
    // milisegundos hasta nanosegundos, que es el rango que puede emitir un
    // Instant/OffsetDateTime de Java (origen real de estos timestamps).
    private const ISO_DATETIME_PATTERN = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * @param array<string, mixed> $bucket
     *
     * @return array{ok: true, data: array{bucket_start: string, bucket_end: string, origin: string, steps: int, distance_m: ?float, floors: ?int}}|array{ok: false, reason: string}
     */
    public function validate(array $bucket): array
    {
        if (!\is_string($bucket['origin'] ?? null) || '' === trim($bucket['origin'])) {
            return ['ok' => false, 'reason' => 'origin es obligatorio y no puede estar vacío'];
        }

        $start = $this->parseOffsetDateTime($bucket['bucket_start'] ?? null);
        if (null === $start) {
            return ['ok' => false, 'reason' => 'bucket_start no es una fecha ISO-8601 válida con offset explícito'];
        }

        $end = $this->parseOffsetDateTime($bucket['bucket_end'] ?? null);
        if (null === $end) {
            return ['ok' => false, 'reason' => 'bucket_end no es una fecha ISO-8601 válida con offset explícito'];
        }

        if ($end->getTimestamp() <= $start->getTimestamp()) {
            return ['ok' => false, 'reason' => 'bucket_end debe ser posterior a bucket_start'];
        }

        // Comparación por epoch: no depende del offset con el que llegó el string.
        if (0 !== $start->getTimestamp() % self::BUCKET_GRID_SECONDS) {
            return ['ok' => false, 'reason' => 'bucket_start no está alineado a la rejilla de 15 minutos'];
        }

        if (0 !== $end->getTimestamp() % self::BUCKET_GRID_SECONDS) {
            return ['ok' => false, 'reason' => 'bucket_end no está alineado a la rejilla de 15 minutos'];
        }

        // steps: igual que distance_m/floors, un null explícito en el JSON
        // se trata como "ausente" (por defecto 0) — así se mantiene el
        // contrato previo ($b['steps'] ?? 0) en vez de rechazar el bucket.
        $steps = 0;
        if (\array_key_exists('steps', $bucket) && null !== $bucket['steps']) {
            if (!$this->isNonNegativeInt($bucket['steps'])) {
                return ['ok' => false, 'reason' => 'steps debe ser un entero no negativo'];
            }
            if ($bucket['steps'] > self::MAX_INT32) {
                return ['ok' => false, 'reason' => 'steps supera el máximo representable en la columna (2147483647)'];
            }
            $steps = $bucket['steps'];
        }

        $distanceM = null;
        if (\array_key_exists('distance_m', $bucket) && null !== $bucket['distance_m']) {
            if (!$this->isNonNegativeNumber($bucket['distance_m'])) {
                return ['ok' => false, 'reason' => 'distance_m debe ser un número no negativo'];
            }
            if ($bucket['distance_m'] > self::MAX_NUMERIC_10_2) {
                return ['ok' => false, 'reason' => 'distance_m supera el máximo representable en la columna (99999999.99)'];
            }
            $distanceM = (float) $bucket['distance_m'];
        }

        $floors = null;
        if (\array_key_exists('floors', $bucket) && null !== $bucket['floors']) {
            if (!$this->isNonNegativeInt($bucket['floors'])) {
                return ['ok' => false, 'reason' => 'floors debe ser un entero no negativo'];
            }
            if ($bucket['floors'] > self::MAX_INT32) {
                return ['ok' => false, 'reason' => 'floors supera el máximo representable en la columna (2147483647)'];
            }
            $floors = $bucket['floors'];
        }

        return [
            'ok' => true,
            'data' => [
                // Normalizado a un único formato: da igual con qué offset llegó,
                // Postgres lo guarda como el mismo instante en timestamptz.
                'bucket_start' => $start->format(\DATE_ATOM),
                'bucket_end' => $end->format(\DATE_ATOM),
                'origin' => mb_substr($bucket['origin'], 0, 191),
                'steps' => $steps,
                'distance_m' => $distanceM,
                'floors' => $floors,
            ],
        ];
    }

    private function isNonNegativeInt(mixed $value): bool
    {
        return \is_int($value) && $value >= 0;
    }

    private function isNonNegativeNumber(mixed $value): bool
    {
        return (\is_int($value) || \is_float($value)) && $value >= 0;
    }

    /**
     * Exige un ISO-8601 con offset explícito (Z o ±HH:MM); los timestamps
     * "naive" (sin offset) se rechazan en vez de asumir una zona horaria.
     *
     * Parseo estricto: el constructor de DateTimeImmutable usa un parser
     * "lenient" que ante componentes de calendario fuera de rango (p.ej.
     * hora 24, día 32, 30 de febrero) no lanza excepción, sino que los
     * "desborda" hacia adelante en silencio (24:00:00 se convierte en el
     * 00:00:00 del día siguiente) — el bucket acabaría guardado bajo el día
     * equivocado sin ningún rechazo. createFromFormat() contra formatos
     * explícitos sí reporta ese desbordamiento vía getLastErrors(), que
     * aquí tratamos como fecha inválida.
     */
    private function parseOffsetDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        if (1 !== preg_match(self::ISO_DATETIME_PATTERN, $value, $matches)) {
            return null;
        }

        // Normaliza el offset "Z" a "+00:00" (como lo representa siempre
        // "P" al formatear) y la fracción de segundo a exactamente 6
        // dígitos: el formato "u" de DateTimeImmutable espera microsegundos,
        // así que se rellena con ceros a la derecha si venían menos de 6
        // dígitos y se trunca si venían más (los dígitos por debajo del
        // microsegundo se pierden igual que los perdería Postgres al
        // guardar en timestamptz). Sin esta normalización, comparar contra
        // el string original rechazaba en falso cualquier fracción que no
        // tuviera exactamente 6 dígitos (format('u') siempre rellena a 6).
        $hasFraction = isset($matches[2]) && '' !== $matches[2];
        $offset = 'Z' === $matches[3] ? '+00:00' : $matches[3];
        $format = $hasFraction ? self::ISO_DATETIME_FORMAT_WITH_FRACTION : self::ISO_DATETIME_FORMAT;
        $normalized = $matches[1].($hasFraction ? '.'.str_pad(substr($matches[2], 0, 6), 6, '0') : '').$offset;

        $parsed = \DateTimeImmutable::createFromFormat($format, $normalized);
        if (false === $parsed) {
            return null;
        }

        // getLastErrors() devuelve false (no un array) cuando no hubo
        // ningún error NI warning — comportamiento de PHP 8.3, hay que
        // contemplarlo además del caso "array con contadores en 0".
        $errors = \DateTimeImmutable::getLastErrors();
        if (false !== $errors && ($errors['error_count'] > 0 || $errors['warning_count'] > 0)) {
            return null;
        }

        // Cinturón y tirantes: si al reformatear el valor parseado con el
        // MISMO formato no se reproduce el string ya normalizado, algo se
        // coló pese a no haber marcado error ni warning (p.ej. hora 24, 30
        // de febrero o mes 13, que createFromFormat "desborda" hacia
        // adelante en vez de rechazar) — se descarta igual en vez de
        // confiar ciegamente.
        if ($parsed->format($format) !== $normalized) {
            return null;
        }

        return $parsed;
    }
}
