<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor;

use App\Models\AiMemoryDelta;
use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasAemorExecutionEvent;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasIntelligenceFactoryCapability;
use App\Models\AtlasIntelligenceFactoryEvolutionEvent;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Str;
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
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->down();
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

    // GOD-DEBULK 3b: IntelligenceFactory quarantined to archive/ (blueprint 91c334a27 §2.2).
    // Outcome-close must SURVIVE the missing class and degrade the evolution sidecar to `skipped`
    // instead of throwing inside the catch (the old catch evaluated a constant on the gone class).
    public function test_close_outcome_with_evidence_degrades_intelligence_factory_evolution_to_skipped(): void
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
            'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
            'evidence_refs' => ['test:youtube-ingestion-green'],
        ]);

        $this->assertSame('succeeded', $outcome['status']);
        $this->assertSame('skipped', data_get($outcome, 'intelligence_factory_evolution.status'));
        $this->assertSame('intelligence_factory_unavailable', data_get($outcome, 'intelligence_factory_evolution.reason'));
        $this->assertSame(0, AtlasIntelligenceFactoryEvolutionEvent::query()->count());
    }

    public function test_distill_with_evidence_creates_learning_signal_memory_candidate_and_pending_delta(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'test distill', 'evidence_refs' => ['e1']]);
        $outcome = $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'Run targeted tests before completion.',
            'evidence_refs' => [$this->verifiedEvidenceRef('test:green')],
        ]);

        $distill = $runtime->distill([
            'episode_id' => $episode['episode_id'],
            'outcome_id' => $outcome['outcome_id'],
            'claim' => 'Run targeted tests before completion.',
            'evidence_refs' => [$this->verifiedEvidenceRef('test:green')],
        ]);

        $this->assertSame('candidate', $distill['status']);
        $this->assertFalse(data_get($distill, 'promotion_gate.promotion_allowed'));
        $this->assertDatabaseCount('atlas_aemor_learning_signals', 1);
        $this->assertDatabaseCount('atlas_aemor_memory_candidates', 1);
        $this->assertSame('watch', AtlasAemorMemoryCandidate::query()->firstOrFail()->status);
        $this->assertSame('pending', AiMemoryDelta::query()->firstOrFail()->status);
    }

    public function test_distill_can_propose_governed_skill_candidate_without_auto_installing(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode([
            'objective' => 'test skill distill',
            'domain' => 'billing_ops',
            'flow_id' => 'invoice_telemetry',
            'evidence_refs' => ['episode:e1'],
        ]);
        $outcome = $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'Rare billing telemetry workflow should become a reusable skill candidate.',
            'evidence_refs' => [$this->verifiedEvidenceRef('test:skill-candidate')],
        ]);

        $distill = $runtime->distill([
            'episode_id' => $episode['episode_id'],
            'outcome_id' => $outcome['outcome_id'],
            'claim' => 'Rare billing telemetry workflow should become a reusable skill candidate.',
            'evidence_refs' => [$this->verifiedEvidenceRef('test:skill-candidate')],
            'propose_skill_candidate' => true,
        ]);

        $this->assertSame('candidate', $distill['status']);
        $this->assertSame('candidate', data_get($distill, 'skill_evolution.status'));
        $this->assertFalse((bool) data_get($distill, 'skill_evolution.claim_policy.auto_installs_skill'));
        // GOD-DEBULK 3b: IntelligenceFactory quarantined — the skill candidate still certifies,
        // but no factory capability row is registered (sidecar degrades to null).
        $this->assertNull(data_get($distill, 'skill_evolution.intelligence_factory_capability'));
        $this->assertSame(0, AtlasIntelligenceFactoryCapability::query()->count());
    }

    public function test_distill_blocks_succeeded_outcome_without_tests_passed_metric(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'test gate', 'evidence_refs' => ['e1']]);
        $outcome = $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'Completed without running tests.',
            'metrics' => ['attribution_reviewed' => true], // tests_passed intentionally omitted
            'evidence_refs' => ['outcome:no-tests'],
        ]);

        $distill = $runtime->distill([
            'episode_id' => $episode['episode_id'],
            'outcome_id' => $outcome['outcome_id'],
            'claim' => 'Completed without running tests.',
            'evidence_refs' => ['outcome:no-tests'],
        ]);

        $this->assertSame('blocked', $distill['status']);
        $this->assertContains('metrics_not_verified', data_get($distill, 'promotion_gate.blockers', []));
        $this->assertNull(data_get($distill, 'memory_candidate_id'));
        $this->assertNull(data_get($distill, 'memory_delta_id'));
        $this->assertDatabaseCount('atlas_aemor_memory_candidates', 0);
        $this->assertDatabaseCount('ai_memory_deltas', 0);
    }

    public function test_replay_manifest_reconstructs_episode_hashes(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'test replay', 'evidence_refs' => ['e1']]);
        $runtime->observe(['episode_id' => $episode['episode_id'], 'payload' => ['stage' => 'one'], 'evidence_refs' => ['event:e1']]);
        $runtime->closeOutcome(['episode_id' => $episode['episode_id'], 'status' => 'succeeded', 'summary' => 'done', 'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true], 'evidence_refs' => ['outcome:e1']]);

        $replay = $runtime->replayManifest((string) $episode['episode_id']);

        $this->assertSame(AtlasAemorRuntimeService::REPLAY_SCHEMA, $replay['schema_version']);
        $this->assertCount(1, $replay['event_hashes']);
        $this->assertContains('verify_hashes', $replay['replay_steps']);
    }

    private function verifiedEvidenceRef(string $label): string
    {
        $eventId = (string) Str::ulid();
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env-'.$label,
            'correlation_id' => 'corr-'.$label,
            'event_type' => LedgerEventType::GatePassed->value,
            'emitter_stage' => 'atlas.aemor',
            'emitter_version' => 'v1',
            'payload' => [
                'tests_passed' => true,
                'attribution_reviewed' => true,
                'label' => $label,
            ],
            'payload_hash' => hash('sha256', $label),
            'occurred_at' => now(),
        ]);

        return 'ledger:'.$eventId;
    }
}
