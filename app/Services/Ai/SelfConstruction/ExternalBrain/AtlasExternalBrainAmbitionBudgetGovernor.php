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

    /** Yield at or above this value (with diverse evidence) grants a weight boost. */
    private const HIGH_YIELD_THRESHOLD = 0.70;

    /** Minimum evidence refs to qualify as "diverse" for a high-yield boost. */
    private const DIVERSITY_MIN_REFS = 2;

    /** Base budget weight per mode before any yield-based reallocation. */
    private const BASE_WEIGHTS = [
        self::MODE_EASY_BUG_HUNT       => 0.40,
        self::MODE_DEEP_ARCHITECTURE   => 0.30,
        self::MODE_RESEARCH_ADAPTATION => 0.20,
        self::MODE_CONSOLIDATION       => 0.10,
    ];

    /** Queue-state ambition modes (distinct concern from the yield-ladder above). */
    public const QUEUE_MODE_BREAKTHROUGH   = 'breakthrough';
    public const QUEUE_MODE_RESEARCH       = 'research';
    public const QUEUE_MODE_SELF_HEAL      = 'self_heal';
    public const QUEUE_MODE_CONSOLIDATION  = 'consolidation';
    public const QUEUE_MODE_SIMPLIFICATION = 'simplification';
    public const QUEUE_MODE_DRAIN          = 'drain';

    /** @var list<string> */
    private const QUEUE_MODES = [
        self::QUEUE_MODE_BREAKTHROUGH,
        self::QUEUE_MODE_RESEARCH,
        self::QUEUE_MODE_SELF_HEAL,
        self::QUEUE_MODE_CONSOLIDATION,
        self::QUEUE_MODE_SIMPLIFICATION,
        self::QUEUE_MODE_DRAIN,
    ];

    private const LOW_QUEUE_HEALTH_THRESHOLD       = 0.50;
    private const HIGH_STALE_BACKLOG_THRESHOLD     = 0.40;
    private const HIGH_STRUCTURAL_LEVERAGE_THRESHOLD = 0.70;
    private const BREAKTHROUGH_LEVERAGE_THRESHOLD  = 0.85;
    private const LOW_BACKLOG_PRESSURE_THRESHOLD   = 0.30;
    private const HIGH_BACKLOG_PRESSURE_THRESHOLD  = 0.70;

    /**
     * Decide which ambition mode (breakthrough, research, self_heal, consolidation,
     * simplification, drain) the brain should spend budget on this cycle, based on queue
     * health, stale backlog, and structural leverage — never on frontier-provider availability.
     *
     * @param  array{
     *   queue_health?:         float,
     *   stale_backlog_ratio?:  float,
     *   structural_leverage?:  float,
     *   backlog_pressure?:     float,
     *   budget_total?:         int,
     * }  $facts
     * @return array{schema:string, selected_mode:string, budget_allocation:array<string,int>, denied_modes:list<string>, reasons:list<string>, remaining_budget:int}
     */
    public function governQueueAmbition(array $facts): array
    {
        $queueHealth        = max(0.0, min(1.0, (float) ($facts['queue_health']        ?? 1.0)));
        $staleBacklogRatio   = max(0.0, min(1.0, (float) ($facts['stale_backlog_ratio']  ?? 0.0)));
        $structuralLeverage  = max(0.0, min(1.0, (float) ($facts['structural_leverage']  ?? 0.0)));
        $backlogPressure     = max(0.0, min(1.0, (float) ($facts['backlog_pressure']     ?? 0.0)));
        $budgetTotal         = max(0, (int) ($facts['budget_total'] ?? 100));

        $reasons = [];

        if ($queueHealth < self::LOW_QUEUE_HEALTH_THRESHOLD) {
            $selected = self::QUEUE_MODE_SELF_HEAL;
            $reasons[] = sprintf('queue_health=%.2f below floor=%.2f: heal before expanding ambition', $queueHealth, self::LOW_QUEUE_HEALTH_THRESHOLD);
        } elseif ($staleBacklogRatio >= self::HIGH_STALE_BACKLOG_THRESHOLD) {
            $selected = self::QUEUE_MODE_CONSOLIDATION;
            $reasons[] = sprintf('stale_backlog_ratio=%.2f at/above ceiling=%.2f: consolidate before creating', $staleBacklogRatio, self::HIGH_STALE_BACKLOG_THRESHOLD);
        } elseif ($structuralLeverage >= self::HIGH_STRUCTURAL_LEVERAGE_THRESHOLD && $backlogPressure < self::LOW_BACKLOG_PRESSURE_THRESHOLD) {
            // AC2: never gated on frontier-provider availability — local muscles can attempt this.
            $selected = $structuralLeverage >= self::BREAKTHROUGH_LEVERAGE_THRESHOLD
                ? self::QUEUE_MODE_BREAKTHROUGH
                : self::QUEUE_MODE_RESEARCH;
            $reasons[] = sprintf(
                'structural_leverage=%.2f high and backlog_pressure=%.2f low: %s budget unlocked without depending on a frontier provider',
                $structuralLeverage, $backlogPressure, $selected,
            );
        } elseif ($backlogPressure >= self::HIGH_BACKLOG_PRESSURE_THRESHOLD) {
            $selected = self::QUEUE_MODE_DRAIN;
            $reasons[] = sprintf('backlog_pressure=%.2f at/above ceiling=%.2f: drain existing claimable work', $backlogPressure, self::HIGH_BACKLOG_PRESSURE_THRESHOLD);
        } else {
            $selected = self::QUEUE_MODE_SIMPLIFICATION;
            $reasons[] = 'no dominant signal: default to low-risk simplification housekeeping';
        }

        $budgetAllocation = [];
        foreach (self::QUEUE_MODES as $mode) {
            $budgetAllocation[$mode] = $mode === $selected ? $budgetTotal : 0;
        }

        $deniedModes = array_values(array_diff(self::QUEUE_MODES, [$selected]));

        return [
            'schema'            => self::SCHEMA,
            'selected_mode'     => $selected,
            'budget_allocation' => $budgetAllocation,
            'denied_modes'      => $deniedModes,
            'reasons'           => $reasons,
            'remaining_budget'  => $budgetTotal,
        ];
    }

    /**
     * Compute budget allocation for the current brain-run state.
     *
     * @param array{
     *   quota_total?:       int,
     *   quota_consumed?:    int,
     *   yield_by_mode?:     array<string,float>,
     *   evidence_by_mode?:  array<string,list<string>>,
     *   attempted_modes?:   list<string>,
     *   mode_stats?:        array<string,array{attempted?:int,credited?:int,verified?:int,rejected?:int}>,
     * } $state
     *
     * @return array{
     *   schema:                   string,
     *   next_mode:                string,
     *   budget_slice:             int,
     *   quota_remaining:          int,
     *   reallocation_triggered:   bool,
     *   reallocated_from:         list<string>,
     *   modes_with_evidence:      list<string>,
     *   honest_exhausted:         bool,
     *   rationale:                string,
     *   mode_weights:             array<string,float>,
     *   marginal_verified_yield:  array<string,float>,
     * }
     */
    public function allocate(array $state): array
    {
        $quotaTotal     = max(1, (int) ($state['quota_total']    ?? 100));
        $quotaConsumed  = max(0, (int) ($state['quota_consumed'] ?? 0));
        $quotaRemaining = max(0, $quotaTotal - $quotaConsumed);

        $yieldByMode    = is_array($state['yield_by_mode']    ?? null) ? $state['yield_by_mode']    : [];
        $evidenceByMode = is_array($state['evidence_by_mode'] ?? null) ? $state['evidence_by_mode'] : [];

        // Compute marginal_verified_yield from per-mode stats (takes priority over yield_by_mode).
        $modeStats = is_array($state['mode_stats'] ?? null) ? $state['mode_stats'] : [];
        $marginalVerifiedYield = [];
        foreach ($modeStats as $statMode => $stats) {
            if (! is_array($stats)) {
                continue;
            }
            $attempted = max(0, (int) ($stats['attempted'] ?? 0));
            $verified  = max(0, (int) ($stats['verified']  ?? 0));
            $marginalVerifiedYield[(string) $statMode] = $attempted > 0
                ? round($verified / $attempted, 4)
                : 0.0;
        }
        // Merge: mode_stats computed values override yield_by_mode.
        $effectiveYield = array_merge($yieldByMode, $marginalVerifiedYield);
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
            $yield = isset($effectiveYield[$mode]) ? (float) $effectiveYield[$mode] : -1.0;
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

        // High-yield boost: modes with yield >= HIGH_YIELD_THRESHOLD AND diverse evidence get a +30% weight boost.
        // Quota pressure (high consumption) is NOT checked here — only yield+evidence qualify (AC3).
        $escalationEvidence = [];
        foreach (self::LADDER as $mode) {
            $yield = isset($effectiveYield[$mode]) ? (float) $effectiveYield[$mode] : -1.0;
            $refs  = is_array($evidenceByMode[$mode] ?? null) ? array_values($evidenceByMode[$mode]) : [];
            if ($yield >= self::HIGH_YIELD_THRESHOLD && count($refs) >= self::DIVERSITY_MIN_REFS) {
                $boost             = $weights[$mode] * 0.30;
                $weights[$mode]   += $boost;
                $escalationEvidence[$mode] = [
                    'yield'         => round($yield, 4),
                    'evidence_refs' => $refs,
                    'weight_boost'  => round($boost, 6),
                ];
            }
        }

        // Recommendation: self_healing when all modes are low-yield; consolidation when reallocation fired; continue otherwise.
        $recommendation = match (true) {
            count($lowYieldModes) === count(self::LADDER) => 'self_healing',
            $reallocationTriggered                        => 'consolidation',
            default                                       => 'continue',
        };

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
                true, 'all_modes_exhausted_with_genuine_evidence', $weights, $marginalVerifiedYield,
                'continue', $escalationEvidence,
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
            false, $rationale, $weights, $marginalVerifiedYield,
            $recommendation, $escalationEvidence,
        );
    }

    /**
     * @param list<string>              $reallocatedFrom
     * @param list<string>              $withEvidence
     * @param array<string,float>       $weights
     * @param array<string,float>       $marginalVerifiedYield
     * @param array<string,mixed>       $escalationEvidence
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
        array $marginalVerifiedYield = [],
        string $recommendation = 'continue',
        array $escalationEvidence = [],
    ): array {
        return [
            'schema'                  => self::SCHEMA,
            'next_mode'               => $nextMode,
            'budget_slice'            => $budgetSlice,
            'quota_remaining'         => $quotaRemaining,
            'reallocation_triggered'  => $reallocationTriggered,
            'reallocated_from'        => $reallocatedFrom,
            'modes_with_evidence'     => $withEvidence,
            'honest_exhausted'        => $honestExhausted,
            'rationale'               => $rationale,
            'mode_weights'            => $weights,
            'marginal_verified_yield' => $marginalVerifiedYield,
            'recommendation'          => $recommendation,
            'escalation_evidence'     => $escalationEvidence,
        ];
    }
}
