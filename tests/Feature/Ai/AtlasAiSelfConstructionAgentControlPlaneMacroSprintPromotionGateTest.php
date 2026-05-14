<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneMacroSprintPromotionGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_no_baseline_status_no_baseline(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertSame('no_baseline', $gate['status']);
        $this->assertFalse((bool) $gate['promotion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['runtime_execution_allowed']);
        $this->assertSame(AgentControlPlaneMacroSprintPromotionGate::SCHEMA_VERSION, $gate['schema_version']);
    }

    public function test_gate_returns_v1_schema(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertSame('atlas.self_construction.agent_control_plane_macro_sprint_promotion_gate.v1', $gate['schema_version']);
        $this->assertSame('read_only_agent_control_plane_macro_sprint_promotion_gate', $gate['mode']);
        $this->assertTrue((bool) $gate['read_only']);
    }

    public function test_gate_non_execution_guarantees_complete(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertContains('promotion_gate_does_not_promote_completion_claim', $gate['non_execution_guarantees']);
        $this->assertContains('promotion_gate_does_not_dispatch_work', $gate['non_execution_guarantees']);
        $this->assertContains('promotion_gate_does_not_spend_tokens', $gate['non_execution_guarantees']);
        $this->assertContains('promotion_gate_does_not_enable_self_programming', $gate['non_execution_guarantees']);
        $this->assertContains('promotion_gate_does_not_mutate_pointer', $gate['non_execution_guarantees']);
        $this->assertContains('promotion_gate_does_not_declare_atlas_self_construction_os_complete', $gate['non_execution_guarantees']);
    }

    public function test_unchanged_clean_diff_passes(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $gate = $this->evaluateWithFakeDiff(['status' => 'unchanged', 'regressions' => [], 'changed' => false]);
        $this->assertSame('passed', $gate['status']);
        $this->assertTrue((bool) $gate['promotion_allowed']);
        $this->assertSame(0, $gate['blocker_count']);
        $this->assertSame(0, $gate['warning_count']);
        $this->assertNotEmpty($gate['gate_hash']);
        $this->assertSame('promotion_gate_is_clean_macro_sprint_may_be_recorded_as_certified', $gate['next_action']);
    }

    public function test_improved_clean_diff_passes(): void
    {
        $gate = $this->evaluateWithFakeDiff([
            'status' => 'improved',
            'regressions' => [],
            'changed' => true,
            'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
        ]);
        $this->assertSame('passed', $gate['status']);
        $this->assertTrue((bool) $gate['promotion_allowed']);
        $this->assertSame(0, $gate['blocker_count']);
        $this->assertEmpty($gate['blockers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $gate['gate_hash']);
    }

    public function test_regression_blocks(): void
    {
        $gate = $this->evaluateWithFakeDiff([
            'status' => 'regressed',
            'regressions' => [['kind' => 'violation_increase']],
            'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
        ]);
        $this->assertSame('blocked', $gate['status']);
        $this->assertFalse((bool) $gate['promotion_allowed']);
        $this->assertNotEmpty($gate['blockers']);
        $this->assertContains('regression_detected:violation_increase', $gate['blockers']);
        $this->assertGreaterThan(0, $gate['blocker_count']);
    }

    public function test_runtime_safety_regression_blocks(): void
    {
        $gate = $this->evaluateWithFakeDiff([
            'status' => 'regressed',
            'regressions' => [['kind' => 'runtime_safety_dropped_from_all_false']],
            'runtime_safety_change' => ['before' => true, 'after' => false, 'changed' => true],
            'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
        ]);
        $this->assertSame('blocked', $gate['status']);
        $this->assertContains('after_runtime_safety_is_not_all_false', $gate['blockers']);
        $this->assertFalse((bool) $gate['promotion_allowed']);
        $this->assertContains('regression_detected:runtime_safety_dropped_from_all_false', $gate['blockers']);
    }

    public function test_violation_blocks(): void
    {
        $gate = $this->evaluateWithFakeDiff([
            'status' => 'regressed',
            'regressions' => [],
            'violation_count_change' => ['before' => 0, 'after' => 3, 'delta' => 3],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
        ]);
        $this->assertSame('blocked', $gate['status']);
        $this->assertContains('after_replay_has_3_violations', $gate['blockers']);
        $this->assertFalse((bool) $gate['promotion_allowed']);
        $this->assertGreaterThan(0, $gate['blocker_count']);
    }

    public function test_warning_increase_produces_warning_status(): void
    {
        $gate = $this->evaluateWithFakeDiff([
            'status' => 'changed_with_warnings',
            'regressions' => [],
            'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
            'warning_count_change' => ['before' => 0, 'after' => 2, 'delta' => 2],
        ]);
        $this->assertSame('warning', $gate['status']);
        $this->assertContains('warning_count_increased_by_2', $gate['warnings']);
        $this->assertTrue((bool) $gate['promotion_allowed']);
        $this->assertSame('review_warnings_before_recording_macro_sprint_as_certified', $gate['next_action']);
        $this->assertGreaterThan(0, $gate['warning_count']);
    }

    public function test_docs_health_required_missing_emits_command_required(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $gate = $this->newGate()->evaluate([
            'require_docs_health_status' => 'ok',
        ]);

        $this->assertContains('php artisan atlas:engineering:knowledge docs-health --json', $gate['command_required']);
    }

    public function test_architecture_validate_required_missing_emits_command_required(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $gate = $this->newGate()->evaluate([
            'require_architecture_validate_status' => 'ok',
        ]);

        $this->assertContains('php artisan atlas:ai:architecture-validate --json', $gate['command_required']);
    }

    public function test_docs_health_mismatch_blocks(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $gate = $this->newGate()->evaluate([
            'require_docs_health_status' => 'ok',
            'docs_health_status' => 'failing',
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertContains('docs_health_status_is_failing_expected_ok', $gate['blockers']);
    }

    public function test_architecture_validate_mismatch_blocks(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $gate = $this->newGate()->evaluate([
            'require_architecture_validate_status' => 'ok',
            'architecture_validate_status' => 'failing',
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertContains('architecture_validate_status_is_failing_expected_ok', $gate['blockers']);
    }

    public function test_completion_claim_allowed_false(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
    }

    public function test_runtime_execution_allowed_false(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertFalse((bool) $gate['runtime_execution_allowed']);
    }

    public function test_provider_call_allowed_false(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertFalse((bool) $gate['provider_call_allowed']);
    }

    public function test_dispatch_allowed_false(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertFalse((bool) $gate['dispatch_allowed']);
    }

    public function test_self_programming_allowed_false(): void
    {
        $gate = $this->newGate()->evaluate();
        $this->assertFalse((bool) $gate['self_programming_allowed']);
    }

    public function test_promotion_allowed_only_when_clean(): void
    {
        $blocked = $this->evaluateWithFakeDiff([
            'status' => 'regressed',
            'regressions' => [['kind' => 'violation_increase']],
            'violation_count_change' => ['before' => 0, 'after' => 1, 'delta' => 1],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
        ]);
        $passed = $this->evaluateWithFakeDiff([
            'status' => 'unchanged',
            'regressions' => [],
            'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
        ]);

        $this->assertFalse((bool) $blocked['promotion_allowed']);
        $this->assertTrue((bool) $passed['promotion_allowed']);
    }

    public function test_next_action_is_honest(): void
    {
        $passed = $this->evaluateWithFakeDiff([
            'status' => 'unchanged',
            'regressions' => [],
            'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
        ]);
        $blocked = $this->evaluateWithFakeDiff([
            'status' => 'regressed',
            'regressions' => [['kind' => 'violation_increase']],
            'violation_count_change' => ['before' => 0, 'after' => 1, 'delta' => 1],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
        ]);

        $this->assertSame('promotion_gate_is_clean_macro_sprint_may_be_recorded_as_certified', $passed['next_action']);
        $this->assertSame('resolve_blockers_before_recording_macro_sprint_as_certified', $blocked['next_action']);
    }

    public function test_status_projection_returns_v1_schema(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-macro-sprint-promotion-gate-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame(
            'read_only_agent_control_plane_macro_sprint_promotion_gate_status',
            $payload['mode'],
        );
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertContains(data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_status.status'), ['no_baseline', 'passed', 'warning', 'blocked']);
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_status.completion_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_status.runtime_execution_allowed'));
    }

    public function test_cli_contract_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-macro-sprint-promotion-gate-contract' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_contract.v1', $payload['schema_version']);
        $this->assertSame('agent_control_plane_macro_sprint_promotion_gate_contract_ready', $payload['status']);
        $this->assertSame(AgentControlPlaneMacroSprintPromotionGate::class, data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_contract.gate_service_class'));
    }

    public function test_cli_preflight_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-macro-sprint-promotion-gate-preflight' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_preflight.v1', $payload['schema_version']);
        $this->assertSame('agent_control_plane_macro_sprint_promotion_gate_preflight_ready', $payload['status']);
        $this->assertSame(0, (int) data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_preflight.blocking_count'));
    }

    public function test_cli_implementation_packet_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-macro-sprint-promotion-gate-implementation-packet' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_implementation_packet.v1', $payload['schema_version']);
        $this->assertSame('ready_for_scoped_agent_control_plane_macro_sprint_promotion_gate_implementation', $payload['status']);
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_implementation_packet.allowed_files'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_implementation_packet.acceptance_criteria'));
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_implementation_packet.implementation_policy.completion_claim_allowed_by_packet'));
    }

    public function test_gate_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_macro_sprint_promotion_gate.v1', AgentControlPlaneMacroSprintPromotionGate::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_macro_sprint_promotion_gate', AgentControlPlaneMacroSprintPromotionGate::MODE);
    }

    public function test_gate_evaluates_with_snapshot_ids(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);

        $gate = $this->newGate()->evaluate([
            'before_snapshot_id' => (string) $put['snapshot_id'],
        ]);

        $this->assertContains($gate['status'], ['no_baseline', 'passed', 'warning', 'blocked']);
        $this->assertSame((string) $put['snapshot_id'], data_get($gate, 'diff_summary.before_snapshot_id'));
    }

    public function test_gate_diff_summary_present(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $gate = $this->newGate()->evaluate();
        $this->assertArrayHasKey('status', $gate['diff_summary']);
        $this->assertArrayHasKey('diff_hash', $gate['diff_summary']);
        $this->assertArrayHasKey('changed', $gate['diff_summary']);
        $this->assertArrayHasKey('regression_count', $gate['diff_summary']);
    }

    public function test_cli_status_json_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-macro-sprint-promotion-gate-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains(data_get($payload, 'agent_control_plane_macro_sprint_promotion_gate_status.status'), ['no_baseline', 'passed', 'warning', 'blocked']);
    }

    public function test_gate_does_not_mutate_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newGate()->evaluate();
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_gate_emits_hash(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);
        $gate = $this->newGate()->evaluate();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $gate['gate_hash']);
    }

    public function test_gate_includes_chain_integrity_summary(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);
        $gate = $this->newGate()->evaluate();

        $this->assertArrayHasKey('chain_integrity_summary', $gate);
        $this->assertNotEmpty($gate['chain_integrity_summary']);
    }

    public function test_gate_includes_replay_summary(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);
        $gate = $this->newGate()->evaluate();

        $this->assertArrayHasKey('replay_summary', $gate);
        $this->assertSame((string) data_get($replay, 'deterministic_replay_hash'), $gate['replay_summary']['deterministic_replay_hash']);
    }

    public function test_gate_does_not_start_process_or_provider_or_token(): void
    {
        $gate = $this->newGate()->evaluate();

        $this->assertFalse((bool) $gate['runtime_execution_allowed']);
        $this->assertFalse((bool) $gate['provider_call_allowed']);
        $this->assertFalse((bool) $gate['dispatch_allowed']);
        $this->assertFalse((bool) $gate['self_programming_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['execution_allowed']);
        $this->assertFalse((bool) $gate['ledger_write_allowed']);
        $this->assertFalse((bool) $gate['runtime_write_allowed']);
    }

    public function test_gate_payload_shape(): void
    {
        $gate = $this->newGate()->evaluate();

        $this->assertArrayHasKey('schema_version', $gate);
        $this->assertArrayHasKey('status', $gate);
        $this->assertArrayHasKey('mode', $gate);
        $this->assertArrayHasKey('gate_id', $gate);
        $this->assertArrayHasKey('generated_at', $gate);
        $this->assertArrayHasKey('read_only', $gate);
        $this->assertArrayHasKey('promotion_allowed', $gate);
        $this->assertArrayHasKey('completion_claim_allowed', $gate);
        $this->assertArrayHasKey('runtime_execution_allowed', $gate);
        $this->assertArrayHasKey('provider_call_allowed', $gate);
        $this->assertArrayHasKey('dispatch_allowed', $gate);
        $this->assertArrayHasKey('self_programming_allowed', $gate);
        $this->assertArrayHasKey('blockers', $gate);
        $this->assertArrayHasKey('blocker_count', $gate);
        $this->assertArrayHasKey('warnings', $gate);
        $this->assertArrayHasKey('warning_count', $gate);
        $this->assertArrayHasKey('command_required', $gate);
        $this->assertArrayHasKey('diff_summary', $gate);
        $this->assertArrayHasKey('replay_summary', $gate);
        $this->assertArrayHasKey('chain_integrity_summary', $gate);
        $this->assertArrayHasKey('next_action', $gate);
        $this->assertArrayHasKey('gate_hash', $gate);
        $this->assertArrayHasKey('non_execution_guarantees', $gate);
        $this->assertArrayHasKey('human_summary', $gate);
    }

    private function newGate(): AgentControlPlaneMacroSprintPromotionGate
    {
        $store = $this->newStore();
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);

        return new AgentControlPlaneMacroSprintPromotionGate($diff, $audit, $replay);
    }

    private function newStore(): AgentControlPlaneReplaySnapshotStore
    {
        return new AgentControlPlaneReplaySnapshotStore('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function freshReplay(): array
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return $replay->replay();
    }

    private function controlPlanePointer(): string
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (string) data_get($payload, 'control_plane.persistent_runtime.next_required_slice');
    }

    /**
     * Evaluate the promotion gate against a synthetic diff payload by replacing
     * the diff service with an anonymous stub. This keeps the test deterministic
     * across the various status buckets.
     *
     * @param  array<string, mixed>  $diffOverride
     * @return array<string, mixed>
     */
    private function evaluateWithFakeDiff(array $diffOverride): array
    {
        $store = $this->newStore();
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        $fakeDiff = new class($store, $replay, $diffOverride) extends AgentControlPlaneReplayDiffService
        {
            /**
             * @param  array<string, mixed>  $override
             */
            public function __construct(
                AgentControlPlaneReplaySnapshotStore $store,
                AgentControlPlaneDeterministicChainReplayService $replay,
                private readonly array $override,
            ) {
                parent::__construct($store, $replay);
            }

            public function diff(array|string|null $before = null, array|string|null $after = null, array $options = []): array
            {
                return array_merge([
                    'schema_version' => AgentControlPlaneReplayDiffService::SCHEMA_VERSION,
                    'status' => 'unchanged',
                    'mode' => AgentControlPlaneReplayDiffService::MODE,
                    'diff_id' => 'fake-diff-id',
                    'generated_at' => '2026-05-14T00:00:00+00:00',
                    'read_only' => true,
                    'execution_allowed' => false,
                    'dispatch_allowed' => false,
                    'ledger_write_allowed' => false,
                    'runtime_write_allowed' => false,
                    'before_snapshot_id' => 'fake-before',
                    'after_snapshot_id' => 'fake-after',
                    'before_source' => 'array',
                    'after_source' => 'array',
                    'before_replay_hash' => '',
                    'after_replay_hash' => '',
                    'before_deterministic_replay_hash' => '',
                    'after_deterministic_replay_hash' => '',
                    'before_proof_bundle_hash' => '',
                    'after_proof_bundle_hash' => '',
                    'changed' => false,
                    'pointer_change' => ['before' => '', 'after' => '', 'changed' => false, 'intentional_reentry' => false],
                    'slice_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'edge_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'warning_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
                    'proof_bundle_hash_change' => ['before' => '', 'after' => '', 'changed' => false],
                    'added_slices' => [],
                    'removed_slices' => [],
                    'added_edges' => [],
                    'removed_edges' => [],
                    'changed_edges' => [],
                    'capability_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'cli_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'readiness_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'invoker_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'doc_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'matrix_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'cycle_integrity_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'terminal_horizon_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'regressions' => [],
                    'regression_count' => 0,
                    'improvements' => [],
                    'improvement_count' => 0,
                    'non_execution_guarantees' => [],
                    'human_summary' => 'fake',
                    'diff_hash' => 'fake-diff-hash',
                ], $this->override);
            }
        };

        $gate = new AgentControlPlaneMacroSprintPromotionGate($fakeDiff, $audit, $replay);

        return $gate->evaluate();
    }
}
