<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneReplayDiffServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function newDiffService(): AgentControlPlaneReplayDiffService
    {
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return new AgentControlPlaneReplayDiffService($store, $replay);
    }

    /** @return array<string, mixed> */
    private function freshReplay(): array
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return $replay->replay();
    }

    public function test_no_regression_yields_none_severity(): void
    {
        $replay = $this->freshReplay();

        $diff = $this->newDiffService()->diff(null, $replay);

        $this->assertSame([], $diff['regression_severity_map']);
        $this->assertSame('none', $diff['highest_regression_severity']);
    }

    public function test_runtime_safety_loss_is_critical(): void
    {
        $before = $this->freshReplay();
        $before['runtime_safety'] = ['runtime_safety_all_false' => true];
        $after = $this->freshReplay();
        $after['runtime_safety'] = ['runtime_safety_all_false' => false];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('critical', $diff['regression_severity_map']['runtime_safety_dropped_from_all_false']);
        $this->assertSame('critical', $diff['highest_regression_severity']);
    }

    public function test_pointer_regression_is_high(): void
    {
        $before = $this->freshReplay();
        $before['current_pointer'] = 'slice_a';
        $after = $this->freshReplay();
        $after['current_pointer'] = 'slice_b';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));
        $after['cycle_integrity'] = [
            'intentional_reentry_detected' => false,
            'regressions' => ['pointer_jump'],
            'cycle_ok' => false,
        ];

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('high', $diff['regression_severity_map']['pointer_regression']);
        $this->assertSame('high', $diff['highest_regression_severity']);
    }

    public function test_large_violation_increase_is_high(): void
    {
        $before = $this->freshReplay();
        $before['violations'] = [];
        $after = $this->freshReplay();
        $after['violations'] = ['v1', 'v2', 'v3'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('high', $diff['regression_severity_map']['violation_increase']);
        $this->assertSame('high', $diff['highest_regression_severity']);
    }

    public function test_small_violation_increase_is_medium(): void
    {
        $before = $this->freshReplay();
        $before['violations'] = [];
        $after = $this->freshReplay();
        $after['violations'] = ['v1'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('medium', $diff['regression_severity_map']['violation_increase']);
        $this->assertSame('medium', $diff['highest_regression_severity']);
    }

    public function test_proof_bundle_drift_without_regressions_is_low_or_medium(): void
    {
        $before = $this->freshReplay();
        $before['proof_bundle_hash'] = 'before_hash';
        $after = $this->freshReplay();
        $after['proof_bundle_hash'] = 'after_hash';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame([], $diff['regressions']);
        $this->assertContains($diff['regression_severity_map']['proof_bundle_hash_drift'], ['low', 'medium']);
        $this->assertContains($diff['highest_regression_severity'], ['low', 'medium']);
    }

    public function test_no_baseline_has_none_severity(): void
    {
        $diff = $this->newDiffService()->diff();

        $this->assertSame('none', $diff['highest_regression_severity']);
        $this->assertSame([], $diff['regression_severity_map']);
    }

    public function test_critical_regression_next_action_blocks_merge(): void
    {
        $before = $this->freshReplay();
        $before['runtime_safety'] = ['runtime_safety_all_false' => true];
        $after = $this->freshReplay();
        $after['runtime_safety'] = ['runtime_safety_all_false' => false];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('block_merge', $diff['next_action']);
        $this->assertStringStartsWith('regressed:runtime_safety_dropped_from_all_false:', $diff['decision_reason']);
    }

    public function test_unchanged_next_action_proceeds(): void
    {
        $replay = $this->freshReplay();

        $diff = $this->newDiffService()->diff($replay, $replay);

        $this->assertSame('unchanged', $diff['status']);
        $this->assertSame('proceed', $diff['next_action']);
        $this->assertSame('unchanged:deterministic_hash_matched', $diff['decision_reason']);
    }

    public function test_pointer_advanced_with_chain_growth_is_classified_as_improvement(): void
    {
        $before = $this->freshReplay();
        $before['current_pointer'] = 'slice_a';
        $before['violations'] = [];
        $after = $this->freshReplay();
        $after['current_pointer'] = 'slice_b';
        $after['violations'] = [];
        $after['replayed_slices'] = array_merge(
            (array) ($after['replayed_slices'] ?? []),
            [['slice_key' => 'extra_grown_slice']],
        );
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));
        $after['cycle_integrity'] = [
            'intentional_reentry_detected' => false,
            'regressions' => [],
            'cycle_ok' => true,
        ];

        $diff = $this->newDiffService()->diff($before, $after);

        $kinds = array_column($diff['improvements'], 'kind');
        $this->assertContains('pointer_advanced_with_chain_growth', $kinds);
        $this->assertSame([], $diff['regressions']);
    }

    // ── mismatch classification ──

    public function test_mismatch_classification_has_required_keys(): void
    {
        $diff = $this->newDiffService()->diff($this->freshReplay(), $this->freshReplay());
        $this->assertArrayHasKey('mismatch_classification', $diff);
        $mc = $diff['mismatch_classification'];
        $this->assertArrayHasKey('mismatches', $mc);
        $this->assertArrayHasKey('summary', $mc);
        $this->assertArrayHasKey('deterministic', $mc);
    }

    public function test_identical_replays_produce_no_mismatches(): void
    {
        $replay = $this->freshReplay();
        $replay['stage_hashes'] = ['context' => 'h1', 'task' => 'h2', 'execution' => 'h3', 'proof' => 'h4', 'learning' => 'h5'];
        $diff = $this->newDiffService()->diff($replay, $replay);
        $this->assertSame([], $diff['mismatch_classification']['mismatches']);
    }

    public function test_context_stage_mismatch_is_data_drift(): void
    {
        $before = $this->freshReplay();
        $before['stage_hashes'] = ['context' => 'ctx_a', 'task' => 't', 'execution' => 'e', 'proof' => 'p', 'learning' => 'l'];
        $after = $this->freshReplay();
        $after['stage_hashes'] = ['context' => 'ctx_b', 'task' => 't', 'execution' => 'e', 'proof' => 'p', 'learning' => 'l'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);
        $mismatches = $diff['mismatch_classification']['mismatches'];
        $this->assertCount(1, $mismatches);
        $this->assertSame('data_drift', $mismatches[0]['kind']);
        $this->assertSame('context', $mismatches[0]['affected_stage']);
        $this->assertArrayHasKey('repair_hint', $mismatches[0]);
    }

    public function test_task_and_execution_mismatches_are_code_drift(): void
    {
        $before = $this->freshReplay();
        $before['stage_hashes'] = ['context' => 'c', 'task' => 't_a', 'execution' => 'e_a', 'proof' => 'p', 'learning' => 'l'];
        $after = $this->freshReplay();
        $after['stage_hashes'] = ['context' => 'c', 'task' => 't_b', 'execution' => 'e_b', 'proof' => 'p', 'learning' => 'l'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);
        $mismatches = $diff['mismatch_classification']['mismatches'];
        foreach ($mismatches as $m) {
            $this->assertSame('code_drift', $m['kind']);
        }
    }

    public function test_proof_mismatch_is_evidence_drift(): void
    {
        $before = $this->freshReplay();
        $before['stage_hashes'] = ['context' => 'c', 'task' => 't', 'execution' => 'e', 'proof' => 'p_a', 'learning' => 'l'];
        $after = $this->freshReplay();
        $after['stage_hashes'] = ['context' => 'c', 'task' => 't', 'execution' => 'e', 'proof' => 'p_b', 'learning' => 'l'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);
        $mismatches = $diff['mismatch_classification']['mismatches'];
        $this->assertCount(1, $mismatches);
        $this->assertSame('evidence_drift', $mismatches[0]['kind']);
    }

    public function test_learning_mismatch_is_nondeterministic_output(): void
    {
        $before = $this->freshReplay();
        $before['stage_hashes'] = ['context' => 'c', 'task' => 't', 'execution' => 'e', 'proof' => 'p', 'learning' => 'l_a'];
        $after = $this->freshReplay();
        $after['stage_hashes'] = ['context' => 'c', 'task' => 't', 'execution' => 'e', 'proof' => 'p', 'learning' => 'l_b'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);
        $mismatches = $diff['mismatch_classification']['mismatches'];
        $this->assertCount(1, $mismatches);
        $this->assertSame('nondeterministic_output', $mismatches[0]['kind']);
    }

    public function test_mismatch_includes_expected_and_actual_hash(): void
    {
        $before = $this->freshReplay();
        $before['stage_hashes'] = ['context' => 'ctx_a', 'task' => 't', 'execution' => 'e', 'proof' => 'p', 'learning' => 'l'];
        $after = $this->freshReplay();
        $after['stage_hashes'] = ['context' => 'ctx_b', 'task' => 't', 'execution' => 'e', 'proof' => 'p', 'learning' => 'l'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);
        $m = $diff['mismatch_classification']['mismatches'][0];
        $this->assertSame('ctx_a', $m['expected_hash']);
        $this->assertSame('ctx_b', $m['actual_hash']);
    }

    public function test_null_replays_produce_empty_mismatches(): void
    {
        $diff = $this->newDiffService()->diff();
        $this->assertSame([], $diff['mismatch_classification']['mismatches']);
    }

    public function test_mismatch_summary_counts_by_kind(): void
    {
        $before = $this->freshReplay();
        $before['stage_hashes'] = ['context' => 'c_a', 'task' => 't_a', 'execution' => 'e', 'proof' => 'p_a', 'learning' => 'l_a'];
        $after = $this->freshReplay();
        $after['stage_hashes'] = ['context' => 'c_b', 'task' => 't_b', 'execution' => 'e', 'proof' => 'p_b', 'learning' => 'l_b'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);
        $summary = $diff['mismatch_classification']['summary'];
        $this->assertSame(1, $summary['data_drift']);
        $this->assertSame(1, $summary['code_drift']);
        $this->assertSame(1, $summary['evidence_drift']);
        $this->assertSame(1, $summary['nondeterministic_output']);
    }
}
