<?php

namespace Tests\Unit\Ai\Programming;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use App\Services\Ai\Programming\ProgrammingLearningCandidateProjector;
use App\Services\Ai\Programming\ProgrammingLearningPromotionGate;
use App\Services\Ai\Programming\ProgrammingPatchVerifier;
use App\Services\Ai\Programming\ProgrammingRepairExecutor;
use App\Services\Ai\Programming\ProgrammingRetrievalPlanner;
use App\Services\Ai\Programming\ProgrammingResumeService;
use App\Services\Ai\Programming\ProgrammingSandboxManager;
use App\Services\Ai\Programming\ProgrammingStageReceiptStore;
use App\Services\Ai\Programming\ProgrammingStageReceiptValidator;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\TestCase;

class ProgrammingEnterpriseRuntimeTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;

    public function test_agentic_rag_plan_includes_required_sources_graph_and_sufficiency_gate(): void
    {
        $plan = app(ProgrammingRetrievalPlanner::class)->plan(
            planId: 'plan-1',
            workspace: base_path(),
            objective: 'corrigir AtlasProgrammingOrchestrator repair tests',
            flow: 'programming.repair',
            options: [
                'quality_required' => true,
                'previous_stage_receipts' => [
                    ['schema_version' => 'atlas.programming.stage_receipt.v1'],
                ],
            ],
        );

        $this->assertSame('atlas.programming.agentic_rag.plan.v1', $plan['schema_version']);
        $this->assertSame('programming.repair', $plan['flow']);
        $this->assertContains('code_symbols', $plan['required_sources']);
        $this->assertContains('related_tests', $plan['required_sources']);
        $this->assertSame('atlas.programming.context_sufficiency_gate.v1', data_get($plan, 'context_sufficiency_gate.schema_version'));
        $this->assertSame('atlas.programming.retrieval_receipt.v1', data_get($plan, 'retrieval_receipt.schema_version'));
        $this->assertIsInt(data_get($plan, 'semantic_code_graph.node_count'));
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
            'metadata' => [],
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

        $graph = app(\App\Services\Ai\Programming\ProgrammingSemanticCodeGraphService::class)->query(
            workspace: base_path(),
            objective: 'programming orchestrator resume repair',
            flow: 'programming.repair',
        );

        $this->assertSame('engineering_code_intelligence', $graph['source']);
        $this->assertGreaterThanOrEqual(3, $graph['node_count']);
        $this->assertContains('tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php', $graph['related_tests']);
        $this->assertContains('docs/engineering-knowledge-base/domains/programming.md', $graph['related_docs']);
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

        $repair = app(ProgrammingRepairExecutor::class)->attemptPlan(
            failurePacket: ['failure_hash' => 'a'],
            retrievalPlan: ['retrieval_receipt' => ['receipt_id' => 'rag-1']],
            attempt: 1,
            maxAttempts: 3,
        );
        $this->assertSame('atlas.programming.repair_attempt.plan.v1', $repair['schema_version']);
        $this->assertSame('planned', $repair['status']);

        $candidate = app(ProgrammingLearningCandidateProjector::class)->project([
            'status' => 'passed',
            'evidence_refs' => ['stage:1'],
        ]);
        $this->assertSame('atlas.programming.learning_candidate.v1', $candidate['schema_version']);
        $this->assertFalse($candidate['promotion_allowed']);

        $gate = app(ProgrammingLearningPromotionGate::class)->evaluate($candidate, humanReviewed: true);
        $this->assertSame('atlas.programming.learning_promotion_gate.v1', $gate['schema_version']);
        $this->assertTrue($gate['promotion_allowed']);
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
}
