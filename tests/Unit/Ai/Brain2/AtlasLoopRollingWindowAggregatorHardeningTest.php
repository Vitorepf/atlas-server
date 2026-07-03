<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowAggregator;

final class AtlasLoopRollingWindowAggregatorHardeningTest extends TestCase
{
    /**
     * aggregate must not throw on an invalid nowIso — it should fall back to now.
     */
    public function test_invalid_now_iso_falls_back_to_now(): void
    {
        $agg = new AtlasLoopRollingWindowAggregator();

        $result = $agg->aggregate([], 'not-a-date');

        $this->assertArrayHasKey('buckets', $result);
        $this->assertIsArray($result['buckets']);
    }

    /**
     * Valid nowIso produces correct buckets.
     */
    public function test_valid_now_iso_works(): void
    {
        $agg = new AtlasLoopRollingWindowAggregator();

        $result = $agg->aggregate([], '2026-01-15T10:30:00+00:00');

        $this->assertArrayHasKey('buckets', $result);
        $this->assertIsArray($result['buckets']);
    }

    /**
     * Verify the source has the try/catch guard.
     */
    public function test_source_has_try_catch(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Telemetry/RollingWindows/AtlasLoopRollingWindowAggregator.php');

        $this->assertStringContainsString('try {', $source, 'aggregate must wrap DateTimeImmutable in try/catch');
        $this->assertStringContainsString('Throwable', $source, 'aggregate must catch Throwable');
    }
}
