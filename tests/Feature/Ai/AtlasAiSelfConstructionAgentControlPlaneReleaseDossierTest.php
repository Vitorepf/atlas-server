<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationMutationGuard;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReleaseDossierService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneReleaseDossierTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cachedDossier = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cachedDossier = null;
    }

    public function test_dossier_returns_schema_v1(): void
    {
        $dossier = $this->dossier();
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_release_dossier.v1',
            $dossier['schema_version'],
        );
        $this->assertSame('read_only_agent_control_plane_release_dossier', $dossier['mode']);
    }

    public function test_dossier_status_available_or_warning_or_blocked(): void
    {
        $dossier = $this->dossier();
        $this->assertContains($dossier['status'], ['available', 'warning', 'blocked']);
    }

    public function test_dossier_includes_baseline_hash(): void
    {
        $dossier = $this->dossier();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $dossier['baseline_hash']);
    }

    public function test_dossier_includes_replay_hash(): void
    {
        $dossier = $this->dossier();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $dossier['replay_hash']);
    }

    public function test_dossier_includes_proof_bundle_hash(): void
    {
        $dossier = $this->dossier();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $dossier['proof_bundle_hash']);
    }

    public function test_dossier_includes_diff_hash(): void
    {
        $dossier = $this->dossier();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $dossier['diff_hash']);
    }

    public function test_dossier_includes_promotion_gate_status(): void
    {
        $dossier = $this->dossier();
        $this->assertNotEmpty($dossier['promotion_gate_status']);
        $this->assertContains($dossier['promotion_gate_status'], ['no_baseline', 'passed', 'warning', 'blocked']);
    }

    public function test_dossier_includes_baseline_capture_readiness(): void
    {
        $dossier = $this->dossier();

        $this->assertArrayHasKey('baseline_capture_readiness', $dossier);
        $this->assertNotEmpty($dossier['baseline_capture_readiness_status']);
        $this->assertContains($dossier['baseline_capture_readiness_status'], [
            'blocked',
            'current_snapshot_present',
            'ready_to_capture_snapshot',
            'ready_to_refresh_snapshot',
        ]);
        $this->assertContains($dossier['baseline_snapshot_state'], ['missing', 'current', 'stale']);
        $this->assertIsBool($dossier['baseline_snapshot_capture_required']);
        $this->assertIsBool($dossier['baseline_snapshot_can_capture']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $dossier['baseline_capture_readiness_hash']);
    }

    public function test_dossier_includes_scenario_detection_rate(): void
    {
        $dossier = $this->dossier();
        $this->assertSame(1.0, (float) $dossier['scenario_detection_rate']);
    }

    public function test_dossier_includes_chain_integrity_status(): void
    {
        $dossier = $this->dossier();
        $this->assertContains($dossier['chain_integrity_status'], ['available', 'degraded', 'blocked']);
    }

    public function test_runtime_safety_all_false(): void
    {
        $dossier = $this->dossier();
        $this->assertTrue((bool) $dossier['runtime_safety_all_false']);
    }

    public function test_dossier_includes_current_pointer(): void
    {
        $dossier = $this->dossier();
        $this->assertNotEmpty($dossier['current_pointer']);
    }

    public function test_dossier_includes_next_required_slice(): void
    {
        $dossier = $this->dossier();
        $this->assertNotEmpty($dossier['next_required_slice']);
    }

    public function test_dossier_includes_next_safe_macro_batch(): void
    {
        $dossier = $this->dossier();
        $this->assertArrayHasKey('next_safe_macro_batch', $dossier);
    }

    public function test_dossier_includes_risk_classification(): void
    {
        $dossier = $this->dossier();
        $this->assertContains($dossier['risk_classification'], ['low', 'medium', 'high']);
    }

    public function test_dossier_includes_operator_summary(): void
    {
        $dossier = $this->dossier();
        $this->assertIsArray($dossier['operator_summary']);
        $this->assertArrayHasKey('overall_status', $dossier['operator_summary']);
        $this->assertArrayHasKey('risk_classification', $dossier['operator_summary']);
        $this->assertArrayHasKey('instruction', $dossier['operator_summary']);
    }

    public function test_dossier_includes_machine_summary(): void
    {
        $dossier = $this->dossier();
        $this->assertIsArray($dossier['machine_summary']);
        $this->assertArrayHasKey('baseline_hash', $dossier['machine_summary']);
        $this->assertArrayHasKey('replay_hash', $dossier['machine_summary']);
        $this->assertArrayHasKey('baseline_capture_readiness_hash', $dossier['machine_summary']);
        $this->assertArrayHasKey('baseline_snapshot_capture_required', $dossier['machine_summary']);
    }

    public function test_dossier_includes_evidence_index(): void
    {
        $dossier = $this->dossier();
        $this->assertIsArray($dossier['evidence_index']);
        $this->assertArrayHasKey('control_plane_baseline', $dossier['evidence_index']);
        $this->assertArrayHasKey('promotion_gate', $dossier['evidence_index']);
    }

    public function test_dossier_includes_command_evidence(): void
    {
        $dossier = $this->dossier();
        $this->assertIsArray($dossier['command_evidence']);
        $this->assertArrayHasKey('baseline_command', $dossier['command_evidence']);
    }

    public function test_dossier_includes_doc_evidence(): void
    {
        $dossier = $this->dossier();
        $this->assertArrayHasKey('agent_control_plane_contract', $dossier['doc_evidence']);
    }

    public function test_dossier_includes_test_evidence(): void
    {
        $dossier = $this->dossier();
        $this->assertArrayHasKey('baseline_tests', $dossier['test_evidence']);
        $this->assertArrayHasKey('simulator_tests', $dossier['test_evidence']);
        $this->assertArrayHasKey('release_dossier_tests', $dossier['test_evidence']);
        $this->assertArrayHasKey('mutation_guard_tests', $dossier['test_evidence']);
    }

    public function test_dossier_exposes_release_dossier_closure_runbook(): void
    {
        $dossier = $this->dossier();

        $this->assertArrayHasKey('release_dossier_closure_runbook', $dossier);
        $runbook = (array) $dossier['release_dossier_closure_runbook'];
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_release_dossier_closure_runbook.v1',
            (string) $runbook['schema_version'],
        );
        $this->assertSame('read_only_agent_control_plane_release_dossier_closure_runbook', (string) $runbook['mode']);
        $this->assertContains((string) $runbook['closure_status'], [
            'release_dossier_green',
            'release_dossier_blocked_resolve_blockers_before_capture',
            'snapshot_capture_required_before_release_dossier_green',
            'snapshot_capture_required_but_capture_not_yet_safe',
            'release_dossier_warning_inspect_blockers_and_warnings',
        ]);
        $this->assertSame(
            'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json',
            (string) $runbook['capture_command'],
        );
        $this->assertSame(
            'php -d memory_limit=512M artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            (string) $runbook['post_capture_audit_command'],
        );
        $this->assertSame(
            'php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-status --json',
            (string) $runbook['post_capture_dossier_command'],
        );
        $this->assertSame(
            'php artisan atlas:ai:self-construction --agent-control-plane-replay-diff-status --json',
            (string) $runbook['post_capture_replay_diff_command'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $runbook['runbook_hash']);
        $this->assertTrue((bool) $runbook['read_only']);
        $this->assertFalse((bool) $runbook['execution_allowed']);
        $this->assertFalse((bool) $runbook['dispatch_allowed']);
        $this->assertFalse((bool) $runbook['provider_call_allowed']);
        $this->assertFalse((bool) $runbook['completion_claim_allowed']);
        $this->assertContains('closure_runbook_does_not_capture_snapshot_itself', (array) $runbook['non_execution_guarantees']);
        $this->assertGreaterThanOrEqual(5, (int) $runbook['step_count']);
    }

    public function test_closure_runbook_resolves_exact_next_command_from_dossier_state(): void
    {
        $dossier = $this->dossier();
        $runbook = (array) $dossier['release_dossier_closure_runbook'];

        $status = (string) $dossier['status'];
        $closureStatus = (string) $runbook['closure_status'];

        // Cross-check the closure_status branch is consistent with dossier
        // status and snapshot readiness, and that exact_next_command picks
        // the right command for that branch. The test deliberately accepts
        // all four valid branches because the faked storage disk can land
        // the dossier in any of warning/blocked/green depending on
        // production replay/snapshot state.
        if ($status === 'available') {
            $this->assertSame('release_dossier_green', $closureStatus);
            $this->assertSame((string) $runbook['post_capture_audit_command'], (string) $runbook['exact_next_command']);
        } elseif ($status === 'blocked') {
            $this->assertSame('release_dossier_blocked_resolve_blockers_before_capture', $closureStatus);
            $this->assertSame((string) $runbook['post_capture_dossier_command'], (string) $runbook['exact_next_command']);
        } else {
            $this->assertSame('warning', $status);
            if ((bool) $runbook['baseline_snapshot_capture_required'] && (bool) $runbook['baseline_snapshot_can_capture']) {
                $this->assertSame('snapshot_capture_required_before_release_dossier_green', $closureStatus);
                $this->assertSame((string) $runbook['capture_command'], (string) $runbook['exact_next_command']);
            } else {
                $this->assertContains($closureStatus, [
                    'snapshot_capture_required_but_capture_not_yet_safe',
                    'release_dossier_warning_inspect_blockers_and_warnings',
                ]);
            }
        }
    }

    public function test_completion_claim_allowed_false(): void
    {
        $dossier = $this->dossier();
        $this->assertFalse((bool) $dossier['completion_claim_allowed']);
    }

    public function test_runtime_execution_allowed_false(): void
    {
        $dossier = $this->dossier();
        $this->assertFalse((bool) $dossier['runtime_execution_allowed']);
    }

    public function test_provider_call_allowed_false(): void
    {
        $dossier = $this->dossier();
        $this->assertFalse((bool) $dossier['provider_call_allowed']);
    }

    public function test_dispatch_allowed_false(): void
    {
        $dossier = $this->dossier();
        $this->assertFalse((bool) $dossier['dispatch_allowed']);
    }

    public function test_self_programming_allowed_false(): void
    {
        $dossier = $this->dossier();
        $this->assertFalse((bool) $dossier['self_programming_allowed']);
    }

    public function test_release_dossier_hash_stable_when_state_unchanged(): void
    {
        $service = $this->newService();
        $a = $service->build(['skip_simulator' => true]);
        $b = $service->build(['skip_simulator' => true]);
        $this->assertSame($a['release_dossier_hash'], $b['release_dossier_hash']);
        $this->assertNotSame($a['dossier_id'], $b['dossier_id']);
        $this->assertNotEmpty($a['generated_at']);
        $this->assertNotEmpty($b['generated_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['release_dossier_hash']);
    }

    public function test_dossier_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-release-dossier-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_release_dossier_status.v1',
            $payload['schema_version'],
        );
        $this->assertContains(data_get($payload, 'agent_control_plane_release_dossier_status.status'), ['available', 'warning', 'blocked']);
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_release_dossier_status.release_dossier_hash'));
    }

    public function test_dossier_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->build(['skip_simulator' => true]);
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_dossier_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-release-dossier-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_release_dossier_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dossier(): array
    {
        if ($this->cachedDossier === null) {
            $this->cachedDossier = $this->newService()->build(['skip_simulator' => true]);
        }

        return $this->cachedDossier;
    }

    private function newService(): AgentControlPlaneReleaseDossierService
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diff, $audit, $replay);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator($readiness, $audit, $replay, $diff, $store, $gate);
        $baseline = new AgentControlPlaneCertificationBaselineService($readiness, $audit, $replay, $store, $diff, $gate);
        $mutationGuard = new AgentControlPlaneCertificationMutationGuard($readiness, $replay, $store);

        return new AgentControlPlaneReleaseDossierService(
            $baseline,
            $replay,
            $store,
            $diff,
            $gate,
            $simulator,
            $audit,
            $mutationGuard,
        );
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
