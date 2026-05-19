<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Models\AtlasVoxDogfoodSession;
use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class VoxDogfoodServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_25_010000_create_atlas_vox_dogfood_sessions_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    private function service(): VoxDogfoodService
    {
        return new VoxDogfoodService($this->app->make(VoxEvidenceService::class));
    }

    public function test_record_persists_session_and_emits_event(): void
    {
        $result = $this->service()->record([
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'vox_session_id' => 'sess-real-1',
            'used_hotkey' => true,
            'used_real_stt' => true,
            'used_governed_execute' => false,
            'duration_ms' => 12_345,
            'notes' => 'rodou bem com prompt polish',
        ]);

        $this->assertInstanceOf(AtlasVoxDogfoodSession::class, $result['session']);
        $this->assertStringStartsWith('voxd_', $result['session']->dogfood_session_id);
        $this->assertSame('VOX_DOGFOOD_SESSION_RECORDED', $result['event']['event_kind']);
        // The ledger event must NOT contain notes/metadata — they may have
        // personal context. Only structured signals reach the ledger.
        $this->assertArrayNotHasKey('notes', $result['event']['payload']);
        $this->assertArrayNotHasKey('metadata', $result['event']['payload']);
        $this->assertSame('intent_compile', $result['event']['payload']['mode']);
        $this->assertTrue($result['event']['payload']['used_hotkey']);
    }

    public function test_record_rejects_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->record([
            'mode' => 'voice_rt',
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
        ]);
    }

    public function test_record_rejects_unknown_outcome(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => 'kind_of_worked',
        ]);
    }

    public function test_record_rejects_notes_over_max_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_PARTIAL,
            'notes' => str_repeat('a', VoxDogfoodService::NOTES_MAX_CHARS + 1),
        ]);
    }

    public function test_record_rejects_invalid_duration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'duration_ms' => -5,
        ]);
    }

    public function test_report_empty_world_returns_zeroes_with_recommendation(): void
    {
        $report = $this->service()->report();

        $this->assertSame(VoxDogfoodService::SCHEMA_REPORT, $report['schema']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['sessions_total']);
        $this->assertSame(0, $report['sessions_last_7_days']);
        $this->assertSame(0.0, $report['success_rate']);
        $this->assertSame(0.0, $report['regret_rate']);
        $this->assertSame(0, $report['governed_execute_usage_count']);
        $this->assertSame(0, $report['eclipse_used_count']);
        $this->assertSame('no_dogfood_sessions_yet', $report['recommendation']);
        $this->assertSame('informational_only', $report['gate_v3_contribution']['integration_mode']);
    }

    public function test_report_aggregates_rates_and_counts(): void
    {
        $service = $this->service();

        // Two successes — one regret, one with eclipse + governed_execute.
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'used_hotkey' => true,
            'used_real_stt' => true,
        ]);
        $service->record([
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'used_hotkey' => true,
            'used_real_stt' => true,
            'used_governed_execute' => true,
            'eclipse_used' => true,
        ]);
        // One regret + failure.
        $service->record([
            'mode' => VoxSchema::MODE_PROMPT_POLISH,
            'outcome' => VoxDogfoodService::OUTCOME_FAILED,
            'used_hotkey' => false,
            'used_real_stt' => true,
            'regret_flag' => true,
        ]);
        // One cancelled.
        $service->record([
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'outcome' => VoxDogfoodService::OUTCOME_CANCELLED,
        ]);

        $report = $service->report();

        $this->assertSame(4, $report['sessions_total']);
        $this->assertSame(2, $report['outcomes'][VoxDogfoodService::OUTCOME_SUCCESS]);
        $this->assertSame(1, $report['outcomes'][VoxDogfoodService::OUTCOME_FAILED]);
        $this->assertSame(1, $report['outcomes'][VoxDogfoodService::OUTCOME_CANCELLED]);
        // 2/4 = 0.5 success rate; 1/4 = 0.25 regret rate.
        $this->assertSame(0.5, $report['success_rate']);
        $this->assertSame(0.25, $report['regret_rate']);
        // Hotkey: sessions 1+2 set true, session 3 explicit false, session 4
        // defaults to false → 2/4 = 0.5.
        $this->assertSame(0.5, $report['hotkey_usage_rate']);
        // Real STT: sessions 1+2+3 set true, session 4 defaults false → 3/4.
        $this->assertSame(0.75, $report['real_stt_usage_rate']);
        $this->assertSame(1, $report['governed_execute_usage_count']);
        $this->assertSame(1, $report['eclipse_used_count']);
        $this->assertSame(1, $report['sessions_by_mode'][VoxSchema::MODE_DICTATION]);
        $this->assertSame(1, $report['sessions_by_mode'][VoxSchema::MODE_PROMPT_POLISH]);
        $this->assertSame(1, $report['sessions_by_mode'][VoxSchema::MODE_INTENT_COMPILE]);
        $this->assertSame(1, $report['sessions_by_mode'][VoxSchema::MODE_GOVERNED_EXECUTE]);
        // gate_v3_contribution mirrors the same regret rate.
        $this->assertSame(0.25, $report['gate_v3_contribution']['would_contribute']['regret_rate']);
        $this->assertSame(1, $report['gate_v3_contribution']['would_contribute']['eclipse_used_count']);
    }

    public function test_report_recommendation_flags_regret_pattern(): void
    {
        $service = $this->service();
        // 1 success + 1 regret = 50% regret >= 20% threshold.
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
        ]);
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_FAILED,
            'regret_flag' => true,
        ]);

        $this->assertSame('investigate_regret_pattern', $service->report()['recommendation']);
    }

    public function test_report_last_7_days_uses_created_at(): void
    {
        $service = $this->service();
        // Record one fresh session.
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
        ]);
        // Force one row to look like it landed 10 days ago by editing the
        // raw row — we cannot pass created_at via the model factory because
        // Eloquent overrides it on `create()`.
        $stale = AtlasVoxDogfoodSession::query()->first();
        $stale->created_at = CarbonImmutable::now('UTC')->subDays(10);
        $stale->save();

        // New row lands now and counts.
        $service->record([
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
        ]);

        $report = $service->report();
        $this->assertSame(2, $report['sessions_total']);
        $this->assertSame(1, $report['sessions_last_7_days']);
    }

    public function test_real_usage_days_counts_distinct_days(): void
    {
        $service = $this->service();
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
        ]);
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_PARTIAL,
        ]);
        // Two rows landed today → 1 distinct day.
        $this->assertSame(1, $service->report()['real_usage_days']);
    }

    public function test_record_accepts_started_at_string(): void
    {
        $result = $this->service()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'started_at' => '2026-05-25T12:00:00Z',
        ]);
        $this->assertNotNull($result['session']->started_at);
        $this->assertSame(
            '2026-05-25T12:00:00+00:00',
            $result['session']->started_at->toIso8601String(),
        );
    }

    public function test_metadata_too_large_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Construct a metadata payload that JSON-encodes past the 64 KiB cap.
        $bigChunk = str_repeat('x', VoxDogfoodService::METADATA_MAX_BYTES + 1024);
        $this->service()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'metadata' => ['blob' => $bigChunk],
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // V6-E · dogfood automático leve
    // ────────────────────────────────────────────────────────────────────

    public function test_record_persists_auto_event_under_metadata(): void
    {
        $result = $this->service()->record([
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'auto_event' => [
                'suggested_mode' => VoxSchema::MODE_INTENT_COMPILE,
                'final_mode' => VoxSchema::MODE_INTENT_COMPILE,
                'manual_override' => false,
                'auto_router_confidence' => 0.82,
                'intervention_present' => true,
                'intervention_kind' => VoxDogfoodService::INTERVENTION_CLARIFY,
                'empty_transcript' => false,
                'stt_failed' => false,
                'clicked_action' => VoxDogfoodService::CLICKED_INSERT,
                'launch_source' => VoxDogfoodService::SOURCE_AMBIENT_LAUNCH,
            ],
        ]);

        $meta = $result['session']->metadata;
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('auto_event', $meta);
        $ev = $meta['auto_event'];
        $this->assertSame(VoxDogfoodService::SCHEMA_AUTO_EVENT, $ev['schema']);
        // V6-E invariant: raw audio is NEVER persisted, regardless of input.
        $this->assertFalse($ev['raw_audio_persisted']);
        $this->assertSame(VoxDogfoodService::SOURCE_AMBIENT_LAUNCH, $ev['launch_source']);
        $this->assertSame(VoxDogfoodService::CLICKED_INSERT, $ev['clicked_action']);
        $this->assertSame(VoxDogfoodService::INTERVENTION_CLARIFY, $ev['intervention_kind']);
        $this->assertSame(0.82, $ev['auto_router_confidence']);
    }

    public function test_auto_event_strips_prohibited_fields(): void
    {
        $result = $this->service()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'auto_event' => [
                'launch_source' => VoxDogfoodService::SOURCE_HOTKEY,
                // Tudo abaixo deve sumir antes de persistir.
                'transcript' => 'isso seria privado',
                'transcript_text' => 'idem',
                'prompt_body' => 'ainda mais privado',
                'audio' => 'binary blob',
                'pcm' => 'samples',
                'clipboard' => 'segredo',
                'clipboard_text' => 'segredo',
                'confirmation_token' => 'token-secreto',
                'literal_confirmation_text' => 'delete production',
            ],
        ]);

        $ev = $result['session']->metadata['auto_event'];
        foreach (VoxDogfoodService::prohibitedAutoEventFields() as $field) {
            $this->assertArrayNotHasKey($field, $ev, "campo `{$field}` deveria ter sido descartado");
        }
        $this->assertSame(VoxDogfoodService::SOURCE_HOTKEY, $ev['launch_source']);
    }

    public function test_auto_event_normalizes_unknown_enums(): void
    {
        $result = $this->service()->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'auto_event' => [
                'launch_source' => 'martian_keyboard',
                'clicked_action' => 'teleport',
                'intervention_kind' => 'mind_meld',
                'suggested_mode' => 'voice_realtime',
                'auto_router_confidence' => 2.5,
            ],
        ]);
        $ev = $result['session']->metadata['auto_event'];
        $this->assertSame(VoxDogfoodService::SOURCE_UNKNOWN, $ev['launch_source']);
        $this->assertSame(VoxDogfoodService::CLICKED_NONE, $ev['clicked_action']);
        $this->assertSame(VoxDogfoodService::INTERVENTION_NONE, $ev['intervention_kind']);
        $this->assertNull($ev['suggested_mode']);
        // confidence é clamp 0..1 (tolerante a int/float pós JSON round-trip).
        $this->assertEqualsWithDelta(1.0, $ev['auto_router_confidence'], 0.0001);
    }

    public function test_report_aggregates_auto_event_signals(): void
    {
        $service = $this->service();
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
            'auto_event' => [
                'manual_override' => false,
                'intervention_present' => false,
                'intervention_kind' => VoxDogfoodService::INTERVENTION_NONE,
                'stt_failed' => false,
                'empty_transcript' => false,
                'launch_source' => VoxDogfoodService::SOURCE_AMBIENT_HELPER,
                'clicked_action' => VoxDogfoodService::CLICKED_INSERT,
                'auto_router_confidence' => 0.9,
            ],
        ]);
        $service->record([
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'outcome' => VoxDogfoodService::OUTCOME_PARTIAL,
            'auto_event' => [
                'manual_override' => true,
                'intervention_present' => true,
                'intervention_kind' => VoxDogfoodService::INTERVENTION_CAUTION,
                'stt_failed' => false,
                'empty_transcript' => false,
                'launch_source' => VoxDogfoodService::SOURCE_HOTKEY,
                'clicked_action' => VoxDogfoodService::CLICKED_CONFIRM,
                'auto_router_confidence' => 0.5,
            ],
        ]);
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_FAILED,
            'auto_event' => [
                'stt_failed' => true,
                'empty_transcript' => true,
                'launch_source' => VoxDogfoodService::SOURCE_AMBIENT_LAUNCH,
                'clicked_action' => VoxDogfoodService::CLICKED_CANCEL,
            ],
        ]);
        // Sessão sem auto_event não distorce os rates.
        $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
        ]);

        $report = $service->report();
        $this->assertSame(4, $report['sessions_total']);
        $this->assertSame(3, $report['auto_event_sessions']);
        $this->assertSame(0.75, $report['auto_event_coverage']);
        // override apenas 1/3.
        $this->assertSame(round(1 / 3, 4), $report['manual_override_rate']);
        $this->assertSame(round(1 / 3, 4), $report['intervention_rate']);
        $this->assertSame(1, $report['intervention_by_kind'][VoxDogfoodService::INTERVENTION_CAUTION]);
        $this->assertSame(2, $report['intervention_by_kind'][VoxDogfoodService::INTERVENTION_NONE]);
        $this->assertSame(round(1 / 3, 4), $report['stt_failed_rate']);
        $this->assertSame(round(1 / 3, 4), $report['empty_transcript_rate']);
        $this->assertSame(1, $report['launch_source_breakdown'][VoxDogfoodService::SOURCE_AMBIENT_HELPER]);
        $this->assertSame(1, $report['launch_source_breakdown'][VoxDogfoodService::SOURCE_HOTKEY]);
        $this->assertSame(1, $report['launch_source_breakdown'][VoxDogfoodService::SOURCE_AMBIENT_LAUNCH]);
        $this->assertSame(0, $report['launch_source_breakdown'][VoxDogfoodService::SOURCE_APP]);
        $this->assertSame(1, $report['clicked_action_breakdown'][VoxDogfoodService::CLICKED_INSERT]);
        $this->assertSame(1, $report['clicked_action_breakdown'][VoxDogfoodService::CLICKED_CONFIRM]);
        $this->assertSame(1, $report['clicked_action_breakdown'][VoxDogfoodService::CLICKED_CANCEL]);
        // Average confidence sobre os dois que reportaram = (0.9 + 0.5) / 2.
        $this->assertSame(0.7, $report['auto_router_confidence_avg']);
    }

    public function test_update_feedback_toggles_regret_flag(): void
    {
        $service = $this->service();
        $session = $service->record([
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => VoxDogfoodService::OUTCOME_SUCCESS,
        ])['session'];
        $id = $session->dogfood_session_id;

        $this->assertFalse($session->regret_flag);

        $updated = $service->updateFeedback($id, true);
        $this->assertNotNull($updated);
        $this->assertTrue($updated->regret_flag);
        // Persistência: re-fetch confirma.
        $this->assertTrue(
            AtlasVoxDogfoodSession::query()->where('dogfood_session_id', $id)->firstOrFail()->regret_flag,
        );

        $unmarked = $service->updateFeedback($id, false);
        $this->assertNotNull($unmarked);
        $this->assertFalse($unmarked->regret_flag);
    }

    public function test_update_feedback_returns_null_for_unknown_session(): void
    {
        $this->assertNull($this->service()->updateFeedback('voxd_does_not_exist', true));
    }
}
