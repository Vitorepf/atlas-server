<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure escalator: when a bug-hunt surface thins, selects the next deeper pass
 * from the ordered ladder. NEVER returns template_farm.
 *
 * Each later pass requires a stronger evidence floor:
 *   bug_hunt                   → inspected_surface
 *   compression                → + rejected_false_leads
 *   research_transfer          → + expected_yield_range
 *   contract_mismatch_deepening → + why_not_stop
 *   stop_with_evidence         → same as above
 *
 * Thinning signals: declining verified_findings, rising token_cost, high duplicate_findings.
 */
final class AtlasExternalBrainFrontierExhaustionEscalator
{
    public const SCHEMA = 'atlas.external_brain.frontier_exhaustion_escalator.v1';

    /** @var list<string> */
    public const PASS_LADDER = [
        'bug_hunt',
        'compression',
        'research_transfer',
        'contract_mismatch_deepening',
        'stop_with_evidence',
    ];

    /** @var list<string> */
    public const FORBIDDEN_PASSES = ['template_farm'];

    /** @var array<string,list<string>> Cumulative evidence floor per pass. */
    public const EVIDENCE_FLOOR_BY_PASS = [
        'bug_hunt'                    => ['inspected_surface'],
        'compression'                  => ['inspected_surface', 'rejected_false_leads'],
        'research_transfer'            => ['inspected_surface', 'rejected_false_leads', 'expected_yield_range'],
        'contract_mismatch_deepening' => ['inspected_surface', 'rejected_false_leads', 'expected_yield_range', 'why_not_stop'],
        'stop_with_evidence'          => ['inspected_surface', 'rejected_false_leads', 'expected_yield_range', 'why_not_stop'],
    ];

    /** Minimum duplicate rate (vs total) to classify as high-duplicate wave. */
    private const HIGH_DUPLICATE_THRESHOLD = 0.5;

    /** Minimum forbidden-wall rate to require an unblock strategy change. */
    private const FORBIDDEN_WALL_THRESHOLD = 0.3;

    /** Minimum consecutive weak proposals before recommending simplification. */
    private const WEAK_PROPOSAL_STREAK_THRESHOLD = 3;

    public const STRATEGY_SIMPLIFY              = 'simplify';
    public const STRATEGY_AUDIT_CODE            = 'audit_code';
    public const STRATEGY_RESEARCH_EXTERNAL     = 'research_external';
    public const STRATEGY_UNBLOCK_FORBIDDEN_WALL = 'unblock_forbidden_wall';
    public const STRATEGY_CONSOLIDATE           = 'consolidate';

    /** @var list<string> */
    public const CHANGE_STRATEGY_ACTIONS = [
        self::STRATEGY_SIMPLIFY,
        self::STRATEGY_AUDIT_CODE,
        self::STRATEGY_RESEARCH_EXTERNAL,
        self::STRATEGY_UNBLOCK_FORBIDDEN_WALL,
        self::STRATEGY_CONSOLIDATE,
    ];

    public const ACTION_SWITCH_PATTERN = 'switch_pattern';

    public const ACTION_KEEP_CURRENT_STRATEGY = 'keep_current_strategy';

    /** Next probe pattern to run for each strategy — never the same search shape as before. */
    private const NEXT_PROBE_PATTERN_BY_STRATEGY = [
        self::STRATEGY_UNBLOCK_FORBIDDEN_WALL => 'bypass_forbidden_surface_via_alternate_entrypoint',
        self::STRATEGY_SIMPLIFY => 'reduce_scope_to_minimal_reproducible_shape',
        self::STRATEGY_CONSOLIDATE => 'merge_duplicate_candidates_before_next_scan',
        self::STRATEGY_AUDIT_CODE => 'static_audit_pass_over_previously_unscanned_modules',
        self::STRATEGY_RESEARCH_EXTERNAL => 'external_research_grounded_probe',
    ];

    /**
     * Detect frontier exhaustion and recommend a strategy CHANGE — never another
     * identical-strategy frontier pass. Separate from escalate()'s pass-ladder model.
     *
     * @param  list<array<string,mixed>>  $waveHistory
     * @param  array<string,mixed>  $context  optional: weak_proposal_streak
     * @return array{schema_version:string, exhaustion_proven:bool, exhaustion_signals:list<string>, recommended_strategy:?string, must_not_repeat_same_strategy:bool}
     */
    public function recommendStrategyChange(array $waveHistory, array $context = []): array
    {
        $latest = $waveHistory !== [] ? $waveHistory[count($waveHistory) - 1] : [];

        $lowMarginalYield   = $this->isDecliningFindings($waveHistory) && $this->isRisingCost($waveHistory);
        $risingDuplicateRate = $this->isHighDuplicates($waveHistory);
        $forbiddenWallRate  = $this->forbiddenWallRate($latest);
        $forbiddenWallHigh  = $forbiddenWallRate >= self::FORBIDDEN_WALL_THRESHOLD;
        $weakProposalStreak = max(0, (int) ($context['weak_proposal_streak'] ?? 0));
        $repeatedWeakProposals = $weakProposalStreak >= self::WEAK_PROPOSAL_STREAK_THRESHOLD;

        $signals = [];
        if ($lowMarginalYield) {
            $signals[] = 'low_marginal_yield';
        }
        if ($risingDuplicateRate) {
            $signals[] = 'rising_duplicate_rate';
        }
        if ($forbiddenWallHigh) {
            $signals[] = 'forbidden_wall_rate';
        }
        if ($repeatedWeakProposals) {
            $signals[] = 'repeated_weak_proposals';
        }

        $exhaustionProven = $signals !== [];

        // Priority: the most actionable, most specific cause wins.
        $strategy = match (true) {
            $forbiddenWallHigh        => self::STRATEGY_UNBLOCK_FORBIDDEN_WALL,
            $repeatedWeakProposals    => self::STRATEGY_SIMPLIFY,
            $risingDuplicateRate      => self::STRATEGY_CONSOLIDATE,
            $lowMarginalYield         => self::STRATEGY_AUDIT_CODE,
            default                   => null,
        };

        // Exhaustion proven but no specific signal matched a strategy (defensive default):
        // never fall back to "run the same strategy again" — go external instead.
        if ($exhaustionProven && $strategy === null) {
            $strategy = self::STRATEGY_RESEARCH_EXTERNAL;
        }

        // AC: a strategy switch must name a concrete next probe pattern and forbid repeating
        // the same search shape — never just a bare strategy label.
        $currentPattern = trim((string) ($context['current_pattern'] ?? ''));
        $nextProbePattern = $exhaustionProven && $strategy !== null
            ? (self::NEXT_PROBE_PATTERN_BY_STRATEGY[$strategy] ?? null)
            : null;
        $forbiddenRepeatPattern = $exhaustionProven
            ? ($currentPattern !== '' ? $currentPattern : 'same_search_shape_as_last_wave')
            : null;

        return [
            'schema_version'                => self::SCHEMA,
            'exhaustion_proven'             => $exhaustionProven,
            'exhaustion_signals'            => $signals,
            'recommended_strategy'          => $exhaustionProven ? $strategy : ($context['current_strategy'] ?? null),
            'must_not_repeat_same_strategy' => $exhaustionProven,
            'action'                        => $exhaustionProven ? self::ACTION_SWITCH_PATTERN : self::ACTION_KEEP_CURRENT_STRATEGY,
            'next_probe_pattern'            => $nextProbePattern,
            'forbidden_repeat_pattern'      => $forbiddenRepeatPattern,
        ];
    }

    private function forbiddenWallRate(array $wave): float
    {
        $hits  = (int) ($wave['forbidden_wall_hits'] ?? 0);
        $total = (int) ($wave['attempts'] ?? 0);

        return $total > 0 ? $hits / $total : 0.0;
    }

    /**
     * Evaluate wave history and return the next escalation pass.
     *
     * @param  list<array<string,mixed>>  $waveHistory  chronological list of wave records
     * @param  array<string,mixed>  $context  optional: current_pass, remaining_high_risk_domains
     * @return array<string,mixed>
     */
    public function escalate(array $waveHistory, array $context = []): array
    {
        $currentPass = (string) ($context['current_pass'] ?? 'bug_hunt');
        if (in_array($currentPass, self::FORBIDDEN_PASSES, true) || ! in_array($currentPass, self::PASS_LADDER, true)) {
            $currentPass = 'bug_hunt';
        }
        $remainingHighRisk = is_array($context['remaining_high_risk_domains'] ?? null)
            ? array_values(array_map('strval', $context['remaining_high_risk_domains']))
            : [];

        $decliningFindings = $this->isDecliningFindings($waveHistory);
        $risingCost = $this->isRisingCost($waveHistory);
        $highDuplicates = $this->isHighDuplicates($waveHistory);

        $latestWave = $waveHistory !== [] ? $waveHistory[count($waveHistory) - 1] : [];
        $requiredFloor = self::EVIDENCE_FLOOR_BY_PASS[$currentPass] ?? ['inspected_surface'];
        $missingEvidence = $this->missingFloor($latestWave, $requiredFloor);

        // Never advance past a pass whose own evidence floor is unmet — a thinning
        // surface alone must not be enough to reach stop_with_evidence without proof.
        $nextPass = $missingEvidence === []
            ? $this->computeNextPass($currentPass, $decliningFindings, $risingCost, $highDuplicates, $remainingHighRisk)
            : $currentPass;

        $reasonParts = [];
        if ($decliningFindings) {
            $reasonParts[] = 'declining_verified_findings';
        }
        if ($risingCost) {
            $reasonParts[] = 'rising_token_cost';
        }
        if ($highDuplicates) {
            $reasonParts[] = 'high_duplicate_rate';
        }
        if ($remainingHighRisk !== []) {
            $reasonParts[] = 'remaining_high_risk_domains';
        }
        $escalationReason = $reasonParts !== [] ? implode(', ', $reasonParts) : 'surface_stable';

        // Compute exhaustion_confidence: higher when more signals + evidence floor satisfied
        $signalCount = (int)$decliningFindings + (int)$risingCost + (int)$highDuplicates + (int)($remainingHighRisk !== []);
        $exhaustionConfidence = $missingEvidence === [] ? min(1.0, $signalCount * 0.25) : max(0.0, ($signalCount - count($missingEvidence)) * 0.25);

        // next_strategy: derive from pass ladder position and signals
        $nextStrategy = $this->deriveNextStrategy($currentPass, $nextPass, $missingEvidence, $signalCount);

        // missing_search_evidence: what surfaces/sources haven't been searched yet
        $missingSearchEvidence = $this->deriveMissingSearchEvidence($missingEvidence, $nextPass, $waveHistory);

        return [
            'schema_version' => self::SCHEMA,
            'next_pass' => $nextPass,
            'current_pass' => $currentPass,
            'escalation_reason' => $escalationReason,
            'evidence_floor' => self::EVIDENCE_FLOOR_BY_PASS[$nextPass] ?? [],
            'missing_evidence' => $missingEvidence,
            'evidence_floor_satisfied' => $missingEvidence === [],
            'exhaustion_confidence' => round($exhaustionConfidence, 2),
            'next_strategy' => $nextStrategy,
            'missing_search_evidence' => $missingSearchEvidence,
            'wave_analysis' => [
                'wave_count' => count($waveHistory),
                'declining_findings' => $decliningFindings,
                'rising_cost' => $risingCost,
                'high_duplicate_rate' => $highDuplicates,
                'remaining_high_risk_count' => count($remainingHighRisk),
            ],
        ];
    }

    private function computeNextPass(string $currentPass, bool $decliningFindings, bool $risingCost, bool $highDuplicates, array $remainingHighRisk): string
    {
        $ladder = self::PASS_LADDER;
        $idx = array_search($currentPass, $ladder, true);
        if ($idx === false) {
            $idx = 0;
        }

        $surfaceThin = $decliningFindings || $risingCost || $highDuplicates;
        $shouldEscalate = $surfaceThin || ($remainingHighRisk !== [] && ($decliningFindings || $highDuplicates));

        if ($shouldEscalate && $idx < count($ladder) - 1) {
            return $ladder[$idx + 1];
        }

        return $ladder[$idx];
    }

    private function isDecliningFindings(array $waveHistory): bool
    {
        if (count($waveHistory) < 2) {
            return false;
        }
        $last = (int) ($waveHistory[count($waveHistory) - 1]['verified_findings'] ?? 0);
        $prev = (int) ($waveHistory[count($waveHistory) - 2]['verified_findings'] ?? 0);

        return $last < $prev;
    }

    private function isRisingCost(array $waveHistory): bool
    {
        if (count($waveHistory) < 2) {
            return false;
        }
        $last = (float) ($waveHistory[count($waveHistory) - 1]['token_cost'] ?? 0.0);
        $prev = (float) ($waveHistory[count($waveHistory) - 2]['token_cost'] ?? 0.0);

        return $last > $prev;
    }

    private function isHighDuplicates(array $waveHistory): bool
    {
        if ($waveHistory === []) {
            return false;
        }
        $latest = $waveHistory[count($waveHistory) - 1];
        $verified = (int) ($latest['verified_findings'] ?? 0);
        $dupes = (int) ($latest['duplicate_findings'] ?? 0);
        $total = $verified + $dupes;

        return $total > 0 && ($dupes / $total) >= self::HIGH_DUPLICATE_THRESHOLD;
    }

    /**
     * @param  list<string>  $required
     * @return list<string>
     */
    private function missingFloor(array $wave, array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            $val = $wave[$field] ?? null;
            if ($val === null || $val === '' || $val === []) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Derive next strategy from pass transition and evidence state.
     */
    private function deriveNextStrategy(string $currentPass, string $nextPass, array $missingEvidence, int $signalCount): ?string
    {
        // If evidence floor is not met, stay on current pass
        if ($missingEvidence !== []) {
            return null;
        }

        // If no signals, keep current strategy
        if ($signalCount === 0) {
            return 'keep_current_strategy';
        }

        // If escalating to a deeper pass, that IS the next strategy
        if ($nextPass !== $currentPass) {
            return $nextPass;
        }

        // At the top of the ladder with signals — recommend external research or stop
        if ($currentPass === 'stop_with_evidence') {
            return 'stop_with_evidence';
        }

        return 'research_external';
    }

    /**
     * Derive missing search evidence — what surfaces, sources, or refactor veins
     * haven't been searched yet.
     *
     * @return list<string>
     */
    private function deriveMissingSearchEvidence(array $missingEvidence, string $nextPass, array $waveHistory): array
    {
        $missing = $missingEvidence;

        // Map evidence floor gaps to concrete search surfaces
        $surfaceMap = [
            'inspected_surface' => 'code_surface_inspection',
            'rejected_false_leads' => 'rejected_leads_documentation',
            'expected_yield_range' => 'yield_estimation_from_research_sources',
            'why_not_stop' => 'stop_criteria_analysis',
        ];

        foreach ($missing as $field) {
            if (isset($surfaceMap[$field])) {
                $missing[] = $surfaceMap[$field];
            }
        }

        // If at stop_with_evidence and still missing evidence, note unsearched refactor veins
        if ($nextPass === 'stop_with_evidence' && $missing !== []) {
            $missing[] = 'unsearched_refactor_veins';
        }

        return array_values(array_unique($missing));
    }
}
