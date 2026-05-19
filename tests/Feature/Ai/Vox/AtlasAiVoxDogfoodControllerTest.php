<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Vox;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 7.9 feature tests for the dogfood session evidence surface.
 *
 *   1. POST /ai/vox/dogfood/session creates a durable row + ledger event.
 *   2. notes > 1000 chars is rejected with 422.
 *   3. unknown outcome / mode is rejected.
 *   4. raw audio fields are rejected (Lei 0.75 parity with rivals).
 *   5. GET /ai/vox/dogfood/report aggregates rates honestly.
 *   6. regret_rate + eclipse_used_count are computed.
 *   7. The endpoint does NOT mutate Voice RT or rivals/metrics.
 */
final class AtlasAiVoxDogfoodControllerTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
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

    public function test_post_dogfood_session_persists_and_emits_event(): void
    {
        $response = $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'intent_compile',
            'outcome' => 'success',
            'vox_session_id' => 'sess-1',
            'used_hotkey' => true,
            'used_real_stt' => true,
            'used_governed_execute' => false,
            'duration_ms' => 9_999,
            'notes' => 'foi bom',
        ], $this->headers);

        $response->assertCreated()
            ->assertJsonPath('schema', VoxDogfoodService::SCHEMA_SESSION)
            ->assertJsonPath('session.mode', 'intent_compile')
            ->assertJsonPath('session.outcome', 'success')
            ->assertJsonPath('session.used_hotkey', true)
            ->assertJsonPath('session.used_real_stt', true)
            ->assertJsonPath('events.0.event_kind', 'VOX_DOGFOOD_SESSION_RECORDED');

        // Ledger event was actually persisted under the new event type.
        $this->assertSame(
            1,
            AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::VoxDogfoodSessionRecorded->value)
                ->count(),
        );
    }

    public function test_post_rejects_unknown_outcome(): void
    {
        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'dictation',
            'outcome' => 'kind_of_worked',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outcome']);
    }

    public function test_post_rejects_unknown_mode(): void
    {
        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'voice_rt',
            'outcome' => 'success',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_post_rejects_notes_over_max_chars(): void
    {
        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'dictation',
            'outcome' => 'success',
            'notes' => str_repeat('a', VoxDogfoodService::NOTES_MAX_CHARS + 1),
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_post_rejects_raw_audio_fields(): void
    {
        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'dictation',
            'outcome' => 'success',
            'audio_bytes' => 'AAAA',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio_bytes']);
    }

    public function test_get_report_empty_world(): void
    {
        $this->getJson('/ai/vox/dogfood/report', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxDogfoodService::SCHEMA_REPORT)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('sessions_total', 0)
            ->assertJsonPath('sessions_last_7_days', 0)
            ->assertJsonPath('recommendation', 'no_dogfood_sessions_yet')
            ->assertJsonPath('gate_v3_contribution.integration_mode', 'informational_only');
    }

    public function test_get_report_aggregates_after_posts(): void
    {
        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'dictation',
            'outcome' => 'success',
            'used_hotkey' => true,
            'used_real_stt' => true,
        ], $this->headers)->assertCreated();

        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'governed_execute',
            'outcome' => 'success',
            'used_governed_execute' => true,
            'eclipse_used' => true,
        ], $this->headers)->assertCreated();

        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'prompt_polish',
            'outcome' => 'failed',
            'regret_flag' => true,
        ], $this->headers)->assertCreated();

        $report = $this->getJson('/ai/vox/dogfood/report', $this->headers)
            ->assertOk()
            ->json();

        $this->assertSame(3, $report['sessions_total']);
        // 2/3 success.
        $this->assertEqualsWithDelta(0.6667, $report['success_rate'], 0.001);
        // 1/3 regret.
        $this->assertEqualsWithDelta(0.3333, $report['regret_rate'], 0.001);
        $this->assertSame(1, $report['governed_execute_usage_count']);
        $this->assertSame(1, $report['eclipse_used_count']);
        // regret_rate 33% >= 20% triggers the regret recommendation.
        $this->assertSame('investigate_regret_pattern', $report['recommendation']);
    }

    public function test_get_report_eclipse_count_is_independent_of_outcome(): void
    {
        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'governed_execute',
            'outcome' => 'cancelled',
            'eclipse_used' => true,
        ], $this->headers)->assertCreated();

        $this->getJson('/ai/vox/dogfood/report', $this->headers)
            ->assertOk()
            ->assertJsonPath('eclipse_used_count', 1)
            ->assertJsonPath('outcomes.cancelled', 1);
    }

    public function test_dogfood_does_not_touch_voice_services_or_rivals(): void
    {
        // Sanity: dropping the rivals table must not affect the dogfood
        // surface — they are independent.
        Schema::dropIfExists('atlas_vox_rivals_cases');

        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => VoxSchema::MODE_DICTATION,
            'outcome' => 'success',
        ], $this->headers)->assertCreated();

        $this->getJson('/ai/vox/dogfood/report', $this->headers)
            ->assertOk()
            ->assertJsonPath('sessions_total', 1);
    }

    public function test_endpoint_requires_atlas_token(): void
    {
        // No header → 401/403 (depending on middleware), but definitely not
        // a 201. We only assert the route is protected.
        $this->postJson('/ai/vox/dogfood/session', [
            'mode' => 'dictation',
            'outcome' => 'success',
        ])->assertStatus(401);
    }
}
