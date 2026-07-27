<?php

namespace Tests\Unit\Ai\Programming;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use App\Models\AtlasToolRun;
use App\Services\Ai\Programming\ProgrammingContextPackStore;
use App\Services\Ai\Programming\ProgrammingLearningCandidateProjector;
use App\Services\Ai\Programming\ProgrammingLearningCandidateStore;
use App\Services\Ai\Programming\ProgrammingLearningPromotionGate;
use App\Services\Ai\Programming\ProgrammingPatchVerifier;
use App\Services\Ai\Programming\ProgrammingPatchVerifierBenchmarkService;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeContract;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeExecutor;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeGraphProjector;
use App\Services\Ai\Programming\ProgrammingRepairAttemptStore;
use App\Services\Ai\Programming\ProgrammingRepairExecutor;
use App\Services\Ai\Programming\ProgrammingRepairLoopBenchmarkService;
use App\Services\Ai\Programming\ProgrammingResumeService;
use App\Services\Ai\Programming\ProgrammingRetrievalBenchmarkService;
use App\Services\Ai\Programming\ProgrammingRetrievalPlanner;
use App\Services\Ai\Programming\ProgrammingRivalsReadinessService;
use App\Services\Ai\Programming\ProgrammingSandboxManager;
use App\Services\Ai\Programming\ProgrammingSemanticCodeGraphService;
use App\Services\Ai\Programming\ProgrammingStageReceiptStore;
use App\Services\Ai\Programming\ProgrammingStageReceiptValidator;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use App\Services\Ai\Programming\ProgrammingTestImpactBenchmarkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\TestCase;

class ProgrammingEnterpriseRuntimeTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;

    public function test_agentic_rag_plan_includes_required_sources_graph_and_sufficiency_gate(): void
    {
        $previousReceipt = app(ProgrammingStageReceiptStore::class)->make(
            planId: 'parent-plan-1',
            parentPlanId: null,
            stage: 'plan',
            attempt: 1,
            status: 'passed',
            input: ['objective' => 'repair'],
            output: ['decision' => 'continue'],
        );

        $plan = app(ProgrammingRetrievalPlanner::class)->plan(
            planId: 'plan-1',
            workspace: base_path(),
            objective: 'corrigir AtlasProgrammingOrchestrator repair tests',
            flow: 'programming.repair',
            options: [
                'quality_required' => true,
                'previous_stage_receipts' => [$previousReceipt],
            ],
        );

        $this->assertSame('atlas.programming.agentic_rag.plan.v1', $plan['schema_version']);
        $this->assertSame('programming.repair', $plan['flow']);
        $this->assertContains('code_symbols', $plan['required_sources']);
        $this->assertContains('related_tests', $plan['required_sources']);
        $this->assertSame('atlas.programming.context_sufficiency_gate.v1', data_get($plan, 'context_sufficiency_gate.schema_version'));
        $this->assertSame('atlas.programming.retrieval_receipt.v1', data_get($plan, 'retrieval_receipt.schema_version'));
        $this->assertSame('atlas.programming.context_pack.v1', data_get($plan, 'context_pack.schema_version'));
        $this->assertNotEmpty(data_get($plan, 'context_pack.ranked_refs'));
        $this->assertSame(true, data_get($plan, 'context_pack.provider_safe'));
        $this->assertSame('atlas.programming.agentic_rag.professional_plan.v1', data_get($plan, 'professional_plan.schema_version'));
        $this->assertSame('atlas.programming.context_pack.professional.v1', data_get($plan, 'professional_context_pack.schema_version'));
        $this->assertSame('promoted_programming_graph_rag_lexical', data_get($plan, 'professional_plan.retrieval_strategy'));
        $this->assertSame('atlas.programming.graph_rag_runtime.v1', data_get($plan, 'graph_rag_runtime.schema_version'));
        $this->assertSame('promoted', data_get($plan, 'graph_rag_runtime.status'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.promoted_runtime'));
        $this->assertSame('programming_only', data_get($plan, 'graph_rag_runtime.runtime_scope'));
        $this->assertGreaterThan(0, data_get($plan, 'graph_rag_runtime.evidence_ref_count'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.execution_policy.local_only'));
        $this->assertFalse(data_get($plan, 'graph_rag_runtime.execution_policy.provider_calls_allowed'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.ap_683_boundary.global_python_graph_rag_policy_unchanged'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.ap_683_boundary.programming_runtime_is_bounded_by_context_pack_receipts'));
        $this->assertSame('atlas.programming.graph_rag_runtime.v1', data_get($plan, 'professional_plan.graph_rag_runtime.schema_version'));
        $this->assertNotEmpty(data_get($plan, 'professional_context_pack.ranked_refs'));
        $this->assertSame('atlas.programming.agentic_rag.gap_critic.v1', data_get($plan, 'gap_critic.schema_version'));
        $this->assertSame('atlas.programming.retrieval_eval.v1', data_get($plan, 'retrieval_eval.schema_version'));
        $this->assertContains(data_get($plan, 'gap_critic.status'), ['passed', 'blocked']);
        if (data_get($plan, 'gap_critic.status') === 'blocked') {
            $this->assertNotEmpty(data_get($plan, 'gap_critic.missing_sources'));
            $this->assertSame('blocked', data_get($plan, 'context_sufficiency_gate.status'));
        } else {
            $this->assertSame([], data_get($plan, 'gap_critic.missing_sources'));
            $this->assertContains(data_get($plan, 'context_sufficiency_gate.status'), ['passed', 'blocked']);
        }
        $this->assertSame('atlas.programming.python_runtime.invocation_contract.v1', data_get($plan, 'python_runtime_contract.schema_version'));
        $this->assertFalse(data_get($plan, 'python_runtime_contract.execution.auto_execute_from_planner'));
        $this->assertFalse(data_get($plan, 'python_runtime_contract.manifest.runtime_policy.provider_calls_allowed'));
        $this->assertFalse(data_get($plan, 'python_runtime_contract.manifest.runtime_policy.network_calls_allowed'));
        $this->assertNotNull(data_get($plan, 'retrieval_receipt.context_pack_hash'));
        $this->assertIsInt(data_get($plan, 'semantic_code_graph.node_count'));
    }

    public function test_professional_context_pack_persists_replays_and_exposes_quality_metrics(): void
    {
        $this->createProgrammingContextPackTable();

        $plan = app(ProgrammingRetrievalPlanner::class)->plan(
            planId: 'plan-professional-rag-1',
            workspace: base_path(),
            objective: 'fortalecer agentic rag professional context pack executor',
            flow: 'programming.review',
            options: [
                'quality_required' => false,
                'max_refs' => 18,
                'max_chars' => 12000,
            ],
        );

        $contextPackHash = (string) data_get($plan, 'professional_context_pack.context_pack_hash');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contextPackHash);
        $this->assertTrue(data_get($plan, 'professional_context_pack.storage.persisted'));
        $this->assertDatabaseHas('atlas_programming_context_packs', [
            'plan_id' => 'plan-professional-rag-1',
            'context_pack_hash' => $contextPackHash,
            'schema_version' => 'atlas.programming.context_pack.professional.v1',
            'retrieval_strategy' => 'promoted_programming_graph_rag_lexical',
        ]);
        $this->assertNotEmpty(data_get($plan, 'professional_context_pack.metrics.required_source_coverage'));
        $this->assertIsFloat(data_get($plan, 'retrieval_eval.recall_at_k_proxy'));
        $this->assertFalse(data_get($plan, 'retrieval_eval.professional_promotion_allowed'));

        $replayed = app(ProgrammingContextPackStore::class)->findByHash($contextPackHash);

        $this->assertSame($contextPackHash, data_get($replayed, 'context_pack_hash'));
        $this->assertTrue(data_get($replayed, 'storage.replayed'));
    }

    public function test_professional_gap_critic_blocks_strict_execution_when_required_source_is_absent(): void
    {
        $plan = app(ProgrammingRetrievalPlanner::class)->plan(
            planId: 'plan-professional-rag-blocked',
            workspace: base_path(),
            objective: 'corrigir repair sem receipts anteriores',
            flow: 'programming.repair',
            options: [
                'quality_required' => true,
                'previous_stage_receipts' => [],
                'max_refs' => 10,
            ],
        );

        $this->assertSame('blocked', data_get($plan, 'gap_critic.status'));
        $this->assertTrue(data_get($plan, 'gap_critic.blocks_execution'));
        $this->assertContains('stage_receipts', data_get($plan, 'gap_critic.missing_sources'));
        $this->assertSame('blocked', data_get($plan, 'context_sufficiency_gate.status'));
        $this->assertContains('stage_receipts_unavailable_in_professional_context_pack', data_get($plan, 'context_sufficiency_gate.reasons'));
    }

    public function test_programming_retrieval_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingRetrievalBenchmarkService::class)->run(base_path());

        $this->assertSame('atlas.programming.retrieval_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_retrieval_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(3, data_get($report, 'golden_set.case_count'));
        $this->assertGreaterThanOrEqual(0.66, data_get($report, 'metrics.recall_at_k'));
        $this->assertTrue(data_get($report, 'promotion_gate.professional_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.graph_rag_runtime_promoted'));
        $this->assertSame('programming_only', data_get($report, 'promotion_gate.graph_rag_runtime_scope'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
        $this->assertSame('promoted', data_get($report, 'cases.0.graph_rag_runtime.status'));
        $this->assertSame('programming_only', data_get($report, 'cases.0.graph_rag_runtime.runtime_scope'));
        $this->assertSame('atlas.programming.retrieval_benchmark_runtime_cache.v1', data_get($report, 'runtime_cache.schema_version'));
        $this->assertFalse(data_get($report, 'runtime_cache.persistent_cache'));
        $this->assertFalse(data_get($report, 'runtime_cache.provider_state_cached'));

        $cachedReport = app(ProgrammingRetrievalBenchmarkService::class)->run(base_path());

        $this->assertTrue(data_get($cachedReport, 'runtime_cache.hit'));
    }

    public function test_programming_retrieval_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:retrieval-benchmark', [
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.retrieval_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(3, data_get($payload, 'golden_set.case_count'));
        $this->assertTrue(data_get($payload, 'promotion_gate.graph_rag_runtime_promoted'));
        $this->assertArrayHasKey('runtime_cache', $payload);

        $refreshExitCode = Artisan::call('atlas:programming:retrieval-benchmark', [
            '--workspace' => base_path(),
            '--refresh' => true,
            '--json' => true,
        ]);
        $refreshPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $refreshExitCode);
        $this->assertTrue(data_get($refreshPayload, 'runtime_cache.refresh_requested'));
        $this->assertFalse(data_get($refreshPayload, 'runtime_cache.hit'));
    }

    public function test_programming_test_impact_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingTestImpactBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.test_impact_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_test_impact_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(3, data_get($report, 'golden_set.case_count'));
        $this->assertSame(1.0, data_get($report, 'metrics.recall'));
        $this->assertTrue(data_get($report, 'promotion_gate.test_selection_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
    }

    public function test_programming_test_impact_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:test-impact-benchmark', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.test_impact_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(3, data_get($payload, 'golden_set.case_count'));
    }

    public function test_programming_patch_verifier_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingPatchVerifierBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.patch_verifier_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_patch_verifier_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(3, data_get($report, 'golden_set.case_count'));
        $this->assertSame(1.0, data_get($report, 'metrics.grounded_patch_rate'));
        $this->assertTrue(data_get($report, 'promotion_gate.grounded_patch_promotion_allowed'));
    }

    public function test_programming_patch_verifier_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:patch-verifier-benchmark', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.patch_verifier_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(3, data_get($payload, 'golden_set.case_count'));
    }

    public function test_programming_repair_loop_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingRepairLoopBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.repair_loop_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_repair_loop_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(4, data_get($report, 'golden_set.case_count'));
        $this->assertSame(1.0, data_get($report, 'metrics.repair_planning_pass_rate'));
        $this->assertSame(1.0, data_get($report, 'metrics.guard_case_pass_rate'));
        $this->assertTrue(data_get($report, 'metrics.receipt_integrity_passed'));
        $this->assertTrue(data_get($report, 'promotion_gate.repair_loop_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
    }

    public function test_programming_repair_loop_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:repair-loop-benchmark', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.repair_loop_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(4, data_get($payload, 'golden_set.case_count'));
    }

    public function test_programming_rivals_readiness_uses_tool_runtime_quality_evidence_when_scan_manifest_is_gone(): void
    {
        if (! Schema::hasTable('atlas_tool_runs')) {
            Schema::create('atlas_tool_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tool_definition_id')->nullable();
                $table->string('tool_slug');
                $table->string('surface');
                $table->string('workspace_hash')->nullable();
                $table->text('workspace')->nullable();
                $table->string('run_context_type')->nullable();
                $table->string('run_context_id')->nullable();
                $table->string('status');
                $table->boolean('required')->default(false);
                $table->string('failure_policy')->default('advisory');
                $table->string('policy_decision')->default('allowed');
                $table->string('command_hash')->nullable();
                $table->integer('exit_code')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->integer('duration_ms')->default(0);
                $table->uuid('stdout_artifact_id')->nullable();
                $table->uuid('stderr_artifact_id')->nullable();
                $table->json('summary_json')->nullable();
                $table->json('normalized_result_json')->nullable();
                $table->json('policy_decision_json')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        $workspace = storage_path('framework/testing/programming-readiness-'.Str::uuid());
        File::ensureDirectoryExists($workspace.'/atlas-visual-report');
        File::put($workspace.'/atlas-visual-report/manifest.json', json_encode([
            'status' => 'passed',
            'workspace_hash' => hash('sha256', $workspace),
            'artifact_dir' => 'atlas-visual-report',
            'strict_failure_summary' => [
                'route_failed_count' => 0,
                'screenshot_required_failed_count' => 0,
            ],
            'screenshot' => ['status' => 'skipped'],
            'screenshot_driver' => ['status' => 'missing'],
        ], JSON_THROW_ON_ERROR));

        $scanHash = hash('sha256', $workspace.'|quality-scan-tool-runtime-fallback');
        foreach (['composer_validate', 'laravel_pint'] as $tool) {
            AtlasToolRun::query()->create([
                'tool_slug' => $tool,
                'surface' => 'engineering_quality_scan',
                'workspace_hash' => hash('sha256', $workspace),
                'workspace' => $workspace,
                'status' => 'passed',
                'required' => false,
                'failure_policy' => 'advisory',
                'policy_decision' => 'allowed',
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 1,
                'summary_json' => [],
                'normalized_result_json' => [],
                'policy_decision_json' => [],
                'metadata_json' => [
                    'source' => 'engineering_quality_scan_service',
                    'scan_artifact_root_hash' => $scanHash,
                    'changed_only' => true,
                ],
            ]);
        }

        try {
            $report = app(ProgrammingRivalsReadinessService::class)->report($workspace);

            $this->assertSame('passed', data_get($report, 'current_local_recheck_evidence.status'));
            $this->assertSame('tool_runtime_evidence', data_get($report, 'current_local_recheck_evidence.quality_changed_only.source'));
            $this->assertSame($scanHash, data_get($report, 'current_local_recheck_evidence.quality_changed_only.artifact_root_hash'));
            $this->assertSame(2, data_get($report, 'current_local_recheck_evidence.quality_changed_only.tool_run_count'));
            $this->assertFalse(data_get($report, 'current_local_recheck_evidence.quality_changed_only.artifact_manifest_available'));
            $this->assertTrue(data_get($report, 'current_local_recheck_evidence.all_required_rechecks_known'));
            $this->assertTrue(data_get($report, 'current_local_recheck_evidence.all_known_rechecks_passed'));
            $this->assertFalse(data_get($report, 'current_local_recheck_evidence.provider_dispatches'));
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_python_runtime_executor_blocks_without_receipt_and_runs_when_approved(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-programming-python-runtime-'.Str::random(8);
        File::ensureDirectoryExists($workspace.'/app');
        File::put($workspace.'/app/Foo.php', "<?php\nclass Foo { function run() {} }\n");

        try {
            $contract = app(ProgrammingPythonRuntimeContract::class)->manifest(
                workspace: $workspace,
                files: ['app/Foo.php'],
            );

            $blocked = app(ProgrammingPythonRuntimeExecutor::class)->execute(
                contract: $contract,
                decisionReceiptHash: 'bad',
                runtimeBoundaryGreen: true,
                approved: true,
            );

            $this->assertSame('blocked', $blocked['status']);
            $this->assertContains('decision_receipt_hash_required', data_get($blocked, 'gate.reasons'));

            $receipt = app(ProgrammingPythonRuntimeExecutor::class)->execute(
                contract: $contract,
                decisionReceiptHash: str_repeat('a', 64),
                runtimeBoundaryGreen: true,
                approved: true,
            );

            $this->assertSame('atlas.programming.python_runtime.execution_receipt.v1', $receipt['schema_version']);
            $this->assertSame('passed', $receipt['status']);
            $this->assertSame('atlas.programming.python_runtime.analysis.v1', data_get($receipt, 'result.schema_version'));
            $this->assertFalse(data_get($receipt, 'result.runtime.provider_calls'));
            $this->assertSame('Foo', data_get($receipt, 'result.files.0.symbols.0.name'));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);

            $fragment = app(ProgrammingPythonRuntimeGraphProjector::class)->project($receipt);

            $this->assertSame('atlas.programming.python_runtime.graph_fragment.v1', $fragment['schema_version']);
            $this->assertSame('ready', $fragment['status']);
            $this->assertContains('declares_symbol', collect($fragment['edges'])->pluck('type')->all());
            $this->assertContains('Foo', collect($fragment['nodes'])->pluck('symbol')->filter()->all());
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_semantic_code_graph_prefers_indexed_code_intelligence_when_available(): void
    {
        $this->createAtlasEngineeringCodeTables();

        $module = AtlasEngineeringCodeModule::query()->create([
            'slug' => 'atlas-programming-orchestrator',
            'name' => 'Atlas Programming Orchestrator',
            'layer' => 'domain',
            'root_path' => 'app/Services/Ai/Programming',
            'primary_language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => str_repeat('a', 64),
            'related_docs_json' => ['docs/engineering-knowledge-base/domains/programming.md'],
            'related_tests_json' => ['tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php'],
            'metadata' => [],
        ]);

        $symbol = AtlasEngineeringCodeSymbol::query()->create([
            'module_id' => $module->id,
            'symbol_type' => 'class',
            'symbol_name' => 'AtlasProgrammingOrchestrator',
            'file_path' => 'app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php',
            'line_start' => 12,
            'line_end' => 560,
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => str_repeat('b', 64),
            'related_doc_ids_json' => [],
            'metadata' => [
                'imports' => ['App\Services\Ai\Programming\ProgrammingResumeService'],
            ],
        ]);

        AtlasEngineeringDocLink::query()->create([
            'module_id' => $module->id,
            'symbol_id' => $symbol->id,
            'link_type' => 'canonical_doc',
            'status' => 'current',
            'canonical_path' => 'docs/engineering-knowledge-base/domains/programming.md',
            'link_hash' => hash('sha256', 'programming-doc-link'),
            'metadata' => [],
        ]);

        $graph = app(ProgrammingSemanticCodeGraphService::class)->query(
            workspace: base_path(),
            objective: 'programming orchestrator resume repair',
            flow: 'programming.repair',
        );

        $this->assertSame('engineering_code_intelligence', $graph['source']);
        $this->assertGreaterThanOrEqual(3, $graph['node_count']);
        $this->assertContains('tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php', $graph['related_tests']);
        $this->assertContains('docs/engineering-knowledge-base/domains/programming.md', $graph['related_docs']);
        $this->assertContains('depends_on', collect($graph['edges'])->pluck('type')->all());
    }

    public function test_stage_receipts_validate_hashes_and_resume_blocks_invalid_timeline(): void
    {
        $store = app(ProgrammingStageReceiptStore::class);
        $receipt = $store->make('plan-1', null, 'plan', 1, 'passed', ['a' => 1], ['b' => 2]);

        $this->assertSame('atlas.programming.stage_receipt.v1', $receipt['schema_version']);
        $this->assertTrue(data_get($receipt, 'validation.valid'));

        $broken = $receipt;
        $broken['receipt_id'] = 'bad';
        $validation = app(ProgrammingStageReceiptValidator::class)->validate($broken);

        $this->assertFalse($validation['valid']);
        $this->assertContains('receipt_id_hash_mismatch', $validation['errors']);

        $patchReceipt = $store->make('plan-1', null, 'patch', 1, 'passed', ['a' => 1], ['b' => 2]);
        $reviewReceipt = $store->make('plan-1', null, 'review', 1, 'passed', ['a' => 1], ['b' => 2]);
        $timeline = app(ProgrammingStageReceiptValidator::class)->validateTimeline([$patchReceipt, $reviewReceipt]);

        $this->assertFalse($timeline['valid']);
        $this->assertContains('stage_order_regression', $timeline['errors']);
    }

    public function test_stage_receipts_can_persist_and_reload_timeline_for_resume(): void
    {
        $this->createProgrammingStageReceiptTable();

        $store = app(ProgrammingStageReceiptStore::class);
        $receipt = $store->make(
            planId: 'plan-resume-1',
            parentPlanId: 'parent-1',
            stage: 'patch',
            attempt: 2,
            status: 'passed',
            input: ['changed_files' => ['app/Services/Foo.php']],
            output: ['tests' => ['tests/Unit/FooTest.php']],
            evidenceRefs: ['tool:patch-verifier'],
            persist: true,
        );

        $this->assertTrue(data_get($receipt, 'storage.persisted'));
        $this->assertDatabaseHas('atlas_programming_stage_receipts', [
            'receipt_id' => $receipt['receipt_id'],
            'plan_id' => 'plan-resume-1',
            'stage' => 'patch',
            'attempt' => 2,
            'status' => 'passed',
        ]);

        $timeline = $store->timeline('plan-resume-1');

        $this->assertCount(1, $timeline);
        $this->assertSame('patch', data_get($timeline, '0.stage'));
        $this->assertSame('tool:patch-verifier', data_get($timeline, '0.evidence_refs.0'));
        $this->assertTrue(data_get($timeline, '0.validation.valid'));

        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-child-1',
            parentPlanId: 'plan-resume-1',
        );

        $this->assertSame('stage_receipt_store', $resume['previous_stage_receipt_source']);
        $this->assertSame(1, $resume['previous_stage_receipt_count']);
        $this->assertSame('patch', $resume['latest_stage']);
        $this->assertTrue($resume['resume_allowed']);
        $this->assertSame('atlas.programming.continuation_packet.v1', data_get($resume, 'continuation_packet.schema_version'));
        $this->assertSame('ready', data_get($resume, 'continuation_packet.status'));
        $this->assertSame('plan', data_get($resume, 'continuation_packet.next_stage'));
        $this->assertContains('load_stage_receipts', data_get($resume, 'continuation_packet.required_before_next_provider_call'));
        $this->assertTrue(data_get($resume, 'continuation_packet.stop_rules.missing_prior_decision_blocks_write'));
    }

    public function test_patch_verifier_test_impact_sandbox_repair_and_learning_contracts(): void
    {
        $impact = app(ProgrammingTestImpactAnalyzer::class)->analyze(
            changedFiles: ['app/Services/Foo.php'],
            codeGraph: ['related_tests' => ['tests/Unit/FooTest.php']],
            risk: 'high',
        );
        $this->assertSame('atlas.programming.test_impact.receipt.v1', $impact['schema_version']);
        $this->assertContains('tests/Unit/FooTest.php', $impact['selected_tests']);
        $this->assertSame([], $impact['selected_existing_tests']);
        $this->assertContains('/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php', $impact['recommended_commands']);
        $this->assertContains('npm run test:engineering', $impact['recommended_commands']);

        $blockedPatch = app(ProgrammingPatchVerifier::class)->verify([
            'changed_files' => ['app/Services/Foo.php'],
            'tests' => [],
            'action_manifests' => [],
        ]);
        $this->assertSame('blocked', $blockedPatch['status']);
        $this->assertContains('missing_tests_or_reason', $blockedPatch['blocking_reasons']);

        $passedPatch = app(ProgrammingPatchVerifier::class)->verify([
            'changed_files' => ['app/Services/Foo.php'],
            'tests' => ['tests/Unit/FooTest.php'],
            'action_manifests' => [[
                'schema_version' => 'atlas.programming.action_manifest.v1',
                'stage' => 'patch',
                'dry_run' => false,
                'changed_files' => ['app/Services/Foo.php'],
                'rollback' => ['available' => true],
                'gate_effect' => 'passed',
            ]],
        ]);
        $this->assertSame('passed', $passedPatch['status']);
        $this->assertSame(['app/Services/Foo.php'], $passedPatch['manifest_covered_files']);

        $sandbox = app(ProgrammingSandboxManager::class)->plan(base_path(), 'high', true);
        $this->assertSame('atlas.programming.execution_sandbox.plan.v1', $sandbox['schema_version']);
        $this->assertSame('isolated_worktree_required', $sandbox['mode']);
        $this->assertTrue($sandbox['promotion_requires_patch_verifier']);
        $this->assertSame('discard_isolated_worktree', data_get($sandbox, 'rollback_plan.strategy'));
        $this->assertTrue(data_get($sandbox, 'integrity_gate.must_attach_action_manifests'));
        $rollbackReceipt = app(ProgrammingSandboxManager::class)->rollbackReceipt($sandbox);
        $this->assertSame('atlas.programming.sandbox_rollback_receipt.v1', $rollbackReceipt['schema_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rollbackReceipt['receipt_hash']);

        $repair = app(ProgrammingRepairExecutor::class)->attemptPlan(
            failurePacket: ['failure_hash' => 'a'],
            retrievalPlan: ['retrieval_receipt' => ['receipt_id' => 'rag-1']],
            attempt: 1,
            maxAttempts: 3,
        );
        $this->assertSame('atlas.programming.repair_attempt.plan.v1', $repair['schema_version']);
        $this->assertSame('planned', $repair['status']);
        $this->assertSame('atlas.programming.repair_capsule.v1', data_get($repair, 'repair_capsule.schema_version'));
        $this->assertSame('unknown_failure', data_get($repair, 'repair_capsule.failure_taxonomy.category'));
        $this->assertFalse(data_get($repair, 'repair_capsule.provider_policy.fallback_allowed'));
        $this->assertTrue(data_get($repair, 'repair_capsule.verification_plan.patch_verifier_required'));
        $this->assertFalse(data_get($repair, 'repair_capsule.scope.allow_scope_expansion'));

        $repairReceipt = app(ProgrammingRepairAttemptStore::class)->receipt(
            planId: 'plan-1',
            parentPlanId: null,
            attempt: 1,
            status: 'failed',
            failurePacket: ['failure_hash' => 'failure-1'],
            patchManifest: [
                'schema_version' => 'atlas.programming.action_manifest.v1',
                'action_id' => 'patch-1',
            ],
            testManifest: [
                'schema_version' => 'atlas.programming.action_manifest.v1',
                'action_id' => 'test-1',
            ],
        );
        $this->assertSame('atlas.programming.stage_receipt.v1', $repairReceipt['schema_version']);
        $this->assertSame('atlas.programming.repair_attempt.receipt.v1', data_get($repairReceipt, 'repair_attempt.repair_attempt_schema'));
        $this->assertContains('manifest:patch-1', $repairReceipt['evidence_refs']);

        $candidate = app(ProgrammingLearningCandidateProjector::class)->project([
            'status' => 'passed',
            'evidence_refs' => ['stage:1'],
        ]);
        $this->assertSame('atlas.programming.learning_candidate.v1', $candidate['schema_version']);
        $this->assertFalse($candidate['promotion_allowed']);
        $this->assertSame('reject_candidate_without_memory_promotion', data_get($candidate, 'rollback.strategy'));

        $gate = app(ProgrammingLearningPromotionGate::class)->evaluate($candidate, humanReviewed: true);
        $this->assertSame('atlas.programming.learning_promotion_gate.v1', $gate['schema_version']);
        $this->assertTrue($gate['promotion_allowed']);
    }

    public function test_learning_candidates_are_queued_deduped_and_promoted_only_after_review_gate(): void
    {
        $this->createProgrammingLearningCandidateTable();

        $candidate = app(ProgrammingLearningCandidateProjector::class)->project([
            'status' => 'passed',
            'evidence_refs' => ['stage:plan', 'manifest:test'],
        ]);
        $store = app(ProgrammingLearningCandidateStore::class);
        $queued = $store->enqueue($candidate);
        $queuedAgain = $store->enqueue($candidate);

        $this->assertTrue(data_get($queued, 'review_queue.queued'));
        $this->assertSame(data_get($queued, 'review_queue.candidate_id'), data_get($queuedAgain, 'review_queue.candidate_id'));
        $this->assertDatabaseCount('atlas_programming_learning_candidates', 1);
        $this->assertSame(1, count($store->pending()));

        $blocked = app(ProgrammingLearningPromotionGate::class)->evaluate($candidate, humanReviewed: false);
        $blockedRecord = $store->recordPromotionGate($candidate['candidate_hash'], $blocked);
        $this->assertTrue($blockedRecord['recorded']);
        $this->assertFalse($blockedRecord['promotion_allowed']);

        $approved = app(ProgrammingLearningPromotionGate::class)->evaluate($candidate, humanReviewed: true);
        $approvedRecord = $store->recordPromotionGate($candidate['candidate_hash'], $approved);
        $this->assertTrue($approvedRecord['recorded']);
        $this->assertTrue($approvedRecord['promotion_allowed']);
        $this->assertDatabaseHas('atlas_programming_learning_candidates', [
            'candidate_hash' => $candidate['candidate_hash'],
            'status' => 'promotion_approved',
            'promotion_allowed' => true,
        ]);
    }

    public function test_high_risk_write_sandbox_provisions_isolated_git_worktree(): void
    {
        $repo = sys_get_temp_dir().'/atlas-programming-sandbox-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($repo);

        try {
            $this->runGit(['git', 'init'], $repo);
            File::put($repo.'/a.txt', 'original');
            $this->runGit(['git', 'add', 'a.txt'], $repo);
            $this->runGit(['git', '-c', 'user.email=atlas@example.test', '-c', 'user.name=Atlas Test', 'commit', '-m', 'initial'], $repo);

            $sandbox = app(ProgrammingSandboxManager::class)->provision($repo, 'high', true);

            $this->assertTrue($sandbox['provisioned']);
            $this->assertSame('git_worktree', $sandbox['provisioning_mode']);
            $this->assertIsString($sandbox['execution_workspace']);
            $this->assertTrue(File::exists($sandbox['execution_workspace'].'/a.txt'));

            File::put($sandbox['execution_workspace'].'/a.txt', 'changed in sandbox');
            $this->assertSame('original', File::get($repo.'/a.txt'));

            $this->runGit(['git', 'worktree', 'remove', '--force', $sandbox['execution_workspace']], $repo);
        } finally {
            File::deleteDirectory($repo);
        }
    }

    public function test_resume_command_replays_parent_plan_from_persisted_receipts(): void
    {
        $this->createProgrammingStageReceiptTable();

        app(ProgrammingStageReceiptStore::class)->make(
            planId: 'parent-plan-command',
            parentPlanId: null,
            stage: 'test',
            attempt: 1,
            status: 'passed',
            input: ['selected_tests' => ['tests/Unit/FooTest.php']],
            output: ['status' => 'passed'],
            evidenceRefs: ['manifest:test'],
            persist: true,
        );

        $exitCode = Artisan::call('atlas:programming:resume', [
            'parent_plan_id' => 'parent-plan-command',
            '--plan-id' => 'child-plan-command',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('atlas.programming.resume_state.v1', data_get($payload, 'resume_state.schema_version'));
        $this->assertSame('stage_receipt_store', data_get($payload, 'resume_state.previous_stage_receipt_source'));
        $this->assertSame('test', data_get($payload, 'resume_state.latest_stage'));
        $this->assertSame('atlas.programming.continuation_packet.v1', data_get($payload, 'resume_state.continuation_packet.schema_version'));
        $this->assertSame('plan', data_get($payload, 'resume_state.continuation_packet.next_stage'));
        $this->assertStringContainsString('parent-plan-command', data_get($payload, 'resume_state.continuation_packet.resume_command'));
        $this->assertSame('continue_from_latest_stage', $payload['next_action']);
    }

    private function createProgrammingStageReceiptTable(): void
    {
        Schema::dropIfExists('atlas_programming_stage_receipts');

        Schema::create('atlas_programming_stage_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('stage', 40)->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 32)->index();
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->json('evidence_refs_json')->default('[]');
            $table->json('payload_json')->default('{}');
            $table->json('validation_json')->default('{}');
            $table->timestamps();
        });
    }

    private function createProgrammingLearningCandidateTable(): void
    {
        Schema::dropIfExists('atlas_programming_learning_candidates');

        Schema::create('atlas_programming_learning_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('candidate_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('source_status', 40)->nullable()->index();
            $table->boolean('promotion_allowed')->default(false)->index();
            $table->boolean('review_required')->default(true)->index();
            $table->json('evidence_refs_json')->default('[]');
            $table->json('payload_json')->default('{}');
            $table->json('promotion_gate_json')->default('{}');
            $table->json('rollback_json')->default('{}');
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function createProgrammingContextPackTable(): void
    {
        Schema::dropIfExists('atlas_programming_context_packs');

        Schema::create('atlas_programming_context_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('context_pack_hash', 64)->unique();
            $table->string('schema_version', 120)->index();
            $table->string('status', 32)->index();
            $table->boolean('provider_safe')->default(true)->index();
            $table->string('retrieval_strategy', 80)->index();
            $table->json('ranked_refs_json')->default('[]');
            $table->json('excluded_refs_json')->default('[]');
            $table->json('source_counts_json')->default('{}');
            $table->json('metrics_json')->default('{}');
            $table->json('budget_json')->default('{}');
            $table->json('payload_json')->default('{}');
            $table->timestamps();
        });
    }

    /**
     * @param  array<int,string>  $command
     */
    private function runGit(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
