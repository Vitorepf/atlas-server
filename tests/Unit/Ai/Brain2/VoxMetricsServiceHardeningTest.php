<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class VoxMetricsServiceHardeningTest extends TestCase
{
    /**
     * Verify the source has a safeParse method with try/catch.
     */
    public function test_parse_source_has_safe_parse_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Vox/Metrics/VoxMetricsService.php');

        $this->assertStringContainsString('safeParse', $source, 'must have safeParse method');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify the old unguarded parse is gone.
     */
    public function test_parse_old_unguarded_parse_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Vox/Metrics/VoxMetricsService.php');

        $this->assertStringNotContainsString(
            'CarbonImmutable::parse($row->occurred_at)',
            $source,
            'old unguarded parse must be replaced'
        );
    }

    /**
     * Demonstrate the bug: CarbonImmutable::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws_without_guard(): void
    {
        $this->expectException(\Throwable::class);
        \Carbon\CarbonImmutable::parse('not-a-date');
    }
}
