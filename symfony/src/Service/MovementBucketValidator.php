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

        $steps = 0;
        if (\array_key_exists('steps', $bucket)) {
            if (!$this->isNonNegativeInt($bucket['steps'])) {
                return ['ok' => false, 'reason' => 'steps debe ser un entero no negativo'];
            }
            $steps = $bucket['steps'];
        }

        $distanceM = null;
        if (\array_key_exists('distance_m', $bucket) && null !== $bucket['distance_m']) {
            if (!$this->isNonNegativeNumber($bucket['distance_m'])) {
                return ['ok' => false, 'reason' => 'distance_m debe ser un número no negativo'];
            }
            $distanceM = (float) $bucket['distance_m'];
        }

        $floors = null;
        if (\array_key_exists('floors', $bucket) && null !== $bucket['floors']) {
            if (!$this->isNonNegativeInt($bucket['floors'])) {
                return ['ok' => false, 'reason' => 'floors debe ser un entero no negativo'];
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
     */
    private function parseOffsetDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
