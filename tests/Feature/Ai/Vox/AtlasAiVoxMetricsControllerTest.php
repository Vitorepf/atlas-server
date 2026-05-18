<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Vox;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wave 7 feature tests — covers the 9 required scenarios in the prompt:
 *   1. metrics endpoint returns schema + safety hard gates
 *   2. rivals case POST validates payload and registers
 *   3. rivals report calculates wins / prompt_quality_delta
 *   4. gate-v3 blocked when no sessions yet
 *   5. gate-v3 blocks if raw_audio_persisted_count > 0
 *   6. gate-v3 blocks if confirmation_bypass_count > 0
 *   7. gate-v3 blocks if destructive_action_without_receipt > 0
 *   8. gate-v3 always demands manual Vitor approval
 *   9. endpoints do not touch Services/Ai/Voice (smoke)
 */
final class AtlasAiVoxMetricsControllerTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
        // The metrics surface MUST work whether or not the ledger table
        // exists. We materialize it explicitly here for the tests that
        // rely on events; tests that need an empty world drop it again.
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_vox_rivals_cases');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_20_010000_create_atlas_vox_rivals_cases_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_metrics_endpoint_returns_canonical_schema_and_safety_hard_gates(): void
    {
        $this->getJson('/ai/vox/metrics', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxMetricsService::SCHEMA)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('summary.total_sessions', 0)
            ->assertJsonPath('summary.real_usage_days', 0)
            ->assertJsonPath('modes.'.VoxSchema::MODE_DICTATION, 0)
            ->assertJsonPath('modes.'.VoxSchema::MODE_PROMPT_POLISH, 0)
            ->assertJsonPath('modes.'.VoxSchema::MODE_INTENT_COMPILE, 0)
            ->assertJsonPath('modes.'.VoxSchema::MODE_GOVERNED_EXECUTE, 0)
            ->assertJsonPath('safety.raw_audio_persisted_count', 0)
            ->assertJsonPath('safety.confirmation_bypass_count', 0)
            ->assertJsonPath('safety.destructive_action_without_receipt', 0)
            ->assertJsonPath('safety.eclipse_test_success_count', 0)
            ->assertJsonPath('hard_gates.raw_audio_persisted_count', 0)
            ->assertJsonPath('hard_gates.confirmation_bypass_count', 0)
            ->assertJsonPath('hard_gates.destructive_action_without_receipt', 0);
    }

    public function test_metrics_counts_sessions_by_mode_from_intent_compiled_events(): void
    {
        $this->seedIntent('dictation');
        $this->seedIntent('prompt_polish');
        $this->seedIntent('prompt_polish');
        $this->seedIntent('intent_compile');

        $this->getJson('/ai/vox/metrics', $this->headers)
            ->assertOk()
            ->assertJsonPath('summary.total_sessions', 4)
            ->assertJsonPath('modes.dictation', 1)
            ->assertJsonPath('modes.prompt_polish', 2)
            ->assertJsonPath('modes.intent_compile', 1)
            ->assertJsonPath('modes.governed_execute', 0);
    }

    public function test_rivals_case_post_validates_required_fields(): void
    {
        $this->postJson('/ai/vox/rivals/case', [
            'kind' => 'invalid_kind',
            'mode' => 'dictation',
            'baseline_label' => 'wispr',
            'preference' => 'vox',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind']);
    }

    public function test_rivals_case_post_rejects_raw_audio_fields(): void
    {
        $this->postJson('/ai/vox/rivals/case', [
            'kind' => 'provider_direct',
            'mode' => 'intent_compile',
            'baseline_label' => 'manual',
            'preference' => 'vox',
            'audio_bytes' => 'AAAA',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio_bytes']);
    }

    public function test_rivals_case_post_registers_durably_and_emits_event(): void
    {
        $response = $this->postJson('/ai/vox/rivals/case', [
            'kind' => 'provider_direct',
            'mode' => 'intent_compile',
            'vox_session_id' => 'sess-1',
            'vox_intent_id' => 'int-1',
            'baseline_label' => 'prompt manual codex',
            'baseline_duration_ms' => 120000,
            'vox_duration_ms' => 70000,
            'baseline_score' => 3,
            'vox_score' => 5,
            'preference' => 'vox',
            'prompt_quality_vote' => 1,
            'regret_flag' => false,
            'notes' => 'Vox cortou tempo pela metade',
        ], $this->headers);

        $response->assertStatus(201)
            ->assertJsonPath('schema', 'atlas.vox.rivals_case_recorded.v1')
            ->assertJsonPath('case.kind', 'provider_direct')
            ->assertJsonPath('case.mode', 'intent_compile')
            ->assertJsonPath('case.preference', 'vox')
            ->assertJsonPath('case.prompt_quality_vote', 1)
            ->assertJsonPath('case.regret_flag', false)
            ->assertJsonPath('events.0.event_kind', 'VOX_RIVALS_CASE_RECORDED');

        $this->assertDatabaseCount('atlas_vox_rivals_cases', 1);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::VoxRivalsCaseRecorded->value,
        ]);
    }

    public function test_rivals_report_aggregates_wins_quality_delta_and_multiplier(): void
    {
        $this->seedRivalsCase(['preference' => 'vox', 'prompt_quality_vote' => 1, 'baseline_duration_ms' => 100, 'vox_duration_ms' => 50]);
        $this->seedRivalsCase(['preference' => 'vox', 'prompt_quality_vote' => 1, 'baseline_duration_ms' => 200, 'vox_duration_ms' => 100]);
        $this->seedRivalsCase(['preference' => 'baseline', 'prompt_quality_vote' => -1]);
        $this->seedRivalsCase(['preference' => 'tie', 'prompt_quality_vote' => 0, 'regret_flag' => true]);

        $this->getJson('/ai/vox/rivals/report', $this->headers)
            ->assertOk()
            ->assertJsonPath('report.cases_total', 4)
            ->assertJsonPath('report.vox_wins', 2)
            ->assertJsonPath('report.baseline_wins', 1)
            ->assertJsonPath('report.ties', 1)
            ->assertJsonPath('report.prompt_quality_delta', 0.25)
            // Two timed cases, both 2x: average = 2. PHP json_encode
            // collapses 2.0 to 2 — assert the JSON-faithful value.
            ->assertJsonPath('report.rivals_voice_multiplier', 2)
            ->assertJsonPath('report.action_regret_score', 0.25);
    }

    public function test_gate_v3_warming_up_when_no_sessions_and_no_safety_violations(): void
    {
        // Eclipse triple-tap is the only hard gate that requires usage
        // evidence beyond "nothing happened yet" — seed it so we end
        // up in warming_up instead of blocked.
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();

        $this->getJson('/ai/vox/gate-v3', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', VoxV3PromotionGateService::SCHEMA)
            ->assertJsonPath('status', VoxV3PromotionGateService::STATUS_WARMING_UP)
            ->assertJsonPath('explicit_vitor_approval_required', true);
    }

    public function test_gate_v3_blocks_when_eclipse_never_tested(): void
    {
        $this->getJson('/ai/vox/gate-v3', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', VoxV3PromotionGateService::STATUS_BLOCKED)
            ->assertJsonPath('blockers.0', 'eclipse_test_success_min');
    }

    public function test_gate_v3_blocks_when_raw_audio_persisted_above_zero(): void
    {
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();
        $this->seedRawAudioPersisted();

        $payload = $this->getJson('/ai/vox/gate-v3', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', VoxV3PromotionGateService::STATUS_BLOCKED)
            ->json();

        $this->assertContains('raw_audio_persisted_zero', $payload['blockers']);
    }

    public function test_gate_v3_blocks_when_confirmation_bypass_count_above_zero(): void
    {
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();
        $this->seedActionBlocked('confirmation_bypass_attempted');

        $payload = $this->getJson('/ai/vox/gate-v3', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', VoxV3PromotionGateService::STATUS_BLOCKED)
            ->json();

        $this->assertContains('confirmation_bypass_zero', $payload['blockers']);
    }

    public function test_gate_v3_blocks_when_destructive_action_without_receipt_above_zero(): void
    {
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();
        $this->seedEclipseSuccess();
        $this->seedActionBlocked('destructive_action_without_receipt');

        $payload = $this->getJson('/ai/vox/gate-v3', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', VoxV3PromotionGateService::STATUS_BLOCKED)
            ->json();

        $this->assertContains('destructive_action_without_receipt_zero', $payload['blockers']);
    }

    public function test_gate_v3_always_requires_explicit_vitor_approval(): void
    {
        $payload = $this->getJson('/ai/vox/gate-v3', $this->headers)
            ->assertOk()
            ->json();
        $this->assertTrue($payload['explicit_vitor_approval_required']);
    }

    public function test_endpoints_do_not_touch_voice_realtime_surface(): void
    {
        // Snapshot file mtimes BEFORE any request. Then hit every Wave 7
        // endpoint and confirm no file under app/Services/Ai/Voice was
        // touched (defensive: we never load those classes at all).
        $voiceDir = base_path('app/Services/Ai/Voice');
        if (! is_dir($voiceDir)) {
            $this->markTestSkipped('app/Services/Ai/Voice not present on this build');
        }
        $before = $this->snapshotMtimes($voiceDir);

        $this->getJson('/ai/vox/metrics', $this->headers)->assertOk();
        $this->getJson('/ai/vox/rivals/report', $this->headers)->assertOk();
        $this->getJson('/ai/vox/gate-v3', $this->headers)->assertOk();

        $after = $this->snapshotMtimes($voiceDir);
        $this->assertSame($before, $after, 'no file under app/Services/Ai/Voice may be touched by Vox metrics endpoints');
    }

    /** @return array<string,int> */
    private function snapshotMtimes(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
            if ($f->isFile()) {
                $out[$f->getPathname()] = $f->getMTime();
            }
        }
        ksort($out);

        return $out;
    }

    private function seedIntent(string $mode): void
    {
        $sessionId = (string) Str::uuid();
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
                'intent_id' => (string) Str::uuid(),
                'mode' => $mode,
            ],
            'payload_hash' => hash('sha256', $sessionId.$mode),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedEclipseSuccess(): void
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
                'reason_code' => 'eclipse_aborted_mid_capture',
            ],
            'payload_hash' => hash('sha256', 'eclipse'.$sessionId),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedRawAudioPersisted(): void
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
                'raw_pcm_persisted' => true,
            ],
            'payload_hash' => hash('sha256', 'raw'.$sessionId),
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

    /** @param  array<string,mixed>  $overrides */
    private function seedRivalsCase(array $overrides): void
    {
        \App\Models\AtlasVoxRivalsCase::create(array_merge([
            'case_id' => 'voxc_'.(string) Str::uuid(),
            'kind' => 'provider_direct',
            'mode' => 'intent_compile',
            'baseline_label' => 'baseline',
            'preference' => 'vox',
            'regret_flag' => false,
        ], $overrides));
    }
}
