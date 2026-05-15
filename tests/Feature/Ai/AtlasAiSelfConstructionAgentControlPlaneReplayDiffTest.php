<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneReplayDiffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_no_baseline_status_no_baseline(): void
    {
        $diff = $this->newDiffService()->diff();
        $this->assertSame('no_baseline', $diff['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $diff['diff_hash']);
        $this->assertTrue((bool) $diff['read_only']);
        $this->assertSame('no_baseline', $diff['before_source']);
        $this->assertSame(0, $diff['regression_count']);
        $this->assertSame(0, $diff['improvement_count']);
    }

    public function test_identical_replay_status_unchanged(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $diff = $this->newDiffService()->diff(null, $replay);

        $this->assertSame('unchanged', $diff['status']);
        $this->assertFalse((bool) $diff['changed']);
        $this->assertSame(0, $diff['regression_count']);
        $this->assertSame(0, $diff['improvement_count']);
        $this->assertSame(0, (int) data_get($diff, 'slice_count_change.delta'));
        $this->assertSame(0, (int) data_get($diff, 'violation_count_change.delta'));
        $this->assertSame(0, (int) data_get($diff, 'warning_count_change.delta'));
        $this->assertSame('latest_snapshot', $diff['before_source']);
        $this->assertEmpty($diff['regressions']);
        $this->assertSame(
            (string) data_get($diff, 'before_deterministic_replay_hash'),
            (string) data_get($diff, 'after_deterministic_replay_hash'),
        );
    }

    public function test_added_slice_status_improved(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['replayed_slices'][] = ['slice_key' => 'synthetic_new_slice'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('synthetic_new_slice', $diff['added_slices']);
        $this->assertSame('improved', $diff['status']);
        $this->assertTrue((bool) $diff['changed']);
        $this->assertSame(1, (int) data_get($diff, 'slice_count_change.delta'));
        $this->assertGreaterThanOrEqual(1, $diff['improvement_count']);
        $this->assertSame(0, $diff['regression_count']);
    }

    public function test_removed_slice_status_regressed(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        array_pop($after['replayed_slices']);
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertNotEmpty($diff['removed_slices']);
        $this->assertSame('regressed', $diff['status']);
    }

    public function test_violation_increase_status_regressed(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['violations'][] = ['code' => 'synthetic_violation'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('regressed', $diff['status']);
        $this->assertGreaterThanOrEqual(1, $diff['regression_count']);
        $this->assertSame(1, (int) data_get($diff, 'violation_count_change.delta'));
        $kinds = array_column($diff['regressions'], 'kind');
        $this->assertContains('violation_increase', $kinds);
    }

    public function test_warning_increase_status_changed_with_warnings(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['warnings'][] = 'synthetic_warning';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('changed_with_warnings', $diff['status']);
        $this->assertSame(1, (int) data_get($diff, 'warning_count_change.delta'));
        $this->assertTrue((bool) $diff['changed']);
    }

    public function test_runtime_safety_false_to_true_status_regressed(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['runtime_safety']['runtime_safety_all_false'] = false;
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('regressed', $diff['status']);
        $this->assertTrue((bool) data_get($diff, 'runtime_safety_change.changed'));
        $this->assertFalse((bool) data_get($diff, 'runtime_safety_change.after'));
        $kinds = array_column($diff['regressions'], 'kind');
        $this->assertContains('runtime_safety_dropped_from_all_false', $kinds);
    }

    public function test_pointer_forward_status_improved_when_chain_grows(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['current_pointer'] = 'synthetic_forward_pointer';
        $after['replayed_slices'][] = ['slice_key' => 'synthetic_new'];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertTrue($diff['pointer_change']['changed']);
        $this->assertSame('improved', $diff['status']);
    }

    public function test_pointer_regression_status_regressed(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['current_pointer'] = 'previously_certified_pointer';
        $after['cycle_integrity']['regressions'] = [['code' => 'pointer_regressed', 'detail' => 'test']];
        $after['cycle_integrity']['cycle_ok'] = false;
        $after['cycle_integrity']['intentional_reentry_detected'] = false;
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('regressed', $diff['status']);
        $kinds = array_column($diff['regressions'], 'kind');
        $this->assertContains('pointer_regression', $kinds);
    }

    public function test_intentional_reentry_not_treated_as_regression(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['current_pointer'] = 'reentered_pointer';
        $after['cycle_integrity']['intentional_reentry_detected'] = true;
        $after['cycle_integrity']['cycle_ok'] = true;
        $after['cycle_integrity']['regressions'] = [];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertNotSame('regressed', $diff['status']);
        $this->assertTrue($diff['pointer_change']['intentional_reentry']);
    }

    public function test_added_edges_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['replayed_edges'][] = [
            'from' => 'synthetic_from',
            'to' => 'synthetic_to',
            'declared_next' => 'synthetic_to',
            'expected_next' => 'synthetic_to',
            'edge_ok' => true,
            'edge_type' => 'linear',
        ];
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('synthetic_from', $diff['added_edges']);
        $this->assertSame(1, (int) data_get($diff, 'edge_count_change.delta'));
        $this->assertEmpty($diff['removed_edges']);
    }

    public function test_removed_edges_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $removed = array_pop($after['replayed_edges']);
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains((string) $removed['from'], $diff['removed_edges']);
    }

    public function test_changed_edges_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['replayed_edges'][0]['edge_ok'] = ! (bool) ($after['replayed_edges'][0]['edge_ok'] ?? true);
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertNotEmpty($diff['changed_edges']);
        $first = (array) data_get($diff, 'changed_edges.0');
        $this->assertArrayHasKey('from', $first);
        $this->assertArrayHasKey('before', $first);
        $this->assertArrayHasKey('after', $first);
    }

    public function test_capability_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['proof_bundle']['capability_summary']['new_capability_key'] = 'value';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('new_capability_key', $diff['capability_changes']['added_keys']);
        $this->assertTrue((bool) $diff['capability_changes']['changed']);
        $this->assertArrayHasKey('before', $diff['capability_changes']);
        $this->assertArrayHasKey('after', $diff['capability_changes']);
        $this->assertArrayHasKey('removed_keys', $diff['capability_changes']);
        $this->assertArrayHasKey('changed_keys', $diff['capability_changes']);
    }

    public function test_cli_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['proof_bundle']['cli_summary']['present_options'][] = 'agent-control-plane-synthetic-option';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('present_options', $diff['cli_changes']['changed_keys']);
    }

    public function test_readiness_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['proof_bundle']['readiness_summary']['extra_field'] = 1;
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('extra_field', $diff['readiness_changes']['added_keys']);
    }

    public function test_invoker_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['proof_bundle']['invoker_summary']['extra_invoker'] = 'synthetic';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('extra_invoker', $diff['invoker_changes']['added_keys']);
    }

    public function test_doc_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['proof_bundle']['docs_summary']['contract_doc_byte_size'] = (int) ($after['proof_bundle']['docs_summary']['contract_doc_byte_size'] ?? 0) + 100;
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('contract_doc_byte_size', $diff['doc_changes']['changed_keys']);
    }

    public function test_matrix_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['proof_bundle']['regression_matrix_summary']['broken_edge_count'] = (int) ($after['proof_bundle']['regression_matrix_summary']['broken_edge_count'] ?? 0) + 1;
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('broken_edge_count', $diff['matrix_changes']['changed_keys']);
    }

    public function test_cycle_integrity_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['cycle_integrity']['repeated_slice_count'] = 99;
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('repeated_slice_count', $diff['cycle_integrity_changes']['changed_keys']);
    }

    public function test_terminal_horizon_changes_detected(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $after['terminal_horizon_analysis']['horizon_type'] = 'synthetic_terminal_horizon';
        $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertContains('horizon_type', $diff['terminal_horizon_changes']['changed_keys']);
    }

    public function test_diff_hash_stable_for_identical_inputs(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();
        $service = $this->newDiffService();
        $a = $service->diff($before, $after);
        $b = $service->diff($before, $after);

        $this->assertSame($a['diff_hash'], $b['diff_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['diff_hash']);
    }

    public function test_diff_accepts_snapshot_ids(): void
    {
        $replay = $this->freshReplay();
        $store = $this->newStore();
        $put = $store->put($replay);

        $diff = $this->newDiffService()->diff((string) $put['snapshot_id'], $replay);

        $this->assertContains($diff['status'], ['unchanged', 'changed', 'improved', 'regressed', 'changed_with_warnings']);
        $this->assertSame($put['snapshot_id'], $diff['before_snapshot_id']);
        $this->assertSame('snapshot', $diff['before_source']);
        $this->assertSame('array', $diff['after_source']);
    }

    public function test_diff_handles_missing_snapshot_id(): void
    {
        $replay = $this->freshReplay();
        $diff = $this->newDiffService()->diff('snap_does_not_exist', $replay);

        $this->assertSame('no_baseline', $diff['status']);
        $this->assertSame('snapshot_missing', $diff['before_source']);
    }

    public function test_diff_accepts_replay_arrays(): void
    {
        $before = $this->freshReplay();
        $after = $this->freshReplay();

        $diff = $this->newDiffService()->diff($before, $after);

        $this->assertSame('array', $diff['before_source']);
        $this->assertSame('array', $diff['after_source']);
    }

    public function test_diff_does_not_write_ledger_or_mutate_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newDiffService()->diff($this->freshReplay(), $this->freshReplay());
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_diff_is_read_only_flags_false(): void
    {
        $diff = $this->newDiffService()->diff($this->freshReplay(), $this->freshReplay());

        $this->assertTrue((bool) $diff['read_only']);
        $this->assertFalse((bool) $diff['execution_allowed']);
        $this->assertFalse((bool) $diff['dispatch_allowed']);
        $this->assertFalse((bool) $diff['ledger_write_allowed']);
        $this->assertFalse((bool) $diff['runtime_write_allowed']);
        $this->assertFalse((bool) $diff['external_provider_call']);
        $this->assertFalse((bool) $diff['token_spend']);
        $this->assertFalse((bool) $diff['process_started']);
        $this->assertFalse((bool) $diff['self_programming_allowed']);
    }

    public function test_diff_payload_shape(): void
    {
        $diff = $this->newDiffService()->diff($this->freshReplay(), $this->freshReplay());

        $this->assertArrayHasKey('pointer_change', $diff);
        $this->assertArrayHasKey('slice_count_change', $diff);
        $this->assertArrayHasKey('edge_count_change', $diff);
        $this->assertArrayHasKey('violation_count_change', $diff);
        $this->assertArrayHasKey('warning_count_change', $diff);
        $this->assertArrayHasKey('runtime_safety_change', $diff);
        $this->assertArrayHasKey('proof_bundle_hash_change', $diff);
        $this->assertArrayHasKey('added_slices', $diff);
        $this->assertArrayHasKey('removed_slices', $diff);
        $this->assertArrayHasKey('added_edges', $diff);
        $this->assertArrayHasKey('removed_edges', $diff);
        $this->assertArrayHasKey('changed_edges', $diff);
        $this->assertArrayHasKey('capability_changes', $diff);
        $this->assertArrayHasKey('cli_changes', $diff);
        $this->assertArrayHasKey('readiness_changes', $diff);
        $this->assertArrayHasKey('invoker_changes', $diff);
        $this->assertArrayHasKey('doc_changes', $diff);
        $this->assertArrayHasKey('matrix_changes', $diff);
        $this->assertArrayHasKey('cycle_integrity_changes', $diff);
        $this->assertArrayHasKey('terminal_horizon_changes', $diff);
        $this->assertArrayHasKey('regressions', $diff);
        $this->assertArrayHasKey('regression_count', $diff);
        $this->assertArrayHasKey('improvements', $diff);
        $this->assertArrayHasKey('improvement_count', $diff);
        $this->assertArrayHasKey('diff_hash', $diff);
    }

    public function test_diff_schema_v1(): void
    {
        $diff = $this->newDiffService()->diff();
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_replay_diff.v1',
            $diff['schema_version'],
        );
        $this->assertSame('read_only_agent_control_plane_replay_diff', $diff['mode']);
        $this->assertArrayHasKey('non_execution_guarantees', $diff);
        $this->assertContains('diff_does_not_mutate_pointer', $diff['non_execution_guarantees']);
        $this->assertContains('diff_does_not_dispatch_work', $diff['non_execution_guarantees']);
        $this->assertContains('diff_does_not_spend_tokens', $diff['non_execution_guarantees']);
        $this->assertContains('diff_does_not_enable_self_programming', $diff['non_execution_guarantees']);
    }

    public function test_diff_status_projection_cli_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-diff-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_diff_status.v1', $payload['schema_version']);
        $this->assertSame('read_only_agent_control_plane_replay_diff_status', $payload['mode']);
        $this->assertContains(data_get($payload, 'agent_control_plane_replay_diff_status.status'), ['no_baseline', 'no_target', 'unchanged', 'improved', 'regressed', 'changed_with_warnings', 'changed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_replay_diff_status.diff_hash'));
    }

    public function test_diff_contract_cli_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-diff-contract' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_diff_contract.v1', $payload['schema_version']);
        $this->assertSame('agent_control_plane_replay_diff_contract_ready', $payload['status']);
    }

    public function test_diff_preflight_cli_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-diff-preflight' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_diff_preflight.v1', $payload['schema_version']);
        $this->assertSame('agent_control_plane_replay_diff_preflight_ready', $payload['status']);
    }

    public function test_diff_implementation_packet_cli_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-diff-implementation-packet' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_diff_implementation_packet.v1', $payload['schema_version']);
        $this->assertSame('ready_for_scoped_agent_control_plane_replay_diff_implementation', $payload['status']);
    }

    public function test_diff_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_replay_diff.v1', AgentControlPlaneReplayDiffService::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_replay_diff', AgentControlPlaneReplayDiffService::MODE);
    }

    public function test_diff_status_when_after_resolves_to_fresh_replay(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);

        $diff = $this->newDiffService()->diff();

        $this->assertContains($diff['status'], ['unchanged', 'changed', 'improved', 'regressed', 'changed_with_warnings']);
        $this->assertSame('latest_snapshot', $diff['before_source']);
        $this->assertSame('fresh_replay', $diff['after_source']);
    }

    public function test_diff_pointer_change_block_shape(): void
    {
        $diff = $this->newDiffService()->diff($this->freshReplay(), $this->freshReplay());

        $this->assertArrayHasKey('before', $diff['pointer_change']);
        $this->assertArrayHasKey('after', $diff['pointer_change']);
        $this->assertArrayHasKey('changed', $diff['pointer_change']);
        $this->assertArrayHasKey('intentional_reentry', $diff['pointer_change']);
    }

    public function test_diff_runtime_safety_change_block_shape(): void
    {
        $diff = $this->newDiffService()->diff($this->freshReplay(), $this->freshReplay());

        $this->assertArrayHasKey('before', $diff['runtime_safety_change']);
        $this->assertArrayHasKey('after', $diff['runtime_safety_change']);
        $this->assertArrayHasKey('changed', $diff['runtime_safety_change']);
    }

    public function test_diff_summary_blocks_have_canonical_keys(): void
    {
        $diff = $this->newDiffService()->diff($this->freshReplay(), $this->freshReplay());

        foreach (['capability_changes', 'cli_changes', 'readiness_changes', 'invoker_changes', 'doc_changes', 'matrix_changes', 'cycle_integrity_changes', 'terminal_horizon_changes'] as $key) {
            $this->assertArrayHasKey('before', $diff[$key]);
            $this->assertArrayHasKey('after', $diff[$key]);
            $this->assertArrayHasKey('changed_keys', $diff[$key]);
            $this->assertArrayHasKey('added_keys', $diff[$key]);
            $this->assertArrayHasKey('removed_keys', $diff[$key]);
            $this->assertArrayHasKey('changed', $diff[$key]);
        }
    }

    private function newDiffService(): AgentControlPlaneReplayDiffService
    {
        $store = $this->newStore();
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return new AgentControlPlaneReplayDiffService($store, $replay);
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
}
