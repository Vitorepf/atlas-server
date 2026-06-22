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

    /**
     * @return array{admitted:bool, auto_apply:bool, status:string, reason:?string}
     */
    public function evaluate(string $targetPath): array
    {
        $target = ltrim(trim($targetPath), '/');
        if ($target === '') {
            return $this->verdict(false, false, 'refused_no_target', 'no_target');
        }

        // (1) CONSTITUTION FIRST — flag-independent. Even with auto-apply ARMED, a cert organ is untouchable.
        if (($this->guard ?? new AtlasLoopHarnessGuard)->isForbiddenSelfTarget($target)) {
            return $this->verdict(false, false, self::STATUS_REFUSED_PETREO, 'constitution_forbids_editing_a_cert_organ');
        }

        // (2) POLICY — a non-pétreo brain file is a legal self-improvement; applying it needs operator policy.
        $autoApply = (bool) config('atlas.loop.recursive_self_improvement_auto_apply', false);

        return $autoApply
            ? $this->verdict(true, true, self::STATUS_AUTO_APPLY_ARMED, null)
            : $this->verdict(true, false, self::STATUS_PARKED, null);
    }

    /**
     * @return array{admitted:bool, auto_apply:bool, status:string, reason:?string}
     */
    private function verdict(bool $admitted, bool $autoApply, string $status, ?string $reason): array
    {
        return ['admitted' => $admitted, 'auto_apply' => $autoApply, 'status' => $status, 'reason' => $reason];
    }
}
