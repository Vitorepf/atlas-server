<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RetrievalFeedbackLoopTest extends TestCase
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

    public function test_successful_context_feedback_records_strong_roi_without_learning_candidate(): void
    {
        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'passed',
            'max_refs' => 8,
            'record' => true,
        ]);

        $this->assertSame(AtlasRetrievalFeedbackLoopService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('recorded', $payload['status']);
        $this->assertSame(AtlasRetrievalFeedbackLoopService::FEEDBACK_EVENT_SCHEMA, data_get($payload, 'feedback_event.schema_version'));
        $this->assertSame(AtlasRetrievalFeedbackLoopService::CONTEXT_ROI_SCHEMA, data_get($payload, 'context_roi.schema_version'));
        $this->assertSame('strong', data_get($payload, 'context_roi.quality_band'));
        $this->assertSame('none', data_get($payload, 'learning_candidate.status'));
        $this->assertTrue(data_get($payload, 'persistence.persisted'));
        $this->assertFalse(data_get($payload, 'learning_candidate.auto_apply'));
        $this->assertFalse(data_get($payload, 'policy.auto_promote_learning'));
        $this->assertSame(1, AiRagFeedbackEvent::query()->count());
        $this->assertStringNotContainsString('corrigir bug no repo', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_failed_context_with_missing_sources_creates_review_only_learning_candidate(): void
    {
        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'debug repo with missing billing fixture',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'failed',
            'failure_reason' => 'test failed because billing fixture was not retrieved',
            'missed_required_sources' => [['source_type' => 'billing_fixture', 'reason' => 'test_failure_missing_fixture']],
            'max_refs' => 8,
            'record' => true,
        ]);

        $this->assertSame('needs_review', $payload['status']);
        $this->assertSame('proposed', data_get($payload, 'learning_candidate.status'));
        $this->assertTrue(data_get($payload, 'learning_candidate.requires_review'));
        $this->assertFalse(data_get($payload, 'learning_candidate.auto_apply'));
        $this->assertContains('missed_required_sources', data_get($payload, 'learning_candidate.reasons'));
        $this->assertSame(['billing_fixture'], data_get($payload, 'learning_candidate.proposed_state.repromote_source_types'));
        $this->assertStringNotContainsString('billing fixture was not retrieved', json_encode($payload, JSON_THROW_ON_ERROR));

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame('atlas.ai.rag.feedback.v1', $event->schema_version);
        $this->assertSame(['billing_fixture'], $event->missed_required_sources);
        $this->assertNotSame('', $event->feedback_hash);
    }

    public function test_noise_refs_create_demote_candidate_without_auto_apply(): void
    {
        $baseline = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'debug repo with tests',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'passed',
            'max_refs' => 8,
        ]);
        $noiseHash = array_key_first((array) data_get($baseline, 'feedback_event.source_utility'));

        $this->assertNotNull($noiseHash);

        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'debug repo with tests',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'partial',
            'noise_ref_hashes' => [$noiseHash],
            'max_refs' => 8,
        ]);

        $this->assertSame('learning_candidate', $payload['status']);
        $this->assertSame(1, data_get($payload, 'context_roi.noise_sources'));
        $this->assertContains('noise_context_detected', data_get($payload, 'learning_candidate.reasons'));
        $this->assertSame([$noiseHash], data_get($payload, 'learning_candidate.proposed_state.demote_noise_source_hashes'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
    }

    public function test_command_emits_json_without_recording_by_default(): void
    {
        $exit = Artisan::call('atlas:context:retrieval-feedback', [
            '--query' => 'debug repo with tests',
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--outcome' => 'passed',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasRetrievalFeedbackLoopService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertFalse(data_get($payload, 'persistence.requested'));
        $this->assertFalse(data_get($payload, 'persistence.persisted'));
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
