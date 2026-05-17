<?php

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiBenchmarkCase;
use App\Models\AiCompoundingMemory;
use App\Models\AiHeuristicUpdate;
use App\Models\AiLearningCandidate;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AiTemporalCertification;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Compounding\AtlasHeuristicEvolutionService;
use App\Services\Ai\Compounding\AtlasTemporalCertificationService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasCompoundingRuntimeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompoundingSchema();
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_records_execution_into_outcome_candidate_memory_rag_benchmark_heuristic_and_temporal_certification(): void
    {
        $result = app(AtlasCompoundingRuntimeService::class)->recordExecution($this->executionPayload('runtime-full'));

        $this->assertSame('atlas.ai.compounding.runtime_record.v1', $result['schema_version']);
        $this->assertSame('recorded', $result['status']);
        $this->assertNotNull($result['compounding_memory']);
        $this->assertNotNull($result['rag_feedback']);
        $this->assertNotNull($result['benchmark_case']);
        $this->assertNotNull($result['heuristic_update']);
        $this->assertSame('atlas.ai.compounding.outcome.v1', AiRunOutcome::query()->firstOrFail()->schema_version);
        $this->assertSame('atlas.ai.compounding.learning_candidate.v1', AiLearningCandidate::query()->firstOrFail()->schema_version);
        $this->assertSame('atlas.ai.compounding.memory.v1', AiCompoundingMemory::query()->firstOrFail()->schema_version);
        $this->assertSame('atlas.ai.rag.feedback.v1', AiRagFeedbackEvent::query()->firstOrFail()->schema_version);
        $this->assertSame('atlas.ai.compounding.benchmark_case.v1', AiBenchmarkCase::query()->firstOrFail()->schema_version);
        $this->assertSame('atlas.ai.compounding.heuristic_update.v1', AiHeuristicUpdate::query()->firstOrFail()->schema_version);
        $this->assertSame('atlas.ai.compounding.temporal_certification.v1', AiTemporalCertification::query()->firstOrFail()->schema_version);
    }

    public function test_blocks_memory_promotion_without_evidence_refs(): void
    {
        $result = app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'runtime-no-evidence',
            'flow_id' => 'atlas_debug',
            'outcome_status' => 'failed',
            'flow_quality' => 30,
            'retrieval_quality' => 20,
            'execution_quality' => 30,
            'evidence_quality' => 0,
            'evidence_refs' => [],
            'learning_signal' => [
                'claim' => 'Raw failure without evidence must not become operational memory.',
                'confidence' => 95,
                'evidence_refs' => [],
            ],
        ]);

        $this->assertNull($result['compounding_memory']);
        $this->assertSame('learning_candidate_not_promotable', $result['memory_blocked_reason']);
        $this->assertSame(0, AiCompoundingMemory::query()->count());
        $this->assertSame('held_for_evidence', AiLearningCandidate::query()->firstOrFail()->status);
    }

    public function test_heuristic_update_is_auditable_and_reversible(): void
    {
        $service = app(AtlasHeuristicEvolutionService::class);
        $update = $service->propose([
            'heuristic_key' => 'router.debug_failure_language',
            'flow_id' => 'atlas_debug',
            'before_state' => ['weight' => 0.55],
            'after_state' => ['weight' => 0.7],
            'evidence_refs' => ['outcome:runtime-full'],
            'rollback_plan' => ['restore' => ['weight' => 0.55]],
            'test_refs' => ['tests/Unit/Ai/Compounding/AtlasCompoundingRuntimeServiceTest.php'],
            'apply' => true,
        ]);

        $this->assertSame('applied', $update->status);
        $this->assertSame(['weight' => 0.55], $update->before_state);
        $this->assertSame(['weight' => 0.7], $update->after_state);
        $this->assertNotSame('', $update->receipt_hash);

        $rolledBack = $service->rollBack($update);

        $this->assertSame('rolled_back', $rolledBack->status);
        $this->assertNotNull($rolledBack->rolled_back_at);
    }

    public function test_temporal_certification_reports_improved_regressed_and_inconclusive_flows(): void
    {
        $previous = now()->subDays(10);
        $current = now()->subDays(2);

        $this->createOutcome('prev-improved', 'atlas_debug', 40, $previous);
        $this->createOutcome('curr-improved', 'atlas_debug', 80, $current);
        $this->createOutcome('prev-regressed', 'atlas_review', 90, $previous);
        $this->createOutcome('curr-regressed', 'atlas_review', 50, $current);
        $this->createOutcome('curr-inconclusive', 'atlas_research', 75, $current);

        $certification = app(AtlasTemporalCertificationService::class)->certify();

        $this->assertSame('improved', data_get($certification->flow_deltas, 'atlas_debug.status'));
        $this->assertSame('regressed', data_get($certification->flow_deltas, 'atlas_review.status'));
        $this->assertSame('inconclusive', data_get($certification->flow_deltas, 'atlas_research.status'));
        $this->assertFalse(data_get($certification->claim_policy, 'ready_to_claim_100x'));
        $this->assertFalse(data_get($certification->claim_policy, 'ready_to_replace_claude_code_codex'));
    }

    private function createOutcome(string $runId, string $flowId, int $score, mixed $createdAt): void
    {
        $outcome = AiRunOutcome::query()->create([
            'run_id' => $runId,
            'flow_id' => $flowId,
            'outcome_status' => 'passed',
            'flow_quality' => $score,
            'retrieval_quality' => $score,
            'execution_quality' => $score,
            'evidence_quality' => $score,
            'learning_required' => false,
            'evidence_refs' => ['receipt:'.$runId],
            'payload' => [],
            'outcome_hash' => hash('sha256', $runId),
            'evaluated_at' => $createdAt,
        ]);
        $outcome->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    /**
     * @return array<string,mixed>
     */
    private function executionPayload(string $runId): array
    {
        return [
            'run_id' => $runId,
            'flow_id' => 'atlas_debug',
            'outcome_status' => 'failed',
            'prompt' => 'Debug this router failure',
            'flow_quality' => 62,
            'retrieval_quality' => 58,
            'execution_quality' => 55,
            'evidence_quality' => 92,
            'evidence_refs' => ['receipt:runtime-full', 'test:compounding-runtime'],
            'learning_signal' => [
                'claim' => 'Debug flows need retrieval feedback before future repair attempts.',
                'memory_type' => 'debug_memory',
                'scope' => 'atlas-server',
                'confidence' => 86,
                'flow_id' => 'atlas_debug',
                'evidence_refs' => ['receipt:runtime-full', 'test:compounding-runtime'],
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => 'retr_runtime_full',
                'query_plan_hash' => str_repeat('b', 64),
                'included_sources' => 5,
                'used_sources' => 3,
                'noise_sources' => 2,
                'missed_required_sources' => ['tests/Unit/Ai/Router/AtlasAiRouterServiceTest.php'],
                'context_sufficiency' => 72,
                'post_execution_utility' => 75,
                'source_utility' => ['docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md' => 'useful'],
            ],
            'benchmark_case' => [
                'force' => true,
                'source' => 'real_user_run',
                'expected_flow' => 'atlas_debug',
                'required_evidence' => ['receipt:runtime-full'],
                'rivals' => ['claude_code', 'codex'],
            ],
            'heuristic_update' => [
                'heuristic_key' => 'router.debug_failure_language',
                'flow_id' => 'atlas_debug',
                'before_state' => ['debug_signal_weight' => 0.55],
                'after_state' => ['debug_signal_weight' => 0.7],
                'evidence_refs' => ['outcome:runtime-full'],
                'rollback_plan' => ['restore_debug_signal_weight' => 0.55],
                'test_refs' => ['tests/Unit/Ai/Compounding/AtlasCompoundingRuntimeServiceTest.php'],
                'apply' => true,
            ],
        ];
    }

    private function bootCompoundingSchema(): void
    {
        $this->dropCompoundingSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
