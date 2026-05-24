<?php

namespace Tests\Feature\Ai\Product;

use App\Models\AtlasDevContextGate;
use App\Models\AtlasDevDecisionMaterialization;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasDevRunCertification;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Product\AtlasAedpdsInspectionService;
use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use App\Services\Ai\Product\AtlasExecutionDoctrineRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevContextGateService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevDecisionMaterializationService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevNativeCapabilityOrchestrator;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRunCertificationService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAedpdsRuntimeTest extends TestCase
{
    private object $devMigration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->devMigration = require base_path('database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php');
        $this->devMigration->down();
        $this->devMigration->up();
    }

    protected function tearDown(): void
    {
        $this->devMigration->down();

        parent::tearDown();
    }

    public function test_ui_bug_selects_ux_atdd_tdd_and_risk(): void
    {
        $selection = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'corrigir bug visual na tela mobile',
            'surface' => 'atlas_dev',
            'code_changes_requested' => true,
        ]);

        $this->assertSame(AtlasExecutionDoctrineRuntimeService::SCHEMA_VERSION, $selection['schema_version']);
        $this->assertContains('ux_driven', $selection['selected_primary_drivers']);
        $this->assertContains('atdd', $selection['selected_primary_drivers']);
        $this->assertContains('tdd', $selection['selected_primary_drivers']);
        $this->assertContains('risk_driven', $selection['selected_secondary_drivers']);
        $this->assertSame('local_dev', $selection['recommended_escalation']);
    }

    public function test_api_endpoint_selects_contract_api_first_tdd_and_security(): void
    {
        $selection = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'criar endpoint API com auth e payload versionado',
            'surface' => 'atlas_dev',
            'code_changes_requested' => true,
            'senior_review_present' => true,
        ]);

        foreach (['cdd', 'api_first', 'tdd', 'security_driven'] as $driver) {
            $this->assertContains($driver, $selection['selected_primary_drivers']);
        }
        $this->assertContains('api_or_payload_contract', $selection['required_contracts']);
    }

    public function test_migration_refactor_product_docs_performance_and_ambiguity_routes_are_selected(): void
    {
        $runtime = app(AtlasExecutionDoctrineRuntimeService::class);

        $migration = $runtime->select(['task' => 'criar migration de schema e rollback', 'code_changes_requested' => true]);
        $this->assertContains('database_driven', $migration['selected_primary_drivers']);
        $this->assertContains('migration_or_query_regression_tests', $migration['required_tests']);

        $refactor = $runtime->select(['task' => 'refactor grande em múltiplos módulos', 'files' => ['a', 'b', 'c', 'd', 'e', 'f'], 'code_changes_requested' => true]);
        $this->assertContains('architecture_driven', $refactor['selected_primary_drivers']);
        $this->assertSame('forge_work_packet', $refactor['recommended_escalation']);

        $product = $runtime->select(['task' => 'criar SaaS complexo para empresa com UX', 'code_changes_requested' => true]);
        foreach (['fdd', 'domain_driven_design', 'atdd', 'ux_driven', 'architecture_driven'] as $driver) {
            $this->assertContains($driver, $product['selected_primary_drivers']);
        }

        $docs = $runtime->select(['task' => 'atualizar documentação canônica e cartografia']);
        $this->assertContains('documentation_driven', $docs['selected_primary_drivers']);
        $this->assertContains('docs_health_or_authority_check', $docs['required_evidence']);

        $performance = $runtime->select(['task' => 'reduzir latência, custo de token e cache']);
        $this->assertContains('performance_driven', $performance['selected_primary_drivers']);

        $model = $runtime->select(['task' => 'implementar state machine model-driven para workflow', 'code_changes_requested' => true]);
        $this->assertContains('model_driven', $model['selected_primary_drivers']);
        $this->assertContains('formal_or_semiformal_model_contract', $model['required_contracts']);
        $this->assertContains('model_contract_gate', $model['required_gates']);

        $observability = $runtime->select(['task' => 'adicionar observability readiness com logs traces e receipts', 'code_changes_requested' => true]);
        $this->assertContains('reliability_observability_driven', $observability['selected_primary_drivers']);
        $this->assertContains('logs_traces_receipts_or_readiness_signal', $observability['required_evidence']);
        $this->assertContains('observability_readiness_gate', $observability['required_gates']);

        $ambiguous = $runtime->select(['task' => 'faz isso', 'missing_context' => true]);
        $this->assertFalse($ambiguous['allowed_to_execute']);
        $this->assertSame('human_clarification', $ambiguous['recommended_escalation']);
    }

    public function test_gate_blocks_missing_acceptance_contract_ux_and_review_then_hash_is_deterministic(): void
    {
        $gate = app(AtlasExecutionDoctrineGateService::class);

        $apiBlocked = $gate->evaluate(['task' => 'criar api endpoint com auth', 'code_changes_requested' => true]);
        $apiBlockedAgain = $gate->evaluate(['task' => 'criar api endpoint com auth', 'code_changes_requested' => true]);
        $this->assertSame('blocked', $apiBlocked['status']);
        $this->assertContains('missing_minimum_context_ref', $apiBlocked['blockers']);
        $this->assertContains('missing_contract_or_schema', $apiBlocked['blockers']);
        $this->assertContains('missing_senior_review_for_sensitive_change', $apiBlocked['blockers']);
        $this->assertSame($apiBlocked['hash'], $apiBlockedAgain['hash']);

        $uiBlocked = $gate->evaluate([
            'task' => 'corrigir bug visual na tela mobile',
            'code_changes_requested' => true,
            'acceptance_criteria' => ['visual bug no longer reproduces'],
            'context_refs' => ['owner doc'],
            'tests' => ['visual regression check'],
        ]);
        $this->assertContains('missing_ux_expectation_or_prototype', $uiBlocked['blockers']);

        $readmeBlocked = $gate->evaluate([
            'task' => 'publicar package SDK com README de uso',
            'code_changes_requested' => true,
            'acceptance_criteria' => ['usage documented'],
            'context_refs' => ['owner doc'],
            'tests' => ['php artisan test --filter=Sdk'],
        ]);
        $this->assertContains('readme_driven', $readmeBlocked['selected_drivers']);
        $this->assertContains('missing_readme_or_usage_contract', $readmeBlocked['blockers']);

        $modelBlocked = $gate->evaluate([
            'task' => 'implementar state machine model-driven para workflow',
            'code_changes_requested' => true,
            'acceptance_criteria' => ['workflow transitions are specified'],
            'context_refs' => ['owner doc'],
            'tests' => ['state machine tests'],
        ]);
        $this->assertContains('model_driven', $modelBlocked['selected_drivers']);
        $this->assertContains('missing_model_or_state_machine_contract', $modelBlocked['blockers']);

        $observabilityBlocked = $gate->evaluate([
            'task' => 'adicionar observability readiness com logs traces e receipts',
            'code_changes_requested' => true,
            'acceptance_criteria' => ['readiness signal emitted'],
            'context_refs' => ['owner doc'],
            'tests' => ['observability test'],
        ]);
        $this->assertContains('reliability_observability_driven', $observabilityBlocked['selected_drivers']);
        $this->assertContains('missing_observability_readiness_evidence', $observabilityBlocked['blockers']);

        $passed = $gate->evaluate([
            'task' => 'corrigir bug pequeno em cálculo local',
            'code_changes_requested' => true,
            'acceptance_criteria' => ['reported bug no longer reproduces'],
            'context_refs' => ['owner doc'],
            'tests' => ['php artisan test --filter=LocalBug'],
            'evidence' => ['test output'],
        ]);
        $this->assertSame('passed', $passed['status']);
    }

    public function test_apdr_embeds_aedpds_doctrine_and_gate(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'criar API endpoint com auth e payload',
            'workspace' => 'atlas-server',
            'operator_approved' => true,
        ]);

        $this->assertSame(AtlasExecutionDoctrineRuntimeService::SCHEMA_VERSION, data_get($plan, 'aedpds.doctrine.schema_version'));
        $this->assertSame(AtlasExecutionDoctrineGateService::SCHEMA_VERSION, data_get($plan, 'aedpds.gate.schema_version'));
        $this->assertContains('api_first', data_get($plan, 'aedpds.doctrine.selected_primary_drivers'));
    }

    public function test_apdr_status_is_blocked_when_aedpds_gate_is_blocked(): void
    {
        $plan = app(AtlasAutonomousProductDeliveryRuntimeService::class)->plan([
            'human_request' => 'estou com bug na tela de login',
            'workspace' => 'atlas-app',
        ]);

        $this->assertSame('blocked_by_aedpds_gate', $plan['status']);
        $this->assertSame('blocked', data_get($plan, 'aedpds.gate.status'));
        $this->assertFalse(data_get($plan, 'aedpds.doctrine.signals.observability'));
        $this->assertContains('missing_ux_expectation_or_prototype', data_get($plan, 'aedpds.gate.blockers'));
        $this->assertContains('delivery_not_ready', collect($plan['enforcement']['blockers'])->pluck('id')->all());
        $this->assertContains('delivery_contract_not_ready', collect($plan['proof_preview']['critical_blockers'])->pluck('id')->all());
    }

    public function test_dev_run_certification_includes_aedpds_summary(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'aedpds-dev-run',
            'task_id' => 'aedpds-dev-task',
            'objective' => 'corrigir bug pequeno em cálculo local',
            'risk_band' => 'medium',
            'task_class' => 'bug',
            'allowed_files' => ['app/Services/Foo.php'],
            'context_refs' => ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md'],
            'suggested_tests' => ['php artisan test --filter=Foo'],
            'acceptance_criteria' => ['reported bug no longer reproduces'],
            'required_evidence' => ['test output'],
        ]);
        $contextGate = (new DevContextGateService)->persist($packet);
        $outcome = (new DevOutcomeMemoryService)->persist([
            'outcome_status' => 'success',
            'evidence_kinds' => ['phpunit'],
            'selected_tests' => ['php artisan test --filter=Foo'],
        ], $packet);
        $native = (new DevNativeCapabilityOrchestrator)->evaluate($packet->toArray(), [
            'context_gate' => $contextGate->toArray(),
            'outcome' => $outcome->toArray(),
            'changed_files' => ['app/Services/Foo.php'],
            'diff_clean' => true,
        ]);
        $decisions = [];
        foreach ((array) ($native['decisions'] ?? []) as $kind => $decision) {
            $decisions[] = (new DevDecisionMaterializationService)->persist($packet, (string) $kind, (array) $decision);
        }

        $certification = (new DevRunCertificationService)->persist($packet, $contextGate, $outcome, decisionMaterializations: $decisions);

        $this->assertSame('pass', collect($certification->summary['aedpds_checks'])->firstWhere('id', 'aedpds_doctrine_selected')['status']);
        $this->assertNull(collect($certification->checks)->firstWhere('id', 'aedpds_doctrine_selected'));
        $this->assertSame(17, $certification->summary['total']);
        $this->assertContains('atdd', $certification->summary['aedpds_selected_drivers']);
        $this->assertSame('passed', $certification->summary['aedpds_gate_status']);
        $this->assertSame(1, AtlasDevTaskPacket::query()->count());
        $this->assertSame(1, AtlasDevContextGate::query()->count());
        $this->assertSame(1, AtlasDevOutcomeMemory::query()->count());
        $this->assertGreaterThanOrEqual(1, AtlasDevDecisionMaterialization::query()->count());
        $this->assertSame(1, AtlasDevRunCertification::query()->count());
    }

    public function test_dev_run_certification_blocks_when_aedpds_gate_fails_without_polluting_legacy_checks(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'aedpds-dev-blocked-run',
            'task_id' => 'aedpds-dev-blocked-task',
            'objective' => 'criar endpoint API com auth e payload versionado',
            'risk_band' => 'high',
            'task_class' => 'feature',
            'allowed_files' => ['app/Http/Controllers/FooController.php'],
            'context_refs' => ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md'],
            'suggested_tests' => ['php artisan test --filter=FooController'],
            'required_evidence' => ['test output'],
        ]);
        $contextGate = (new DevContextGateService)->persist($packet);
        $outcome = (new DevOutcomeMemoryService)->persist([
            'outcome_status' => 'success',
            'evidence_kinds' => ['phpunit'],
            'selected_tests' => ['php artisan test --filter=FooController'],
        ], $packet);
        $native = (new DevNativeCapabilityOrchestrator)->evaluate($packet->toArray(), [
            'context_gate' => $contextGate->toArray(),
            'outcome' => $outcome->toArray(),
            'changed_files' => ['app/Http/Controllers/FooController.php'],
            'diff_clean' => true,
        ]);
        $decisions = [];
        foreach ((array) ($native['decisions'] ?? []) as $kind => $decision) {
            $decisions[] = (new DevDecisionMaterializationService)->persist($packet, (string) $kind, (array) $decision);
        }

        $certification = (new DevRunCertificationService)->persist($packet, $contextGate, $outcome, decisionMaterializations: $decisions);

        $this->assertSame(DevRunCertificationService::STATUS_NEEDS_REVIEW, $certification->status);
        $this->assertSame(17, $certification->summary['total']);
        $this->assertGreaterThanOrEqual(1, $certification->summary['aedpds_fail']);
        $this->assertSame('fail', collect($certification->summary['aedpds_checks'])->firstWhere('id', 'aedpds_gate_not_blocked')['status']);
        $this->assertNull(collect($certification->checks)->firstWhere('id', 'aedpds_gate_not_blocked'));
        $this->assertNotEmpty($certification->blockers);
    }

    public function test_forge_integration_and_certification_are_visible(): void
    {
        $inspection = app(AtlasAedpdsInspectionService::class)->inspect();
        $certification = app(AtlasAedpdsInspectionService::class)->certify();

        $this->assertSame('implemented_runtime', data_get($inspection, 'items.forge_outcome_memory.classification'));
        $this->assertSame(14, data_get($inspection, 'classifications.implemented_runtime'));
        $this->assertSame(AtlasAedpdsInspectionService::CERTIFICATION_SCHEMA_VERSION, $certification['schema_version']);
        $this->assertSame('ready', $certification['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'forge_integration_present')['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'forge_work_packet_capability_present')['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'sample_gate_blocks_missing_artifacts')['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'readme_gate_mapping_present')['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'model_gate_mapping_present')['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'observability_gate_mapping_present')['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'minimum_context_gate_mapping_present')['status']);
        $this->assertSame('passed', collect($certification['checks'])->firstWhere('id', 'no_documentation_only_claims')['status']);
    }

    public function test_aedpds_commands_emit_json(): void
    {
        $selectExit = Artisan::call('atlas:aedpds:select', [
            '--task' => 'criar API endpoint com auth',
            '--surface' => 'dev',
            '--json' => true,
        ]);
        $this->assertSame(0, $selectExit);
        $this->assertStringContainsString(AtlasExecutionDoctrineRuntimeService::SCHEMA_VERSION, Artisan::output());

        $gateBlockedExit = Artisan::call('atlas:aedpds:gate', [
            '--task' => 'criar API endpoint com auth',
            '--surface' => 'dev',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(1, $gateBlockedExit);
        $this->assertStringContainsString('missing_contract_or_schema', Artisan::output());

        $gatePassedExit = Artisan::call('atlas:aedpds:gate', [
            '--task' => 'criar API endpoint com auth',
            '--surface' => 'dev',
            '--acceptance' => ['auth failure specified'],
            '--context' => ['owner doc'],
            '--test' => ['php artisan test --filter=FooController'],
            '--contract' => ['openapi contract'],
            '--review' => ['senior review'],
            '--evidence' => ['test output'],
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(0, $gatePassedExit);
        $this->assertStringContainsString(AtlasExecutionDoctrineGateService::SCHEMA_VERSION, Artisan::output());

        $certifyExit = Artisan::call('atlas:aedpds:certify', ['--json' => true, '--strict' => true]);
        $this->assertSame(0, $certifyExit);
        $this->assertStringContainsString(AtlasAedpdsInspectionService::CERTIFICATION_SCHEMA_VERSION, Artisan::output());
    }
}
