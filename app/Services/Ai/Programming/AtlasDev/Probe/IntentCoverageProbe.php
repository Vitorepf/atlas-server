<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Probe;

use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;

/**
 * E2 — Intent coverage probe.
 *
 * Determines whether a write task's intent is backed by at least one
 * behavioral acceptance criterion carrying a real verification_ref. The
 * result drives the `intent_not_tested` honesty flag (advisory channel):
 * when no behavioral AC backs the intent, the flag fires and the
 * CompletionStateGate auto-downgrades PASSED -> needs_review (the
 * passed-forbids-flags invariant guarantees no green-with-flag).
 *
 * VAL-E2-009: intent_not_tested fires when only tautological ACs back the
 * intent (no behavioral AC with a real verification_ref), so the completion
 * is NOT passed.
 * VAL-E2-010: intent_not_tested does NOT fire when a behavioral AC with a
 * real verification_ref backs the intent.
 *
 * The probe is DETERMINISTIC and model-irrelevant: it inspects the
 * SpecComposer-produced acceptanceCriteria (behavioral ACs have ids prefixed
 * with `ac_behavior_` and carry a non-empty verification_ref). It is NOT the
 * same as the E1 intent-falsification probe (which inspects the diff against
 * the intent verb); this probe inspects the SPEC, not the diff, and answers
 * "does the spec define a real verification path for this intent?".
 *
 * Read-only / review / escalate-preview tasks never trip the flag: the
 * intent_text is empty for those paths, and the probe returns false (no
 * untested intent to flag) so the flag never fires for non-write tasks.
 *
 * Gated by atlas_dev.elevations.e2.mode: off => the probe never reports
 * intent_not_tested (byte-identical to pre-E2 behavior, no E2 flag raised).
 */
final class IntentCoverageProbe
{
    /**
     * The honesty flag name appended when the intent is not backed by any
     * behavioral AC with a real verification_ref.
     */
    public const FLAG_INTENT_NOT_TESTED = 'intent_not_tested';

    /**
     * Returns true when the write task's intent is NOT backed by any
     * behavioral AC carrying a real verification_ref (VAL-E2-009). Returns
     * false when:
     *   - the task is not a write task (intent_text empty => no intent to
     *     flag, byte-identical to pre-E2 for read-only paths);
     *   - OR at least one behavioral AC with a real verification_ref backs
     *     the intent (VAL-E2-010: the intent IS tested).
     *
     * @param  ?MiniProgrammingSpec  $miniSpec  the composed spec carrying
     *                                          acceptanceCriteria; null when the spec cannot be read (degrades to
     *                                          "not tested" for a write task, mirroring the safe-degradation
     *                                          pattern: an unevaluable intent is never silently green).
     */
    public function isIntentNotTested(LightTaskContract $taskContract, ?MiniProgrammingSpec $miniSpec): bool
    {
        // Only write tasks (non-empty intent_text) can trip the flag.
        // Read-only / review / escalate-preview tasks carry an empty
        // intent_text and never trip it (byte-identical to pre-E2).
        if (trim($taskContract->intentText) === '') {
            return false;
        }

        // When the spec is unavailable, a write task's intent is conservatively
        // treated as not-tested (never silently green over an unevaluable
        // intent). This mirrors the E5 DatabaseTableAvailability safe-
        // degradation pattern: absent evidence => honest flag, not a pass.
        if ($miniSpec === null) {
            return true;
        }

        return ! $this->hasBehavioralAcWithRealVerificationRef($miniSpec);
    }

    /**
     * Whether the spec carries at least one behavioral AC (id prefixed with
     * `ac_behavior_`) with a non-empty, non-placeholder verification_ref.
     * The behavioral ACs are emitted by SpecComposer::buildBehavioralAcceptanceCriteria
     * and carry a real test command as their verification_ref.
     */
    private function hasBehavioralAcWithRealVerificationRef(MiniProgrammingSpec $miniSpec): bool
    {
        foreach ($miniSpec->acceptanceCriteria as $ac) {
            if (! is_array($ac)) {
                continue;
            }
            $id = (string) ($ac['id'] ?? '');
            if (! str_starts_with($id, 'ac_behavior_')) {
                continue;
            }
            $ref = $ac['verification_ref'] ?? null;
            if (is_string($ref) && trim($ref) !== '') {
                return true;
            }
        }

        return false;
    }
}
