<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

/**
 * Fase 1 — production writers emit MULTX-01 assembler shape (no fixture:true).
 */
final class MarcoEspV1WriterShapeTest extends TestCase
{
    use CreatesAemorTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAemorTables();
        (require database_path('migrations/2026_07_09_153500_repair_missing_ai_memory_deltas_table.php'))->up();
        if (! Schema::hasTable('atlas_aaeos_test_run_receipts')) {
            (require database_path('migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php'))->up();
        }
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
        config()->set('atlas.aemor.engineering_outcome_enabled', true);
        config()->set('atlas.aemor.engineering_outcome_mode', 'default');
        config()->set('atlas.ai.capture_quality_gate.mode', 'observe');
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_learning_proposals',
            'ai_rag_feedback_events',
            'ai_learning_candidates',
            'ai_run_outcomes',
            'ai_memory_deltas',
            'atlas_aaeos_test_run_receipts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        $this->dropAemorTables();
        parent::tearDown();
    }

    public function test_aemor_spine_stamps_proven_real_decision_id_and_flywheel_candidate(): void
    {
        $receipt = AtlasAaeosTestRunReceipt::query()->create([
            'capability_id' => 'marco.writer.shape',
            'test_ref' => self::class.'::test_aemor_spine_stamps_proven_real_decision_id_and_flywheel_candidate',
            'filter' => 'test_aemor_spine_stamps_proven_real_decision_id_and_flywheel_candidate',
            'passed' => true,
            'tests_run' => 1,
            'exit_code' => 0,
            'metadata' => ['attribution_reviewed' => true],
            'ran_at' => now(),
        ]);

        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'autonomos',
            'objective' => 'Land a scoped change with evidence.',
            'workspace' => base_path(),
            'status' => 'succeeded',
            'summary' => 'Scoped change passed verification.',
            'task_id' => 'task-marco-writer-1',
            'run_id' => 'run-marco-writer-1',
            'scope_id' => 'task-marco-writer-1',
            'decision_id' => 'decision-marco-writer-1',
            'decision_receipt_id' => 'decision-marco-writer-1',
            'evidence_refs' => ['test_run_receipt:'.$receipt->id, 'commit:abc123'],
            'metrics' => [
                'tests_passed' => true,
                'attribution_reviewed' => true,
                'server_verified' => true,
            ],
            'verified' => true,
            'learning_claim' => 'Keep decision_id and proven_real on the outcome payload the flywheel assembler reads.',
        ]);

        $this->assertSame('recorded', $result['status']);
        $this->assertTrue((bool) data_get($result, 'spine.ai_run_outcome.proven_real'));
        $this->assertSame('decision-marco-writer-1', data_get($result, 'spine.ai_run_outcome.decision_id'));
        $this->assertSame('candidate', data_get($result, 'flywheel_learning.status'));

        $payload = json_decode((string) DB::table('ai_run_outcomes')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['proven_real'] ?? false);
        $this->assertSame('decision-marco-writer-1', $payload['decision_id'] ?? null);
        $this->assertSame('task-marco-writer-1', $payload['task_id'] ?? null);

        $outcomeId = (string) DB::table('ai_run_outcomes')->value('id');
        $this->assertDatabaseHas('ai_learning_candidates', [
            'run_outcome_id' => $outcomeId,
        ]);

        $assembled = app(AcosMaxLote2MeasureService::class)->multx01FlywheelLoops();
        $partial = collect($assembled['loops_partial'] ?? [])->firstWhere('chain.outcome_id', $outcomeId);
        $this->assertIsArray($partial);
        $this->assertNotContains('outcome_not_proven_real', $partial['blocked_by']);
        $this->assertNotContains('decision_receipt_missing', $partial['blocked_by']);
        $this->assertNotContains('learning_candidate_missing', $partial['blocked_by']);
        $this->assertContains('delivered_context_missing', $partial['blocked_by']);
        $this->assertNotEmpty($assembled['blocked_by_top'] ?? []);
    }

    public function test_rag_feedback_with_run_outcome_id_clears_delivered_context_gap(): void
    {
        $receipt = AtlasAaeosTestRunReceipt::query()->create([
            'capability_id' => 'marco.writer.arfl',
            'test_ref' => self::class.'::test_rag_feedback_with_run_outcome_id_clears_delivered_context_gap',
            'filter' => 'test_rag_feedback_with_run_outcome_id_clears_delivered_context_gap',
            'passed' => true,
            'tests_run' => 1,
            'exit_code' => 0,
            'metadata' => ['attribution_reviewed' => true],
            'ran_at' => now(),
        ]);

        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'dev',
            'objective' => 'Dev pipeline with ARFL join.',
            'workspace' => base_path(),
            'status' => 'succeeded',
            'summary' => 'Dev passed.',
            'task_id' => 'dev-marco-arfl-1',
            'run_id' => 'dev-marco-arfl-1',
            'decision_id' => 'verification_receipt:dev-marco-arfl-1',
            'evidence_refs' => ['test_run_receipt:'.$receipt->id, 'verification_receipt:dev-marco-arfl-1'],
            'metrics' => [
                'tests_passed' => true,
                'attribution_reviewed' => true,
                'server_verified' => true,
            ],
            'verified' => true,
            'learning_claim' => 'ARFL must join run_outcome_id for delivered context.',
        ]);

        $outcomeId = (string) data_get($result, 'spine.ai_run_outcome.id');
        $this->assertNotSame('', $outcomeId);

        app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'Dev pipeline with ARFL join.',
            'workspace' => base_path(),
            'task_type' => 'dev',
            'domain' => 'atlas',
            'outcome_status' => 'passed',
            'retrieval_receipt_id' => 'retrieval-dev-marco-arfl-1',
            'run_outcome_id' => $outcomeId,
            'delivered_context_refs' => ['code:module:example'],
            'used_context_refs' => ['code:module:example'],
            'noise_context_refs' => [],
            'flow_id' => 'atlas.dev',
            'record' => true,
            'post_execution_utility' => 80,
        ]);

        $candidateId = (string) data_get($result, 'flywheel_learning.candidate_id');
        $this->assertNotSame('', $candidateId);

        // Subsequent measured recall (2nd feedback after candidate exists).
        app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'Recall the lesson.',
            'workspace' => base_path(),
            'task_type' => 'dev',
            'domain' => 'atlas',
            'outcome_status' => 'passed',
            'retrieval_receipt_id' => 'recall-dev-marco-arfl-1',
            'memory_candidate_id' => $candidateId,
            'delivered_context_refs' => ['memory:'.$candidateId],
            'used_context_refs' => ['memory:'.$candidateId],
            'noise_context_refs' => [],
            'flow_id' => 'atlas.dev',
            'record' => true,
            'post_execution_utility' => 90,
        ]);

        $assembled = app(AcosMaxLote2MeasureService::class)->multx01FlywheelLoops();
        $this->assertGreaterThanOrEqual(1, (int) ($assembled['loops_complete'] ?? 0));
        $this->assertTrue((bool) data_get($assembled, 'marco_esp_v1.satisfied'));
        $loop = collect($assembled['loops'] ?? [])->firstWhere('chain.outcome_id', $outcomeId);
        $this->assertIsArray($loop);
        $this->assertTrue($loop['proven_real']);
        $this->assertTrue($loop['fixture_free']);
    }
}
