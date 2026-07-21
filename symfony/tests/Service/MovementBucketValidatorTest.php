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
}
