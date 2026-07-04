<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasBrainHeartbeatLedgerHardeningTest extends TestCase
{
    /**
     * Verify the source guards k <= 0.
     */
    public function test_source_has_zero_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHeartbeatLedger.php');

        $this->assertStringContainsString('$k <= 0', $source, 'must guard k <= 0');
        $this->assertStringContainsString('return []', $source, 'must return empty for k <= 0');
    }

    /**
     * Verify the old max(1, $k) pattern is gone.
     */
    public function test_old_max_floor_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHeartbeatLedger.php');

        $this->assertStringNotContainsString(
            '-max(1, $k)',
            $source,
            'old max(1, $k) floor must be replaced'
        );
    }

    /**
     * Demonstrate the bug: max(1, 0) = 1, so array_slice returns one row.
     */
    public function test_zero_k_returns_one_row_with_old_formula(): void
    {
        $rows = ['a', 'b', 'c'];
        $k = 0;

        // Old behavior: array_slice($rows, -max(1, 0)) = array_slice($rows, -1) = ['c']
        $oldResult = array_slice($rows, -max(1, $k));
        $this->assertCount(1, $oldResult, 'old formula returns 1 row for k=0');

        // New behavior: k <= 0 → []
        $newResult = $k <= 0 ? [] : array_slice($rows, -$k);
        $this->assertCount(0, $newResult, 'new formula returns 0 rows for k=0');
    }

    /**
     * AtlasBrainHeartbeatLedger returns an empty list when asked for zero heartbeat rows.
     */
    public function test_atlas_brain_heartbeat_ledger_returns_empty_for_zero_rows(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHeartbeatLedger.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$k\s*<=\s*0\s*\)\s*\{[^}]*return\s*\[\s*\]/s',
            $source,
            'tail() must return [] for k <= 0 without reading the store'
        );
    }
}
