<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowRunbookV1Part07Service;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow runbook rules
 * (Parte 7 · §10.4 Run gate + confirmation token + streaming policy, §11 repair
 * loop, §12.1 surface boundary).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md
 */
class AtlasDevEffProgFlowRunbookV1Part07Test extends TestCase
{
    private function service(): AtlasDevEffProgFlowRunbookV1Part07Service
    {
        return new AtlasDevEffProgFlowRunbookV1Part07Service();
    }

    /**
     * §10.4 — the Run gate accepts only when operator_confirmed=true AND the
     * task_contract_hash matches AND the confirmation token is single-use valid;
     * each missing condition rejects with its documented HTTP status, in the
     * documented precedence (key 500 > confirmation 400 > hash 422 > token 403).
     */
    public function test_run_gate_requires_all_three_conditions_with_documented_statuses(): void
    {
        $s = $this->service();

        $accept = $s->evaluateRunGate(true, true, $s::TOKEN_VALID);
        $this->assertTrue($accept['accepted']);
        $this->assertSame($s::HTTP_OK, $accept['http_status']);

        // Missing operator_confirmed -> 400.
        $missing = $s->evaluateRunGate(false, true, $s::TOKEN_VALID);
        $this->assertFalse($missing['accepted']);
        $this->assertSame(400, $missing['http_status']);
        $this->assertSame('operator_confirmed', $missing['failed_condition']);

        // Invalid task_contract_hash -> 422.
        $this->assertSame(422, $s->evaluateRunGate(true, false, $s::TOKEN_VALID)['http_status']);

        // Token absent/invalid/expired/reused/contract-mismatch -> 403.
        $this->assertSame(403, $s->evaluateRunGate(true, true, $s::TOKEN_REUSED)['http_status']);
        $this->assertSame(403, $s->evaluateRunGate(true, true, $s::TOKEN_EXPIRED)['http_status']);

        // APP_KEY < 32 bytes fails closed with ATLAS_DEV_KEY_MISSING (500), and
        // this precondition outranks every other failure.
        $keyMissing = $s->evaluateRunGate(false, false, $s::TOKEN_ABSENT, false);
        $this->assertSame(500, $keyMissing['http_status']);
        $this->assertSame('ATLAS_DEV_KEY_MISSING', $keyMissing['error']);
    }

    /**
     * §10.4 — confirmation token: TTL is 300s (5min); a token at/after the TTL
     * is expired; a token bound to a different (run_id, task_contract_hash) is a
     * contract mismatch; an already-consumed token is reused; a token "issued"
     * for a non-fast-path routing decision is not issuable at all.
     */
    public function test_confirmation_token_ttl_binding_single_use_and_issuance(): void
    {
        $s = $this->service();
        $this->assertSame(300, $s::CONFIRMATION_TOKEN_TTL_SECONDS);

        // Fresh, bound, unconsumed -> redeemable.
        $valid = $s->decideConfirmationToken(true, true, true, false, 10);
        $this->assertTrue($valid['redeemable']);
        $this->assertSame($s::TOKEN_VALID, $valid['state']);

        // Exactly at the TTL boundary -> expired (issued_at + TTL <= now).
        $this->assertSame($s::TOKEN_EXPIRED, $s->decideConfirmationToken(true, true, true, false, 300)['state']);

        // Bound to another plan -> contract mismatch (never redeems).
        $this->assertSame($s::TOKEN_CONTRACT_MISMATCH, $s->decideConfirmationToken(true, false, true, false, 10)['state']);

        // Already consumed -> reused.
        $this->assertSame($s::TOKEN_REUSED, $s->decideConfirmationToken(true, true, true, true, 10)['state']);

        // Issued outside the fast path -> not issuable.
        $notIssuable = $s->decideConfirmationToken(true, true, true, false, 10, 'forge_promotion_preview');
        $this->assertFalse($notIssuable['redeemable']);
        $this->assertSame('not_issuable_outside_fast_path', $notIssuable['reason']);

        // Contract: DB+HMAC, no filesystem store, issued only on fast path.
        $contract = $s->confirmationTokenContract();
        $this->assertFalse($contract['filesystem_store']);
        $this->assertSame('hmac_sha256', $contract['token_hash_algorithm']);
        $this->assertSame('atlas_dev_fast_path', $contract['issued_on_routing_decision']);
    }

    /**
     * §10.4 — the locked streaming policy is snapshot-replay-then-close with NO
     * keepalive: the events arrive in a deterministic order ending with the
     * receipt, then the `stream_closed` terminal marker, and REST GET
     * /runs/{run_id} is the resume contract / source of truth.
     */
    public function test_streaming_policy_order_terminal_marker_and_rest_resume(): void
    {
        $s = $this->service();
        $plan = $s->streamingPlan();

        $this->assertSame('snapshot_replay_then_close', $plan['policy']);
        $this->assertFalse($plan['keepalive']);
        $this->assertSame('stream_closed', $plan['terminal_marker']);

        // receipt precedes stream_closed, and stream_closed is the last event.
        $order = $plan['event_order'];
        $this->assertSame('stream_closed', $order[array_key_last($order)]);
        $this->assertLessThan(
            array_search('stream_closed', $order, true),
            array_search('receipt', $order, true),
            'receipt must be emitted before the stream closes',
        );

        // The REST resume contract exposes the documented fields.
        $this->assertContains('completion_state', $plan['rest_resume_fields']);
        $this->assertContains('persisted_artifact_refs', $plan['rest_resume_fields']);
    }

    /**
     * §11.2 PR 4.1 — the failure_signature is deterministic for the same gate +
     * normalized error and matches the runtime DTO FailureCapsule::signatureOf()
     * byte-for-byte; whitespace/case normalization collapses surface noise so
     * two "same reason" errors share a signature.
     */
    public function test_failure_signature_is_deterministic_and_matches_runtime_dto(): void
    {
        $s = $this->service();

        $a = $s->failureSignatureOf('verifying', 'Assertion failed: expected 1');
        $b = $s->failureSignatureOf('verifying', '  Assertion   FAILED: Expected 1 ');
        $this->assertSame($a, $b, 'normalization must collapse whitespace and case');

        // Different gate -> different signature.
        $this->assertNotSame($a, $s->failureSignatureOf('scope_guarding', 'Assertion failed: expected 1'));

        // Exact parity with the runtime DTO contract.
        $this->assertSame(
            FailureCapsule::signatureOf('verifying', 'Assertion failed: expected 1'),
            $a,
        );
    }

    /**
     * §11.2 PR 4.1 / PR 4.2 — repair-loop step decision:
     *   green                       -> continue (no new capsule);
     *   first fail under budget     -> retry;
     *   same signature twice        -> escalate;
     *   diff growing                -> escalate;
     *   max_attempts reached        -> stop.
     * Escalation signals win over the budget check.
     */
    public function test_repair_loop_decision_continue_retry_stop_escalate(): void
    {
        $s = $this->service();

        // Green attempt -> continue, no new capsule.
        $green = $s->decideRepairLoopStep(true, 0, 3, false, false, false);
        $this->assertSame($s::ORCHESTRATOR_CONTINUE, $green['decision']);
        $this->assertFalse($green['new_capsule']);

        // First fail with budget left -> retry.
        $this->assertSame($s::REPAIR_RETRY, $s->decideRepairLoopStep(false, 0, 3, false, false, false)['decision']);

        // Same signature twice -> escalate (carries the signal).
        $escalate = $s->decideRepairLoopStep(false, 1, 3, true, false, false);
        $this->assertSame($s::REPAIR_ESCALATE, $escalate['decision']);
        $this->assertContains('same_signature_twice', $escalate['signals']);

        // Diff growing -> escalate even with budget remaining.
        $this->assertSame($s::REPAIR_ESCALATE, $s->decideRepairLoopStep(false, 0, 3, false, true, false)['decision']);

        // Budget exhausted (attempt_index 3, max 3 -> next would be 4) -> stop.
        $stop = $s->decideRepairLoopStep(false, 3, 3, false, false, false);
        $this->assertSame($s::REPAIR_STOP, $stop['decision']);
        $this->assertSame(0, $stop['retries_remaining']);

        // abort_on_same_signature_twice = false demotes the same-signature case
        // back to a budgeted retry instead of an escalation.
        $this->assertSame($s::REPAIR_RETRY, $s->decideRepairLoopStep(false, 0, 3, true, false, false, false)['decision']);
    }

    /**
     * §12.1 — the desktop surface adapter receives raw intent + workspace + UX
     * selections and MUST NOT receive the final prompt, build a
     * ProviderPromptProjection on the frontend, or accept a custom prompt.
     */
    public function test_surface_adapter_boundary_rejects_forbidden_inputs(): void
    {
        $s = $this->service();
        $boundary = $s->surfaceAdapterBoundary();

        $this->assertFalse($boundary['builds_prompt_projection_on_frontend']);
        $this->assertFalse($boundary['allows_custom_prompt']);
        $this->assertContains('raw_intent', $boundary['allowed_inputs']);
        $this->assertContains('final_prompt', $boundary['forbidden_inputs']);

        // Allowed-only inputs -> conformant.
        $ok = $s->evaluateSurfaceInputs(['raw_intent', 'workspace', 'ux_selections']);
        $this->assertTrue($ok['conformant']);
        $this->assertSame([], $ok['violations']);

        // A forbidden input -> non-conformant, fail closed.
        $bad = $s->evaluateSurfaceInputs(['raw_intent', 'provider_prompt_projection']);
        $this->assertFalse($bad['conformant']);
        $this->assertContains('provider_prompt_projection', $bad['violations']);
    }
}
