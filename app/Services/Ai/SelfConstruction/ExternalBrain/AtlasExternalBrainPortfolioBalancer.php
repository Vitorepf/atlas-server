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

        // Risk-dimension aggregates from candidate fields.
        $totalGbr = 0.0;
        $totalPr  = 0.0;
        $totalLev = 0.0;
        foreach ($candidates as $c) {
            $totalGbr += (float) ($c['give_back_risk']  ?? 0.0);
            $totalPr  += (float) ($c['proxy_risk']      ?? 0.0);
            $totalLev += (float) ($c['leverage_score']  ?? 0.5);
        }
        $n      = max(1, $total);
        $avgGbr = $totalGbr / $n;
        $avgPr  = $totalPr  / $n;
        $avgLev = $totalLev / $n;

        $riskFlags              = [];
        $balanceReasons         = [];
        $replacementCat         = null;
        $replacementRecs        = [];

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
        $scaffoldCount = 0;
        foreach ($candidates as $c) {
            $subtype = strtolower(trim((string) ($c['category_subtype'] ?? '')));
            if (in_array($subtype, self::SCAFFOLD_SUBTYPES, true)) {
                $scaffoldCount++;
            }
        }
        $scaffoldRatio        = $total > 0 ? $scaffoldCount / $total : 0.0;
        $consolidationDebtHigh = $consolidationDebt > self::CONSOLIDATION_DEBT_THRESHOLD;
        if ($total > 0 && $consolidationDebtHigh && $scaffoldRatio > self::SCAFFOLD_DOMINANCE_THRESHOLD) {
            $riskFlags[]      = 'scaffold_dominance_with_high_consolidation_debt';
            $balanceReasons[] = 'consolidation_debt:high:'.round($consolidationDebt, 2).':scaffold_ratio:'.round($scaffoldRatio, 2);
        }

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
}
