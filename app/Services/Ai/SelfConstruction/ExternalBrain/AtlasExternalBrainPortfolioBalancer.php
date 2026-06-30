<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Ensures each brain wave contains a healthy mix of value categories and risk tiers.
 *
 * Categories: bug_fix, architecture_unlock, test_gate, runtime_continuity,
 *             task_quality_repair, docs_sync, learning_loop.
 *
 * Enforcement (applied when wave >= MIN_WAVE_SIZE):
 *   - Any single category may not exceed MAX_FRACTION of the wave.
 *   - Every category must have at least one candidate; missing ones are reported as deficits.
 *
 * When a surplus exists the highest-scored candidates in that category are preserved
 * up to max_allowed; the rest are trimmed. Deficits are always reported so the
 * caller can source missing categories before the wave is dispatched.
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

    public const RISK_TIERS = ['low', 'medium', 'high'];

    /** No single category may exceed this share of the wave. */
    private const MAX_FRACTION = 0.50;

    /** Minimum wave size before minimum-per-category is enforced. */
    private const MIN_WAVE_SIZE = 7;

    private const MAX_AVG_GIVE_BACK_RISK = 0.6;
    private const MAX_AVG_PROXY_RISK = 0.5;
    private const MIN_AVG_LEVERAGE_SCORE = 0.3;

    /**
     * Balance a candidate wave.
     *
     * Each candidate array should carry:
     *   - 'category'   (string)  — one of CATEGORIES
     *   - 'risk_tier'  (string)  — one of RISK_TIERS, defaults to 'low'
     *   - 'final_score' (float)  — used to rank within surplus categories
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{
     *     schema:            string,
     *     status:            string,
     *     passed:            bool,
     *     total_in:          int,
     *     total_out:         int,
     *     deficits:          list<array{category:string,count:int,minimum_required:int}>,
     *     surpluses:         list<array{category:string,count:int,max_allowed:int}>,
     *     category_counts:   array<string,int>,
     *     risk_tier_counts:  array<string,int>,
     *     candidates:        list<array<string,mixed>>,
     * }
     */
    public function balance(array $candidates): array
    {
        $total = count($candidates);
        $enforceMinimum = $total >= self::MIN_WAVE_SIZE;
        $maxAllowed = $total > 0 ? (int) ceil($total * self::MAX_FRACTION) : 0;

        $categoryCounts = array_fill_keys(self::CATEGORIES, 0);
        $riskTierCounts = array_fill_keys(self::RISK_TIERS, 0);
        foreach ($candidates as $c) {
            $cat  = (string) ($c['category'] ?? '');
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
        $n              = max(1, $total);
        $avgGbr         = $totalGbr / $n;
        $avgPr          = $totalPr  / $n;
        $avgLev         = $totalLev / $n;

        $riskFlags       = [];
        $balanceReasons  = [];
        $replacementCat  = null;

        if ($total > 0 && $avgGbr > self::MAX_AVG_GIVE_BACK_RISK) {
            $riskFlags[]     = 'high_give_back_risk';
            $balanceReasons[] = 'give_back_risk:high:avg_'.round($avgGbr, 2);
            $replacementCat  ??= $this->leastRepresentedCategory($categoryCounts);
        }
        if ($total > 0 && $avgPr > self::MAX_AVG_PROXY_RISK) {
            $riskFlags[]     = 'high_proxy_risk';
            $balanceReasons[] = 'proxy_risk:high:avg_'.round($avgPr, 2);
            $replacementCat  ??= $this->leastRepresentedCategory($categoryCounts);
        }
        if ($total > 0 && $avgLev < self::MIN_AVG_LEVERAGE_SCORE) {
            $riskFlags[]     = 'low_leverage_score';
            $balanceReasons[] = 'leverage_score:low:avg_'.round($avgLev, 2);
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
            'schema'                => self::SCHEMA,
            'status'                => $status,
            'passed'                => $status === 'balanced',
            'total_in'              => $total,
            'total_out'             => count($out),
            'deficits'              => $deficits,
            'surpluses'             => $surpluses,
            'category_counts'       => $categoryCounts,
            'risk_tier_counts'      => $riskTierCounts,
            'avg_leverage_score'    => round($avgLev, 4),
            'avg_give_back_risk'    => round($avgGbr, 4),
            'avg_proxy_risk'        => round($avgPr, 4),
            'unbalanced_risk_flags' => $riskFlags,
            'balance_reasons'       => $balanceReasons,
            'replacement_category'  => $replacementCat,
            'candidates'            => $out,
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
