<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationMutationGuard;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReleaseDossierExporter;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReleaseDossierService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneReleaseDossierExporterTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cached = null;
    }

    public function test_export_returns_schema_v1(): void
    {
        $payload = $this->exportResult();
        $this->assertSame(AgentControlPlaneReleaseDossierExporter::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AgentControlPlaneReleaseDossierExporter::MODE, $payload['mode']);
    }

    public function test_export_status_available_without_persist(): void
    {
        $payload = $this->exportResult();
        $this->assertSame('available', $payload['status']);
        $this->assertFalse((bool) $payload['persist']);
        $this->assertNull($payload['persisted_md_path']);
    }

    public function test_markdown_generated(): void
    {
        $payload = $this->exportResult();
        $this->assertNotEmpty($payload['markdown']);
        $this->assertGreaterThan(500, strlen((string) $payload['markdown']));
        $this->assertSame(strlen((string) $payload['markdown']), (int) $payload['markdown_byte_size']);
    }

    public function test_markdown_contains_pointer(): void
    {
        $payload = $this->exportResult();
        $this->assertStringContainsString('Current pointer', (string) $payload['markdown']);
        $this->assertStringContainsString('Next required slice', (string) $payload['markdown']);
    }

    public function test_markdown_contains_runtime_safety(): void
    {
        $payload = $this->exportResult();
        $this->assertStringContainsString('Runtime safety all false', (string) $payload['markdown']);
    }

    public function test_markdown_contains_operator_checklist(): void
    {
        $payload = $this->exportResult();
        $this->assertStringContainsString('Operator checklist', (string) $payload['markdown']);
        $this->assertStringContainsString('Confirm runtime safety all false', (string) $payload['markdown']);
        $this->assertStringContainsString('Verify scenario detection rate is 1.0', (string) $payload['markdown']);
    }

    public function test_markdown_contains_chain_integrity_section(): void
    {
        $payload = $this->exportResult();
        $this->assertStringContainsString('## Chain integrity', (string) $payload['markdown']);
    }

    public function test_markdown_contains_replay_section(): void
    {
        $payload = $this->exportResult();
        $this->assertStringContainsString('## Replay', (string) $payload['markdown']);
    }

    public function test_markdown_contains_mutation_guard_section(): void
    {
        $payload = $this->exportResult();
        $this->assertStringContainsString('## Mutation guard', (string) $payload['markdown']);
    }

    public function test_hashes_sha256(): void
    {
        $payload = $this->exportResult();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['markdown_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['machine_summary_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['operator_summary_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['export_hash']);
    }

    public function test_no_file_write_by_default(): void
    {
        $this->exportResult();
        $disk = Storage::disk('local');
        $this->assertSame([], $disk->files(AgentControlPlaneReleaseDossierExporter::ALLOWED_PREFIX));
    }

    public function test_persist_writes_only_allowed_prefix(): void
    {
        $payload = $this->newService()->export(['persist' => true, 'label' => 'test-persist']);
        $this->assertSame('available', $payload['status']);
        $this->assertNotNull($payload['persisted_md_path']);
        $this->assertStringStartsWith(AgentControlPlaneReleaseDossierExporter::ALLOWED_PREFIX.'/', (string) $payload['persisted_md_path']);
        Storage::disk('local')->assertExists((string) $payload['persisted_md_path']);
        Storage::disk('local')->assertExists((string) $payload['persisted_json_path']);
    }

    public function test_machine_summary_present(): void
    {
        $payload = $this->exportResult();
        $this->assertIsArray($payload['machine_summary']);
        $this->assertArrayHasKey('baseline_hash', $payload['machine_summary']);
    }

    public function test_operator_summary_present(): void
    {
        $payload = $this->exportResult();
        $this->assertIsArray($payload['operator_summary']);
        $this->assertArrayHasKey('overall_status', $payload['operator_summary']);
        $this->assertArrayHasKey('instruction', $payload['operator_summary']);
    }

    public function test_json_payload_present(): void
    {
        $payload = $this->exportResult();
        $this->assertIsArray($payload['json_payload']);
        $this->assertSame('atlas.self_construction.agent_control_plane_release_dossier.v1', $payload['json_payload']['schema_version']);
    }

    public function test_export_hash_stable_across_runs(): void
    {
        $svc = $this->newService();
        $a = $svc->export();
        $b = $svc->export();
        $this->assertSame($a['export_hash'], $b['export_hash']);
        $this->assertNotSame($a['export_id'], $b['export_id']);
    }

    public function test_export_is_read_only(): void
    {
        $payload = $this->exportResult();
        $this->assertTrue((bool) $payload['read_only']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertFalse((bool) $payload['completion_claim_allowed']);
        $this->assertFalse((bool) $payload['runtime_execution_allowed']);
        $this->assertFalse((bool) $payload['provider_call_allowed']);
        $this->assertFalse((bool) $payload['self_programming_allowed']);
    }

    public function test_export_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->export();
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_export_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-release-dossier-exporter-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_release_dossier_exporter_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame('available', data_get($payload, 'agent_control_plane_release_dossier_exporter_status.status'));
    }

    public function test_exporter_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-release-dossier-exporter-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_release_dossier_exporter_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_allowed_prefix_canonical(): void
    {
        $this->assertSame('atlas/self-construction/agent-control-plane/dossiers', AgentControlPlaneReleaseDossierExporter::ALLOWED_PREFIX);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportResult(): array
    {
        if ($this->cached === null) {
            $this->cached = $this->newService()->export();
        }

        return $this->cached;
    }

    private function newService(): AgentControlPlaneReleaseDossierExporter
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
        $dossier = new AgentControlPlaneReleaseDossierService($baseline, $replay, $store, $diff, $gate, $simulator, $audit, $mutationGuard);

        return new AgentControlPlaneReleaseDossierExporter($dossier);
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
