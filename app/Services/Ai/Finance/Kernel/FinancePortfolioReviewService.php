<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;

class FinancePortfolioReviewService
{
    /**
     * Review-only portfolio inspection. Returns concentration, drift, exposure
     * summary. NEVER suggests an order or executes a rebalance.
     *
     * @param  array<int,array<string,mixed>>  $positions  e.g. [['asset'=>'AAPL','weight_pct'=>12.5], ...]
     * @param  array<string,mixed>  $targets  optional target weights by asset
     * @return array<string,mixed>
     */
    public function review(AiMission $mission, array $positions, array $targets = []): array
    {
        if ($positions === []) {
            throw FinanceDomainException::insufficientEvidence('portfolio review requires at least one position');
        }

        $totalWeight = 0.0;
        $normalized = [];
        foreach ($positions as $i => $position) {
            $asset = (string) ($position['asset'] ?? '');
            $weight = (float) ($position['weight_pct'] ?? 0);
            if ($asset === '' || $weight <= 0) {
                throw FinanceDomainException::invalidAsset("position #{$i} requires asset and positive weight_pct");
            }
            $totalWeight += $weight;
            $normalized[] = ['asset' => $asset, 'weight_pct' => $weight];
        }

        $top = collect($normalized)->sortByDesc('weight_pct')->take(5)->values()->all();
        $herfindahl = 0.0;
        foreach ($normalized as $p) {
            $herfindahl += pow($p['weight_pct'] / max($totalWeight, 0.0001), 2);
        }

        $drift = [];
        foreach ($normalized as $p) {
            $target = $targets[$p['asset']] ?? null;
            if ($target !== null) {
                $drift[] = [
                    'asset' => $p['asset'],
                    'actual_pct' => $p['weight_pct'],
                    'target_pct' => (float) $target,
                    'drift_pct' => round($p['weight_pct'] - (float) $target, 4),
                ];
            }
        }

        $report = [
            'schema' => 'atlas.ai.finance.portfolio_review.v1',
            'kind' => 'portfolio_view',
            'mission_id' => $mission->id,
            'positions_count' => count($normalized),
            'total_weight_pct' => round($totalWeight, 4),
            'concentration_top5' => $top,
            'herfindahl_index' => round($herfindahl, 4),
            'drift_vs_target' => $drift,
            'risk_disclosure' => 'Review-only. No order suggested. No auto-rebalance.',
            'live_trade_blocked' => true,
            'auto_rebalance_blocked' => true,
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($report);

        return $report;
    }
}
