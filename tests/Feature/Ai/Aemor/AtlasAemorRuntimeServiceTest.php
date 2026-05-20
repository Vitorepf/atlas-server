<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor;

use App\Models\AiMemoryDelta;
use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasAemorExecutionEvent;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasIntelligenceFactoryEvolutionEvent;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Tests\Concerns\CreatesAemorTables;
use Tests\Concerns\CreatesIntelligenceFactoryTables;
use Tests\TestCase;

final class AtlasAemorRuntimeServiceTest extends TestCase
{
    use CreatesAemorTables;
    use CreatesIntelligenceFactoryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAemorTables();
        $this->createIntelligenceFactoryTables();
    }

    protected function tearDown(): void
    {
        $this->dropIntelligenceFactoryTables();
        $this->dropAemorTables();
        parent::tearDown();
    }

    public function test_opens_episode_with_hash_and_risk_prediction(): void
    {
        $payload = app(AtlasAemorRuntimeService::class)->openEpisode([
            'objective' => 'corrigir rich input no Atlas Dev',
            'workspace' => base_path(),
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'persistent_context_hash' => str_repeat('a', 64),
            'evidence_refs' => ['apcr:pack'],
        ]);

        $this->assertSame(AtlasAemorRuntimeService::EPISODE_SCHEMA, $payload['schema_version']);
        $this->assertSame('open', $payload['status']);
        $this->assertNotEmpty($payload['episode_hash']);
        $this->assertNotEmpty($payload['episode_id']);
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertSame('atlas_dev', AtlasAemorExecutionEpisode::query()->firstOrFail()->flow_id);
    }

    public function test_observe_sanitizes_raw_text_and_persists_event_hash(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'test event', 'evidence_refs' => ['e1']]);

        $event = $runtime->observe([
            'episode_id' => $episode['episode_id'],
            'event_type' => 'command_run',
            'payload' => ['command' => 'php artisan test', 'response_text' => 'secret raw'],
            'evidence_refs' => ['test:run'],
        ]);

        $this->assertSame('observed', $event['status']);
        $record = AtlasAemorExecutionEvent::query()->firstOrFail();
        $this->assertArrayHasKey('command', $record->payload);
        $this->assertArrayNotHasKey('response_text', $record->payload);
    }

    public function test_close_outcome_without_evidence_is_blocked(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'test no evidence']);

        $outcome = $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'summary' => 'No evidence.',
            'evidence_refs' => [],
        ]);

        $this->assertSame('blocked', $outcome['status']);
        $this->assertSame('missing_evidence_refs', data_get($outcome, 'blockers.0.id'));
        $this->assertDatabaseCount('atlas_aemor_outcomes', 1);
    }

    public function test_close_outcome_with_evidence_creates_intelligence_factory_evolution_candidate(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode([
            'objective' => 'melhorar parser de YouTube',
            'domain' => 'research',
            'flow_id' => 'atlas_research',
            'evidence_refs' => ['episode:e1'],
        ]);

        $outcome = $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'YouTube ingestion improved after preserving canonical URL attachments.',
            'metrics' => ['tests_passed' => true],
            'evidence_refs' => ['test:youtube-ingestion-green'],
        ]);

        $this->assertSame('candidate', data_get($outcome, 'intelligence_factory_evolution.status'));
        $this->assertNotEmpty(data_get($outcome, 'intelligence_factory_evolution.event_hash'));

        $event = AtlasIntelligenceFactoryEvolutionEvent::query()->firstOrFail();
        $this->assertSame('aemor_outcome', $event->source_type);
        $this->assertSame('successful_outcome_learning_candidate', $event->event_type);
        $this->assertSame(['test:youtube-ingestion-green'], $event->evidence_refs);
        $this->assertTrue((bool) data_get($event->payload, 'requires_operator_review'));
        $this->assertFalse((bool) data_get($event->payload, 'auto_mutates_policy'));
    }

    public function test_distill_with_evidence_creates_learning_signal_memory_candidate_and_pending_delta(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'test distill', 'evidence_refs' => ['e1']]);
        $outcome = $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'Run targeted tests before completion.',
            'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
            'evidence_refs' => ['test:green'],
        ]);

        $distill = $runtime->distill([
            'episode_id' => $episode['episode_id'],
            'outcome_id' => $outcome['outcome_id'],
            'claim' => 'Run targeted tests before completion.',
            'evidence_refs' => ['test:green'],
        ]);

        $this->assertSame('candidate', $distill['status']);
        $this->assertFalse(data_get($distill, 'promotion_gate.promotion_allowed'));
        $this->assertDatabaseCount('atlas_aemor_learning_signals', 1);
        $this->assertDatabaseCount('atlas_aemor_memory_candidates', 1);
        $this->assertSame('watch', AtlasAemorMemoryCandidate::query()->firstOrFail()->status);
        $this->assertSame('pending', AiMemoryDelta::query()->firstOrFail()->status);
    }

    public function test_replay_manifest_reconstructs_episode_hashes(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'test replay', 'evidence_refs' => ['e1']]);
        $runtime->observe(['episode_id' => $episode['episode_id'], 'payload' => ['stage' => 'one'], 'evidence_refs' => ['event:e1']]);
        $runtime->closeOutcome(['episode_id' => $episode['episode_id'], 'status' => 'succeeded', 'summary' => 'done', 'metrics' => ['tests_passed' => true], 'evidence_refs' => ['outcome:e1']]);

        $replay = $runtime->replayManifest((string) $episode['episode_id']);

        $this->assertSame(AtlasAemorRuntimeService::REPLAY_SCHEMA, $replay['schema_version']);
        $this->assertCount(1, $replay['event_hashes']);
        $this->assertContains('verify_hashes', $replay['replay_steps']);
    }
}
