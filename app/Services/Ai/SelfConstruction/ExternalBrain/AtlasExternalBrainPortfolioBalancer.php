<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Ensures each brain wave contains a healthy mix of value categories and risk tiers,
 * following operator-ranked priority order.
 *
 * Operator ranking (highest to lowest):
 *   task_quality_repair, learning_loop  — HIGH_PRIORITY (must not be missing when filler present)
 *   bug_fix, architecture_unlock, test_gate — MID_PRIORITY
 *   runtime_continuity, docs_sync       — LOW_PRIORITY_FILLER (blocked when high-priority absent)
 *
 * Enforcement (applied when wave >= MIN_WAVE_SIZE):
 *   - Any single category may not exceed MAX_FRACTION of the wave.
 *   - Every category must have at least one candidate; missing ones are reported as deficits.
 *
 * Additional checks (always applied, regardless of wave size):
 *   - If HIGH_PRIORITY_CATEGORIES are missing AND LOW_PRIORITY_FILLER is present:
 *       → unbalanced + replacement_recommendations emitted before accepting filler.
 *   - If wave scaffold/new_organ subtype > SCAFFOLD_DOMINANCE_THRESHOLD AND
 *       waveContext consolidation_debt > CONSOLIDATION_DEBT_THRESHOLD:
 *       → unbalanced even if raw category counts look diverse.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainPortfolioBalancer
{
    public const SCHEMA = 'atlas.external_brain.portfolio_balancer.v1';

    public const CATEGORIES = [
        'bug_fix',
        'architecture_unlock',
        'test_gate',
        'runtime_continuity',
        'task_quality_repair',
        'docs_sync',
        'learning_loop',
    ];

    /** Operator-ranked priority order (index 0 = highest). */
    public const PRIORITY_RANKING = [
        'task_quality_repair',
        'learning_loop',
        'bug_fix',
        'architecture_unlock',
        'test_gate',
        'runtime_continuity',
        'docs_sync',
    ];

    /** Missing any of these while filler present → unbalanced + replacement recs. */
    public const HIGH_PRIORITY_CATEGORIES = ['task_quality_repair', 'learning_loop'];

    /** These categories are blocked when high-priority slots are empty. */
    public const LOW_PRIORITY_FILLER = ['docs_sync', 'runtime_continuity'];

    /** candidate.category_subtype values that count as scaffold/new-organ work. */
    public const SCAFFOLD_SUBTYPES = ['new_organ', 'scaffold'];

    public const RISK_TIERS = ['low', 'medium', 'high'];

    /** No single category may exceed this share of the wave. */
    private const MAX_FRACTION = 0.50;

    /** Minimum wave size before minimum-per-category is enforced. */
    private const MIN_WAVE_SIZE = 7;

    private const MAX_AVG_GIVE_BACK_RISK        = 0.6;
    private const MAX_AVG_PROXY_RISK            = 0.5;
    private const MIN_AVG_LEVERAGE_SCORE        = 0.3;
    private const CONSOLIDATION_DEBT_THRESHOLD  = 0.60;
    private const SCAFFOLD_DOMINANCE_THRESHOLD  = 0.50;

    /** Cognitive lanes a portfolio must spread across instead of overfocusing on the easiest one. */
    public const LANES = ['exploration', 'consolidation', 'simplification', 'learning', 'certification', 'delivery'];

    private const DEFAULT_LANE_CAPACITY = 10;

    /** No single lane may take more than this share of capacity. */
    private const LANE_MAX_FRACTION = 0.40;

    /** A lane this risky is suppressed entirely regardless of leverage. */
    private const LANE_SUPPRESSION_RISK_CEILING = 0.80;

    /** Starvation reaching this many days fully maxes out the starvation boost. */
    private const LANE_STARVATION_FULL_DAYS = 30;

    /**
     * Allocates wave capacity across cognitive lanes (exploration,
     * consolidation, simplification, learning, certification, delivery)
     * from maturity, queue pressure, starvation, risk and expected leverage
     * — instead of letting the easiest lane absorb every slot.
     *
     * A lane explicitly flagged as needing repair/consolidation evidence
     * gets a reserved minimum allocation regardless of its raw score so
     * accumulated debt is never starved out by flashier exploration work.
     * A lane whose risk exceeds the suppression ceiling is suppressed to
     * zero — leverage alone never buys past that floor.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function allocateLanes(array $input): array
    {
        $laneFacts = (array) ($input['lanes'] ?? []);
        $capacity = max(1, (int) ($input['capacity'] ?? self::DEFAULT_LANE_CAPACITY));
        $maxPerLane = max(1, (int) floor($capacity * self::LANE_MAX_FRACTION));

        $suppressedLanes = [];
        $reservedAllocation = [];
        $scores = [];
        $rationale = [];

        foreach (self::LANES as $lane) {
            $facts = (array) ($laneFacts[$lane] ?? []);
            $maturity = max(0.0, min(1.0, (float) ($facts['maturity'] ?? 0.5)));
            $queuePressure = max(0.0, min(1.0, (float) ($facts['queue_pressure'] ?? 0.0)));
            $starvationDays = max(0, (int) ($facts['starvation_days'] ?? 0));
            $risk = max(0.0, min(1.0, (float) ($facts['risk'] ?? 0.0)));
            $expectedLeverage = max(0.0, min(1.0, (float) ($facts['expected_leverage'] ?? 0.0)));
            $repairEvidenceRequired = (bool) ($facts['repair_evidence_required'] ?? false);

            if ($risk >= self::LANE_SUPPRESSION_RISK_CEILING) {
                $suppressedLanes[] = ['lane' => $lane, 'reason' => 'risk_exceeds_suppression_ceiling'];
                $rationale[] = "{$lane}: suppressed, risk {$risk} exceeds ceiling ".self::LANE_SUPPRESSION_RISK_CEILING;

                continue;
            }

            $starvationBoost = min(1.0, $starvationDays / self::LANE_STARVATION_FULL_DAYS);
            $score = max(0.01, round(
                $expectedLeverage * 0.40
                + $starvationBoost * 0.30
                + (1.0 - $maturity) * 0.20
                + $queuePressure * 0.10
                - $risk * 0.30,
                6,
            ));

            if ($repairEvidenceRequired) {
                $reservedAllocation[$lane] = 1;
                $rationale[] = "{$lane}: reserved minimum slot, repair/consolidation evidence required";
            }

            $scores[$lane] = $score;
        }

        $reservedTotal = array_sum($reservedAllocation);
        $remainingCapacity = max(0, $capacity - $reservedTotal);
        $scoreSum = array_sum($scores);

        // Largest-remainder method: floor each share, then hand out the
        // leftover slots to the lanes with the biggest fractional remainder
        // so the total always equals remainingCapacity exactly.
        $allocation = $reservedAllocation;
        $shares = [];
        foreach ($scores as $lane => $score) {
            $shares[$lane] = $scoreSum > 0.0 ? ($score / $scoreSum) * $remainingCapacity : 0.0;
            $allocation[$lane] = ($allocation[$lane] ?? 0) + (int) floor($shares[$lane]);
        }
        $distributed = array_sum(array_intersect_key($allocation, $shares));
        $leftover = $remainingCapacity - $distributed;
        if ($leftover > 0) {
            $remainders = [];
            foreach ($shares as $lane => $share) {
                $remainders[$lane] = $share - floor($share);
            }
            arsort($remainders);
            foreach (array_keys($remainders) as $lane) {
                if ($leftover <= 0) {
                    break;
                }
                $allocation[$lane]++;
                $leftover--;
            }
        }
        foreach (self::LANES as $lane) {
            $allocation[$lane] = $allocation[$lane] ?? 0;
        }

        // Cap overfocused lanes and redistribute the overflow to the rest.
        $promotedLanes = [];
        $overflow = 0;
        foreach ($allocation as $lane => $slots) {
            if (! isset($scores[$lane])) {
                continue;
            }
            if ($slots > $maxPerLane) {
                $overflow += $slots - $maxPerLane;
                $allocation[$lane] = $maxPerLane;
                $rationale[] = "{$lane}: capped at {$maxPerLane} slots to prevent overfocus";
            }
        }
        if ($overflow > 0) {
            $redistributable = array_values(array_filter(
                array_keys($scores),
                static fn (string $lane): bool => $allocation[$lane] < $maxPerLane,
            ));
            while ($overflow > 0 && $redistributable !== []) {
                foreach ($redistributable as $lane) {
                    if ($overflow <= 0) {
                        break;
                    }
                    if ($allocation[$lane] >= $maxPerLane) {
                        continue;
                    }
                    $allocation[$lane]++;
                    $overflow--;
                    $promotedLanes[$lane] = true;
                }
                $redistributable = array_values(array_filter(
                    $redistributable,
                    static fn (string $lane) => $allocation[$lane] < $maxPerLane,
                ));
            }
        }

        foreach (array_keys($reservedAllocation) as $lane) {
            $promotedLanes[$lane] = true;
        }

        return [
            'schema' => self::SCHEMA,
            'capacity' => $capacity,
            'allocation' => $allocation,
            'suppressed_lanes' => $suppressedLanes,
            'promoted_lanes' => array_values(array_keys($promotedLanes)),
            'rationale' => $rationale,
        ];
    }

    /**
     * Balance a candidate wave.
     *
     * Each candidate array should carry:
     *   - 'category'          (string)  — one of CATEGORIES
     *   - 'category_subtype'  (string)  — optional; 'new_organ'|'scaffold' counts toward scaffold ratio
     *   - 'risk_tier'         (string)  — one of RISK_TIERS, defaults to 'low'
     *   - 'final_score'       (float)   — used to rank within surplus categories
     *   - 'give_back_risk'    (float)   — 0-1
     *   - 'proxy_risk'        (float)   — 0-1
     *   - 'leverage_score'    (float)   — 0-1
     *
     * $waveContext optional:
     *   - 'consolidation_debt' (float) — 0-1; high values activate scaffold-dominance check
     *
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>        $waveContext
     */
    public function balance(array $candidates, array $waveContext = []): array
    {
        $total             = count($candidates);
        $enforceMinimum    = $total >= self::MIN_WAVE_SIZE;
        $maxAllowed        = $total > 0 ? (int) ceil($total * self::MAX_FRACTION) : 0;
        $consolidationDebt = (float) ($waveContext['consolidation_debt'] ?? 0.0);

        $categoryCounts = array_fill_keys(self::CATEGORIES, 0);
        $riskTierCounts = array_fill_keys(self::RISK_TIERS, 0);
        foreach ($candidates as $c) {
            $cat  = (string) ($c['category']  ?? '');
            $tier = (string) ($c['risk_tier'] ?? 'low');
            if (array_key_exists($cat, $categoryCounts)) {
                $categoryCounts[$cat]++;
            }
            if (array_key_exists($tier, $riskTierCounts)) {
                $riskTierCounts[$tier]++;
            }
        }

        $deficits  = [];
        $surpluses = [];
        foreach (self::CATEGORIES as $cat) {
            $count = $categoryCounts[$cat];
            if ($enforceMinimum && $count === 0) {
                $deficits[] = ['category' => $cat, 'count' => 0, 'minimum_required' => 1];
            }
            if ($total > 0 && $count > $maxAllowed) {
                $surpluses[] = ['category' => $cat, 'count' => $count, 'max_allowed' => $maxAllowed];
            }
        }

        // Trim surplus categories: keep the highest-scored candidates up to max_allowed.
        $out = $candidates;
        if ($surpluses !== []) {
            $surplusSet = array_fill_keys(array_column($surpluses, 'category'), true);
            $other      = [];
            $buckets    = [];
            foreach ($out as $c) {
                $cat = (string) ($c['category'] ?? '');
                if (isset($surplusSet[$cat])) {
                    $buckets[$cat][] = $c;
                } else {
                    $other[] = $c;
                }
            }
            $trimmed = [];
            foreach ($buckets as $cat => $bucket) {
                usort($bucket, static fn (array $a, array $b): int => (float) ($b['final_score'] ?? 0.0) <=> (float) ($a['final_score'] ?? 0.0));
                $trimmed = array_merge($trimmed, array_slice($bucket, 0, $maxAllowed));
            }
            $out = array_values(array_merge($other, $trimmed));
        }

        // Single balance-metrics circuit: risk averages, risk flags, replacement
        // category/recommendations, and scaffold-dominance flag all derive from
        // this one pass so no metric can disagree with another about wave health.
        $metrics = $this->computeBalanceMetrics($candidates, $categoryCounts, $consolidationDebt);
        $avgGbr              = $metrics['avg_give_back_risk'];
        $avgPr               = $metrics['avg_proxy_risk'];
        $avgLev              = $metrics['avg_leverage_score'];
        $riskFlags           = $metrics['risk_flags'];
        $balanceReasons      = $metrics['balance_reasons'];
        $replacementCat      = $metrics['replacement_category'];
        $replacementRecs     = $metrics['replacement_recommendations'];
        $consolidationDebtHigh = $metrics['consolidation_debt_flag'];

        foreach ($deficits as $d) {
            $balanceReasons[] = 'missing_capability_dimension:'.$d['category'];
        }
        foreach ($surpluses as $s) {
            $balanceReasons[] = 'category_diversity:surplus:'.$s['category'];
        }

        $status = match (true) {
            $riskFlags !== []                     => 'unbalanced',
            $deficits !== [] && $surpluses !== [] => 'rebalanced',
            $deficits !== []                      => 'deficit',
            $surpluses !== []                     => 'rebalanced',
            default                               => 'balanced',
        };

        return [
            'schema'                       => self::SCHEMA,
            'status'                       => $status,
            'passed'                       => $status === 'balanced',
            'total_in'                     => $total,
            'total_out'                    => count($out),
            'deficits'                     => $deficits,
            'surpluses'                    => $surpluses,
            'category_counts'              => $categoryCounts,
            'risk_tier_counts'             => $riskTierCounts,
            'avg_leverage_score'           => round($avgLev, 4),
            'avg_give_back_risk'           => round($avgGbr, 4),
            'avg_proxy_risk'               => round($avgPr, 4),
            'unbalanced_risk_flags'        => $riskFlags,
            'balance_reasons'              => $balanceReasons,
            'replacement_category'         => $replacementCat,
            'replacement_recommendations'  => $replacementRecs,
            'consolidation_debt_flag'      => $consolidationDebtHigh,
            'candidates'                   => $out,
        ];
    }

    /**
     * Single balance metrics circuit: computes give_back/proxy/leverage
     * averages, the risk flags they trigger, replacement category selection,
     * operator-priority coverage recommendations, and the scaffold-dominance
     * flag from one pass over the candidates — so no two metrics can disagree
     * about whether the wave is healthy.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,int>          $categoryCounts
     * @return array{avg_give_back_risk:float, avg_proxy_risk:float, avg_leverage_score:float, risk_flags:list<string>, balance_reasons:list<string>, replacement_category:?string, replacement_recommendations:list<array<string,string>>, consolidation_debt_flag:bool}
     */
    private function computeBalanceMetrics(array $candidates, array $categoryCounts, float $consolidationDebt): array
    {
        $total = count($candidates);

        $totalGbr = 0.0;
        $totalPr  = 0.0;
        $totalLev = 0.0;
        $scaffoldCount = 0;
        foreach ($candidates as $c) {
            $totalGbr += (float) ($c['give_back_risk']  ?? 0.0);
            $totalPr  += (float) ($c['proxy_risk']      ?? 0.0);
            $totalLev += (float) ($c['leverage_score']  ?? 0.5);

            $subtype = strtolower(trim((string) ($c['category_subtype'] ?? '')));
            if (in_array($subtype, self::SCAFFOLD_SUBTYPES, true)) {
                $scaffoldCount++;
            }
        }
        $n      = max(1, $total);
        $avgGbr = $totalGbr / $n;
        $avgPr  = $totalPr  / $n;
        $avgLev = $totalLev / $n;
        $scaffoldRatio = $total > 0 ? $scaffoldCount / $total : 0.0;

        $riskFlags       = [];
        $balanceReasons  = [];
        $replacementCat  = null;
        $replacementRecs = [];

        if ($total > 0 && $avgGbr > self::MAX_AVG_GIVE_BACK_RISK) {
            $riskFlags[]      = 'high_give_back_risk';
            $balanceReasons[] = 'give_back_risk:high:avg_'.round($avgGbr, 2);
            $replacementCat  ??= $this->leastRepresentedCategory($categoryCounts);
        }
        if ($total > 0 && $avgPr > self::MAX_AVG_PROXY_RISK) {
            $riskFlags[]      = 'high_proxy_risk';
            $balanceReasons[] = 'proxy_risk:high:avg_'.round($avgPr, 2);
            $replacementCat  ??= $this->leastRepresentedCategory($categoryCounts);
        }
        if ($total > 0 && $avgLev < self::MIN_AVG_LEVERAGE_SCORE) {
            $riskFlags[]      = 'low_leverage_score';
            $balanceReasons[] = 'leverage_score:low:avg_'.round($avgLev, 2);
        }

        // Operator ranking: missing high-priority + has low-priority filler → unbalanced.
        $missingHighPriority = array_values(array_filter(
            self::HIGH_PRIORITY_CATEGORIES,
            fn (string $cat) => ($categoryCounts[$cat] ?? 0) === 0,
        ));
        $presentFillerCats = array_values(array_filter(
            self::LOW_PRIORITY_FILLER,
            fn (string $cat) => ($categoryCounts[$cat] ?? 0) > 0,
        ));
        if ($missingHighPriority !== [] && $presentFillerCats !== []) {
            $riskFlags[]      = 'missing_high_priority_coverage';
            foreach ($missingHighPriority as $hpCat) {
                $balanceReasons[] = 'missing_high_priority_category:'.$hpCat;
                foreach ($presentFillerCats as $lpCat) {
                    $replacementRecs[] = ['replace' => $lpCat, 'with' => $hpCat];
                }
            }
        }

        // Scaffold dominance check: too much new_organ/scaffold when consolidation_debt is high.
        $consolidationDebtHigh = $consolidationDebt > self::CONSOLIDATION_DEBT_THRESHOLD;
        if ($total > 0 && $consolidationDebtHigh && $scaffoldRatio > self::SCAFFOLD_DOMINANCE_THRESHOLD) {
            $riskFlags[]      = 'scaffold_dominance_with_high_consolidation_debt';
            $balanceReasons[] = 'consolidation_debt:high:'.round($consolidationDebt, 2).':scaffold_ratio:'.round($scaffoldRatio, 2);
        }

        return [
            'avg_give_back_risk' => $avgGbr,
            'avg_proxy_risk' => $avgPr,
            'avg_leverage_score' => $avgLev,
            'risk_flags' => $riskFlags,
            'balance_reasons' => $balanceReasons,
            'replacement_category' => $replacementCat,
            'replacement_recommendations' => $replacementRecs,
            'consolidation_debt_flag' => $consolidationDebtHigh,
        ];
    }

    /** @param array<string,int> $categoryCounts */
    private function leastRepresentedCategory(array $categoryCounts): string
    {
        $min  = PHP_INT_MAX;
        $best = self::CATEGORIES[0];
        foreach (self::CATEGORIES as $cat) {
            $count = $categoryCounts[$cat] ?? 0;
            if ($count < $min) {
                $min  = $count;
                $best = $cat;
            }
        }
        return $best;
    }

    /** Originator work lanes: the whole originator batch splits across these. */
    public const PORTFOLIO_LANES = [
        'build', 'repair', 'simplify', 'research',
        'verification', 'learning', 'model_amplifier', 'queue_self_healing',
    ];

    /** Repair/verification may never be squeezed below this share of the batch. */
    private const PORTFOLIO_FLOOR_PCT = 10.0;

    /** No lane may exceed this share of the batch — prevents one easy lane from dominating. */
    private const PORTFOLIO_MAX_SHARE_PCT = 25.0;

    /** repair_pressure above this counts as strong risk evidence, exempting 'repair' from the cap. */
    private const STRONG_RISK_EVIDENCE_THRESHOLD = 0.6;

    /** Repair pressure above this activates the repair/queue-self-healing shift. */
    private const REPAIR_PRESSURE_ACTIVATION = 0.3;

    /** Simplification debt above this activates the simplify shift. */
    private const SIMPLIFICATION_DEBT_ACTIVATION = 0.5;

    /**
     * Balances one originator batch across the 8 portfolio lanes from live
     * risk (give_back/poison/malformed/collision pressure), simplification
     * debt, and queue health/leverage — instead of a static even split.
     *
     * INPUT:
     *   give_back_rate, poison_rate, malformed_rate, collision_rate: float 0..1
     *   simplification_debt: float 0..1
     *   queue_shallow: bool — the queue is healthy/shallow, not backed up
     *   high_leverage_candidates: bool — real high-leverage build/research candidates exist
     *
     * OUTPUT: { schema, percentages: array<lane,int> summing to 100, total_percent, reasons }
     *
     * @param  array<string,mixed>  $signals
     * @return array{schema:string, percentages:array<string,int>, total_percent:int, reasons:list<string>}
     */
    public function balancePortfolio(array $signals): array
    {
        $clamp = static fn (float $v): float => max(0.0, min(1.0, $v));

        $giveBackRate  = $clamp((float) ($signals['give_back_rate']  ?? 0.0));
        $poisonRate    = $clamp((float) ($signals['poison_rate']     ?? 0.0));
        $malformedRate = $clamp((float) ($signals['malformed_rate']  ?? 0.0));
        $collisionRate = $clamp((float) ($signals['collision_rate']  ?? 0.0));
        $simplificationDebt = $clamp((float) ($signals['simplification_debt'] ?? 0.0));
        $queueShallow = (bool) ($signals['queue_shallow'] ?? false);
        $highLeverageCandidates = (bool) ($signals['high_leverage_candidates'] ?? false);

        $repairPressure = ($giveBackRate + $poisonRate + $malformedRate + $collisionRate) / 4.0;

        $scores = array_fill_keys(self::PORTFOLIO_LANES, 1.0);
        $reasons = [];

        if ($repairPressure > self::REPAIR_PRESSURE_ACTIVATION) {
            $scores['repair'] += $repairPressure * 3.0;
            $scores['queue_self_healing'] += $repairPressure * 2.5;
            $scores['build'] -= $repairPressure * 0.4;
            $scores['simplify'] -= $repairPressure * 0.3;
            $scores['research'] -= $repairPressure * 0.3;
            $reasons[] = sprintf(
                'repair_pressure=%.2f (give_back/poison/malformed/collision) shifts share toward repair and queue_self_healing',
                $repairPressure,
            );
        }

        if ($simplificationDebt > self::SIMPLIFICATION_DEBT_ACTIVATION) {
            // Never taken from repair or verification — debt-driven simplification must not starve them.
            $scores['simplify'] += $simplificationDebt * 2.0;
            $scores['build'] -= $simplificationDebt * 0.3;
            $scores['research'] -= $simplificationDebt * 0.2;
            $scores['learning'] -= $simplificationDebt * 0.2;
            $reasons[] = sprintf(
                'simplification_debt=%.2f shifts share toward simplify without reducing repair or verification',
                $simplificationDebt,
            );
        }

        if ($queueShallow && $highLeverageCandidates) {
            $scores['build'] += 0.5;
            $scores['research'] += 0.5;
            $reasons[] = 'healthy shallow queue with high-leverage candidates keeps build/research lanes active';
        }

        foreach ($scores as $lane => $score) {
            $scores[$lane] = max(0.1, $score);
        }

        $sum = array_sum($scores);
        $shares = [];
        foreach ($scores as $lane => $score) {
            $shares[$lane] = $sum > 0.0 ? ($score / $sum) * 100.0 : 0.0;
        }

        foreach (['repair', 'verification'] as $lane) {
            if ($shares[$lane] >= self::PORTFOLIO_FLOOR_PCT) {
                continue;
            }
            $deficit = self::PORTFOLIO_FLOOR_PCT - $shares[$lane];
            $shares[$lane] = self::PORTFOLIO_FLOOR_PCT;
            $donors = array_filter(
                array_keys($shares),
                static fn (string $l): bool => $l !== $lane && $shares[$l] > self::PORTFOLIO_FLOOR_PCT,
            );
            $donorSum = array_sum(array_intersect_key($shares, array_flip($donors)));
            if ($donorSum > 0.0) {
                foreach ($donors as $donor) {
                    $shares[$donor] -= $deficit * ($shares[$donor] / $donorSum);
                }
            }
        }

        // AC2/AC3: cap any single lane's share so it can never dominate the batch while other
        // lanes go undercovered — except 'repair', which may exceed the cap when repair_pressure
        // itself is strong risk evidence (a real incident is never squeezed by a fairness rule).
        $strongRiskEvidence = $repairPressure > self::STRONG_RISK_EVIDENCE_THRESHOLD;
        $rejectedOverconcentration = [];
        $overflow = 0.0;
        foreach ($shares as $lane => $share) {
            if ($lane === 'repair' && $strongRiskEvidence) {
                continue;
            }
            if ($share > self::PORTFOLIO_MAX_SHARE_PCT) {
                $overflow += $share - self::PORTFOLIO_MAX_SHARE_PCT;
                $rejectedOverconcentration[] = [
                    'lane' => $lane,
                    'capped_from' => round($share, 2),
                    'capped_to' => self::PORTFOLIO_MAX_SHARE_PCT,
                    'reason' => 'lane_exceeds_max_share_without_strong_risk_evidence',
                ];
                $shares[$lane] = self::PORTFOLIO_MAX_SHARE_PCT;
            }
        }
        if ($overflow > 0.0) {
            $recipients = array_filter(
                array_keys($shares),
                static fn (string $l): bool => $shares[$l] < self::PORTFOLIO_MAX_SHARE_PCT || ($l === 'repair' && $strongRiskEvidence),
            );
            $recipientSum = array_sum(array_intersect_key($shares, array_flip($recipients)));
            if ($recipientSum > 0.0) {
                foreach ($recipients as $lane) {
                    $shares[$lane] += $overflow * ($shares[$lane] / $recipientSum);
                }
            }
        }
        if ($rejectedOverconcentration !== []) {
            $reasons[] = sprintf(
                'capped %d overconcentrated lane(s) at %.0f%% max share and redistributed the overflow',
                count($rejectedOverconcentration),
                self::PORTFOLIO_MAX_SHARE_PCT,
            );
        }

        if ($reasons === []) {
            $reasons[] = 'no pressure signals present; baseline allocation across lanes';
        }

        $percentages = $this->normalizeToIntegerPercentages($shares);

        return [
            'schema' => self::SCHEMA,
            'percentages' => $percentages,
            'lane_allocations' => $percentages,
            'total_percent' => array_sum($percentages),
            'reasons' => $reasons,
            'rejected_overconcentration' => $rejectedOverconcentration,
        ];
    }

    /**
     * Largest-remainder rounding so integer percentages always sum to exactly 100.
     *
     * @param  array<string,float>  $shares
     * @return array<string,int>
     */
    private function normalizeToIntegerPercentages(array $shares): array
    {
        $floored = array_map(static fn (float $v): int => (int) floor($v), $shares);
        $remainder = 100 - array_sum($floored);

        $remainders = [];
        foreach ($shares as $lane => $v) {
            $remainders[$lane] = $v - floor($v);
        }
        arsort($remainders);

        $result = $floored;
        foreach (array_keys($remainders) as $lane) {
            if ($remainder <= 0) {
                break;
            }
            $result[$lane]++;
            $remainder--;
        }

        return $result;
    }
}
