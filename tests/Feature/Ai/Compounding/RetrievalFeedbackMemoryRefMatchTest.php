<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class RetrievalFeedbackMemoryRefMatchTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        config([
            'atlas.ai.loop.learning_recall_use_lift.enabled' => true,
            'atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm' => 1,
            'atlas.ai.loop.learning_recall_use_lift.min_passing_memory_use' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_used_compounding_memory_matches_lift_with_arm(): void
    {
        $memory = $this->activeMemory();
        $passingOutcome = $this->runOutcome('ope03-with', 'atlas_dev', 'passed', 90);
        $this->runOutcome('ope03-without', 'atlas_dev', 'failed', 40);

        Artisan::call('atlas:context:retrieval-feedback', [
            '--query' => 'used compounding memory in passing run',
            '--task-type' => 'debug',
            '--domain' => 'atlas_dev',
            '--outcome' => 'passed',
            '--memory-ref' => [$memory->memory_hash],
            '--run-outcome-id' => $passingOutcome->id,
            '--record' => true,
            '--json' => true,
        ]);

        Artisan::call('atlas:context:retrieval-feedback', [
            '--query' => 'baseline without compounding memory',
            '--task-type' => 'debug',
            '--domain' => 'atlas_dev',
            '--outcome' => 'failed',
            '--record' => true,
            '--json' => true,
        ]);

        $event = AiRagFeedbackEvent::query()->where('run_outcome_id', $passingOutcome->id)->firstOrFail();
        $this->assertSame('used', $event->source_utility['compounding_memory:'.$memory->memory_hash] ?? null);

        $report = app(AtlasLearningRecallUseLiftService::class)->report(minCases: 1, minPassingUse: 1);
        $this->assertSame(1, data_get($report, 'measurement.with_recalled_memory.case_count'));
        $this->assertSame('positive_live_lift', $report['status']);
    }

    public function test_served_but_not_used_compounding_memory_does_not_count_in_with_arm(): void
    {
        $memory = $this->activeMemory();
        $this->runOutcome('ope03-served-only', 'atlas_dev', 'passed', 88);
        $this->runOutcome('ope03-baseline', 'atlas_dev', 'failed', 40);

        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'served compounding memory without explicit use',
            'task_type' => 'debug',
            'domain' => 'atlas_dev',
            'outcome_status' => 'passed',
            'delivered_context_refs' => ['compounding_memory:'.$memory->memory_hash],
            'record' => true,
        ]);

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame('included', $event->source_utility['compounding_memory:'.$memory->memory_hash] ?? null);

        $report = app(AtlasLearningRecallUseLiftService::class)->report(minCases: 1, minPassingUse: 1);
        $this->assertSame(0, data_get($report, 'measurement.with_recalled_memory.case_count'));
        $this->assertSame('insufficient_live_ab_evidence', $report['status']);
        $this->assertStringNotContainsString(
            'compounding_memory:'.$memory->memory_hash,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public function test_non_memory_sources_remain_hash_only_in_source_utility(): void
    {
        $hash = MissionCanonicalHash::sha256('doc:owner-context');

        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'non memory hash only attribution',
            'task_type' => 'debug',
            'domain' => 'developer',
            'outcome_status' => 'passed',
            'delivered_context_refs' => ['doc:owner-context'],
            'used_context_refs' => ['doc:owner-context'],
            'record' => true,
        ]);

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame('used', $event->source_utility['doc:owner-context'] ?? null);
        $this->assertSame(
            0,
            count(array_filter(
                array_keys((array) $event->source_utility),
                static fn (string $key): bool => str_starts_with($key, 'compounding_memory:'),
            )),
        );
        $this->assertStringNotContainsString('compounding_memory:', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function activeMemory(): AiCompoundingMemory
    {
        return AiCompoundingMemory::query()->create([
            'schema_version' => 'atlas.ai.compounding.memory.v1',
            'learning_candidate_id' => null,
            'memory_type' => 'routing_memory',
            'scope' => 'atlas-server',
            'flow_id' => 'atlas_dev',
            'status' => 'active',
            'claim' => 'Prefer recorded recall-use feedback for certified Atlas Dev tasks.',
            'confidence' => 92,
            'evidence_refs' => ['receipt:ope03'],
            'revalidation_policy' => 'revalidate_on_failure_or_expiry',
            'valid_until' => now()->addDays(7),
            'last_revalidated_at' => now(),
            'payload' => [],
            'memory_hash' => hash('sha256', 'ope03-memory'),
        ]);
    }

    private function runOutcome(string $runId, string $flowId, string $status, int $quality): AiRunOutcome
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
}
