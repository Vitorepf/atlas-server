<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\KillAuthorityService;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ACDE lever S2 — the self-improvement APPLY actuator (the door's actuation, INERT by default).
 *
 * This is the FIRST code that can turn the EarnedAutonomy auto_apply SIGNAL into a real `git apply`. It is
 * therefore the most dangerous surface in the system, and is gated by FIVE independent barriers, all of which
 * must pass before a single byte is written, and three of which only an OPERATOR can satisfy:
 *
 *   GATE 1 — a default-OFF flag (atlas.foundry.rsi.self_improvement_apply_enabled). Returns before ANY gate or
 *            git call, so OFF == today exactly (no .patch, no process, working tree byte-identical).
 *   GATE 0 — KillAuthorityService::isAutonomyKilled(): the operator KILL-FILE + the dead-man heartbeat (TTL).
 *            The adversarial pass found this is NOT checked by EarnedAutonomyGateService::decide() (which only
 *            reads isArmed()), so the actuator re-checks it explicitly — a dropped kill-file or a stale
 *            heartbeat blocks the apply even when otherwise fully armed.
 *   GATES 3+4 — RE-DERIVE the verdict from the REAL diff via RsiSelfImprovementProposalGate::admit() (guard
 *            screen fail-closed -> EarnedAutonomy decide). The actuator keys the apply ONLY on its OWN freshly-
 *            computed STATUS_AUTO_APPLIED_EARNED + auto_applied===true, NEVER a verdict the caller supplied —
 *            so a forged decision in the proposal cannot actuate. decide() requires armed + earned-tier +
 *            drift-clean + red-team-survived + NOT gate_or_invariant_touch.
 *
 * It applies to the WORKING TREE ONLY — it NEVER commits, NEVER calls AtlasLoopAutoMergeService::mergeOne, and
 * NEVER sets AtlasLoopProposal::$governedMergeInProgress; the pétreo never-merge door is untouched and self-
 * improvement proposals stay parked there. The apply itself is fail-closed (`git apply --check` first; the
 * patch is unlinked in finally). The operator's three acts — the flag, earned_autonomy.mode, and
 * KillAuthority::arm with their actor — are the irreducible human authority; this class neither arms nor can
 * arm anything.
 */
final class RsiSelfImprovementApplyActuatorService
{
    public const STATUS_FLAG_OFF = 'flag_off';

    public const STATUS_AUTONOMY_KILLED = 'autonomy_killed';

    public const STATUS_NOT_AUTO_APPLIED = 'not_auto_applied';

    public const STATUS_APPLY_FAILED = 'apply_failed';

    public const STATUS_APPLIED = 'applied';

    public function __construct(
        private readonly RsiSelfImprovementProposalGate $gate,
        private readonly KillAuthorityService $kill,
    ) {}

    /**
     * Attempt to apply a self-improvement diff to a working tree. Returns a status envelope; applies a byte
     * ONLY when every gate passes. INERT (STATUS_FLAG_OFF, nothing touched) unless the operator armed it.
     *
     * @param  array<string,mixed>  $proposal   must carry a `diff` descriptor (changed_paths, added_lines)
     * @param  array<string,mixed>  $context    flag/area seam (earned_autonomy_mode_enabled, area_id, focus, rsi_mode_enabled)
     * @return array{status:string, applied:bool, gate?:string}
     */
    public function attempt(array $proposal, array $context, string $repoRoot, string $patchText): array
    {
        // GATE 1 — flag default-OFF. Return BEFORE any gate consult or git call => OFF is byte-identical.
        if (! (bool) config('atlas.foundry.rsi.self_improvement_apply_enabled', false)) {
            return ['status' => self::STATUS_FLAG_OFF, 'applied' => false];
        }

        // GATE 0 — operator kill-file + dead-man heartbeat. decide() does NOT check these; the actuator must.
        if ($this->kill->isAutonomyKilled($context)) {
            return ['status' => self::STATUS_AUTONOMY_KILLED, 'applied' => false];
        }

        // GATES 3+4 — re-derive screen + decide from the REAL diff; trust ONLY our own fresh verdict.
        $verdict = $this->gate->admit($proposal, $context);
        if (($verdict['status'] ?? '') !== RsiSelfImprovementProposalGate::STATUS_AUTO_APPLIED_EARNED
            || ($verdict['auto_applied'] ?? false) !== true) {
            return ['status' => self::STATUS_NOT_AUTO_APPLIED, 'applied' => false, 'gate' => (string) ($verdict['status'] ?? '')];
        }

        // APPLY — working tree only. NEVER commit / mergeOne / governedMergeInProgress.
        return $this->applyToWorkingTree($repoRoot, $patchText);
    }

    /**
     * @return array{status:string, applied:bool}
     */
    private function applyToWorkingTree(string $repoRoot, string $patchText): array
    {
        $patch = tempnam(sys_get_temp_dir(), 'atlas-rsi-apply-');
        if ($patch === false) {
            return ['status' => self::STATUS_APPLY_FAILED, 'applied' => false];
        }

        try {
            file_put_contents($patch, $patchText);

            $check = new Process(['git', 'apply', '--check', '--whitespace=nowarn', $patch], $repoRoot);
            $check->run();
            if (! $check->isSuccessful()) {
                return ['status' => self::STATUS_APPLY_FAILED, 'applied' => false];
            }

            $apply = new Process(['git', 'apply', '--whitespace=nowarn', $patch], $repoRoot);
            $apply->run();

            return $apply->isSuccessful()
                ? ['status' => self::STATUS_APPLIED, 'applied' => true]
                : ['status' => self::STATUS_APPLY_FAILED, 'applied' => false];
        } catch (Throwable) {
            return ['status' => self::STATUS_APPLY_FAILED, 'applied' => false];
        } finally {
            @unlink($patch);
        }
    }
}
