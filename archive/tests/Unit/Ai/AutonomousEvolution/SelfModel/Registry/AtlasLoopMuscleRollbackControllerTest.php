<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Registry;

use App\Services\Ai\AutonomousEvolution\SelfModel\Registry\AtlasLoopModelRegistry;
use App\Services\Ai\AutonomousEvolution\SelfModel\Registry\AtlasLoopMuscleRollbackController;
use PHPUnit\Framework\TestCase;

final class AtlasLoopMuscleRollbackControllerTest extends TestCase
{
    public function test_degraded_canary_with_previous_version_rolls_active_back(): void
    {
        $registry = $this->registryWithTwoPromotedVersions();

        $result = (new AtlasLoopMuscleRollbackController)->rollback($registry, [
            'degraded' => true,
            'reason' => 'canary_accuracy_drop',
        ]);

        $this->assertSame([
            'status' => 'rolled_back',
            'from' => 'muscle-v2',
            'to' => 'muscle-v1',
            'reason' => 'canary_accuracy_drop',
        ], $result);
        $this->assertSame('muscle-v1', $registry->active());
        $this->assertSame('muscle-v2', $registry->previous());
    }

    public function test_non_degraded_canary_is_noop_and_leaves_active_unchanged(): void
    {
        $registry = $this->registryWithTwoPromotedVersions();

        $result = (new AtlasLoopMuscleRollbackController)->rollback($registry, [
            'degraded' => false,
            'reason' => 'healthy',
        ]);

        $this->assertSame(['status' => 'noop'], $result);
        $this->assertSame('muscle-v2', $registry->active());
        $this->assertSame('muscle-v1', $registry->previous());
    }

    public function test_degraded_canary_with_no_previous_version_is_blocked(): void
    {
        $registry = new AtlasLoopModelRegistry;
        $registry->promote('muscle-v1', true);

        $result = (new AtlasLoopMuscleRollbackController)->rollback($registry, [
            'degraded' => true,
            'reason' => 'regression',
        ]);

        $this->assertSame(['status' => 'blocked', 'reason' => 'no_previous_version'], $result);
        $this->assertSame('muscle-v1', $registry->active());
        $this->assertNull($registry->previous());
    }

    public function test_rollback_result_reports_from_and_to_versions(): void
    {
        $registry = $this->registryWithTwoPromotedVersions();

        $result = (new AtlasLoopMuscleRollbackController)->rollback($registry, [
            'degraded' => true,
            'reason' => 'latency_spike',
        ]);

        $this->assertSame('muscle-v2', $result['from']);
        $this->assertSame('muscle-v1', $result['to']);
    }

    private function registryWithTwoPromotedVersions(): AtlasLoopModelRegistry
    {
        $registry = new AtlasLoopModelRegistry;
        $registry->promote('muscle-v1', true);
        $registry->promote('muscle-v2', true);

        return $registry;
    }
}
