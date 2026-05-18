<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;

class FinanceRiskReviewService
{
    /**
     * Review-only risk metrics over a sequence of returns. Returns
     * vol/drawdown/VaR rough estimates and a qualitative risk label.
     *
     * @param  array<int,float>  $returns  decimal returns (e.g. [0.012, -0.004])
     * @return array<string,mixed>
     */
    public function review(AiMission $mission, string $asset, array $returns = []): array
    {
        if (trim($asset) === '') {
            throw FinanceDomainException::invalidAsset('asset cannot be empty');
        }
        if ($returns === []) {
            $returns = [0.012, -0.008, 0.004, 0.022, -0.015, 0.003, -0.011, 0.018];
        }

        $count = count($returns);
        $mean = array_sum($returns) / $count;
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += pow($r - $mean, 2);
        }
        $variance /= max($count - 1, 1);
        $vol = sqrt($variance);

        sort($returns);
        $varIndex = max(0, (int) floor(0.05 * $count));
        $var95 = $returns[$varIndex];

        $cum = 1.0;
        $peak = 1.0;
        $maxDrawdown = 0.0;
        foreach ($returns as $r) {
            $cum *= (1 + $r);
            if ($cum > $peak) {
                $peak = $cum;
            }
            $drawdown = ($cum - $peak) / max($peak, 0.0001);
            if ($drawdown < $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        $label = match (true) {
            $vol < 0.01 => 'low_volatility',
            $vol < 0.03 => 'medium_volatility',
            default => 'high_volatility',
        };

        $report = [
            'schema' => 'atlas.ai.finance.risk_review.v1',
            'kind' => 'risk_report',
            'mission_id' => $mission->id,
            'asset' => $asset,
            'sample_size' => $count,
            'mean_return' => round($mean, 6),
            'volatility' => round($vol, 6),
            'var_95' => round($var95, 6),
            'max_drawdown' => round($maxDrawdown, 6),
            'qualitative_label' => $label,
            'risk_disclosure' => 'These are model estimates over a small sample. Atlas Finance does NOT execute trades.',
            'live_trade_blocked' => true,
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($report);

        return $report;
    }
}
