<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §4 · RECURSIVE SELF-IMPROVEMENT — BUILT but GATED (the exponential source AND the max-Goodhart surface).
 *
 * The brain may PROPOSE improvements to its OWN code (the loop's scope IS its own machinery), but two
 * invariants hold, in this strict order:
 *
 *   1. CONSTITUTION (absolute, flag-independent) — a self-edit to a CERT ORGAN (the frozen judge, the
 *      architect gate, the harness guard, …; {@see AtlasLoopHarnessGuard::isForbiddenSelfTarget}) is REFUSED
 *      no matter what. The loop can never improve its way into weakening the gate that judges it. No flag,
 *      no policy, no operator switch reaches past this — it is checked FIRST and short-circuits.
 *   2. POLICY (operator's lever) — a self-edit to a NON-pétreo brain file is a LEGAL proposal, but APPLYING
 *      it unattended needs explicit operator policy (`recursive_self_improvement_auto_apply`, default-OFF).
 *      OFF ⇒ the proposal is PARKED (propose-only) — the loop never edits itself without a human in the loop.
 *
 * Pure + deterministic (no provider, no I/O beyond the pétreo authority + the flag). This is the gate; the
 * actual application path stays propose-only and is the operator's to arm. Built, NOT activated.
 */
final class AtlasLoopRecursiveSelfImprovementGate
{
    public const STATUS_REFUSED_PETREO = 'refused_constitution_petreo';

    public const STATUS_PARKED = 'parked_for_operator';

    public const STATUS_AUTO_APPLY_ARMED = 'auto_apply_armed';

    public function __construct(private readonly ?AtlasLoopHarnessGuard $guard = null)
    {
    }

    /** A self-improvement proposal HARDENS the brain (adds a guard/test/forbidden — only-adds-never-loosens). */
    public const KIND_HARDEN = 'harden';

    /** A self-improvement proposal IMPROVES a non-pétreo brain file's behaviour (riskier than hardening). */
    public const KIND_IMPROVE = 'improve';

    /**
     * @param  string  $intent  the proposal's stated kind (harden|improve) — metadata for the policy, NEVER a
     *                          bypass: the constitution is checked FIRST and is intent-independent.
     * @return array{admitted:bool, auto_apply:bool, status:string, reason:?string, kind:?string}
     */
    public function evaluate(string $targetPath, string $intent = self::KIND_IMPROVE): array
    {
        $target = ltrim(trim($targetPath), '/');
        if ($target === '') {
            return $this->verdict(false, false, 'refused_no_target', 'no_target', null);
        }

        // (1) CONSTITUTION FIRST — flag- AND intent-independent. Even a "harden" intent with auto-apply ARMED
        // cannot edit a cert organ: a self-edit to the judge is refused no matter how it is framed.
        if (($this->guard ?? new AtlasLoopHarnessGuard)->isForbiddenSelfTarget($target)) {
            return $this->verdict(false, false, self::STATUS_REFUSED_PETREO, 'constitution_forbids_editing_a_cert_organ', null);
        }

        // (2) CLASSIFY — hardening (add-only: a new guard/test/forbidden) is the SAFE exponential direction;
        // improving a non-pétreo file's behaviour is riskier. Both are LEGAL but policy-gated; the kind lets a
        // future operator policy auto-apply ONLY hardening while still parking behaviour changes.
        $kind = strtolower(trim($intent)) === self::KIND_HARDEN ? self::KIND_HARDEN : self::KIND_IMPROVE;

        // (3) POLICY — applying ANY self-edit unattended needs explicit operator policy (default-OFF ⇒ park).
        $autoApply = (bool) config('atlas.loop.recursive_self_improvement_auto_apply', false);

        return $autoApply
            ? $this->verdict(true, true, self::STATUS_AUTO_APPLY_ARMED, null, $kind)
            : $this->verdict(true, false, self::STATUS_PARKED, null, $kind);
    }

    /**
     * @return array{admitted:bool, auto_apply:bool, status:string, reason:?string, kind:?string}
     */
    private function verdict(bool $admitted, bool $autoApply, string $status, ?string $reason, ?string $kind): array
    {
        return ['admitted' => $admitted, 'auto_apply' => $autoApply, 'status' => $status, 'reason' => $reason, 'kind' => $kind];
    }
}
