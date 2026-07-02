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
 * Wave 7 feature tests:
 *   1. metrics endpoint returns schema + safety hard gates
 *   2. gate-v3 blocked when no sessions yet
 *   3. gate-v3 blocks if raw_audio_persisted_count > 0
 *   4. gate-v3 blocks if confirmation_bypass_count > 0
 *   5. gate-v3 blocks if destructive_action_without_receipt > 0
 *   6. gate-v3 always demands manual Vitor approval
 *   7. endpoints do not touch Services/Ai/Voice (smoke)
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
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
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

    // ──────────────────────────────────────────────────────────────────
    // Wave 7.6 (Claude R) · V3 Certification Pack + Promotion Review
    // ──────────────────────────────────────────────────────────────────

    public function test_certification_pack_returns_schema_hash_and_review_required(): void
    {
        $response = $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', 'atlas.vox.v3_certification_pack.v1')
            ->assertJsonPath('vitor_review_required', true)
            ->assertJsonPath('v4_unlock_allowed', false)
            ->assertJsonPath('promotion_constraints.v4_unlock_performed_by_this_service', false);

        $hash = $response->json('certification_hash');
        $this->assertIsString($hash);
        $this->assertStringStartsWith('sha256:', $hash);

        // Pack must enumerate ALL the required safety invariants.
        $names = array_column($response->json('safety_invariants'), 'name');
        foreach (\App\Services\Ai\Vox\Gate\VoxV3CertificationPackService::SAFETY_INVARIANTS as $req) {
            $this->assertContains($req, $names, "invariante ausente: {$req}");
        }
    }

    public function test_certification_pack_includes_metrics_gate_and_blockers_blocks(): void
    {
        // Force a hard-safety violation by persisting a raw-pcm transcript.
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
                'raw_pcm_persisted' => true,
            ],
            'payload_hash' => hash('sha256', $sessionId),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);

        $response = $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)
            ->assertOk()
            ->assertJsonPath('gate_status', 'blocked')
            ->assertJsonPath('v4_unlock_allowed', false);

        $this->assertNotEmpty($response->json('metrics_snapshot'));
        $this->assertStringContainsString('BLOQUEADO', (string) $response->json('readiness_summary'));
        $blockers = $response->json('blockers');
        $this->assertNotEmpty($blockers);
        $codes = array_column($blockers, 'code');
        $this->assertContains('safety_invariant_raw_audio_persisted_count_zero_failed', $codes);
    }

    public function test_certification_pack_emits_ledger_event_with_hash_and_v4_unlock_false(): void
    {
        $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)->assertOk();

        $events = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxV3CertificationPackCreated->value)
            ->get();

        $this->assertCount(1, $events);
        $payload = $events[0]->payload;
        $this->assertStringStartsWith('sha256:', (string) ($payload['certification_hash'] ?? ''));
        $this->assertFalse($payload['v4_unlock_allowed']);
    }

    public function test_review_with_wrong_hash_is_rejected_and_does_not_unlock_v4(): void
    {
        $response = $this->postJson('/ai/vox/gate-v3/review', [
            'reviewed_by' => 'vitor',
            'decision' => 'approved_for_v4_planning',
            'certification_hash' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
            'notes' => 'tentativa de aprovação com hash inválido',
        ], $this->headers);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['certification_hash']);

        // Ledger MUST NOT contain a PromotionReviewRecorded event.
        $reviewEvents = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxV3PromotionReviewRecorded->value)
            ->count();
        $this->assertSame(0, $reviewEvents);

        // BUT a VOX_ACTION_BLOCKED must be present with the hash mismatch reason.
        $blocked = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxActionBlocked->value)
            ->whereJsonContains('payload->reason_code', 'v3_review_hash_mismatch')
            ->count();
        $this->assertGreaterThanOrEqual(1, $blocked);
    }

    public function test_review_with_correct_hash_records_but_does_not_unlock_v4(): void
    {
        $pack = $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)
            ->assertOk()
            ->json();

        $response = $this->postJson('/ai/vox/gate-v3/review', [
            'reviewed_by' => 'vitor',
            'decision' => 'approved_for_v4_planning',
            'certification_hash' => $pack['certification_hash'],
            'notes' => 'aprovado para planejar V4 manualmente',
        ], $this->headers);

        $response->assertStatus(201)
            ->assertJsonPath('schema', 'atlas.vox.v3_promotion_review.v1')
            ->assertJsonPath('reviewed_by', 'vitor')
            ->assertJsonPath('decision', 'approved_for_v4_planning')
            ->assertJsonPath('certification_hash', $pack['certification_hash'])
            // CRITICAL: even an approval NEVER flips a V4 unlock flag.
            ->assertJsonPath('v4_unlock_allowed', false)
            ->assertJsonPath('v4_unlocked_by_review', false);

        $events = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxV3PromotionReviewRecorded->value)
            ->get();
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]->payload['v4_unlocked_by_review']);
    }

    public function test_review_rejects_invalid_decision(): void
    {
        $pack = $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)->json();
        $this->postJson('/ai/vox/gate-v3/review', [
            'reviewed_by' => 'vitor',
            'decision' => 'unlock_v4_now', // not in allow-list
            'certification_hash' => $pack['certification_hash'],
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    public function test_review_rejects_audio_fields(): void
    {
        $pack = $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)->json();
        $this->postJson('/ai/vox/gate-v3/review', [
            'reviewed_by' => 'vitor',
            'decision' => 'needs_more_usage',
            'certification_hash' => $pack['certification_hash'],
            'raw_audio' => 'AAAA',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['raw_audio']);
    }

    public function test_review_with_needs_more_usage_decision_is_accepted(): void
    {
        $pack = $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)->json();
        $this->postJson('/ai/vox/gate-v3/review', [
            'reviewed_by' => 'vitor',
            'decision' => 'needs_more_usage',
            'certification_hash' => $pack['certification_hash'],
        ], $this->headers)
            ->assertStatus(201)
            ->assertJsonPath('decision', 'needs_more_usage')
            ->assertJsonPath('v4_unlock_allowed', false);
    }

    public function test_certification_endpoints_do_not_touch_voice_realtime_surface(): void
    {
        // The pack legitimately NAMES the voice_realtime invariant
        // (`voice_realtime_touched_false`) — what we forbid is any
        // reference to Voice Realtime *code paths* or atlas-app artefacts
        // leaking into the response.
        $pack = $this->getJson('/ai/vox/gate-v3/certification-pack', $this->headers)
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Services/Ai/Voice', $pack);
        $this->assertStringNotContainsString('app/Services/Ai/Voice', $pack);
        $this->assertStringNotContainsString('atlas-app/', $pack);
    }
}
