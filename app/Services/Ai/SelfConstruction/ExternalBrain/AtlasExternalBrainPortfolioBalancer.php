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

        $status = match (true) {
            $deficits !== [] && $surpluses !== [] => 'rebalanced',
            $deficits !== []                      => 'deficit',
            $surpluses !== []                     => 'rebalanced',
            default                               => 'balanced',
        };

        return [
            'schema'           => self::SCHEMA,
            'status'           => $status,
            'passed'           => $status === 'balanced',
            'total_in'         => $total,
            'total_out'        => count($out),
            'deficits'         => $deficits,
            'surpluses'        => $surpluses,
            'category_counts'  => $categoryCounts,
            'risk_tier_counts' => $riskTierCounts,
            'candidates'       => $out,
        ];
    }
}
