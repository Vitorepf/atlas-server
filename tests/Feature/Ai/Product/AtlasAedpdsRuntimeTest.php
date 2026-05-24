<?php

namespace Tests\Feature\Ai\Product;

use App\Services\Ai\Product\AtlasAedpdsInspectionService;
use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use App\Services\Ai\Product\AtlasExecutionDoctrineRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRunCertificationService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevContextGateService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAedpdsRuntimeTest extends TestCase
{
    public function test_ui_bug_selects_ux_atdd_tdd_and_risk(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Corrigir bug visual na tela mobile de checkout',
            'surface' => 'atlas_dev',
            'code_changes_requested' => true,
        ]);

        $this->assertSame(AtlasExecutionDoctrineRuntimeService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertContains('ux_driven', $result['selected_primary_drivers']);
        $this->assertContains('atdd', $result['selected_primary_drivers']);
        $this->assertContains('tdd', $result['selected_primary_drivers']);
        $this->assertContains('risk_driven', array_merge($result['selected_primary_drivers'], $result['selected_secondary_drivers']));
    }

    public function test_api_endpoint_selects_contract_api_first_tdd_and_security(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Criar endpoint API de billing com payload e auth',
            'surface' => 'atlas_dev',
            'code_changes_requested' => true,
            'senior_review_present' => true,
        ]);

        $this->assertContains('cdd', $result['selected_primary_drivers']);
        $this->assertContains('api_first', $result['selected_primary_drivers']);
        $this->assertContains('tdd', $result['selected_primary_drivers']);
        $this->assertContains('security_driven', $result['selected_primary_drivers']);
        $this->assertContains('api_or_payload_contract', $result['required_contracts']);
    }

    public function test_migration_selects_database_risk_and_tests(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Adicionar migration de schema e query de rollback',
            'code_changes_requested' => true,
        ]);

        $this->assertContains('database_driven', $result['selected_primary_drivers']);
        $this->assertContains('risk_driven', array_merge($result['selected_primary_drivers'], $result['selected_secondary_drivers']));
        $this->assertContains('migration_or_query_regression_tests', $result['required_tests']);
    }

    public function test_refactor_large_selects_architecture_risk_and_test_impact(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Refactor grande em múltiplos módulos do runtime',
            'code_changes_requested' => true,
            'files' => ['a', 'b', 'c', 'd', 'e', 'f'],
        ]);

        $this->assertContains('architecture_driven', $result['selected_primary_drivers']);
        $this->assertContains('risk_driven', $result['selected_primary_drivers']);
        $this->assertContains('test_impact_gate', $result['required_gates']);
        $this->assertSame('forge_work_packet', $result['recommended_escalation']);
    }

    public function test_complex_product_selects_fdd_ddd_atdd_ux_architecture(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Criar SaaS empresarial complexo com painel e domínio de negócio',
            'surface' => 'atlas_forge',
            'code_changes_requested' => true,
            'senior_review_present' => true,
        ]);

        foreach (['fdd', 'domain_driven_design', 'atdd', 'ux_driven', 'architecture_driven'] as $driver) {
            $this->assertContains($driver, $result['selected_primary_drivers']);
        }
    }

    public function test_security_auth_billing_blocks_without_review(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Alterar auth e billing provider runtime crítico',
            'code_changes_requested' => true,
        ]);

        $this->assertContains('security_driven', $result['selected_primary_drivers']);
        $this->assertContains('sensitive_change_requires_senior_review', $result['blockers']);
        $this->assertFalse($result['allowed_to_execute']);
    }

    public function test_documentation_canon_selects_documentation_driven_docs_health(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Atualizar documentação canônica e cartografia de governança',
        ]);

        $this->assertContains('documentation_driven', $result['selected_primary_drivers']);
        $this->assertContains('docs_health_or_authority_check', $result['required_evidence']);
    }

    public function test_performance_cost_selects_performance_driven(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Reduzir custo de token cache e latência',
            'code_changes_requested' => true,
        ]);

        $this->assertContains('performance_driven', $result['selected_primary_drivers']);
    }

    public function test_ambiguity_high_blocks_or_clarifies(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'faz',
            'code_changes_requested' => true,
        ]);

        $this->assertSame('high', $result['ambiguity_level']);
        $this->assertContains('high_ambiguity_requires_clarification_or_context_gate', $result['blockers']);
        $this->assertSame('human_clarification', $result['recommended_escalation']);
    }

    public function test_many_files_multiple_domains_escalates_to_forge(): void
    {
        $result = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Implementar feature de produto',
            'code_changes_requested' => true,
            'files' => ['a', 'b', 'c', 'd', 'e', 'f'],
            'domains' => ['billing', 'security'],
            'senior_review_present' => true,
        ]);

        $this->assertSame('forge_work_packet', $result['recommended_escalation']);
    }

    public function test_gate_blocks_missing_acceptance_contract_ux_and_review(): void
    {
        $gate = app(AtlasExecutionDoctrineGateService::class)->evaluate([
            'task' => 'Criar tela API auth billing',
            'surface' => 'atlas_dev',
            'code_changes_requested' => true,
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertContains('missing_acceptance_criteria', $gate['blockers']);
        $this->assertContains('missing_contract_or_schema', $gate['blockers']);
        $this->assertContains('missing_ux_expectation_or_prototype', $gate['blockers']);
        $this->assertContains('missing_senior_review_for_sensitive_change', $gate['blockers']);
        $this->assertSame(64, strlen((string) $gate['hash']));
    }

    public function test_gate_allows_simple_task_with_minimum_gates(): void
    {
        $gate = app(AtlasExecutionDoctrineGateService::class)->evaluate([
            'task' => 'Corrigir bug em serviço local',
            'code_changes_requested' => true,
            'acceptance_criteria' => ['reported failure no longer reproduces'],
            'context_refs' => ['app/Services/Foo.php'],
            'tests' => ['php artisan test --filter=Foo'],
            'evidence' => ['test output'],
        ]);

        $this->assertContains($gate['status'], ['passed', 'warning']);
        $this->assertSame(64, strlen((string) $gate['hash']));
    }

    public function test_dev_task_packet_receives_selected_drivers_and_test_impact(): void
    {
        $packet = app(DevTaskPacketRuntimeService::class)->build([
            'objective' => 'Criar endpoint API com auth',
            'task_class' => 'feature',
            'risk_band' => 'high',
            'context_refs' => ['docs/owner.md'],
            'allowed_files' => ['app/Foo.php'],
            'suggested_tests' => ['tests/Feature/FooTest.php'],
            'acceptance_criteria' => ['contract behavior is accepted'],
            'review_refs' => ['senior-review'],
        ]);

        $this->assertArrayHasKey('aedpds', $packet);
        $this->assertContains('api_first', data_get($packet, 'aedpds.doctrine.selected_primary_drivers'));
        $this->assertContains('aedpds_test:contract_tests', $packet['suggested_tests']);
    }

    public function test_dev_run_cert_includes_aedpds_block(): void
    {
        $task = app(DevTaskPacketRuntimeService::class)->persist([
            'objective' => 'Corrigir bug em serviço local',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'context_refs' => ['app/Services/Foo.php'],
            'allowed_files' => ['app/Services/Foo.php'],
            'suggested_tests' => ['tests/Feature/FooTest.php'],
            'acceptance_criteria' => ['reported behavior passes'],
            'required_evidence' => ['test output'],
        ]);
        $context = app(DevContextGateService::class)->persist($task);
        $outcome = app(DevOutcomeMemoryService::class)->persist([
            'outcome_status' => 'success',
            'evidence_kinds' => ['test output'],
            'selected_tests' => ['tests/Feature/FooTest.php'],
        ], $task);

        $cert = app(DevRunCertificationService::class)->certify($task, $context, $outcome, null, []);

        $this->assertArrayHasKey('aedpds', $cert);
        $this->assertContains('tdd', $cert['aedpds']['selected_drivers']);
    }

    public function test_certify_returns_ready(): void
    {
        $cert = app(AtlasAedpdsInspectionService::class)->certify();

        $this->assertSame(AtlasAedpdsInspectionService::CERTIFICATION_SCHEMA_VERSION, $cert['schema_version']);
        $this->assertSame('ready', $cert['status']);
        $this->assertSame([], $cert['remaining_blockers']);
    }

    public function test_aedpds_commands_return_json(): void
    {
        Artisan::call('atlas:aedpds:select', ['--task' => 'bug visual na tela mobile', '--json' => true]);
        $select = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasExecutionDoctrineRuntimeService::SCHEMA_VERSION, $select['schema_version']);

        Artisan::call('atlas:aedpds:gate', ['--task' => 'endpoint api com auth', '--json' => true]);
        $gate = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasExecutionDoctrineGateService::SCHEMA_VERSION, $gate['schema_version']);

        Artisan::call('atlas:aedpds:certify', ['--json' => true]);
        $cert = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasAedpdsInspectionService::CERTIFICATION_SCHEMA_VERSION, $cert['schema_version']);
    }
}
