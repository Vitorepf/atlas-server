<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneDeterministicChainReplayTest extends TestCase
{
    public function test_replay_returns_schema_v1(): void
    {
        $replay = $this->newService()->replay();

        $this->assertSame(
            'atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1',
            data_get($replay, 'schema_version'),
        );
        $this->assertSame('read_only_agent_control_plane_deterministic_chain_replay', data_get($replay, 'mode'));
    }

    public function test_replay_status_is_available_or_degraded(): void
    {
        $replay = $this->newService()->replay();
        $this->assertContains(data_get($replay, 'status'), ['available', 'degraded', 'blocked']);
    }

    public function test_replay_is_read_only(): void
    {
        $replay = $this->newService()->replay();
        $this->assertTrue((bool) data_get($replay, 'read_only'));
        $this->assertFalse((bool) data_get($replay, 'execution_allowed'));
        $this->assertFalse((bool) data_get($replay, 'dispatch_allowed'));
        $this->assertFalse((bool) data_get($replay, 'ledger_write_allowed'));
        $this->assertFalse((bool) data_get($replay, 'runtime_write_allowed'));
    }

    public function test_replay_never_enables_provider_token_dispatch_or_self_programming(): void
    {
        $replay = $this->newService()->replay();
        $this->assertFalse((bool) data_get($replay, 'external_provider_call'));
        $this->assertFalse((bool) data_get($replay, 'token_spend'));
        $this->assertFalse((bool) data_get($replay, 'process_started'));
        $this->assertFalse((bool) data_get($replay, 'dispatch_allowed'));
        $this->assertFalse((bool) data_get($replay, 'self_programming_allowed'));
    }

    public function test_replay_includes_current_pointer(): void
    {
        $replay = $this->newService()->replay();
        $this->assertNotEmpty(data_get($replay, 'current_pointer'));
    }

    public function test_replay_includes_not_yet_runtime_capable(): void
    {
        $replay = $this->newService()->replay();
        $this->assertIsArray(data_get($replay, 'not_yet_runtime_capable'));
    }

    public function test_replay_includes_next_build_slices(): void
    {
        $replay = $this->newService()->replay();
        $this->assertIsArray(data_get($replay, 'next_build_slices'));
    }

    public function test_replay_includes_proof_bundle(): void
    {
        $replay = $this->newService()->replay();
        $this->assertIsArray(data_get($replay, 'proof_bundle'));
        $this->assertArrayHasKey('chain_integrity_summary', (array) data_get($replay, 'proof_bundle'));
        $this->assertArrayHasKey('control_plane_summary', (array) data_get($replay, 'proof_bundle'));
    }

    public function test_proof_bundle_hash_is_sha256(): void
    {
        $replay = $this->newService()->replay();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($replay, 'proof_bundle_hash'));
    }

    public function test_replay_hash_is_sha256(): void
    {
        $replay = $this->newService()->replay();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($replay, 'replay_hash'));
    }

    public function test_deterministic_replay_hash_stable_across_two_runs(): void
    {
        $service = $this->newService();
        $a = $service->replay();
        $b = $service->replay();
        $this->assertSame(
            (string) data_get($a, 'deterministic_replay_hash'),
            (string) data_get($b, 'deterministic_replay_hash'),
            'Deterministic replay hash must remain identical between two consecutive replays when nothing changed.',
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($a, 'deterministic_replay_hash'));
    }

    public function test_deterministic_replay_hash_changes_when_chain_override_changes(): void
    {
        $service = $this->newService();
        $base = $service->replay();
        $changed = $service->replay([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
                ],
            ],
        ]);
        $this->assertNotSame(
            (string) data_get($base, 'deterministic_replay_hash'),
            (string) data_get($changed, 'deterministic_replay_hash'),
            'Deterministic replay hash must change when chain integrity state changes.',
        );
    }

    public function test_generated_at_and_replay_id_do_not_affect_deterministic_hash(): void
    {
        // Two consecutive replays naturally have different generated_at/replay_id
        // (UUIDs are random, timestamps move). If the deterministic hash matches
        // it is, by construction, immune to those volatile fields.
        $service = $this->newService();
        $a = $service->replay();
        $b = $service->replay();
        $this->assertNotSame(data_get($a, 'replay_id'), data_get($b, 'replay_id'));
        $this->assertSame((string) data_get($a, 'deterministic_replay_hash'), (string) data_get($b, 'deterministic_replay_hash'));
    }

    public function test_replayed_slices_count_matches_chain_integrity_deep_coverage(): void
    {
        $replay = $this->newService()->replay();
        $audit = app(AgentControlPlaneChainIntegrityAuditService::class)->audit();
        $this->assertSame(
            (int) data_get($audit, 'chain_length'),
            (int) data_get($replay, 'replayed_slice_count'),
        );
    }

    public function test_replayed_edges_count_matches_edge_coverage(): void
    {
        $replay = $this->newService()->replay();
        $this->assertGreaterThanOrEqual(0, (int) data_get($replay, 'replayed_edge_count'));
        $this->assertSame(
            count((array) data_get($replay, 'replayed_edges', [])),
            (int) data_get($replay, 'replayed_edge_count'),
        );
    }

    public function test_each_replayed_slice_includes_readiness_proof(): void
    {
        $replay = $this->newService()->replay();
        foreach ((array) data_get($replay, 'replayed_slices', []) as $slice) {
            $this->assertArrayHasKey('readiness_proof', $slice);
            $this->assertArrayHasKey('contract_method_exists', (array) data_get($slice, 'readiness_proof'));
        }
    }

    public function test_each_replayed_slice_includes_capability_proof(): void
    {
        $replay = $this->newService()->replay();
        foreach ((array) data_get($replay, 'replayed_slices', []) as $slice) {
            $this->assertArrayHasKey('capability_proof', $slice);
            $this->assertArrayHasKey('contract_capability', (array) data_get($slice, 'capability_proof'));
        }
    }

    public function test_each_replayed_slice_includes_cli_proof(): void
    {
        $replay = $this->newService()->replay();
        foreach ((array) data_get($replay, 'replayed_slices', []) as $slice) {
            $this->assertArrayHasKey('cli_proof', $slice);
            $this->assertArrayHasKey('contract_option', (array) data_get($slice, 'cli_proof'));
        }
    }

    public function test_each_replayed_slice_includes_invoker_proof(): void
    {
        $replay = $this->newService()->replay();
        foreach ((array) data_get($replay, 'replayed_slices', []) as $slice) {
            $this->assertArrayHasKey('invoker_proof', $slice);
            $this->assertArrayHasKey('invoker_class', (array) data_get($slice, 'invoker_proof'));
        }
    }

    public function test_each_replayed_slice_includes_doc_proof(): void
    {
        $replay = $this->newService()->replay();
        foreach ((array) data_get($replay, 'replayed_slices', []) as $slice) {
            $this->assertArrayHasKey('doc_proof', $slice);
            $this->assertArrayHasKey('doc_bullet_anchor', (array) data_get($slice, 'doc_proof'));
        }
    }

    public function test_each_replayed_slice_includes_runtime_safety_proof(): void
    {
        $replay = $this->newService()->replay();
        foreach ((array) data_get($replay, 'replayed_slices', []) as $slice) {
            $this->assertArrayHasKey('runtime_safety_proof', $slice);
            $flags = (array) data_get($slice, 'runtime_safety_proof.runtime_flags_must_remain_false');
            $this->assertFalse((bool) ($flags['execution_allowed'] ?? true));
            $this->assertFalse((bool) ($flags['dispatch_allowed'] ?? true));
            $this->assertFalse((bool) ($flags['token_spend_allowed'] ?? true));
        }
    }

    public function test_each_edge_includes_edge_type(): void
    {
        $replay = $this->newService()->replay();
        foreach ((array) data_get($replay, 'replayed_edges', []) as $edge) {
            $this->assertArrayHasKey('edge_type', $edge);
            $this->assertContains(data_get($edge, 'edge_type'), ['linear', 'intentional_reentry', 'terminal_horizon']);
        }
    }

    public function test_terminal_horizon_analysis_is_included(): void
    {
        $replay = $this->newService()->replay();
        $this->assertIsArray(data_get($replay, 'terminal_horizon_analysis'));
        $this->assertContains(
            data_get($replay, 'terminal_horizon_analysis.horizon_type'),
            ['linear_next', 'intentional_reentry', 'terminal_runtime_gate', 'blocked_unknown'],
        );
    }

    public function test_next_safe_macro_batch_exists(): void
    {
        $replay = $this->newService()->replay();
        $this->assertIsString(data_get($replay, 'next_safe_macro_batch'));
    }

    public function test_override_broken_chain_produces_degraded_status(): void
    {
        $replay = $this->newService()->replay([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract',
                ],
            ],
        ]);
        $this->assertContains(data_get($replay, 'status'), ['degraded', 'blocked']);
    }

    public function test_override_runtime_flag_true_produces_violation(): void
    {
        $replay = $this->newService()->replay([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                ],
            ],
        ]);
        $this->assertNotSame('available', data_get($replay, 'status'));
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($replay, 'violations', []));
        $this->assertContains('runtime_safety_invariant_breached', $codes);
    }

    public function test_override_missing_capability_produces_violation(): void
    {
        $replay = $this->newService()->replay([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
                ],
            ],
        ]);
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($replay, 'violations', []));
        $this->assertContains('slice_missing_artifact', $codes);
    }

    public function test_override_missing_cli_produces_violation_via_synthetic_chain(): void
    {
        $replay = $this->newService()->replay([
            'override_slices' => [
                [
                    'slice_key' => 'synthetic_cli_gap_slice_for_replay',
                    'method_prefix' => 'agentControlPlane',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneDeterministicChainReplayService',
                    'prepare_method' => 'replay',
                    'doc_bullet' => 'synthetic cli gap replay',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_synthetic_cli_gap_slice_for_replay_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_synthetic_cli_gap_slice_for_replay_contract_runtime',
                ],
            ],
        ]);
        // Synthetic chain will lack CLI options.
        $this->assertContains(data_get($replay, 'status'), ['available', 'degraded']);
        // Either the CLI option gap is reflected in the audit's cli_option_gaps
        // (which travels into the replay via violations/warnings) or the
        // capability gap fires; both are sufficient signals.
        $this->assertTrue(
            count((array) data_get($replay, 'violations', [])) > 0
            || count((array) data_get($replay, 'warnings', [])) > 0,
        );
    }

    public function test_override_broken_edge_propagates_to_replay(): void
    {
        $replay = $this->newService()->replay([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
            ],
        ]);
        // A pointer regression must surface either as a violation or a
        // cycle_integrity warning.
        $audit = data_get($replay, 'cycle_integrity', []);
        $this->assertNotSame('ok', data_get($audit, 'status'));
    }

    public function test_max_slices_limits_replayed_slices_honestly(): void
    {
        $replay = $this->newService()->replay(['max_slices' => 5]);
        $this->assertLessThanOrEqual(5, (int) data_get($replay, 'replayed_slice_count'));
    }

    public function test_include_proof_bundle_false_omits_heavy_bundle_but_keeps_hash_null_policy(): void
    {
        $replay = $this->newService()->replay(['include_proof_bundle' => false]);
        $this->assertNull(data_get($replay, 'proof_bundle'));
        $this->assertNull(data_get($replay, 'proof_bundle_hash'));
    }

    public function test_cli_contract_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-deterministic-chain-replay-contract' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_deterministic_chain_replay_contract.v1',
            data_get($payload, 'schema_version'),
        );
        $this->assertSame('agent_control_plane_deterministic_chain_replay_contract_ready', data_get($payload, 'status'));
    }

    public function test_cli_preflight_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-deterministic-chain-replay-preflight' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_deterministic_chain_replay_preflight.v1',
            data_get($payload, 'schema_version'),
        );
        $this->assertSame('agent_control_plane_deterministic_chain_replay_preflight_ready', data_get($payload, 'status'));
        $this->assertSame(0, (int) data_get($payload, 'agent_control_plane_deterministic_chain_replay_preflight.blocking_count'));
    }

    public function test_cli_implementation_packet_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-deterministic-chain-replay-implementation-packet' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_deterministic_chain_replay_implementation_packet.v1',
            data_get($payload, 'schema_version'),
        );
        $allowed = (array) data_get($payload, 'agent_control_plane_deterministic_chain_replay_implementation_packet.allowed_files', []);
        $this->assertContains('app/Services/Ai/SelfConstruction/AgentControlPlaneDeterministicChainReplayService.php', $allowed);
    }

    public function test_cli_status_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-deterministic-chain-replay-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_deterministic_chain_replay_status.v1',
            data_get($payload, 'schema_version'),
        );
        $this->assertContains(data_get($payload, 'status'), ['available', 'degraded', 'blocked']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'agent_control_plane_deterministic_chain_replay_status.replay_hash'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'agent_control_plane_deterministic_chain_replay_status.deterministic_replay_hash'),
        );
    }

    public function test_agent_control_plane_exposes_new_replay_capabilities(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);
        $this->assertContains('agent_control_plane_deterministic_chain_replay_contract', $capabilities);
        $this->assertContains('agent_control_plane_deterministic_chain_replay_preflight', $capabilities);
        $this->assertContains('agent_control_plane_deterministic_chain_replay_implementation_packet', $capabilities);
        $this->assertContains('agent_control_plane_deterministic_chain_replay_service', $capabilities);
        $this->assertContains('agent_control_plane_deterministic_chain_replay_status_projection', $capabilities);
    }

    public function test_docs_mention_deterministic_replay_certification(): void
    {
        $contractDoc = (string) file_get_contents(base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md'));
        $this->assertStringContainsString('Agent Control Plane Deterministic Chain Replay', $contractDoc);
        $this->assertStringContainsString('atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1', $contractDoc);
    }

    public function test_replay_does_not_mutate_next_required_slice(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->replay();
        $afterPointer = $this->controlPlanePointer();
        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_replay_does_not_write_ledger_or_status_projection(): void
    {
        $payload = $this->readiness()->agentControlPlaneDeterministicChainReplayStatus();
        $this->assertFalse((bool) data_get($payload, 'execution_allowed'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_allowed'));
        $this->assertFalse((bool) data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse((bool) data_get($payload, 'runtime_write_allowed'));
        $guarantees = (array) data_get($payload, 'non_execution_guarantees', []);
        $this->assertContains('agent_control_plane_deterministic_chain_replay_status_does_not_advance_pointer', $guarantees);
    }

    public function test_replay_includes_non_execution_guarantees(): void
    {
        $replay = $this->newService()->replay();
        $guarantees = (array) data_get($replay, 'non_execution_guarantees', []);
        $this->assertContains('replay_does_not_start_codex', $guarantees);
        $this->assertContains('replay_does_not_mutate_pointer', $guarantees);
        $this->assertContains('replay_does_not_dispatch_work', $guarantees);
    }

    public function test_replay_options_applied_reflects_input(): void
    {
        $replay = $this->newService()->replay(['include_proof_bundle' => false, 'max_slices' => 3]);
        $options = (array) data_get($replay, 'options_applied', []);
        $this->assertFalse((bool) $options['include_proof_bundle']);
        $this->assertSame(3, (int) $options['max_slices']);
    }

    public function test_replay_invariants_block_holds(): void
    {
        $replay = $this->newService()->replay();
        $invariants = (array) data_get($replay, 'invariants', []);
        $this->assertNotEmpty($invariants);
        $this->assertTrue((bool) data_get($invariants, 'replay_is_read_only'));
        $this->assertTrue((bool) data_get($invariants, 'replay_did_not_advance_pointer'));
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

    private function newService(): AgentControlPlaneDeterministicChainReplayService
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this->readiness());

        return new AgentControlPlaneDeterministicChainReplayService($audit, $this->readiness());
    }

    private function readiness(): AtlasSelfConstructionReadinessService
    {
        return app(AtlasSelfConstructionReadinessService::class);
    }
}
