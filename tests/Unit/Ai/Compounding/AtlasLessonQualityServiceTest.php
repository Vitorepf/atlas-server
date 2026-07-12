<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Console\Commands\AtlasAcosFreezeCommand;
use App\Models\AiCompoundingMemory;
use App\Models\AiLearningCandidate;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Compounding\AtlasLessonQualityService;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class AtlasLessonQualityServiceTest extends TestCase
{
    use BootsCompoundingSchema;

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

    public function test_maxj01_freeze_payload_records_independent_judge_and_registry_entry(): void
    {
        $freezePath = storage_path('framework/testing/maxj01-freeze-'.bin2hex(random_bytes(4)).'.jsonl');
        $payload = AtlasAcosFreezeCommand::lessonQualityFreezePayload();

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:freeze', [
            '--json' => json_encode($payload, JSON_THROW_ON_ERROR),
            '--path' => $freezePath,
        ], $output);

        $this->assertSame(0, $exit);
        $result = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
        $row = json_decode(trim((string) file_get_contents($freezePath)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasLessonQualityService::MEASURE_ID, $result['measure_id']);
        $this->assertSame('atlas_ai_lesson_quality_v2', $row['formula_version']);
        $this->assertSame('cursor-acos-max-maxj-01', $row['author_engine_id']);
        $this->assertSame('codex-independent-lesson-quality-judge', $row['judge_engine_id']);
        $this->assertTrue($row['judge_author_distinct']);
        $this->assertSame(1, $row['denominator_min']);

        $entry = collect(app(AcosMaxMeasureSeriesRegistry::class)->entries())->firstWhere('slice', 'MAXJ-01');
        $this->assertSame(AtlasLessonQualityService::MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('command', $entry['source_type'] ?? null);
    }

    public function test_lesson_quality_command_reports_insufficient_signal_without_live_mass(): void
    {
        $payload = $this->callLessonQuality();

        $this->assertSame(AtlasLessonQualityService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasLessonQualityService::MEASURE_ID, $payload['measure_id']);
        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame([], $payload['groups']);
        $this->assertSame(0, data_get($payload, 'totals.measured_count'));
        $this->assertSame(0, data_get($payload, 'totals.total'));
        $this->assertFalse(data_get($payload, 'claim_policy.completion_claim_allowed'));
        $this->assertFalse(data_get($payload, 'claim_policy.memory_written'));
    }

    public function test_lesson_quality_groups_candidates_feedback_and_raw_denominators(): void
    {
        $outcomeA = $this->outcome('run-a', 'atlas_dev', 'passed', 90);
        $outcomeB = $this->outcome('run-b', 'atlas_dev', 'failed', 30);
        $candidateA = $this->candidate($outcomeA, 'routing_memory', 'atlas-server', 'promoted', true);
        $candidateB = $this->candidate($outcomeB, 'routing_memory', 'atlas-server', 'rejected', false);

        $this->feedback('feedback-a', 'atlas_dev', 'passed', $outcomeA, $candidateA);
        $this->feedback('feedback-b', 'atlas_dev', 'failed', $outcomeB, $candidateB);
        $this->feedback('feedback-baseline', 'atlas_dev', 'failed', $outcomeB, null);

        $payload = $this->callLessonQuality();

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(2, data_get($payload, 'totals.candidate_count'));
        $this->assertSame(2, data_get($payload, 'totals.measured_count'));
        $this->assertSame(2, data_get($payload, 'totals.total'));

        $group = $payload['groups'][0];
        $this->assertSame('routing_memory', $group['memory_type']);
        $this->assertSame('atlas_dev', $group['flow_id']);
        $this->assertSame('atlas-server', $group['scope']);
        $this->assertSame('measured', $group['status']);
        $this->assertSame(2, $group['candidate_count']);
        $this->assertSame(2, $group['case_count']);
        $this->assertSame(1, $group['negative_count']);
        $this->assertSame(2, $group['measured_count']);
        $this->assertSame(2, $group['total']);
        $this->assertSame(0.5, $group['promoted_rate']);
        $this->assertSame(0.5, $group['measured_lift']);
    }

    public function test_maxj05_freeze_payload_records_floor_and_registry_entry(): void
    {
        $freezePath = storage_path('framework/testing/maxj05-freeze-'.bin2hex(random_bytes(4)).'.jsonl');
        $payload = AtlasAcosFreezeCommand::lessonTypeYieldFreezePayload();

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:freeze', [
            '--json' => json_encode($payload, JSON_THROW_ON_ERROR),
            '--path' => $freezePath,
        ], $output);

        $this->assertSame(0, $exit);
        $result = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
        $row = json_decode(trim((string) file_get_contents($freezePath)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID, $result['measure_id']);
        $this->assertSame('atlas_ai_lesson_type_yield_v2', $row['formula_version']);
        $this->assertSame(8, $row['denominator_min']);
        $this->assertSame('cursor-acos-max-maxj-05', $row['author_engine_id']);
        $this->assertSame('codex-independent-lesson-type-yield-judge', $row['judge_engine_id']);
        $this->assertTrue($row['judge_author_distinct']);

        $entry = collect(app(AcosMaxMeasureSeriesRegistry::class)->entries())->firstWhere('slice', 'MAXJ-05');
        $this->assertSame(AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('command', $entry['source_type'] ?? null);
    }

    public function test_lesson_type_yield_uses_new_floor_without_changing_v1_aggregate(): void
    {
        CarbonImmutable::setTestNow('2026-07-12T06:00:00+00:00');
        config(['atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm' => 2]);

        $routing = $this->activeMemory('routing_memory', 'atlas_dev');
        $rare = $this->activeMemory('refutation_memory', 'atlas_dev');
        for ($i = 1; $i <= 10; $i++) {
            $this->recallLiftFeedback('routing-with-'.$i, 'atlas_dev', $i <= 8 ? 'passed' : 'failed', [$routing->memory_hash => 'useful']);
            $this->recallLiftFeedback('routing-baseline-'.$i, 'atlas_dev', $i <= 5 ? 'passed' : 'failed', ['docs/base-'.$i => 'useful']);
        }
        $this->recallLiftFeedback('rare-with-1', 'atlas_dev', 'passed', [$rare->memory_hash => 'useful']);

        $service = app(AtlasLearningRecallUseLiftService::class);
        $before = $service->report();
        $payload = $this->callLessonTypeYield();
        $after = $service->report();

        $this->assertSame($before, $after);
        $this->assertSame(2, data_get($after, 'measurement.min_cases_per_arm'));
        $this->assertSame(AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID, $payload['measure_id']);
        $this->assertSame(8, $payload['denominator_min']);

        $routingType = collect($payload['types'])->firstWhere('memory_type', 'routing_memory');
        $rareType = collect($payload['types'])->firstWhere('memory_type', 'refutation_memory');

        $this->assertSame('measured', $routingType['status']);
        $this->assertSame(10, $routingType['case_count']);
        $this->assertSame(0.3, $routingType['passed_rate_lift']);

        $this->assertSame('insufficient_signal', $rareType['status']);
        $this->assertSame(1, $rareType['case_count']);
        $this->assertSame('case_count_below_lesson_type_floor', $rareType['reason']);
        $this->assertNull($rareType['passed_rate_lift']);
    }

    /**
     * @return array<string,mixed>
     */
    private function callLessonQuality(): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:ai:lesson-quality', ['--json' => true], $output);

        $this->assertSame(0, $exit);

        return json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function callLessonTypeYield(): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:ai:lesson-type-yield', ['--json' => true], $output);

        $this->assertSame(0, $exit);

        return json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function outcome(string $runId, string $flowId, string $status, int $quality): AiRunOutcome
    {
        return AiRunOutcome::query()->create([
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
    }

    private function candidate(AiRunOutcome $outcome, string $memoryType, string $scope, string $decision, bool $promotionAllowed): AiLearningCandidate
    {
        return AiLearningCandidate::query()->create([
            'schema_version' => 'atlas.ai.compounding.learning_candidate.v1',
            'run_outcome_id' => $outcome->id,
            'candidate_hash' => hash('sha256', 'candidate-'.$outcome->run_id),
            'status' => $decision,
            'decision' => $decision,
            'memory_type' => $memoryType,
            'scope' => $scope,
            'claim' => 'A lesson quality candidate for '.$memoryType,
            'confidence' => $promotionAllowed ? 90 : 20,
            'promotion_allowed' => $promotionAllowed,
            'evidence_refs' => ['receipt:candidate-'.$outcome->run_id],
            'payload' => [],
            'receipt_hash' => hash('sha256', 'receipt-'.$outcome->run_id),
            'decided_at' => now(),
        ]);
    }

    private function feedback(string $id, string $flowId, string $status, AiRunOutcome $outcome, ?AiLearningCandidate $candidate): void
    {
        AiRagFeedbackEvent::query()->create([
            'schema_version' => 'atlas.ai.rag.feedback.v1',
            'retrieval_receipt_id' => 'retr-'.$id,
            'flow_id' => $flowId,
            'query_plan_hash' => hash('sha256', 'query-'.$id),
            'included_sources' => 1,
            'used_sources' => 1,
            'noise_sources' => $status === 'passed' ? 0 : 1,
            'missed_required_sources' => [],
            'context_sufficiency' => $status === 'passed' ? 90 : 30,
            'post_execution_utility' => $status === 'passed' ? 90 : 30,
            'source_utility' => [],
            'outcome_status' => $status,
            'failure_reason' => $status === 'passed' ? null : 'fixture_failure',
            'next_retrieval_hint' => null,
            'memory_candidate_id' => $candidate?->id,
            'learning_proposal_id' => null,
            'run_outcome_id' => $outcome->id,
            'payload' => [],
            'feedback_hash' => hash('sha256', 'feedback-'.$id),
        ]);
    }

    private function activeMemory(string $memoryType, string $flowId): AiCompoundingMemory
    {
        return AiCompoundingMemory::query()->create([
            'schema_version' => 'atlas.ai.compounding.memory.v1',
            'learning_candidate_id' => null,
            'memory_type' => $memoryType,
            'scope' => 'atlas-server',
            'flow_id' => $flowId,
            'status' => 'active',
            'claim' => 'Measure lesson-type yield for '.$memoryType,
            'confidence' => 90,
            'evidence_refs' => ['receipt:memory-'.$memoryType],
            'revalidation_policy' => 'revalidate_on_failure_or_expiry',
            'valid_until' => now()->addDays(7),
            'last_revalidated_at' => now(),
            'payload' => [],
            'memory_hash' => hash('sha256', 'memory-'.$memoryType.'-'.$flowId),
        ]);
    }

    /**
     * @param  array<string,mixed>  $sourceUtility
     */
    private function recallLiftFeedback(string $runId, string $flowId, string $status, array $sourceUtility): void
    {
        $quality = $status === 'passed' ? 90 : 30;
        $outcome = $this->outcome($runId, $flowId, $status, $quality);

        AiRagFeedbackEvent::query()->create([
            'schema_version' => 'atlas.ai.rag.feedback.v1',
            'retrieval_receipt_id' => 'retr-'.$runId,
            'flow_id' => $flowId,
            'query_plan_hash' => hash('sha256', 'query-'.$runId),
            'included_sources' => count($sourceUtility),
            'used_sources' => max(1, min(count($sourceUtility), 2)),
            'noise_sources' => $status === 'passed' ? 0 : 1,
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
}
