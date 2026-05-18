<?php

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiBenchmarkCase;
use App\Models\AiCompoundingMemory;
use App\Models\AiHeuristicUpdate;
use App\Models\AiLearningCandidate;
use App\Models\AiLearningProposal;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AiTemporalCertification;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Compounding\AtlasHeuristicEvolutionService;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Compounding\AtlasTemporalCertificationService;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
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

    public function test_successful_run_records_positive_rag_feedback_without_proposal(): void
    {
        $result = app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'runtime-success',
            'flow_id' => 'atlas_dev',
            'outcome_status' => 'passed',
            'flow_quality' => 90,
            'retrieval_quality' => 88,
            'execution_quality' => 92,
            'evidence_quality' => 90,
            'evidence_refs' => ['receipt:runtime-success'],
            'learning_signal' => [
                'claim' => 'Successful run informs future routing.',
                'confidence' => 86,
                'evidence_refs' => ['receipt:runtime-success'],
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => 'retr_success',
                'included_sources' => 5,
                'used_sources' => 5,
                'noise_sources' => 0,
                'missed_required_sources' => [],
                'context_sufficiency' => 88,
                'post_execution_utility' => 86,
                'source_utility' => [
                    'docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md' => 'useful',
                ],
            ],
        ]);

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame('passed', $event->outcome_status);
        $this->assertNull($event->failure_reason);
        $this->assertNull($event->next_retrieval_hint);
        $this->assertNotNull($event->memory_candidate_id);
        $this->assertNull($event->learning_proposal_id);
        $this->assertSame([], $result['learning_proposals']);
    }

    public function test_failure_with_missed_sources_creates_retrieval_hint_proposal(): void
    {
        $result = app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'runtime-missed',
            'flow_id' => 'atlas_debug',
            'outcome_status' => 'failed',
            'flow_quality' => 40,
            'retrieval_quality' => 35,
            'execution_quality' => 38,
            'evidence_quality' => 60,
            'evidence_refs' => ['receipt:runtime-missed'],
            'failure_reason' => 'retrieval_gap',
            'learning_signal' => [
                'claim' => 'Failure with missed sources must propose retrieval hint.',
                'confidence' => 72,
                'evidence_refs' => ['receipt:runtime-missed'],
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => 'retr_missed',
                'included_sources' => 4,
                'used_sources' => 1,
                'noise_sources' => 3,
                'missed_required_sources' => [
                    'tests/Unit/Ai/Router/AtlasAiRouterServiceTest.php',
                    'app/Services/Ai/Router/AtlasAiRouterService.php',
                ],
                'context_sufficiency' => 40,
                'post_execution_utility' => 25,
                'source_utility' => [
                    'docs/legacy.md' => 'noise',
                ],
            ],
        ]);

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame('failed', $event->outcome_status);
        $this->assertSame('retrieval_gap', $event->failure_reason);

        $hint = $event->next_retrieval_hint;
        $this->assertIsArray($hint);
        $this->assertContains('missed_required_sources', $hint['reasons']);
        $this->assertContains('low_context_sufficiency', $hint['reasons']);
        $this->assertFalse($hint['auto_apply']);

        $this->assertNotEmpty($result['learning_proposals']);
        $proposal = AiLearningProposal::query()->firstOrFail();
        $this->assertSame('proposed', $proposal->status);
        $this->assertSame('retrieval_hint', $proposal->kind);
        $this->assertTrue($proposal->requires_human_review);
        $this->assertNotEmpty($proposal->evidence_refs);
        $this->assertSame($proposal->id, $event->fresh()->learning_proposal_id);
    }

    public function test_noise_sources_are_registered_on_feedback_event(): void
    {
        app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'runtime-noise',
            'flow_id' => 'atlas_review',
            'outcome_status' => 'passed',
            'flow_quality' => 80,
            'retrieval_quality' => 70,
            'execution_quality' => 82,
            'evidence_quality' => 85,
            'evidence_refs' => ['receipt:runtime-noise'],
            'learning_signal' => [
                'claim' => 'Noise must be registered even on passing runs.',
                'confidence' => 80,
                'evidence_refs' => ['receipt:runtime-noise'],
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => 'retr_noise',
                'included_sources' => 8,
                'used_sources' => 5,
                'noise_sources' => 3,
                'missed_required_sources' => [],
                'context_sufficiency' => 75,
                'post_execution_utility' => 60,
                'source_utility' => [
                    'docs/unrelated-a.md' => 'noise',
                    'docs/unrelated-b.md' => 'noise',
                    'docs/unrelated-c.md' => 'noise',
                ],
            ],
        ]);

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame(3, $event->noise_sources);
        $this->assertSame(['docs/unrelated-a.md' => 'noise', 'docs/unrelated-b.md' => 'noise', 'docs/unrelated-c.md' => 'noise'], $event->source_utility);
        $hint = $event->next_retrieval_hint;
        $this->assertIsArray($hint);
        $this->assertContains('excess_noise', $hint['reasons']);
    }

    public function test_critical_heuristic_update_is_forced_into_proposal(): void
    {
        $result = app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'runtime-critical-heuristic',
            'flow_id' => 'atlas_debug',
            'outcome_status' => 'passed',
            'flow_quality' => 80,
            'retrieval_quality' => 70,
            'execution_quality' => 82,
            'evidence_quality' => 88,
            'evidence_refs' => ['receipt:runtime-critical'],
            'learning_signal' => [
                'claim' => 'Critical heuristic key must never auto-apply.',
                'confidence' => 80,
                'evidence_refs' => ['receipt:runtime-critical'],
            ],
            'heuristic_update' => [
                'heuristic_key' => 'router.debug_failure_language',
                'flow_id' => 'atlas_debug',
                'before_state' => ['weight' => 0.4],
                'after_state' => ['weight' => 0.9],
                'evidence_refs' => ['outcome:runtime-critical'],
                'rollback_plan' => ['restore_weight' => 0.4],
                'test_refs' => ['tests/Unit/Ai/Compounding/AtlasCompoundingRuntimeServiceTest.php'],
                'apply' => true,
            ],
        ]);

        $heuristic = AiHeuristicUpdate::query()->firstOrFail();
        $this->assertSame('proposed', $heuristic->status, 'router.* heuristic must be forced to proposed even when apply:true.');
        $this->assertNull($heuristic->applied_at);

        $proposals = AiLearningProposal::query()->where('kind', 'routing')->get();
        $this->assertCount(1, $proposals);
        $this->assertSame('proposed', $proposals->first()->status);
        $this->assertNotEmpty($result['learning_proposals']);
    }

    public function test_explicit_learning_proposal_payload_is_recorded_and_never_applied(): void
    {
        app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'runtime-explicit-proposal',
            'flow_id' => 'atlas_research',
            'outcome_status' => 'passed',
            'flow_quality' => 80,
            'retrieval_quality' => 80,
            'execution_quality' => 82,
            'evidence_quality' => 88,
            'evidence_refs' => ['receipt:runtime-proposal'],
            'learning_signal' => [
                'claim' => 'Explicit proposal payload must materialise.',
                'confidence' => 80,
                'evidence_refs' => ['receipt:runtime-proposal'],
            ],
            'learning_proposal' => [
                'kind' => 'policy',
                'summary' => 'Increase strict-flows context_sufficiency floor to 75 after sustained gap.',
                'current_state' => ['floor' => 60],
                'proposed_state' => ['floor' => 75],
                'evidence_refs' => ['outcome:runtime-explicit-proposal'],
                'scope' => 'atlas-server',
                'flow_id' => 'atlas_research',
            ],
        ]);

        $proposal = AiLearningProposal::query()->where('kind', 'policy')->firstOrFail();
        $this->assertSame('proposed', $proposal->status);
        $this->assertNull($proposal->decided_at);
        $this->assertTrue($proposal->requires_human_review);
    }

    public function test_learning_proposal_service_rejects_auto_apply_flag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('learning_proposal_cannot_auto_apply');

        app(AtlasLearningProposalService::class)->propose([
            'kind' => 'policy',
            'summary' => 'Attempted auto-apply must be rejected.',
            'evidence_refs' => ['outcome:hack'],
            'apply' => true,
        ]);
    }

    public function test_learning_proposal_lifecycle_requires_approval_before_applied(): void
    {
        $service = app(AtlasLearningProposalService::class);
        $proposal = $service->propose([
            'kind' => 'policy',
            'summary' => 'Lifecycle test proposal.',
            'evidence_refs' => ['outcome:lifecycle'],
            'current_state' => ['x' => 1],
            'proposed_state' => ['x' => 2],
        ]);

        $this->assertSame('proposed', $proposal->status);

        try {
            $service->markApplied($proposal);
            $this->fail('markApplied must reject a proposal that has not been approved.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('learning_proposal_must_be_approved_before_applied', $exception->getMessage());
        }

        $approved = $service->approve($proposal, 'operator-test', 'unit-test approval');
        $this->assertSame('approved', $approved->status);
        $this->assertSame('operator-test', $approved->decided_by);
        $this->assertNotNull($approved->decided_at);

        $applied = $service->markApplied($approved);
        $this->assertSame('applied', $applied->status);
    }

    public function test_rag_feedback_hash_is_stable_across_identical_payloads(): void
    {
        $payload = $this->executionPayload('runtime-stable');
        $first = app(AtlasCompoundingRuntimeService::class)->recordExecution($payload);
        $second = app(AtlasCompoundingRuntimeService::class)->recordExecution($payload);

        $this->assertSame($first['rag_feedback']['feedback_hash'], $second['rag_feedback']['feedback_hash']);
        $this->assertSame(1, AiRagFeedbackEvent::query()->count(), 'firstOrCreate must dedupe by feedback_hash.');
        $this->assertSame($first['outcome']['outcome_hash'], $second['outcome']['outcome_hash']);
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
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
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
