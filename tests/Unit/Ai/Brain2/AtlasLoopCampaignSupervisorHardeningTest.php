<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasLoopCampaignSupervisorHardeningTest extends TestCase
{
    /**
     * Verify the source guards tail <= 0.
     */
    public function test_source_has_zero_tail_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php');

        $this->assertStringContainsString('$tail <= 0', $source, 'must guard tail <= 0');
        $this->assertStringContainsString('? []', $source, 'must return empty for tail <= 0');
    }

    /**
     * Verify the old max(1, $tail) pattern is gone.
     */
    public function test_old_max_floor_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php');

        $this->assertStringNotContainsString(
            '-max(1, $tail)',
            $source,
            'old max(1, $tail) floor must be replaced'
        );
    }

    /**
     * Demonstrate the bug: max(1, 0) = 1, so array_slice returns one row.
     */
    public function test_zero_tail_returns_one_row_with_old_formula(): void
    {
        $lines = ['a', 'b', 'c'];
        $tail = 0;

        // Old behavior: array_slice($lines, -max(1, 0)) = array_slice($lines, -1) = ['c']
        $oldResult = array_slice($lines, -max(1, $tail));
        $this->assertCount(1, $oldResult, 'old formula returns 1 row for tail=0');

        // New behavior: tail <= 0 → []
        $newResult = $tail <= 0 ? [] : array_slice($lines, -$tail);
        $this->assertCount(0, $newResult, 'new formula returns 0 rows for tail=0');
    }
}
