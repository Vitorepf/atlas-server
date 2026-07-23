<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainQueueSaturationQualityGovernorHardeningTest extends TestCase
{
    /**
     * Verify the source has a zero-worker guard for maxNewTasks.
     */
    public function test_source_has_zero_worker_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainQueueSaturationQualityGovernor.php');

        $this->assertStringContainsString('$activeWorkers === 0', $source, 'must check for zero active workers');
        $this->assertStringContainsString('? 0', $source, 'must return 0 for zero workers');
    }

    /**
     * Verify the old max(1, ...) floor is no longer unconditional.
     */
    public function test_max_one_floor_is_conditional(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainQueueSaturationQualityGovernor.php');

        // The old pattern was: $maxNewTasks = max(1, min(..., $remainingCapacity));
        // After the fix, max(1,...) is inside a ternary branch, not unconditional.
        $this->assertStringNotContainsString(
            '$maxNewTasks = max(1, min(self::MAX_NEW_TASKS_CAP, $remainingCapacity));',
            $source,
            'unconditional max(1,...) floor must be replaced'
        );
    }

    /**
     * Verify the guard comes before the return.
     */
    public function test_guard_before_return(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainQueueSaturationQualityGovernor.php');

        $guardPos = strpos($source, '$activeWorkers === 0');
        $returnPos = strpos($source, "'max_new_tasks' => \$maxNewTasks");

        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($returnPos);
        $this->assertLessThan($returnPos, $guardPos, 'guard must come before return');
    }
}
