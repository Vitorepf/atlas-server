<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexSymbolAgeReporter;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasCortexSymbolAgeReporter does not throw when the symbol last-seen
 * timestamp is an invalid date string.
 */
final class AtlasCortexSymbolAgeReporterHardeningTest extends TestCase
{
    // ── normalizeTimestamp via reflection ──────────────────────────────────────

    private function normalizeTimestamp(AtlasCortexSymbolAgeReporter $reporter, string $value): ?string
    {
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('normalizeTimestamp');
        return $method->invoke($reporter, $value);
    }

    public function test_normalize_timestamp_with_valid_iso_string(): void
    {
        $reporter = new AtlasCortexSymbolAgeReporter();

        $result = $this->normalizeTimestamp($reporter, '2026-07-03T12:00:00+00:00');

        $this->assertNotNull($result);
        $this->assertStringContainsString('2026-07-03', $result);
    }

    public function test_normalize_timestamp_with_invalid_date_returns_null(): void
    {
        $reporter = new AtlasCortexSymbolAgeReporter();

        $result = $this->normalizeTimestamp($reporter, 'not-a-date');

        $this->assertNull($result);
    }

    public function test_normalize_timestamp_with_garbage_returns_null(): void
    {
        $reporter = new AtlasCortexSymbolAgeReporter();

        $result = $this->normalizeTimestamp($reporter, '???');

        $this->assertNull($result);
    }

    public function test_normalize_timestamp_with_partial_date_returns_null(): void
    {
        $reporter = new AtlasCortexSymbolAgeReporter();

        $result = $this->normalizeTimestamp($reporter, '2026-13-40');

        $this->assertNull($result);
    }
}
