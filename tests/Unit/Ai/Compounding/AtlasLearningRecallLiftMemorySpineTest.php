<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasLearningRecallLiftMemorySpineTest extends TestCase
{
    use BootsCompoundingSchema;
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompoundingSchema();
        $this->createAtlasMemoryEntryTable();
        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            $table->string('content_hash', 64)->nullable()->index();
        });
        config(['atlas.ai.loop.learning_recall_use_lift.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_lift_matches_source_utility_against_atlas_memory_entries(): void
    {
        $entry = AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Memory spine recall',
            'summary' => 'Provider-safe memory spine summary.',
            'body' => 'Provider-safe memory spine body.',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'manual',
            'metadata' => [],
            'content_hash' => hash('sha256', 'memory-spine-entry'),
            'recorded_at' => now(),
        ]);

        $this->feedback('with-atlas-memory', 'passed', [$entry->content_hash => 'useful']);
        $this->feedback('without-memory-id', 'failed', ['docs/only-path.md' => 'useful']);

        $report = app(AtlasLearningRecallUseLiftService::class)->report(minCases: 1, minPassingUse: 1);
        $withRecall = data_get($report, 'sample.with_recalled_memory.0');
        $withoutRecall = data_get($report, 'sample.without_recalled_memory.0');

        $this->assertSame(1, data_get($report, 'measurement.active_atlas_memory_entry_count'));
        $this->assertTrue(data_get($withRecall, 'memory_recall_used'));
        $this->assertNotSame([], data_get($withRecall, 'matched_memory_ref_hashes'));
        $this->assertSame(['atlas_memory_entry'], data_get($withRecall, 'matched_memory_sources'));
        $this->assertFalse(data_get($withoutRecall, 'memory_recall_used'));
        $this->assertSame([], data_get($withoutRecall, 'matched_memory_ref_hashes'));
    }

    /**
     * @param  array<string,mixed>  $sourceUtility
     */
    private function feedback(string $runId, string $status, array $sourceUtility): void
    {
        $outcome = AiRunOutcome::query()->create([
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => $runId,
            'trace_id' => null,
            'flow_id' => 'atlas_dev',
            'outcome_status' => $status,
            'flow_quality' => $status === 'passed' ? 90 : 40,
            'retrieval_quality' => $status === 'passed' ? 90 : 40,
            'execution_quality' => $status === 'passed' ? 90 : 40,
            'evidence_quality' => $status === 'passed' ? 90 : 40,
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
            'flow_id' => 'atlas_dev',
            'query_plan_hash' => hash('sha256', 'query-'.$runId),
            'included_sources' => count($sourceUtility),
            'used_sources' => 1,
            'noise_sources' => $status === 'passed' ? 0 : 1,
            'missed_required_sources' => [],
            'context_sufficiency' => $status === 'passed' ? 90 : 40,
            'post_execution_utility' => $status === 'passed' ? 90 : 40,
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
