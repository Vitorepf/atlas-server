<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservation;

final class AtlasCortexInsightObservationHardeningTest extends TestCase
{
    /**
     * normalizeTimestamp must not throw on an invalid date string —
     * it should fall back to now instead.
     */
    public function test_invalid_date_falls_back_to_now(): void
    {
        $result = AtlasCortexInsightObservation::normalizeTimestamp('not-a-date');

        // Should return a valid ISO timestamp (not throw).
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result);
    }

    /**
     * Valid date strings are parsed correctly.
     */
    public function test_valid_date_parsed(): void
    {
        $result = AtlasCortexInsightObservation::normalizeTimestamp('2026-01-15T10:30:00+00:00');

        $this->assertSame('2026-01-15T10:30:00Z', $result);
    }

    /**
     * Null/empty falls back to now.
     */
    public function test_null_falls_back_to_now(): void
    {
        $result = AtlasCortexInsightObservation::normalizeTimestamp(null);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result);
    }

    /**
     * Verify the source has the try/catch guard.
     */
    public function test_source_has_try_catch(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Insights/AtlasCortexInsightObservation.php');

        $this->assertStringContainsString('try {', $source, 'normalizeTimestamp must wrap DateTimeImmutable in try/catch');
        $this->assertStringContainsString('Throwable', $source, 'normalizeTimestamp must catch Throwable');
    }
}
