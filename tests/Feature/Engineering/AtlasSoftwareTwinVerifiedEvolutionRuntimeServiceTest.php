<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasAutonomousChangeOrchestratorService;
use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use App\Services\Engineering\AtlasSoftwareTwinVerifiedEvolutionCertificationService;
use App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\Concerns\CreatesAemorTables;
use Tests\Concerns\CreatesAverTables;
use Tests\Concerns\CreatesSoftwareTwinTables;
use Tests\TestCase;

final class AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;
    use CreatesAemorTables;
    use CreatesAverTables;
    use CreatesSoftwareTwinTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSoftwareTwinTables();
        $this->createAtlasEngineeringCodeTables();
        $this->createAemorTables();
        $this->createAverTables();
    }

    protected function tearDown(): void
    {
        $this->dropAverTables();
        $this->dropAemorTables();
        $this->dropAtlasEngineeringCodeTables();
        $this->dropSoftwareTwinTables();

        parent::tearDown();
    }

    public function test_software_twin_builds_read_only_living_system_twin(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->twin('app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php');

        $this->assertSame(AtlasSoftwareTwinRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('twin', $payload['action']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
        $this->assertSame('read_only_living_system_twin', data_get($payload, 'software_twin.mode'));
        $this->assertGreaterThanOrEqual(8, data_get($payload, 'software_twin.node_count'));
        $this->assertGreaterThanOrEqual(6, data_get($payload, 'software_twin.edge_count'));
        $this->assertSame('ready', data_get($payload, 'quality_score.status'));
        $this->assertSame('app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php', $payload['target_path']);
        $this->assertContains('operational_reality', array_keys(data_get($payload, 'software_twin.runtime_lenses')));
    }

    public function test_software_twin_impact_resolves_tests_docs_and_required_gates(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)
            ->impact('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php');

        $this->assertSame(AtlasSoftwareTwinRuntimeService::IMPACT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('impact', $payload['action']);
        $this->assertSame('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', $payload['target_path']);
        $this->assertContains($payload['classification'], ['active_runtime', 'active_read_only', 'headless_available']);
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', data_get($payload, 'impact.required_tests'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md', data_get($payload, 'impact.owner_docs'));
        $this->assertContains('php artisan atlas:aver:certify --json --strict', data_get($payload, 'impact.required_gates'));
    }

    public function test_software_twin_impact_selects_bounded_impact_graphrag_context_from_code_graph(): void
    {
        $moduleId = (string) Str::uuid();
        $targetSymbolId = (string) Str::uuid();
        $now = now();

        DB::table('atlas_engineering_code_modules')->insert([
            'id' => $moduleId,
            'slug' => 'engineering-verified-evolution',
            'name' => 'Engineering Verified Evolution',
            'layer' => 'engineering',
            'root_path' => 'app/Services/Engineering',
            'primary_language' => 'php',
            'status' => 'active',
            'owner' => 'engineering',
            'description' => 'Verified evolution runtime fixture.',
            'docs_status' => 'documented',
            'file_count' => 3,
            'symbol_count' => 4,
            'route_count' => 1,
            'command_count' => 1,
            'migration_count' => 0,
            'test_count' => 1,
            'source_hash' => hash('sha256', 'module'),
            'docs_hash' => hash('sha256', 'docs'),
            'tags_json' => json_encode(['verified-evolution']),
            'related_docs_json' => json_encode(['docs/engineering-knowledge-base/impact-graphrag-fixture.md']),
            'related_tests_json' => json_encode(['tests/Feature/Engineering/ImpactGraphRagFixtureTest.php']),
            'metadata' => $this->encodeJson([]),
            'indexed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_engineering_code_symbols')->insert([
            $this->codeSymbolRow([
                'id' => $targetSymbolId,
                'module_id' => $moduleId,
                'symbol_type' => 'class',
                'symbol_name' => 'App\\Services\\Engineering\\AtlasVerifiedEvolutionRuntimeService',
                'file_path' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
                'line_start' => 20,
                'line_end' => 460,
                'signature' => 'class AtlasVerifiedEvolutionRuntimeService',
                'namespace' => 'App\\Services\\Engineering',
                'source_hash' => hash('sha256', 'target-symbol'),
                'visibility' => 'public',
            ], $now),
            $this->codeSymbolRow([
                'module_id' => $moduleId,
                'symbol_type' => 'route',
                'symbol_name' => 'GET /api/atlas/verified-evolution/proof-plan',
                'file_path' => 'routes/api.php',
                'line_start' => 42,
                'line_end' => 42,
                'signature' => 'Route::get(...)',
                'source_hash' => hash('sha256', 'route-symbol'),
            ], $now),
            $this->codeSymbolRow([
                'module_id' => $moduleId,
                'symbol_type' => 'test_method',
                'symbol_name' => 'test_verified_evolution_impact_graphrag_fixture',
                'file_path' => 'tests/Feature/Engineering/ImpactGraphRagFixtureTest.php',
                'line_start' => 12,
                'line_end' => 30,
                'signature' => 'public function test_verified_evolution_impact_graphrag_fixture(): void',
                'visibility' => 'public',
                'source_hash' => hash('sha256', 'test-symbol'),
            ], $now),
        ]);

        DB::table('atlas_engineering_doc_links')->insert([
            'id' => (string) Str::uuid(),
            'knowledge_item_id' => null,
            'module_id' => $moduleId,
            'symbol_id' => $targetSymbolId,
            'link_type' => 'owner_doc',
            'status' => 'current',
            'canonical_path' => 'docs/engineering-knowledge-base/impact-graphrag-fixture.md',
            'target_path' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            'doc_hash' => hash('sha256', 'doc'),
            'target_hash' => hash('sha256', 'target'),
            'link_hash' => hash('sha256', 'doc-link'),
                'metadata' => $this->encodeJson([]),
            'indexed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload = app(AtlasSoftwareTwinRuntimeService::class)
            ->impact('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php');

        $graph = data_get($payload, 'impact.impact_graphrag');

        $this->assertSame(AtlasSoftwareTwinRuntimeService::IMPACT_GRAPHRAG_SCHEMA_VERSION, data_get($graph, 'schema_version'));
        $this->assertSame('ready', data_get($graph, 'status'));
        $this->assertTrue(data_get($graph, 'provider_safe'));
        $this->assertTrue(data_get($graph, 'bounded'));
        $this->assertSame('high', data_get($graph, 'confidence.label'));
        $this->assertContains('docs/engineering-knowledge-base/impact-graphrag-fixture.md', data_get($graph, 'selected_context.owner_docs'));
        $this->assertContains('tests/Feature/Engineering/ImpactGraphRagFixtureTest.php', data_get($graph, 'selected_context.required_tests'));
        $this->assertContains('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', data_get($graph, 'selected_context.read_first'));
        $this->assertContains('must_verify_with_test', array_column(data_get($graph, 'causal_paths'), 'kind'));
        $this->assertContains('may_affect_runtime_entrypoint', array_column(data_get($graph, 'causal_paths'), 'kind'));

        $proof = app(AtlasVerifiedEvolutionRuntimeService::class)->proofPlan(
            'verificar contexto causal GraphRAG',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );
        $this->assertSame('ready', data_get($proof, 'proof_plan.causal_verification.graph_status'));
        $this->assertSame('high', data_get($proof, 'proof_plan.causal_verification.confidence.label'));
        $this->assertContains('causal_paths_reviewed', data_get($proof, 'proof_plan.completion_requires'));
        $this->assertContains('must_verify_with_test', array_column(data_get($proof, 'proof_plan.causal_verification.causal_paths'), 'kind'));

        $execution = app(AtlasVerifiedEvolutionRuntimeService::class)->executionContract(
            'preparar AVER com contexto causal GraphRAG',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );
        $this->assertSame('ready', data_get($execution, 'execution_contract.aver_plan_input.verification_plan.causal_verification.graph_status'));
        $this->assertContains(
            'docs/engineering-knowledge-base/impact-graphrag-fixture.md',
            data_get($execution, 'execution_contract.aver_plan_input.evidence_refs')
        );
    }

    public function test_autonomous_change_orchestrator_composes_causal_verified_execution_and_repair_plan(): void
    {
        $this->seedImpactGraphRagFixture();

        $payload = app(AtlasAutonomousChangeOrchestratorService::class)->plan(
            'orquestrar mudanca causal verificada em AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            [
                'changed_files' => ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'],
                'max_repair_attempts' => 2,
                'business_context' => [
                    'north_star_metric' => 'verified_change_success_rate',
                    'metrics' => ['verified_change_success_rate' => 0.93],
                    'observed_revenue_usd' => 25000,
                    'target_revenue_usd' => 100000,
                    'priority_score' => 88,
                ],
                'include_contracts' => true,
            ],
        );

        $this->assertSame(AtlasAutonomousChangeOrchestratorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertFalse(data_get($payload, 'orchestrator.mutation_authorized'));
        $this->assertTrue(data_get($payload, 'orchestrator.requires_aver_for_execution'));
        $this->assertCount(5, $payload['multi_step_plan']);
        $this->assertContains('verified_execution', array_column($payload['multi_step_plan'], 'name'));
        $this->assertContains('repairer', array_column($payload['multi_agent_schedule'], 'agent_role'));
        $this->assertSame('ready', data_get($payload, 'causal_verification.graph_status'));
        $this->assertSame('high', data_get($payload, 'causal_verification.confidence.label'));
        $this->assertSame(2, data_get($payload, 'repair_strategy.max_attempts'));
        $this->assertSame('atlas.autonomous_change_orchestrator.product_business_twin.v1', data_get($payload, 'product_business_twin.schema_version'));
        $this->assertSame('verified_change_success_rate', data_get($payload, 'product_business_twin.business_twin.business_model.metrics.north_star_metric'));
        $this->assertSame(25000.0, data_get($payload, 'product_business_twin.business_twin.business_model.revenue.observed_revenue_usd'));
        $this->assertSame('critical', data_get($payload, 'product_business_twin.business_twin.business_model.priority.band'));
        $this->assertSame('ready', data_get($payload, 'contracts.aver_plan.status'));
        $this->assertSame('ready', data_get($payload, 'contracts.aver_plan.verification_plan.causal_verification.graph_status'));
        $this->assertContains(
            'docs/engineering-knowledge-base/impact-graphrag-fixture.md',
            data_get($payload, 'contracts.aver_plan.evidence_refs')
        );

        $compact = app(AtlasAutonomousChangeOrchestratorService::class)->plan(
            'orquestrar mudanca causal verificada em AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
        );
        $this->assertSame('summary', data_get($compact, 'contracts.detail_level'));
        $this->assertTrue(data_get($compact, 'contracts.full_contracts_deferred'));
        $this->assertSame(
            'required_tests_deferred_until_execution_or_repair',
            data_get($compact, 'multi_step_plan.2.inputs.1')
        );
        $this->assertDatabaseCount('atlas_aver_executions', 2);

        $exit = Artisan::call('atlas:autonomous-change-orchestrator', [
            'action' => 'plan',
            '--objective' => 'orquestrar mudanca causal verificada em AVEOR',
            '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            '--changed-file' => ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'],
            '--json' => true,
            '--strict' => true,
        ]);
        $cliPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasAutonomousChangeOrchestratorService::SCHEMA_VERSION, $cliPayload['schema_version']);
        $this->assertSame('ready', $cliPayload['status']);
        $this->assertSame('summary', data_get($cliPayload, 'contracts.detail_level'));
        $this->assertTrue(data_get($cliPayload, 'contracts.full_contracts_deferred'));
    }

    private function seedImpactGraphRagFixture(): void
    {
        $moduleId = (string) Str::uuid();
        $targetSymbolId = (string) Str::uuid();
        $now = now();

        DB::table('atlas_engineering_code_modules')->insert([
            'id' => $moduleId,
            'slug' => 'engineering-verified-evolution',
            'name' => 'Engineering Verified Evolution',
            'layer' => 'engineering',
            'root_path' => 'app/Services/Engineering',
            'primary_language' => 'php',
            'status' => 'active',
            'owner' => 'engineering',
            'description' => 'Verified evolution runtime fixture.',
            'docs_status' => 'documented',
            'file_count' => 3,
            'symbol_count' => 4,
            'route_count' => 1,
            'command_count' => 1,
            'migration_count' => 0,
            'test_count' => 1,
            'source_hash' => hash('sha256', 'module'),
            'docs_hash' => hash('sha256', 'docs'),
            'tags_json' => $this->encodeJson(['verified-evolution']),
            'related_docs_json' => $this->encodeJson(['docs/engineering-knowledge-base/impact-graphrag-fixture.md']),
            'related_tests_json' => $this->encodeJson(['tests/Feature/Engineering/ImpactGraphRagFixtureTest.php']),
            'metadata' => $this->encodeJson([]),
            'indexed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('atlas_engineering_code_symbols')->insert([
            $this->codeSymbolRow([
                'id' => $targetSymbolId,
                'module_id' => $moduleId,
                'symbol_type' => 'class',
                'symbol_name' => 'App\\Services\\Engineering\\AtlasVerifiedEvolutionRuntimeService',
                'file_path' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
                'line_start' => 20,
                'line_end' => 460,
                'signature' => 'class AtlasVerifiedEvolutionRuntimeService',
                'namespace' => 'App\\Services\\Engineering',
                'source_hash' => hash('sha256', 'target-symbol'),
                'visibility' => 'public',
            ], $now),
            $this->codeSymbolRow([
                'module_id' => $moduleId,
                'symbol_type' => 'route',
                'symbol_name' => 'GET /api/atlas/verified-evolution/proof-plan',
                'file_path' => 'routes/api.php',
                'line_start' => 42,
                'line_end' => 42,
                'signature' => 'Route::get(...)',
                'source_hash' => hash('sha256', 'route-symbol'),
            ], $now),
            $this->codeSymbolRow([
                'module_id' => $moduleId,
                'symbol_type' => 'test_method',
                'symbol_name' => 'test_verified_evolution_impact_graphrag_fixture',
                'file_path' => 'tests/Feature/Engineering/ImpactGraphRagFixtureTest.php',
                'line_start' => 12,
                'line_end' => 30,
                'signature' => 'public function test_verified_evolution_impact_graphrag_fixture(): void',
                'visibility' => 'public',
                'source_hash' => hash('sha256', 'test-symbol'),
            ], $now),
        ]);

        DB::table('atlas_engineering_doc_links')->insert([
            'id' => (string) Str::uuid(),
            'knowledge_item_id' => null,
            'module_id' => $moduleId,
            'symbol_id' => $targetSymbolId,
            'link_type' => 'owner_doc',
            'status' => 'current',
            'canonical_path' => 'docs/engineering-knowledge-base/impact-graphrag-fixture.md',
            'target_path' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            'doc_hash' => hash('sha256', 'doc'),
            'target_hash' => hash('sha256', 'target'),
            'link_hash' => hash('sha256', 'doc-link'),
            'metadata' => $this->encodeJson([]),
            'indexed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function codeSymbolRow(array $overrides, mixed $now): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'module_id' => null,
            'symbol_type' => 'class',
            'symbol_name' => '',
            'file_path' => '',
            'line_start' => 1,
            'line_end' => 1,
            'language' => 'php',
            'signature' => '',
            'namespace' => null,
            'parent_symbol' => null,
            'visibility' => null,
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => hash('sha256', 'symbol'),
            'related_doc_ids_json' => $this->encodeJson([]),
            'metadata' => $this->encodeJson([]),
            'indexed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);
    }

    /**
     * @param  array<int|string,mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return (string) json_encode($value);
    }

    public function test_software_twin_snapshot_persists_living_system_twin(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->snapshot('app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php');

        $this->assertSame(AtlasSoftwareTwinRuntimeService::SNAPSHOT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue($payload['writes']);
        $this->assertNotEmpty($payload['snapshot_id']);
        $this->assertNotEmpty($payload['snapshot_hash']);
        $this->assertDatabaseCount('atlas_software_twin_snapshots', 1);
    }

    public function test_context_envelope_is_provider_safe_and_names_do_not_claim(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->contextEnvelope(
            'implementar AVEOR boundary contract',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $this->assertSame(AtlasSoftwareTwinRuntimeService::CONTEXT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue($payload['provider_safe']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md', $payload['minimal_sources']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-verified-execution-runtime.md', $payload['minimal_sources']);
        $this->assertContains('safe_to_edit_without_boundary_contract', $payload['do_not_claim']);
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', $payload['required_tests']);
    }

    public function test_verified_evolution_boundary_contract_limits_mutation_and_requires_aver(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->boundaryContract(
            'implementar ASTR e AVEOR com runtime read-only',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::BOUNDARY_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('boundary-contract', $payload['action']);
        $this->assertContains('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', data_get($payload, 'boundary_contract.allowed_write_paths'));
        $this->assertContains('unrelated_user_changes', data_get($payload, 'boundary_contract.do_not_touch_paths'));
        $this->assertFalse(data_get($payload, 'boundary_contract.mutation_policy.direct_mutation_authorized'));
        $this->assertTrue(data_get($payload, 'boundary_contract.mutation_policy.execution_must_go_through_aver'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_verified_evolution_proof_plan_requires_tests_gates_aver_and_aemor(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->proofPlan(
            'certificar runtime de software twin',
            'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::PROOF_PLAN_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', data_get($payload, 'proof_plan.required_tests'));
        $this->assertTrue(data_get($payload, 'proof_plan.aver_bridge.required'));
        $this->assertTrue(data_get($payload, 'proof_plan.aemor_bridge.required'));
        $this->assertContains('allowed_write_paths_respected', data_get($payload, 'proof_plan.completion_requires'));
        $this->assertContains('php artisan atlas:software-twin quality-score --json', data_get($payload, 'proof_plan.required_gates'));
    }

    public function test_verified_evolution_execution_contract_prepares_aver_plan_input(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->executionContract(
            'executar mudanca via AVER com prova',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::EXECUTION_CONTRACT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('ready_for_aver_plan', data_get($payload, 'execution_contract.status'));
        $this->assertSame('programming', data_get($payload, 'execution_contract.aver_plan_input.domain'));
        $this->assertSame('atlas_dev', data_get($payload, 'execution_contract.aver_plan_input.flow_id'));
        $this->assertContains('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', data_get($payload, 'execution_contract.aver_plan_input.allowed_write_paths'));
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', data_get($payload, 'execution_contract.aver_plan_input.verification_plan.required_tests'));
        $this->assertContains('scope_drift_watch_required', data_get($payload, 'execution_contract.pre_execution_guards'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_verified_evolution_drift_watch_blocks_changed_files_outside_boundary(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->driftWatch(
            'editar apenas AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            [
                'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
                'app/Services/Ai/UnrelatedRuntime.php',
            ]
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::SCOPE_DRIFT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('app/Services/Ai/UnrelatedRuntime.php', data_get($payload, 'scope_drift_watch.outside_boundary_files'));
        $this->assertContains('changed_file_outside_boundary', array_column($payload['blockers'], 'reason'));
        $this->assertTrue(data_get($payload, 'scope_drift_watch.requires_human_review'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_verified_evolution_patch_simulation_predicts_blast_radius_and_required_gates(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->patchSimulation(
            'simular patch AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php']
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::PATCH_SIMULATION_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('proceed_to_aver_plan', data_get($payload, 'patch_simulation.recommendation'));
        $this->assertContains('git diff --check', data_get($payload, 'patch_simulation.must_run'));
        $this->assertSame(0, data_get($payload, 'patch_simulation.predicted_blast_radius.outside_boundary_count'));
    }

    public function test_verified_evolution_outcome_bridge_records_aemor_outcome_with_evidence(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->outcomeBridge(
            'fechar outcome AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            'succeeded',
            ['test:aveor_outcome']
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::OUTCOME_BRIDGE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('recorded', data_get($payload, 'outcome_bridge.status'));
        $this->assertTrue(data_get($payload, 'outcome_bridge.writes'));
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('atlas_aemor_outcomes', 1);
    }

    public function test_verified_evolution_blocks_unknown_target_conservatively(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->boundaryContract(
            'editar runtime desconhecido',
            'NoSuchAtlasTwinTarget'
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_not_found_for_boundary', array_column($payload['blockers'], 'reason'));
        $this->assertSame([], data_get($payload, 'boundary_contract.allowed_write_paths'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_cli_actions_emit_canonical_json(): void
    {
        $softwareTwinCases = [
            ['twin', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'], AtlasSoftwareTwinRuntimeService::SCHEMA_VERSION],
            ['impact', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'], AtlasSoftwareTwinRuntimeService::IMPACT_SCHEMA_VERSION],
            ['context-envelope', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php', '--task' => 'implementar ASTR'], AtlasSoftwareTwinRuntimeService::CONTEXT_SCHEMA_VERSION],
            ['quality-score', [], AtlasSoftwareTwinRuntimeService::QUALITY_SCHEMA_VERSION],
            ['snapshot', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'], AtlasSoftwareTwinRuntimeService::SNAPSHOT_SCHEMA_VERSION],
        ];

        foreach ($softwareTwinCases as [$action, $options, $schema]) {
            $exit = Artisan::call('atlas:software-twin', array_merge(['action' => $action, '--json' => true, '--strict' => true], $options));
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, 'software-twin '.$action);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertSame($action === 'snapshot', $payload['writes']);
        }

        $verifiedEvolutionCases = [
            ['intent-lock', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::SCHEMA_VERSION],
            ['boundary-contract', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::BOUNDARY_SCHEMA_VERSION],
            ['proof-plan', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::PROOF_PLAN_SCHEMA_VERSION],
            ['execution-contract', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::EXECUTION_CONTRACT_SCHEMA_VERSION],
            ['drift-watch', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', '--changed-file' => ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php']], AtlasVerifiedEvolutionRuntimeService::SCOPE_DRIFT_SCHEMA_VERSION],
            ['patch-simulation', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', '--changed-file' => ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php']], AtlasVerifiedEvolutionRuntimeService::PATCH_SIMULATION_SCHEMA_VERSION],
            ['outcome-bridge', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', '--evidence' => ['test:cli_outcome']], AtlasVerifiedEvolutionRuntimeService::OUTCOME_BRIDGE_SCHEMA_VERSION],
            ['quality-score', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::QUALITY_SCHEMA_VERSION],
            ['evolution-envelope', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::SCHEMA_VERSION],
        ];

        foreach ($verifiedEvolutionCases as [$action, $options, $schema]) {
            $exit = Artisan::call('atlas:verified-evolution', array_merge(['action' => $action, '--json' => true, '--strict' => true], $options));
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, 'verified-evolution '.$action);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse((bool) ($payload['claim_policy']['authorizes_mutation'] ?? true));
        }
    }

    public function test_certification_service_and_command_are_ready(): void
    {
        $payload = app(AtlasSoftwareTwinVerifiedEvolutionCertificationService::class)->certify();

        $this->assertSame(AtlasSoftwareTwinVerifiedEvolutionCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(17, $payload['summary']['total']);
        $this->assertSame(17, $payload['summary']['passed']);
        $this->assertSame([], $payload['remaining_blockers']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);

        $exit = Artisan::call('atlas:software-twin-verified-evolution:certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $cliPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSoftwareTwinVerifiedEvolutionCertificationService::SCHEMA_VERSION, $cliPayload['schema_version']);
        $this->assertSame('ready', $cliPayload['status']);
    }
}
