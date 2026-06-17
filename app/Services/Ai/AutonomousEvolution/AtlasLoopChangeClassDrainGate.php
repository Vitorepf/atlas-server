<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Policy\PolicyCanon;
use Throwable;

/**
 * ACDE lever DG2 — the per-change-class EARNED-AUTONOMY drain gate (the missing READ of a live pair).
 *
 * The evidence WRITE-end is already live: every real merge feeds AtlasChangeClassTrustLadder::recordMergeOutcome
 * (a clean promotion on a landed commit, a revoke on a red canary), keyed by the change class. But the live
 * single-file drain merges any one certified proposal on the strength of THAT diff alone — it never reads the
 * class's accrued track record. DG2 reads it: a change class that has NOT yet earned autonomous trust (its clean
 * streak is below the operator's configured `autonomous` threshold) PARKS for operator review instead of
 * auto-merging. Merge authority becomes a function of the weak model's PROVEN per-class history, not the
 * persuasiveness of the current change.
 *
 * Fail-OPEN: returns false (no abstention) when the DG2 flag is OFF or on any error — so default-OFF is
 * byte-identical. NOTE the composition once armed: the trust-ladder thresholds are DISABLED by default
 * (non-positive => unreachable), so a freshly-armed DG2 parks EVERY class until the operator sets a positive
 * atlas.ai.trust_ladder.thresholds.autonomous AND the class's clean-merge streak reaches it. That is the
 * conservative-when-armed direction by design: DG2 is the operator's risk-averse knob — nothing auto-merges
 * until autonomy is both configured and earned.
 */
final class AtlasLoopChangeClassDrainGate
{
    public function __construct(private readonly ?AtlasChangeClassTrustLadder $ladder = null) {}

    /**
     * Should a certified proposal touching $targetPath ABSTAIN (park) because its change class has not earned
     * autonomous trust? Fail-open false on flag-OFF / any error.
     */
    public function shouldAbstain(string $targetPath): bool
    {
        if (! (bool) config('atlas.loop.change_class_drain_gate_enabled', false)) {
            return false;
        }
        $targetPath = trim($targetPath);
        if ($targetPath === '') {
            return false;
        }
        try {
            $ladder = $this->ladder ?? app(AtlasChangeClassTrustLadder::class);
            $class = $ladder->classifyChangedFiles([$targetPath]);
            if ($class === '') {
                return false;
            }

            return $ladder->earnedAutonomy($class) !== PolicyCanon::AUTONOMY_AUTONOMOUS;
        } catch (Throwable) {
            return false; // a trust-ladder hiccup must never block an otherwise-mergeable proposal
        }
    }
}
