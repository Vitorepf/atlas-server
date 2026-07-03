<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexOrphanAgeReporter;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasCortexOrphanAgeReporter does not throw when an orphan unwired-since
 * timestamp is an invalid date string.
 */
final class AtlasCortexOrphanAgeReporterHardeningTest extends TestCase
{
    private function normalizeTimestamp(AtlasCortexOrphanAgeReporter $reporter, string $value): ?string
    {
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('normalizeTimestamp');
        return $method->invoke($reporter, $value);
    }

    private function unwiredDays(AtlasCortexOrphanAgeReporter $reporter, ?string $unwiredSinceAt, ?string $headTimestamp): ?int
    {
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('unwiredDays');
        return $method->invoke($reporter, $unwiredSinceAt, $headTimestamp);
    }

    public function test_normalize_timestamp_with_valid_iso_string(): void
    {
        $reporter = new AtlasCortexOrphanAgeReporter();

        $result = $this->normalizeTimestamp($reporter, '2026-07-03T12:00:00+00:00');

        $this->assertNotNull($result);
        $this->assertStringContainsString('2026-07-03', $result);
    }

    public function test_normalize_timestamp_with_invalid_date_returns_null(): void
    {
        $reporter = new AtlasCortexOrphanAgeReporter();

        $result = $this->normalizeTimestamp($reporter, 'not-a-date');

        $this->assertNull($result);
    }

    public function test_unwired_days_with_invalid_unwired_since_returns_null(): void
    {
        $reporter = new AtlasCortexOrphanAgeReporter();

        $result = $this->unwiredDays($reporter, 'not-a-date', '2026-07-03T12:00:00Z');

        $this->assertNull($result);
    }

    public function test_unwired_days_with_invalid_head_timestamp_returns_null(): void
    {
        $reporter = new AtlasCortexOrphanAgeReporter();

        $result = $this->unwiredDays($reporter, '2026-07-01T12:00:00Z', 'not-a-date');

        $this->assertNull($result);
    }

    public function test_unwired_days_with_valid_dates_returns_days(): void
    {
        $reporter = new AtlasCortexOrphanAgeReporter();

        $result = $this->unwiredDays($reporter, '2026-07-01T12:00:00+00:00', '2026-07-03T12:00:00+00:00');

        $this->assertSame(2, $result);
    }
}
