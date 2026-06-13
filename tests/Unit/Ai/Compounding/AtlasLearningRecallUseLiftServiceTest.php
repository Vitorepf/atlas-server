<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLearningRecallUseLiftServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompoundingSchema();
        config([
            'atlas.ai.loop.learning_recall_use_lift.enabled' => true,
            'atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm' => 2,
            'atlas.ai.loop.learning_recall_use_lift.min_passing_memory_use' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_positive_ab_lift_allows_completion_claim_only_when_recalled_memory_was_used_in_passing_runs(): void
    {
        $memory = $this->activeMemory('atlas_dev');
        $this->feedback('with-recall-1', 'atlas_dev', 'passed', 92, [$memory->memory_hash => 'useful']);
        $this->feedback('with-recall-2', 'atlas_dev', 'passed', 88, ['compounding_memory:'.$memory->id => ['used' => true]]);
        $this->feedback('baseline-1', 'atlas_dev', 'failed', 45, ['docs/noise.md' => 'useful']);
        $this->feedback('baseline-2', 'atlas_dev', 'passed', 65, ['docs/context.md' => 'useful']);

        $report = app(AtlasLearningRecallUseLiftService::class)->report();

        $this->assertSame('positive_live_lift', $report['status']);
        $this->assertTrue(data_get($report, 'measurement.live_dod_met'));
        $this->assertTrue(data_get($report, 'claim_policy.completion_claim_allowed'));
        $this->assertSame(2, data_get($report, 'measurement.with_recalled_memory.case_count'));
        $this->assertSame(2, data_get($report, 'measurement.without_recalled_memory.case_count'));
        $this->assertSame(0.5, data_get($report, 'measurement.passed_rate_lift'));
        $this->assertSame([], data_get($report, 'measurement.blockers'));
    }

    public function test_live_report_stays_blocked_when_feedback_never_marks_active_memory_as_used(): void
    {
        $this->activeMemory('atlas_dev');
        $this->feedback('baseline-1', 'atlas_dev', 'passed', 92, ['docs/a.md' => 'useful']);
        $this->feedback('baseline-2', 'atlas_dev', 'failed', 45, ['docs/b.md' => 'useful']);

        $report = app(AtlasLearningRecallUseLiftService::class)->report();

        $this->assertSame('insufficient_live_ab_evidence', $report['status']);
        $this->assertFalse(data_get($report, 'measurement.live_dod_met'));
        $this->assertFalse(data_get($report, 'claim_policy.completion_claim_allowed'));
        $this->assertContains('insufficient_memory_recall_use_cases', data_get($report, 'measurement.blockers'));
        $this->assertContains('no_passing_task_with_memory_recall_use', data_get($report, 'measurement.blockers'));
    }

    public function test_command_strict_exits_non_zero_until_live_dod_is_met(): void
    {
        $this->activeMemory('atlas_dev');
        $this->feedback('baseline-1', 'atlas_dev', 'passed', 90, ['docs/a.md' => 'useful']);
        $this->feedback('baseline-2', 'atlas_dev', 'failed', 40, ['docs/b.md' => 'useful']);

        $exit = Artisan::call('atlas:ai:learning-recall-lift', ['--json' => true, '--strict' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse(data_get($payload, 'claim_policy.completion_claim_allowed'));
    }

    private function activeMemory(string $flowId): AiCompoundingMemory
    {
        return AiCompoundingMemory::query()->create([
            'schema_version' => 'atlas.ai.compounding.memory.v1',
            'learning_candidate_id' => null,
            'memory_type' => 'routing_memory',
            'scope' => 'atlas-server',
            'flow_id' => $flowId,
            'status' => 'active',
            'claim' => 'Prefer focused compounding recall evidence for certified Atlas Dev tasks.',
            'confidence' => 92,
            'evidence_refs' => ['receipt:memory'],
            'revalidation_policy' => 'revalidate_on_failure_or_expiry',
            'valid_until' => now()->addDays(7),
            'last_revalidated_at' => now(),
            'payload' => [],
            'memory_hash' => hash('sha256', 'memory-'.$flowId),
        ]);
    }

    /**
     * @param  array<string,mixed>  $sourceUtility
     */
    private function feedback(string $runId, string $flowId, string $status, int $quality, array $sourceUtility): void
    {
        $outcome = AiRunOutcome::query()->create([
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => $runId,
            'trace_id' => null,
            'flow_id' => $flowId,
            'outcome_status' => $status,
            'flow_quality' => $quality,
            'retrieval_quality' => $quality,
            'execution_quality' => $quality,
            'evidence_quality' => $quality,
            'human_override' => false,
            'learning_required' => true,
            'missed_signals' => [],
            'evidence_refs' => ['receipt:'.$runId],
            'payload' => [],
            'outcome_hash' => hash('sha256', 'outcome-'.$runId),
            'evaluated_at' => now(),
        ]);

        AiRagFeedbackEvent::query()->create([
            'schema_version' => 'atlas.ai.rag.feedback.v1',
            'retrieval_receipt_id' => 'retr-'.$runId,
            'flow_id' => $flowId,
            'query_plan_hash' => hash('sha256', 'query-'.$runId),
            'included_sources' => count($sourceUtility),
            'used_sources' => max(1, min(count($sourceUtility), 2)),
            'noise_sources' => $status === 'passed' ? 0 : 2,
            'missed_required_sources' => [],
            'context_sufficiency' => $quality,
            'post_execution_utility' => $quality,
            'source_utility' => $sourceUtility,
            'outcome_status' => $status,
            'failure_reason' => $status === 'passed' ? null : 'fixture_failure',
            'next_retrieval_hint' => null,
            'memory_candidate_id' => null,
            'learning_proposal_id' => null,
            'run_outcome_id' => $outcome->id,
            'payload' => [],
            'feedback_hash' => hash('sha256', 'feedback-'.$runId),
        ]);
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
