<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

/**
 * Matematica de indicadores compartilhada pelas estrategias do StrategyLoop (atr/sma/
 * highest/lowest) — vivia clonada em ate 5 estrategias (medicao jscpd 05/07). Estrategia
 * com variante PROPRIA de um metodo a mantem na classe (metodo de classe vence trait).
 */
trait SharedIndicatorMath
{
    private function atr(array $high, array $low, array $close, int $idx, int $period): float
    {
        $lo = max(1, $idx - $period + 1);
        $sum = 0.0;
        $count = 0;
        for ($i = $lo; $i <= $idx; $i++) {
            $tr = max(
                $high[$i] - $low[$i],
                abs($high[$i] - $close[$i - 1]),
                abs($low[$i] - $close[$i - 1]),
            );
            $sum += $tr;
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    private function sma(array $a, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $sum = 0.0;
        $count = 0;
        for ($i = $lo; $i <= $idx; $i++) {
            $sum += $a[$i];
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    private function highest(array $a, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $m = -INF;
        for ($i = $lo; $i <= $idx; $i++) {
            if ($a[$i] > $m) {
                $m = $a[$i];
            }
        }

        return $m === -INF ? PHP_FLOAT_MAX : $m; // empty window => unreachable breakout
    }

    private function lowest(array $a, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $m = INF;
        for ($i = $lo; $i <= $idx; $i++) {
            if ($a[$i] < $m) {
                $m = $a[$i];
            }
        }

        return $m === INF ? -PHP_FLOAT_MAX : $m; // empty window => unreachable exit
    }
}
