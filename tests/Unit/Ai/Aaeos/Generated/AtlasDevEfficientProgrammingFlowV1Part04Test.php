<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part04Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow rules
 * (Parte 4 · §26.4 Sumario Operacional + §27 Regra Final).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-04.md
 */
class AtlasDevEfficientProgrammingFlowV1Part04Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowV1Part04Service
    {
        return new AtlasDevEfficientProgrammingFlowV1Part04Service();
    }

    /**
     * §26.4 Flags — all three flags default false in prod; a disabled flag makes
     * the controller answer 503 with the documented code and NO side effect.
     * Plan -> ATLAS_DEV_PLAN_DISABLED, Run -> ATLAS_DEV_RUN_DISABLED.
     */
    public function test_flags_default_false_and_disabled_returns_503_no_side_effect(): void
    {
        $s = $this->service();
        $matrix = $s->flagMatrix();

        // All three default false in production.
        $this->assertFalse($matrix['plan']['default']);
        $this->assertFalse($matrix['run']['default']);
        $this->assertFalse($matrix['desktop']['default']);

        // Disabled Plan => 503 ATLAS_DEV_PLAN_DISABLED, no side effect, not allowed.
        $plan = $s->resolveFlagGate('plan', false);
        $this->assertFalse($plan['allowed']);
        $this->assertSame(503, $plan['http_status']);
        $this->assertSame('ATLAS_DEV_PLAN_DISABLED', $plan['code']);
        $this->assertFalse($plan['side_effect']);

        // Disabled Run => 503 ATLAS_DEV_RUN_DISABLED.
        $run = $s->resolveFlagGate('run', false);
        $this->assertSame('ATLAS_DEV_RUN_DISABLED', $run['code']);

        // Enabled => allowed, 200, no error code.
        $planOn = $s->resolveFlagGate('plan', true);
        $this->assertTrue($planOn['allowed']);
        $this->assertSame(200, $planOn['http_status']);
        $this->assertNull($planOn['code']);
    }

    /**
     * §26.4 — unlock order: Plan is safe first; Run only after Plan is green;
     * Desktop only after Plan. Enabling Run (or Desktop) before Plan is a violation.
     */
    public function test_unlock_order_run_requires_plan_green_first(): void
    {
        $s = $this->service();

        $bad = $s->unlockOrderRespected(false, true, false);
        $this->assertFalse($bad['order_respected']);
        $this->assertContains('run_enabled_before_plan', $bad['violations']);

        $deskBad = $s->unlockOrderRespected(false, false, true);
        $this->assertContains('desktop_enabled_before_plan', $deskBad['violations']);

        // Plan first, then Run, then Desktop => respected.
        $good = $s->unlockOrderRespected(true, true, true);
        $this->assertTrue($good['order_respected']);
        $this->assertSame([], $good['violations']);
    }

    /**
     * §26.4 APP_KEY fail-closed — must be base64:... decoding to >= 32 bytes.
     * Absent or short => Plan AND Run fail closed with 500 ATLAS_DEV_KEY_MISSING.
     * A valid 32-byte key passes and blocks nothing.
     */
    public function test_app_key_fail_closed_blocks_plan_and_run(): void
    {
        $s = $this->service();

        // Absent key => fail closed, both blocked, 500 ATLAS_DEV_KEY_MISSING.
        $absent = $s->validateAppKey(null);
        $this->assertFalse($absent['valid']);
        $this->assertTrue($absent['fail_closed']);
        $this->assertTrue($absent['plan_blocked']);
        $this->assertTrue($absent['run_blocked']);
        $this->assertSame(500, $absent['http_status']);
        $this->assertSame('ATLAS_DEV_KEY_MISSING', $absent['code']);
        $this->assertSame('key_absent', $absent['reason']);

        // 16 decoded bytes < 32 => too short, still fail closed.
        $short = $s->validateAppKey('base64:' . base64_encode(str_repeat('x', 16)));
        $this->assertFalse($short['valid']);
        $this->assertSame(16, $short['decoded_bytes']);
        $this->assertSame('key_too_short', $short['reason']);
        $this->assertSame('ATLAS_DEV_KEY_MISSING', $short['code']);

        // Missing base64: prefix => fail closed.
        $noPrefix = $s->validateAppKey(str_repeat('y', 40));
        $this->assertFalse($noPrefix['valid']);
        $this->assertSame('key_not_base64_prefixed', $noPrefix['reason']);

        // Exactly 32 decoded bytes => valid, nothing blocked, no error envelope.
        $ok = $s->validateAppKey('base64:' . base64_encode(str_repeat('k', 32)));
        $this->assertTrue($ok['valid']);
        $this->assertSame(32, $ok['decoded_bytes']);
        $this->assertFalse($ok['fail_closed']);
        $this->assertNull($ok['http_status']);
        $this->assertNull($ok['code']);
    }

    /**
     * §26.4 Stream — snapshot-replay-then-close: exactly one terminal
     * stream_closed which must be last; nothing may follow it; no keepalive; the
     * REST endpoint is the source of truth.
     */
    public function test_stream_snapshot_replay_then_close_contract(): void
    {
        $s = $this->service();

        $valid = $s->validateStreamSequence(['phase:plan', 'phase:run', 'receipt:run', 'stream_closed']);
        $this->assertTrue($valid['valid']);
        $this->assertSame('valid_snapshot_replay_then_close', $valid['reason']);
        $this->assertSame(1, $valid['close_count']);
        $this->assertFalse($valid['keepalive_expected']);
        $this->assertSame('GET /runs/{run_id}', $valid['source_of_truth']);

        // Anything after stream_closed is illegal.
        $afterClose = $s->validateStreamSequence(['phase:plan', 'stream_closed', 'phase:extra']);
        $this->assertFalse($afterClose['valid']);
        $this->assertSame(1, $afterClose['events_after_close']);
        $this->assertSame('events_after_stream_closed', $afterClose['reason']);

        // Missing the terminal marker is illegal.
        $noClose = $s->validateStreamSequence(['phase:plan', 'receipt:run']);
        $this->assertFalse($noClose['valid']);
        $this->assertSame('missing_stream_closed_marker', $noClose['reason']);

        // Two close markers is illegal.
        $doubleClose = $s->validateStreamSequence(['phase:plan', 'stream_closed', 'stream_closed']);
        $this->assertFalse($doubleClose['valid']);
        $this->assertSame('multiple_stream_closed_markers', $doubleClose['reason']);
    }

    /**
     * §26.4 Path redaction — a body containing "/Users/" or the receipts storage
     * path leaks; redaction projects a workspace path into basename label +
     * provider-safe hash + receipts/<run_id>/<file>, with no absolute leak.
     */
    public function test_path_redaction_smoke_and_projection(): void
    {
        $s = $this->service();

        // Leak detection: absolute user path and the receipts storage path both fail.
        $this->assertTrue($s->bodyLeaksAbsolutePath('{"p":"/Users/op/dev/ws/file.php"}'));
        $this->assertTrue($s->bodyLeaksAbsolutePath('see storage/atlas-dev/receipts/r1/plan.json'));
        $this->assertFalse($s->bodyLeaksAbsolutePath('{"workspace_label":"ws","ref":"receipts/r1/plan.json"}'));

        $leak = $s->redactionSmoke('{"path":"/Users/op/ws"}');
        $this->assertFalse($leak['passes']);
        $this->assertContains('/Users/', $leak['found']);

        $clean = $s->redactionSmoke('{"workspace_label":"ws","workspace_hash":"abc123"}');
        $this->assertTrue($clean['passes']);
        $this->assertSame([], $clean['found']);

        // Projection: /Users/op/dev/my-ws => label "my-ws", ref receipts/<run>/<file>.
        $proj = $s->redactWorkspacePath(
            '/Users/op/dev/my-ws/app/Foo.php',
            '/Users/op/dev/my-ws',
            'ws_9f2c',
            'run_77',
            'plan.json'
        );
        $this->assertSame('my-ws', $proj['workspace_label']);
        $this->assertSame('ws_9f2c', $proj['workspace_hash']);
        $this->assertSame('receipts/run_77/plan.json', $proj['persisted_ref']);
        $this->assertFalse($proj['leaks_absolute']);
    }

    /**
     * §26.4 Follow-ups — the HMAC confirmation pin covers exactly
     * {task_contract_hash, compact_sdd_hash} today (mismatch => 422 with the
     * matching code); envelope_hash / prompt_projection_hash are documented as
     * NOT yet covered, so a mismatch there does not block. App/public-API and the
     * single full-bundle hash are listed as not delivered.
     */
    public function test_hmac_confirmation_pin_scope_and_delivery_state(): void
    {
        $s = $this->service();

        // Pinned hash mismatch => blocks with 422 + COMPACT_SDD_TAMPERED.
        $sdd = $s->confirmationPinVerdict('compact_sdd_hash', true);
        $this->assertTrue($sdd['pinned']);
        $this->assertTrue($sdd['blocked']);
        $this->assertSame(422, $sdd['http_status']);
        $this->assertSame('COMPACT_SDD_TAMPERED', $sdd['code']);

        $tc = $s->confirmationPinVerdict('task_contract_hash', true);
        $this->assertSame('TASK_CONTRACT_HASH_MISMATCH', $tc['code']);
        $this->assertTrue($tc['blocked']);

        // Unpinned hash mismatch => documented gap, NOT yet covered, does not block.
        $env = $s->confirmationPinVerdict('envelope_hash', true);
        $this->assertFalse($env['pinned']);
        $this->assertFalse($env['blocked']);
        $this->assertSame('mismatch_on_unpinned_hash_not_yet_covered', $env['reason']);

        // No mismatch => never blocks.
        $noMismatch = $s->confirmationPinVerdict('task_contract_hash', false);
        $this->assertFalse($noMismatch['blocked']);

        // Delivery state separates delivered vs documented follow-ups.
        $state = $s->deliveryState();
        $this->assertEqualsCanonicalizing(['task_contract_hash', 'compact_sdd_hash'], $state['hmac_pinned_hashes']);
        $this->assertContains('live_async_stream', $state['not_delivered']);
        $this->assertContains('public_full_bundle_hash_for_operator', $state['not_delivered']);
        $this->assertContains('hmac_pin_over_envelope_and_prompt_projection', $state['not_delivered']);
        $this->assertContains('app_key_fail_closed', $state['delivered']);
    }

    /**
     * §27 Regra Final — Atlas Dev wins by SYSTEM, not by model; the ordered
     * maxims survive intact and the slice is marked canonical contract.
     */
    public function test_final_rule_wins_by_system_not_model(): void
    {
        $s = $this->service();

        $rule = $s->finalRule();
        $this->assertSame('system_not_model', $rule['wins_by']);
        $this->assertTrue($rule['canonical_contract']);
        $this->assertContains('falha honesta', $rule['maxims']);
        $this->assertContains('repair pequeno', $rule['maxims']);
        $this->assertSame('forge quando precisa', $rule['maxims'][count($rule['maxims']) - 1]);
    }
}
