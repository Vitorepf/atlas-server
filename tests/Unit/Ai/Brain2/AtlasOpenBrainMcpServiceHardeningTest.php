<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasOpenBrainMcpServiceHardeningTest extends TestCase
{
    /**
     * Verify the source wraps Carbon::parse in try/catch for last_indexed_at.
     *
     * GOD-DEBULK D3: the last_indexed_at parse lives in the relocated
     * atlas_recent_changes handler (OpenBrainMcp/NavigationTools); the invariant
     * (guarded parse) is unchanged, only its section home moved.
     */
    public function test_source_has_try_catch_for_index_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/OpenBrainMcp/NavigationTools.php');

        $this->assertStringContainsString('Carbon::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify the old unguarded parse is gone.
     */
    public function test_old_unguarded_parse_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/OpenBrainMcp/NavigationTools.php');

        // The old pattern: $indexFresh = Carbon::parse($lastIndexAt)\n                ->greaterThan
        $this->assertStringNotContainsString(
            "\$indexFresh = Carbon::parse(\$lastIndexAt)\n                ->greaterThan",
            $source,
            'old unguarded parse must be replaced'
        );
    }

    /**
     * Demonstrate the bug: Carbon::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws(): void
    {
        $this->expectException(\Throwable::class);
        \Carbon\CarbonImmutable::parse('not-a-date');
    }
}
