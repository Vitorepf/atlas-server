<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationEvidenceQueryService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationEvidenceQueryTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cachedEmpty = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cachedEmpty = null;
    }

    public function test_query_returns_schema_v1(): void
    {
        $payload = $this->emptyQuery();
        $this->assertSame(AgentControlPlaneCertificationEvidenceQueryService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AgentControlPlaneCertificationEvidenceQueryService::MODE, $payload['mode']);
    }

    public function test_empty_query_returns_all_records(): void
    {
        $payload = $this->emptyQuery();
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThan(100, $payload['record_count']);
        $this->assertSame($payload['record_count'], $payload['result_count']);
    }

    public function test_slice_filter_equals(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'slice', 'operator' => 'equals', 'value' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThanOrEqual(1, $payload['result_count']);
    }

    public function test_capability_filter_starts_with(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'capability', 'operator' => 'starts_with', 'value' => 'agent_control_plane_certification_baseline']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThanOrEqual(5, $payload['result_count']);
    }

    public function test_cli_option_filter_contains(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'cli_option', 'operator' => 'contains', 'value' => 'agent-control-plane']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThanOrEqual(1, $payload['result_count']);
    }

    public function test_readiness_method_filter_starts_with(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'readiness_method', 'operator' => 'starts_with', 'value' => 'agentAutomatic']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThan(0, $payload['result_count']);
    }

    public function test_invoker_class_filter_contains(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'invoker_class', 'operator' => 'contains', 'value' => 'CodexRealInvoker']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThan(0, $payload['result_count']);
    }

    public function test_doc_anchor_filter_exists(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'doc_anchor', 'operator' => 'exists']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThan(0, $payload['result_count']);
    }

    public function test_runtime_flag_filter_contains(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'runtime_flag', 'operator' => 'contains', 'value' => 'allowed_anywhere']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThanOrEqual(1, $payload['result_count']);
    }

    public function test_edge_from_filter_starts_with(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'edge_from', 'operator' => 'starts_with', 'value' => 'automatic_dispatch_scheduler']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThan(0, $payload['result_count']);
    }

    public function test_corridor_filter_in(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'corridor', 'operator' => 'in', 'value' => ['post_start_evidence_corridor', 'provider_to_runtime_corridor']]]]);
        $this->assertSame('available', $payload['status']);
        $this->assertSame(2, $payload['result_count']);
    }

    public function test_status_filter_equals(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'status', 'operator' => 'exists']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThanOrEqual(1, $payload['result_count']);
    }

    public function test_proof_kind_filter_equals(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'proof_kind', 'operator' => 'equals', 'value' => 'chain_integrity_summary']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertSame(1, $payload['result_count']);
    }

    public function test_hash_filter_exists(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'hash', 'operator' => 'exists']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThanOrEqual(3, $payload['result_count']);
    }

    public function test_violation_type_filter_missing(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'violation_type', 'operator' => 'missing']]]);
        $this->assertContains($payload['status'], ['available', 'no_match']);
    }

    public function test_filter_not_in_excludes_values(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'corridor', 'operator' => 'not_in', 'value' => ['post_start_evidence_corridor']]]]);
        $this->assertSame('available', $payload['status']);
        $this->assertSame(2, $payload['result_count']);
    }

    public function test_filter_ends_with(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'capability', 'operator' => 'ends_with', 'value' => '_status_projection']]]);
        $this->assertSame('available', $payload['status']);
        $this->assertGreaterThan(0, $payload['result_count']);
    }

    public function test_facets_present(): void
    {
        $payload = $this->emptyQuery();
        $this->assertIsArray($payload['facets']);
        $this->assertArrayHasKey('slice', $payload['facets']);
        $this->assertArrayHasKey('capability', $payload['facets']);
        $this->assertArrayHasKey('cli_option', $payload['facets']);
        $this->assertArrayHasKey('runtime_flag', $payload['facets']);
    }

    public function test_query_hash_stable_across_runs(): void
    {
        $svc = $this->newService();
        $a = $svc->query(['filters' => [['field' => 'capability', 'operator' => 'starts_with', 'value' => 'agent_control_plane']]]);
        $b = $svc->query(['filters' => [['field' => 'capability', 'operator' => 'starts_with', 'value' => 'agent_control_plane']]]);
        $this->assertSame($a['query_hash'], $b['query_hash']);
        $this->assertNotSame($a['query_id'], $b['query_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['query_hash']);
    }

    public function test_invalid_field_blocks(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'unknown_field_xyz', 'operator' => 'equals', 'value' => 'whatever']]]);
        $this->assertSame('blocked', $payload['status']);
        $this->assertNotEmpty($payload['invalid_filters']);
    }

    public function test_invalid_operator_blocks(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'capability', 'operator' => 'unknown_operator', 'value' => 'whatever']]]);
        $this->assertSame('blocked', $payload['status']);
    }

    public function test_in_with_non_array_value_blocks(): void
    {
        $payload = $this->newService()->query(['filters' => [['field' => 'capability', 'operator' => 'in', 'value' => 'not_an_array']]]);
        $this->assertSame('blocked', $payload['status']);
    }

    public function test_query_is_read_only(): void
    {
        $payload = $this->emptyQuery();
        $this->assertTrue((bool) $payload['read_only']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertFalse((bool) $payload['external_provider_call']);
        $this->assertFalse((bool) $payload['token_spend']);
        $this->assertFalse((bool) $payload['process_started']);
        $this->assertFalse((bool) $payload['self_programming_allowed']);
    }

    public function test_query_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->emptyQuery();
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_query_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-evidence-query-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_evidence_query_status.v1',
            $payload['schema_version'],
        );
        $this->assertContains(data_get($payload, 'agent_control_plane_certification_evidence_query_status.status'), ['available', 'no_match']);
    }

    public function test_query_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-evidence-query-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_evidence_query_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_supported_fields_complete(): void
    {
        $payload = $this->emptyQuery();
        foreach (['slice', 'capability', 'cli_option', 'readiness_method', 'invoker_class', 'doc_anchor', 'runtime_flag', 'edge_from', 'edge_to', 'corridor', 'violation_type', 'proof_kind', 'hash', 'status'] as $field) {
            $this->assertContains($field, $payload['supported_fields']);
        }
    }

    public function test_supported_operators_complete(): void
    {
        $payload = $this->emptyQuery();
        foreach (['equals', 'contains', 'starts_with', 'ends_with', 'exists', 'missing', 'in', 'not_in'] as $op) {
            $this->assertContains($op, $payload['supported_operators']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyQuery(): array
    {
        if ($this->cachedEmpty === null) {
            $this->cachedEmpty = $this->newService()->query();
        }

        return $this->cachedEmpty;
    }

    private function newService(): AgentControlPlaneCertificationEvidenceQueryService
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diff, $audit, $replay);
        $baseline = new AgentControlPlaneCertificationBaselineService($readiness, $audit, $replay, $store, $diff, $gate);

        return new AgentControlPlaneCertificationEvidenceQueryService($audit, $replay, $baseline);
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
