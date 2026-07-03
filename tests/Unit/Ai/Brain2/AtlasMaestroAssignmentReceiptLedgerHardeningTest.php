<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroAssignmentReceiptLedgerHardeningTest extends TestCase
{
    /**
     * Verify the source guards n <= 0.
     */
    public function test_source_has_zero_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/MultiProvider/AtlasMaestroAssignmentReceiptLedger.php');

        $this->assertStringContainsString('$n <= 0', $source, 'must guard n <= 0');
        $this->assertStringContainsString('return []', $source, 'must return empty for n <= 0');
    }

    /**
     * Verify the old max(1, $n) pattern is gone.
     */
    public function test_old_max_floor_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/MultiProvider/AtlasMaestroAssignmentReceiptLedger.php');

        $this->assertStringNotContainsString(
            '-max(1, $n)',
            $source,
            'old max(1, $n) floor must be replaced'
        );
    }

    /**
     * Demonstrate the bug: max(1, 0) = 1, so array_slice returns one row.
     */
    public function test_zero_n_returns_one_row_with_old_formula(): void
    {
        $rows = ['a', 'b', 'c'];
        $n = 0;

        // Old behavior: array_slice($rows, -max(1, 0)) = array_slice($rows, -1) = ['c']
        $oldResult = array_slice($rows, -max(1, $n));
        $this->assertCount(1, $oldResult, 'old formula returns 1 row for n=0');

        // New behavior: n <= 0 → []
        $newResult = $n <= 0 ? [] : array_slice($rows, -$n);
        $this->assertCount(0, $newResult, 'new formula returns 0 rows for n=0');
    }
}
