<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;

/**
 * Small, deterministic regime labeller for campaign reports. It does not affect
 * certification; it explains where a strategy did or did not work.
 */
final class MarketRegimeAnalyzer
{
    /**
     * @param  list<Bar>  $bars
     * @param  list<float>  $strategyReturns  length usually bars - 1
     * @return array<string,array<string,float|int|null>>
     */
    public function summarize(array $bars, array $strategyReturns, float $periodsPerYear, int $trendWindow = 90, int $volWindow = 30): array
    {
        $n = min(count($strategyReturns), max(0, count($bars) - 1));
        $buckets = [
            'bull' => [],
            'bear' => [],
            'lateral' => [],
            'high_volatility' => [],
            'low_volatility' => [],
        ];
        if ($n === 0) {
            return $this->summaries($buckets, $periodsPerYear);
        }

        $marketReturns = [];
        for ($i = 1; $i < count($bars); $i++) {
            $prev = $bars[$i - 1]->close;
            $marketReturns[$i] = $prev > 0 ? $bars[$i]->close / $prev - 1.0 : 0.0;
        }
        $rollingVols = [];
        for ($i = 1; $i < count($bars); $i++) {
            $slice = array_slice($marketReturns, max(1, $i - $volWindow + 1), min($volWindow, $i), true);
            $rollingVols[$i] = $this->meanAbs($slice);
        }
        $volThreshold = $this->median(array_values($rollingVols));

        for ($r = 0; $r < $n; $r++) {
            $barIndex = $r + 1;
            $strategyReturn = (float) $strategyReturns[$r];
            $start = max(0, $barIndex - $trendWindow);
            $trend = $bars[$start]->close > 0 ? $bars[$barIndex]->close / $bars[$start]->close - 1.0 : 0.0;
            if ($trend > 0.15) {
                $buckets['bull'][] = $strategyReturn;
            } elseif ($trend < -0.15) {
                $buckets['bear'][] = $strategyReturn;
            } else {
                $buckets['lateral'][] = $strategyReturn;
            }

            if (($rollingVols[$barIndex] ?? 0.0) >= $volThreshold) {
                $buckets['high_volatility'][] = $strategyReturn;
            } else {
                $buckets['low_volatility'][] = $strategyReturn;
            }
        }

        return $this->summaries($buckets, $periodsPerYear);
    }

    /**
     * @param  array<string,list<float>>  $buckets
     * @return array<string,array<string,float|int|null>>
     */
    private function summaries(array $buckets, float $periodsPerYear): array
    {
        $metrics = new HonestMetrics;
        $out = [];
        foreach ($buckets as $name => $returns) {
            $cumulative = 1.0;
            foreach ($returns as $r) {
                $cumulative *= (1.0 + (float) $r);
            }
            $out[$name] = [
                'observations' => count($returns),
                'mean_return' => $returns !== [] ? round($metrics->mean($returns), 8) : null,
                'cumulative_return' => $returns !== [] ? round($cumulative - 1.0, 8) : null,
                'ann_sharpe' => count($returns) > 2 ? round($metrics->sharpe($returns, $periodsPerYear), 6) : null,
            ];
        }

        return $out;
    }

    /** @param array<int,float> $values */
    private function meanAbs(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum(array_map(static fn (float $v): float => abs($v), $values)) / count($values);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
