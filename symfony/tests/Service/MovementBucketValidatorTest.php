<?php

namespace App\Tests\Service;

use App\Service\MovementBucketValidator;
use PHPUnit\Framework\TestCase;

final class MovementBucketValidatorTest extends TestCase
{
    private MovementBucketValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MovementBucketValidator();
    }

    private function validBucket(array $overrides = []): array
    {
        return array_merge([
            'bucket_start' => '2024-01-01T10:00:00+00:00',
            'bucket_end' => '2024-01-01T10:15:00+00:00',
            'origin' => 'phone',
            'steps' => 120,
            'distance_m' => 85.5,
            'floors' => 1,
        ], $overrides);
    }

    public function testValidBucketIsAccepted(): void
    {
        $result = $this->validator->validate($this->validBucket());

        self::assertTrue($result['ok']);
        self::assertSame('phone', $result['data']['origin']);
        self::assertSame(120, $result['data']['steps']);
        self::assertSame(85.5, $result['data']['distance_m']);
        self::assertSame(1, $result['data']['floors']);
    }

    public function testOptionalFieldsDefaultWhenAbsent(): void
    {
        $result = $this->validator->validate([
            'bucket_start' => '2024-01-01T10:00:00+00:00',
            'bucket_end' => '2024-01-01T10:15:00+00:00',
            'origin' => 'phone',
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['data']['steps']);
        self::assertNull($result['data']['distance_m']);
        self::assertNull($result['data']['floors']);
    }

    public function testExplicitNullStepsDefaultsToZeroLikeAbsent(): void
    {
        // Igual que distance_m/floors: un null explícito en el JSON se
        // trata como "ausente" (contrato previo: $b['steps'] ?? 0), no como
        // un valor inválido a rechazar.
        $result = $this->validator->validate($this->validBucket(['steps' => null]));

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['data']['steps']);
    }

    public function testMissingOriginIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['origin' => '']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('origin', $result['reason']);
    }

    public function testNaiveTimestampWithoutOffsetIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['bucket_start' => '2024-01-01T10:00:00']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('bucket_start', $result['reason']);
    }

    public function testUnparseableTimestampIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['bucket_end' => 'no-es-una-fecha+00:00']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('bucket_end', $result['reason']);
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket([
            'bucket_start' => '2024-01-01T10:15:00+00:00',
            'bucket_end' => '2024-01-01T10:00:00+00:00',
        ]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('posterior', $result['reason']);
    }

    public function testEndEqualToStartIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket([
            'bucket_start' => '2024-01-01T10:00:00+00:00',
            'bucket_end' => '2024-01-01T10:00:00+00:00',
        ]));

        self::assertFalse($result['ok']);
    }

    public function testMisalignedStartIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['bucket_start' => '2024-01-01T10:01:00+00:00']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('rejilla', $result['reason']);
    }

    public function testAlignmentIsOffsetIndependent(): void
    {
        // 10:00 UTC == 12:00 +02:00, sigue alineado al cuarto de hora.
        $result = $this->validator->validate($this->validBucket([
            'bucket_start' => '2024-01-01T12:00:00+02:00',
            'bucket_end' => '2024-01-01T12:15:00+02:00',
        ]));

        self::assertTrue($result['ok']);
    }

    public function testNegativeStepsIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['steps' => -1]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('steps', $result['reason']);
    }

    public function testNonIntegerStepsIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['steps' => 12.5]));

        self::assertFalse($result['ok']);
    }

    public function testNegativeDistanceIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['distance_m' => -0.1]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('distance_m', $result['reason']);
    }

    public function testNegativeFloorsIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['floors' => -1]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('floors', $result['reason']);
    }

    public function testStepsAboveInt32MaxIsRejected(): void
    {
        // hc_movement_bucket.steps es INTEGER (máx. 2147483647): un valor
        // mayor pasaría la validación de "entero no negativo" pero
        // reventaría el INSERT.
        $result = $this->validator->validate($this->validBucket(['steps' => 2_147_483_648]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('steps', $result['reason']);
    }

    public function testFloorsAboveInt32MaxIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['floors' => 2_147_483_648]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('floors', $result['reason']);
    }

    public function testDistanceAboveNumeric10_2MaxIsRejected(): void
    {
        // hc_movement_bucket.distance_m es NUMERIC(10,2) (máx. 99999999.99).
        $result = $this->validator->validate($this->validBucket(['distance_m' => 100_000_000.0]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('distance_m', $result['reason']);
    }

    public function testOutOfRangeBucketDoesNotBlockOthersInSameCall(): void
    {
        // El validador es puro: cada llamada es independiente. Confirma
        // que un bucket fuera de rango se rechaza con motivo claro sin
        // afectar la validación de otro bucket válido (el "no 500 en lote
        // mixto" se verifica a nivel de controlador/funcional).
        $invalid = $this->validator->validate($this->validBucket(['steps' => 9_999_999_999]));
        $valid = $this->validator->validate($this->validBucket());

        self::assertFalse($invalid['ok']);
        self::assertTrue($valid['ok']);
    }

    public function testHourTwentyFourIsRejectedInsteadOfRollingOverToNextDay(): void
    {
        // El constructor lenient de DateTimeImmutable aceptaría esto y lo
        // "desbordaría" en silencio a 2024-01-02T00:00:00+00:00: el bucket
        // acabaría bajo el día equivocado sin ningún rechazo.
        $result = $this->validator->validate($this->validBucket(['bucket_start' => '2024-01-01T24:00:00+00:00']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('bucket_start', $result['reason']);
    }

    public function testMonthThirteenIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['bucket_start' => '2024-13-01T10:00:00+00:00']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('bucket_start', $result['reason']);
    }

    public function testDayThirtyTwoIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['bucket_start' => '2024-01-32T10:00:00+00:00']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('bucket_start', $result['reason']);
    }

    public function testFebruaryThirtiethIsRejected(): void
    {
        $result = $this->validator->validate($this->validBucket(['bucket_start' => '2024-02-30T10:00:00+00:00']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('bucket_start', $result['reason']);
    }

    public function testZuluOffsetIsAcceptedAndNormalized(): void
    {
        $result = $this->validator->validate($this->validBucket([
            'bucket_start' => '2024-01-01T10:00:00Z',
            'bucket_end' => '2024-01-01T10:15:00Z',
        ]));

        self::assertTrue($result['ok']);
    }

    public function testFractionalSecondsAreAccepted(): void
    {
        $result = $this->validator->validate($this->validBucket([
            'bucket_start' => '2024-01-01T10:00:00.123456+00:00',
            'bucket_end' => '2024-01-01T10:15:00.654321+00:00',
        ]));

        self::assertTrue($result['ok']);
    }
}
