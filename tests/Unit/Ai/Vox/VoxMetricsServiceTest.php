<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasVoxRivalsCase;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VoxMetricsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_vox_rivals_cases');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    private function bootTables(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_20_010000_create_atlas_vox_rivals_cases_table.php'))->up();
    }

    public function test_returns_zero_shape_when_tables_missing(): void
    {
        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(VoxMetricsService::SCHEMA, $snap['schema']);
        $this->assertSame(0, $snap['summary']['total_sessions']);
        $this->assertSame(0, $snap['safety']['raw_audio_persisted_count']);
        $this->assertSame(0.0, $snap['rivals']['prompt_quality_delta']);
        $this->assertSame(0, $snap['summary']['real_usage_days']);
    }

    public function test_counts_unique_sessions_only_once_per_session_id(): void
    {
        $this->bootTables();
        $sessionA = (string) Str::uuid();
        // Two events from the same session — should count once.
        $this->seedIntentCompiled($sessionA, 'dictation');
        $this->seedIntentCompiled($sessionA, 'dictation');
        $this->seedIntentCompiled((string) Str::uuid(), 'prompt_polish');

        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(2, $snap['summary']['total_sessions']);
        $this->assertSame(1, $snap['modes']['dictation']);
        $this->assertSame(1, $snap['modes']['prompt_polish']);
    }

    public function test_raw_audio_persisted_counter_increments_on_raw_pcm_true_event(): void
    {
        $this->bootTables();
        $this->seedTranscriptReady(rawPcmPersisted: true);
        $this->seedTranscriptReady(rawPcmPersisted: false);
        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(1, $snap['safety']['raw_audio_persisted_count']);
    }

    public function test_confirmation_bypass_counter_includes_token_invalid_and_expired(): void
    {
        $this->bootTables();
        $this->seedActionBlocked('confirmation_bypass_attempted');
        $this->seedActionBlocked('confirmation_token_invalid');
        $this->seedActionBlocked('confirmation_token_expired');
        $this->seedActionBlocked('some_other_reason');
        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(3, $snap['safety']['confirmation_bypass_count']);
    }

    public function test_destructive_action_without_receipt_is_counted_separately(): void
    {
        $this->bootTables();
        $this->seedActionBlocked('destructive_action_without_receipt');
        $this->seedActionBlocked('destructive_action_without_receipt');
        $this->seedActionBlocked('confirmation_bypass_attempted');
        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(2, $snap['safety']['destructive_action_without_receipt']);
    }

    public function test_eclipse_success_counts_eclipse_block_events(): void
    {
        $this->bootTables();
        $this->seedActionBlocked('eclipse_aborted_mid_capture');
        $this->seedActionBlocked('eclipse_active');
        $this->seedActionBlocked('eclipse_test_success');
        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(3, $snap['safety']['eclipse_test_success_count']);
    }

    public function test_rivals_aggregates_quality_delta_and_multiplier_and_regret(): void
    {
        $this->bootTables();
        AtlasVoxRivalsCase::create([
            'case_id' => 'voxc_'.Str::uuid(),
            'kind' => 'provider_direct',
            'mode' => 'intent_compile',
            'baseline_label' => 'a',
            'preference' => 'vox',
            'prompt_quality_vote' => 1,
            'baseline_duration_ms' => 100,
            'vox_duration_ms' => 50,
            'regret_flag' => false,
        ]);
        AtlasVoxRivalsCase::create([
            'case_id' => 'voxc_'.Str::uuid(),
            'kind' => 'manual',
            'mode' => 'governed_execute',
            'baseline_label' => 'b',
            'preference' => 'vox',
            'prompt_quality_vote' => 1,
            'baseline_duration_ms' => 200,
            'vox_duration_ms' => 100,
            'regret_flag' => true,
        ]);

        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(2, $snap['rivals']['cases_total']);
        $this->assertSame(2, $snap['rivals']['vox_wins']);
        $this->assertSame(1.0, $snap['rivals']['prompt_quality_delta']); // 2× +1
        $this->assertSame(2.0, $snap['rivals']['rivals_voice_multiplier']);
        $this->assertSame(0.5, $snap['rivals']['action_regret_score']);
    }

    public function test_usage_window_collapses_same_day_sessions_to_one_day(): void
    {
        $this->bootTables();
        $sameDay = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $this->seedIntentCompiled((string) Str::uuid(), 'dictation', $sameDay);
        $this->seedIntentCompiled((string) Str::uuid(), 'dictation', $sameDay->addHours(2));
        $snap = (new VoxMetricsService())->snapshot();
        $this->assertSame(1, $snap['summary']['real_usage_days']);
        $this->assertSame(2.0, $snap['summary']['average_sessions_per_day']);
    }

    private function seedIntentCompiled(string $sessionId, string $mode, ?CarbonImmutable $at = null): void
    {
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxIntentCompiled->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'mode' => $mode,
                'intent_id' => (string) Str::uuid(),
            ],
            'payload_hash' => hash('sha256', $sessionId.$mode),
            'occurred_at' => $at ?? CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedTranscriptReady(bool $rawPcmPersisted): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxTranscriptReady->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'transcript_id' => (string) Str::uuid(),
                'raw_pcm_persisted' => $rawPcmPersisted,
            ],
            'payload_hash' => hash('sha256', $sessionId.($rawPcmPersisted ? 'r' : 'n')),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedActionBlocked(string $reasonCode): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxActionBlocked->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'reason_code' => $reasonCode,
            ],
            'payload_hash' => hash('sha256', $reasonCode.$sessionId),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
