<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Allocates effort budget across brain-run modes and blocks honest_exhausted until
 * every mode has been genuinely tried with real evidence.
 *
 * Mode ladder (shallow → deep):
 *   easy_bug_hunt        — surface scan; high yield early, drops fast
 *   deep_architecture    — cross-layer search; slower but high-value findings
 *   research_adaptation  — adapting external research patterns; deepest probing
 *   consolidation        — pruning/merging existing work; always last
 *
 * REALLOCATION: when yield for a shallower mode falls at or below LOW_YIELD_THRESHOLD
 * the governor shifts half its remaining weight to the next deeper mode that still has
 * room (anti-padding: only modes with genuine evidence can be considered yielding).
 *
 * HONEST_EXHAUSTED gate: only returned once EVERY mode on the ladder has been
 * attempted AND carries at least one evidence reference. Quota consumption alone
 * never satisfies this gate — preventing low-value-task padding from short-circuiting
 * the deeper search.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainAmbitionBudgetGovernor
{
    public const SCHEMA = 'atlas.external_brain.ambition_budget_governor.v1';

    public const MODE_EASY_BUG_HUNT       = 'easy_bug_hunt';
    public const MODE_DEEP_ARCHITECTURE   = 'deep_architecture';
    public const MODE_RESEARCH_ADAPTATION = 'research_adaptation';
    public const MODE_CONSOLIDATION       = 'consolidation';
    public const MODE_HONEST_EXHAUSTED    = 'honest_exhausted';

    /** Ordered ladder — shallower modes first; reallocation flows downward. */
    private const LADDER = [
        self::MODE_EASY_BUG_HUNT,
        self::MODE_DEEP_ARCHITECTURE,
        self::MODE_RESEARCH_ADAPTATION,
        self::MODE_CONSOLIDATION,
    ];

    /** Yield at or below this value triggers reallocation to the next deeper mode. */
    private const LOW_YIELD_THRESHOLD = 0.25;

    /** Base budget weight per mode before any yield-based reallocation. */
    private const BASE_WEIGHTS = [
        self::MODE_EASY_BUG_HUNT       => 0.40,
        self::MODE_DEEP_ARCHITECTURE   => 0.30,
        self::MODE_RESEARCH_ADAPTATION => 0.20,
        self::MODE_CONSOLIDATION       => 0.10,
    ];

    /**
     * Compute budget allocation for the current brain-run state.
     *
     * @param array{
     *   quota_total?:       int,
     *   quota_consumed?:    int,
     *   yield_by_mode?:     array<string,float>,
     *   evidence_by_mode?:  array<string,list<string>>,
     *   attempted_modes?:   list<string>,
     * } $state
     *
     * @return array{
     *   schema:                 string,
     *   next_mode:              string,
     *   budget_slice:           int,
     *   quota_remaining:        int,
     *   reallocation_triggered: bool,
     *   reallocated_from:       list<string>,
     *   modes_with_evidence:    list<string>,
     *   honest_exhausted:       bool,
     *   rationale:              string,
     *   mode_weights:           array<string,float>,
     * }
     */
    public function allocate(array $state): array
    {
        $quotaTotal     = max(1, (int) ($state['quota_total']    ?? 100));
        $quotaConsumed  = max(0, (int) ($state['quota_consumed'] ?? 0));
        $quotaRemaining = max(0, $quotaTotal - $quotaConsumed);

        $yieldByMode    = is_array($state['yield_by_mode']    ?? null) ? $state['yield_by_mode']    : [];
        $evidenceByMode = is_array($state['evidence_by_mode'] ?? null) ? $state['evidence_by_mode'] : [];
        $attempted      = array_values(array_filter(
            array_map('strval', (array) ($state['attempted_modes'] ?? [])),
            static fn (string $m): bool => $m !== '',
        ));

        // Modes that carry at least one evidence reference (anti-padding: tasks without evidence don't count).
        $withEvidence = array_values(array_filter(self::LADDER, static function (string $m) use ($evidenceByMode): bool {
            $refs = is_array($evidenceByMode[$m] ?? null) ? array_values($evidenceByMode[$m]) : [];
            return $refs !== [];
        }));

        // Identify shallow modes where measured yield has fallen to or below the threshold.
        $lowYieldModes = [];
        foreach (self::LADDER as $mode) {
            $yield = isset($yieldByMode[$mode]) ? (float) $yieldByMode[$mode] : -1.0;
            if ($yield >= 0.0 && $yield <= self::LOW_YIELD_THRESHOLD) {
                $lowYieldModes[] = $mode;
            }
        }

        // Adjust weights: for each low-yield mode, shift half its weight to the next deeper mode.
        $weights         = self::BASE_WEIGHTS;
        $reallocatedFrom = [];

        foreach ($lowYieldModes as $lowMode) {
            $idx = array_search($lowMode, self::LADDER, true);
            if ($idx === false) {
                continue;
            }
            // Target: next deeper mode that is not also low-yield; fall back to deepest.
            $target = self::LADDER[count(self::LADDER) - 1];
            for ($i = (int) $idx + 1; $i < count(self::LADDER); $i++) {
                if (! in_array(self::LADDER[$i], $lowYieldModes, true)) {
                    $target = self::LADDER[$i];
                    break;
                }
            }

            $shift            = $weights[$lowMode] * 0.5;
            $weights[$lowMode] -= $shift;
            $weights[$target]  += $shift;
            $reallocatedFrom[] = $lowMode;
        }

        $reallocatedFrom       = array_values(array_unique($reallocatedFrom));
        $reallocationTriggered = $reallocatedFrom !== [];

        // Select next mode: first in ladder not yet attempted.
        $nextMode = null;
        foreach (self::LADDER as $mode) {
            if (! in_array($mode, $attempted, true)) {
                $nextMode = $mode;
                break;
            }
        }

        $allAttempted    = array_diff(self::LADDER, $attempted) === [];
        $allHaveEvidence = count($withEvidence) === count(self::LADDER);

        // Honest exhaustion: every mode tried AND every mode carries genuine evidence.
        if ($allAttempted && $allHaveEvidence) {
            return $this->envelope(
                self::MODE_HONEST_EXHAUSTED, 0, $quotaRemaining,
                $reallocationTriggered, $reallocatedFrom, $withEvidence,
                true, 'all_modes_exhausted_with_genuine_evidence', $weights,
            );
        }

        // All attempted but some modes still lack evidence — re-queue the first evidenceless mode.
        if ($nextMode === null) {
            foreach (self::LADDER as $mode) {
                $refs = is_array($evidenceByMode[$mode] ?? null) ? array_values($evidenceByMode[$mode]) : [];
                if ($refs === []) {
                    $nextMode = $mode;
                    break;
                }
            }
        }

        $nextMode ??= self::LADDER[0];

        $budgetSlice = $quotaRemaining > 0
            ? max(1, (int) round($quotaRemaining * ($weights[$nextMode] ?? 0.25)))
            : 0;

        $rationale = $reallocationTriggered
            ? 'falling_yield_in_shallow_modes_reallocated_budget_to_deeper_search'
            : 'budget_allocated_by_depth_weighted_heuristic';

        return $this->envelope(
            $nextMode, $budgetSlice, $quotaRemaining,
            $reallocationTriggered, $reallocatedFrom, $withEvidence,
            false, $rationale, $weights,
        );
    }

    /**
     * @param list<string>       $reallocatedFrom
     * @param list<string>       $withEvidence
     * @param array<string,float> $weights
     */
    private function envelope(
        string $nextMode,
        int $budgetSlice,
        int $quotaRemaining,
        bool $reallocationTriggered,
        array $reallocatedFrom,
        array $withEvidence,
        bool $honestExhausted,
        string $rationale,
        array $weights,
    ): array {
        return [
            'schema'                 => self::SCHEMA,
            'next_mode'              => $nextMode,
            'budget_slice'           => $budgetSlice,
            'quota_remaining'        => $quotaRemaining,
            'reallocation_triggered' => $reallocationTriggered,
            'reallocated_from'       => $reallocatedFrom,
            'modes_with_evidence'    => $withEvidence,
            'honest_exhausted'       => $honestExhausted,
            'rationale'              => $rationale,
            'mode_weights'           => $weights,
        ];
    }
}
