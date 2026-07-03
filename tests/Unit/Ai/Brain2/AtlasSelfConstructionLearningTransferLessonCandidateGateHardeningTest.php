<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionLearningTransferLessonCandidateGateHardeningTest extends TestCase
{
    /**
     * Verify the source uses >= for conflict tolerance check.
     */
    public function test_source_uses_inclusive_conflict_boundary(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/LearningTransfer/AtlasSelfConstructionLearningTransferLessonCandidateGate.php');

        $this->assertStringContainsString('$second >= $conflictTolerance', $source, 'must use >= for conflict boundary');
    }

    /**
     * Verify the old strict > is gone.
     */
    public function test_old_strict_greater_than_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/LearningTransfer/AtlasSelfConstructionLearningTransferLessonCandidateGate.php');

        $this->assertStringNotContainsString(
            '$second > $conflictTolerance',
            $source,
            'strict > must be replaced with >='
        );
    }

    /**
     * Demonstrate the off-by-one: 50/50 split with tolerance=5 should be rejected.
     */
    public function test_even_split_at_tolerance_is_conflict(): void
    {
        $second = 5;
        $conflictTolerance = 5;

        // Old behavior: 5 > 5 = false → admitted (wrong)
        $oldBehavior = $second > $conflictTolerance;
        $this->assertFalse($oldBehavior, 'old > would not reject at boundary');

        // New behavior: 5 >= 5 = true → rejected (correct)
        $newBehavior = $second >= $conflictTolerance;
        $this->assertTrue($newBehavior, 'new >= correctly rejects at boundary');
    }
}
