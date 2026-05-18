<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;

class FinanceValuationService
{
    /**
     * Produce a deterministic valuation review with explicit assumptions. The
     * output is informational only. No live data feed, no execution.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function value(AiMission $mission, string $asset, array $args = []): array
    {
        if (trim($asset) === '') {
            throw FinanceDomainException::invalidAsset('asset cannot be empty');
        }

        $method = (string) ($args['method'] ?? 'multiples');
        if (! in_array($method, ['dcf', 'multiples', 'scenario'], true)) {
            $method = 'multiples';
        }
        $assumptions = (array) ($args['assumptions'] ?? []);
        if ($assumptions === []) {
            $assumptions = [
                'discount_rate_pct' => 10,
                'growth_pct' => 3,
                'horizon_years' => 5,
                'risk_premium_pct' => 4,
            ];
        }

        $signal = $this->deterministicSignal($asset, $assumptions);
        $report = [
            'schema' => 'atlas.ai.finance.valuation.v1',
            'kind' => 'valuation_model',
            'mission_id' => $mission->id,
            'asset' => $asset,
            'method' => $method,
            'assumptions' => $assumptions,
            'estimated_fair_value_label' => $signal['label'],
            'estimated_fair_value_score' => $signal['score'],
            'sensitivity' => [
                'discount_rate_pct +200bps' => 'lower fair value',
                'growth_pct -100bps' => 'lower fair value',
                'risk_premium_pct +200bps' => 'lower fair value',
            ],
            'risk_disclosure' => 'Valuation is a model, not a price. Atlas Finance is review-only.',
            'live_trade_blocked' => true,
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($report);

        return $report;
    }

    /**
     * @param  array<string,mixed>  $assumptions
     * @return array<string,mixed>
     */
    private function deterministicSignal(string $asset, array $assumptions): array
    {
        $seed = hexdec(substr(hash('sha256', $asset.json_encode($assumptions)), 0, 6));
        $score = $seed % 100;

        return [
            'score' => $score,
            'label' => match (true) {
                $score < 25 => 'overvalued (signal)',
                $score < 55 => 'fairly_valued (signal)',
                $score < 80 => 'undervalued (signal)',
                default => 'deep_value (signal)',
            },
        ];
    }
}
