<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingPatternOutcome;

/**
 * LearnedWeightLedger — the flywheel. The OS today scores patterns with MY hand-tuned weights;
 * once real conversion data lands, this ledger learns per-niche weights from outcomes. Each row =
 * one page snapshot + the measured conversion that came back from the postback. The weight of a
 * pattern in a niche = the lift in CVR (or RPV) when that pattern is present vs. absent across
 * recorded outcomes (Bayesian-smoothed for low sample sizes). Provider-free.
 *
 * Currently DORMANT for live data — the operator hasn't run campaigns yet (loop-implement-only-defer-running).
 * What's built: the recording layer + the learning math. The moment one row lands, weights start to
 * calibrate. Until then, the OS uses my craft weights.
 */
class LearnedWeightLedger
{
    /**
     * Record a page snapshot + its measured outcome. The audit fingerprint at the moment freezes
     * which patterns were present so attribution is deterministic later.
     *
     * @param  array<string,mixed>  $opts  niche, page_kind, conversion_rate, clicks, conversions,
     *                                     revenue_per_visitor, vsl_asset_id
     */
    public function record(array $auditSnapshot, int $hollowness, array $opts = []): AiMarketingPatternOutcome
    {
        $present = [];
        foreach (($auditSnapshot['by_library'] ?? []) as $libName => $r) {
            foreach (($r['present'] ?? []) as $k) {
                $present[] = $libName.':'.$k;
            }
        }

        return AiMarketingPatternOutcome::create([
            'vsl_asset_id' => $opts['vsl_asset_id'] ?? null,
            'niche' => (string) ($opts['niche'] ?? 'general'),
            'page_kind' => (string) ($opts['page_kind'] ?? 'bridge'),
            'present_patterns' => $present,
            'audit_snapshot' => $auditSnapshot,
            'hollowness' => $hollowness,
            'conversion_rate' => $opts['conversion_rate'] ?? null,
            'clicks' => $opts['clicks'] ?? null,
            'conversions' => $opts['conversions'] ?? null,
            'revenue_per_visitor' => $opts['revenue_per_visitor'] ?? null,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Learned weight per pattern within a niche: Bayesian-smoothed CVR-lift when present vs absent.
     * Returns ['library:key' => float weight ∈ [0, 1]]. Empty when no data yet.
     *
     * @return array<string,float>
     */
    public function weights(string $niche, string $pageKind = 'bridge', int $minRows = 30): array
    {
        $rows = AiMarketingPatternOutcome::query()
            ->where('niche', $niche)
            ->where('page_kind', $pageKind)
            ->whereNotNull('conversion_rate')
            ->get(['present_patterns', 'conversion_rate'])
            ->map(fn ($r) => ['present_patterns' => (array) $r->present_patterns, 'conversion_rate' => (float) $r->conversion_rate])
            ->all();

        return $this->computeWeights($rows, $minRows);
    }

    /**
     * Pure math: Bayesian-smoothed CVR-lift per pattern, given pre-fetched rows. Public so the
     * learning logic can be tested without a database round-trip.
     *
     * @param  array<int,array{present_patterns:array<int,string>,conversion_rate:float}>  $rows
     * @return array<string,float>
     */
    public function computeWeights(array $rows, int $minRows = 30): array
    {
        if (count($rows) < $minRows) {
            return [];
        }

        $globalMean = array_sum(array_column($rows, 'conversion_rate')) / max(1, count($rows));
        if ($globalMean <= 0) {
            $globalMean = 0.01;
        }

        $patterns = [];
        foreach ($rows as $row) {
            foreach ($row['present_patterns'] as $p) {
                $patterns[$p][] = (float) $row['conversion_rate'];
            }
        }

        $weights = [];
        $prior = 10;
        foreach ($patterns as $p => $present) {
            $n = count($present);
            $mean = array_sum($present) / $n;
            $smoothed = ($n * $mean + $prior * $globalMean) / ($n + $prior);
            $lift = max(0.0, ($smoothed - $globalMean) / max($globalMean, 0.0001));
            $weights[$p] = round(min(1.0, $lift), 4);
        }

        arsort($weights);

        return $weights;
    }
}
