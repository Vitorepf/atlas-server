<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\ExecutionGateBlockerCollector;
use PHPUnit\Framework\TestCase;

final class ExecutionGateBlockerCollectorTest extends TestCase
{
    public function test_all_ready_contracts_yield_no_blockers(): void
    {
        $collector = new ExecutionGateBlockerCollector();

        $blockers = $collector->collect([
            'runtime_ready' => true,
            'contracts_certified' => true,
            'artifact_count' => 10,
            'shadow_ready' => true,
            'brain_ready' => true,
        ]);

        $this->assertSame([], $blockers);
    }

    public function test_all_false_contracts_yield_all_five_blockers_in_documented_order(): void
    {
        $collector = new ExecutionGateBlockerCollector();

        $blockers = $collector->collect([
            'runtime_ready' => false,
            'contracts_certified' => false,
            'artifact_count' => 0,
            'shadow_ready' => false,
            'brain_ready' => false,
        ]);

        $this->assertSame([
            'workspace_not_ready',
            'workspace_contracts_not_certified',
            'workspace_artifacts_incomplete',
            'artifact_shadow_execution_blocked',
            'workspace_next_session_brain_not_ready',
        ], $blockers);
    }

    public function test_artifact_count_threshold_is_exactly_ten(): void
    {
        $collector = new ExecutionGateBlockerCollector();

        $atThreshold = $collector->collect([
            'runtime_ready' => true,
            'contracts_certified' => true,
            'artifact_count' => 10,
            'shadow_ready' => true,
            'brain_ready' => true,
        ]);

        $this->assertNotContains('workspace_artifacts_incomplete', $atThreshold);
        $this->assertSame([], $atThreshold);

        $belowThreshold = $collector->collect([
            'runtime_ready' => true,
            'contracts_certified' => true,
            'artifact_count' => 9,
            'shadow_ready' => true,
            'brain_ready' => true,
        ]);

        $this->assertSame(['workspace_artifacts_incomplete'], $belowThreshold);
    }

    public function test_single_failing_signal_yields_only_its_blocker(): void
    {
        $collector = new ExecutionGateBlockerCollector();

        $blockers = $collector->collect([
            'runtime_ready' => true,
            'contracts_certified' => true,
            'artifact_count' => 10,
            'shadow_ready' => false,
            'brain_ready' => true,
        ]);

        $this->assertSame(['artifact_shadow_execution_blocked'], $blockers);
    }

    public function test_empty_contracts_conservatively_block_with_all_five(): void
    {
        $collector = new ExecutionGateBlockerCollector();

        $blockers = $collector->collect([]);

        $this->assertSame([
            'workspace_not_ready',
            'workspace_contracts_not_certified',
            'workspace_artifacts_incomplete',
            'artifact_shadow_execution_blocked',
            'workspace_next_session_brain_not_ready',
        ], $blockers);
    }

    public function test_mixed_signals_preserve_documented_order_for_failing_subset(): void
    {
        $collector = new ExecutionGateBlockerCollector();

        $blockers = $collector->collect([
            'runtime_ready' => false,
            'contracts_certified' => true,
            'artifact_count' => 25,
            'shadow_ready' => false,
            'brain_ready' => true,
        ]);

        $this->assertSame([
            'workspace_not_ready',
            'artifact_shadow_execution_blocked',
        ], $blockers);
    }
}
