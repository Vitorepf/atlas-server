<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationBaselineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_baseline_returns_schema_v1(): void
    {
        $baseline = $this->newService()->build();

        $this->assertSame(
            'atlas.self_construction.agent_control_plane_certification_baseline.v1',
            data_get($baseline, 'schema_version'),
        );
        $this->assertSame('read_only_agent_control_plane_certification_baseline', data_get($baseline, 'mode'));
    }

    public function test_baseline_status_available_or_degraded(): void
    {
        $baseline = $this->newService()->build();
        $this->assertContains($baseline['status'], ['available', 'degraded']);
    }

    public function test_baseline_includes_current_pointer(): void
    {
        $baseline = $this->newService()->build();
        $this->assertNotEmpty($baseline['current_pointer']);
    }

    public function test_baseline_includes_next_build_slices(): void
    {
        $baseline = $this->newService()->build();
        $this->assertIsArray($baseline['next_build_slices']);
        $this->assertNotEmpty($baseline['next_build_slices']);
    }

    public function test_baseline_includes_not_yet_runtime_capable(): void
    {
        $baseline = $this->newService()->build();
        $this->assertIsArray($baseline['not_yet_runtime_capable']);
    }

    public function test_chain_integrity_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['chain_integrity_hash']);
    }

    public function test_deterministic_replay_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['deterministic_replay_hash']);
    }

    public function test_docs_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['docs_hash']);
    }

    public function test_command_surface_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['command_surface_hash']);
    }

    public function test_capability_surface_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['capability_surface_hash']);
    }

    public function test_readiness_surface_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['readiness_surface_hash']);
    }

    public function test_invoker_surface_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['invoker_surface_hash']);
    }

    public function test_runtime_safety_hash_is_sha256(): void
    {
        $baseline = $this->newService()->build();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $baseline['runtime_safety_hash']);
    }

    public function test_baseline_hash_stable_across_two_builds_when_state_unchanged(): void
    {
        $service = $this->newService();
        $a = $service->build();
        $b = $service->build();
        $this->assertSame($a['baseline_hash'], $b['baseline_hash']);
        $this->assertNotSame($a['baseline_id'], $b['baseline_id']);
        $this->assertNotSame($a['generated_at'], $b['generated_at']);
        $this->assertSame($a['baseline_fingerprint'], $b['baseline_fingerprint']);
    }

    public function test_baseline_hash_changes_when_snapshot_state_changes(): void
    {
        $service = $this->newService();
        $store = $this->newStore();
        $a = $service->build();
        $store->put($this->freshReplay(), ['label' => 'baseline-test']);
        $b = $service->build();
        $this->assertNotSame($a['baseline_hash'], $b['baseline_hash']);
        $this->assertNotEmpty($b['latest_snapshot_hash']);
    }

    public function test_baseline_is_read_only(): void
    {
        $baseline = $this->newService()->build();
        $this->assertTrue((bool) $baseline['read_only']);
        $this->assertFalse((bool) $baseline['execution_allowed']);
        $this->assertFalse((bool) $baseline['dispatch_allowed']);
        $this->assertFalse((bool) $baseline['ledger_write_allowed']);
        $this->assertFalse((bool) $baseline['runtime_write_allowed']);
    }

    public function test_baseline_disables_provider_token_dispatch_self_programming(): void
    {
        $baseline = $this->newService()->build();
        $this->assertFalse((bool) $baseline['external_provider_call']);
        $this->assertFalse((bool) $baseline['token_spend']);
        $this->assertFalse((bool) $baseline['process_started']);
        $this->assertFalse((bool) $baseline['self_programming_allowed']);
        $this->assertFalse((bool) $baseline['completion_claim_allowed']);
    }

    public function test_baseline_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->build();
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
        $this->assertNotEmpty($beforePointer);
    }

    public function test_baseline_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-baseline-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_baseline_status.v1',
            $payload['schema_version'],
        );
        $this->assertContains(data_get($payload, 'agent_control_plane_certification_baseline_status.status'), ['available', 'degraded']);
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_certification_baseline_status.baseline_hash'));
    }

    public function test_baseline_contract_preflight_packet_cli_work(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-baseline-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_baseline_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_baseline_capability_exposed_in_agent_control_plane(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $caps = (array) data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('agent_control_plane_certification_baseline_contract', $caps);
        $this->assertContains('agent_control_plane_certification_baseline_preflight', $caps);
        $this->assertContains('agent_control_plane_certification_baseline_implementation_packet', $caps);
        $this->assertContains('agent_control_plane_certification_baseline_service', $caps);
        $this->assertContains('agent_control_plane_certification_baseline_status_projection', $caps);
    }

    public function test_baseline_sections_complete(): void
    {
        $baseline = $this->newService()->build();
        $sections = $baseline['sections'];
        $this->assertArrayHasKey('control_plane', $sections);
        $this->assertArrayHasKey('chain_integrity', $sections);
        $this->assertArrayHasKey('deterministic_replay', $sections);
        $this->assertArrayHasKey('snapshot_store', $sections);
        $this->assertArrayHasKey('replay_diff', $sections);
        $this->assertArrayHasKey('promotion_gate', $sections);
        $this->assertArrayHasKey('docs', $sections);
        $this->assertArrayHasKey('command_surface', $sections);
        $this->assertArrayHasKey('capability_surface', $sections);
        $this->assertArrayHasKey('readiness_surface', $sections);
        $this->assertArrayHasKey('invoker_surface', $sections);
        $this->assertArrayHasKey('test_surface', $sections);
        $this->assertArrayHasKey('runtime_safety', $sections);
    }

    public function test_baseline_invariants_block_holds(): void
    {
        $baseline = $this->newService()->build();
        $invariants = (array) $baseline['invariants'];
        $this->assertNotEmpty($invariants);
        $this->assertTrue((bool) $baseline['invariants_all_true']);
        $this->assertArrayHasKey('baseline_is_read_only', $invariants);
        $this->assertArrayHasKey('runtime_safety_all_false', $invariants);
        $this->assertArrayHasKey('completion_claim_not_allowed', $invariants);
    }

    private function newService(): AgentControlPlaneCertificationBaselineService
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = $this->newStore();
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diff, $audit, $replay);

        return new AgentControlPlaneCertificationBaselineService($readiness, $audit, $replay, $store, $diff, $gate);
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

        return (new AgentControlPlaneDeterministicChainReplayService($audit, $readiness))->replay();
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
