<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ARBOR-GRAFT J2 — the TRANSFER-SLICE holdout (the anti-Goodhart residual the dissection named).
 *
 * Atlas already certifies against a FROZEN acceptance + adversarial re-prove. The residual risk (and Arbor's
 * own confessed weakness) is overfitting to THAT fine acceptance: a change can satisfy its own frozen test
 * yet not generalize. The honest defense Arbor proved (its Fig-3b transfer experiment) is to re-check the
 * SAME candidate against a held-out task of a DIFFERENT type and require it not to regress.
 *
 * This gate wraps — never edits — the pétreo AtlasEvolutionFrozenJudge: it scores the candidate on the main
 * acceptance, and (when a frozen transfer acceptance is declared + the flag is ON) ALSO re-proves it on the
 * transfer slice out-of-process, requiring BOTH to hold. CONJUNCTIVE and fail-closed: a candidate that wins
 * its own metric but regresses the transfer task is rejected.
 *
 * FLOOR-SAFE: it only ADDS a conjunctive held-out check (never relaxes the main gate); it re-uses the same
 * out-of-process judge (no self-report); flag-gated default-OFF + no transfer acceptance => returns the main
 * verdict unchanged (byte-identical). The transfer acceptance is DATA the frozen objective builder declares —
 * a provider can never author or weaken it.
 */
final class AtlasLoopTransferGate
{
    public function __construct(
        private readonly AtlasEvolutionFrozenJudge $judge,
    ) {}

    /**
     * @param  array<string,mixed>       $mainAcceptance
     * @param  array<string,mixed>|null  $transferAcceptance  a frozen contract on a DIFFERENT task; null => skip
     * @return array{ok:bool, reason:string, main:array<string,mixed>, transfer?:array<string,mixed>}
     */
    public function verify(string $workspace, array $mainAcceptance, ?array $transferAcceptance = null): array
    {
        $main = $this->judge->score($workspace, $mainAcceptance);

        // The main gate is authoritative and unchanged: a main failure is rejected exactly as today.
        if (($main['passed'] ?? false) !== true) {
            return ['ok' => false, 'reason' => 'main_failed', 'main' => $main];
        }

        // Byte-identical when OFF / no transfer slice declared: the main verdict stands alone.
        // The flag read is fail-safe (no container in a pure-unit context => OFF, never throws).
        $enabled = false;
        try {
            $enabled = (bool) config('atlas.loop.transfer_gate_enabled', false);
        } catch (\Throwable) {
            $enabled = false;
        }
        if (! is_array($transferAcceptance) || $transferAcceptance === [] || ! $enabled) {
            return ['ok' => true, 'reason' => 'main_passed_no_transfer', 'main' => $main];
        }

        // J2: re-prove the SAME candidate on a held-out task of a different type — out-of-process, same judge.
        $transfer = $this->judge->score($workspace, $transferAcceptance);
        $held = ($transfer['passed'] ?? false) === true;

        return [
            'ok' => $held,
            'reason' => $held ? 'transfer_held' : 'transfer_regressed',
            'main' => $main,
            'transfer' => $transfer,
        ];
    }
}
