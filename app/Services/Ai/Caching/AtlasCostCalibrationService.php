<?php

declare(strict_types=1);

namespace App\Services\Ai\Caching;

/**
 * Turns observed cost telemetry (the JSONL the cost sentinel records in observe
 * mode) into the numbers the operator needs to set a hard ceiling from DATA, not
 * a guess: the percentile distribution of real per-call pre-cost, plus a
 * conservative suggested ceiling (p99 of observed cost).
 *
 * This is the "Calibrate" stage of the staged-rollout ratchet: observe with the
 * sentinel → calibrate from this report → set the hard_gate_units → enforce.
 */
final class AtlasCostCalibrationService
{
    public const SCHEMA_VERSION = 'atlas.ai.cost_calibration.v1';

    /**
     * @return array{schema_version:string,available:bool,samples:int,records:int,p50:?float,p90:?float,p99:?float,max:?float,soft_warn_rate:float,suggested_hard_gate_units:?float}
     */
    public function calibrate(string $logPath): array
    {
        $costs = [];
        $softWarns = 0;
        $total = 0;

        if (is_file($logPath)) {
            foreach (file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $rec = json_decode($line, true);
                if (! is_array($rec)) {
                    continue;
                }
                $total++;
                if (isset($rec['pre_cost_units']) && is_numeric($rec['pre_cost_units'])) {
                    $costs[] = (float) $rec['pre_cost_units'];
                }
                if (($rec['soft_warn'] ?? false) === true) {
                    $softWarns++;
                }
            }
        }

        sort($costs);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'available' => $costs !== [],
            'samples' => count($costs),
            'records' => $total,
            'p50' => $this->percentile($costs, 0.50),
            'p90' => $this->percentile($costs, 0.90),
            'p99' => $this->percentile($costs, 0.99),
            'max' => $costs === [] ? null : $costs[count($costs) - 1],
            'soft_warn_rate' => $total > 0 ? round($softWarns / $total, 4) : 0.0,
            // A conservative starting ceiling: the p99 of observed pre-cost.
            'suggested_hard_gate_units' => $this->percentile($costs, 0.99),
        ];
    }

    /**
     * Linear-interpolated percentile.
     *
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $q): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return $sorted[0];
        }

        $rank = $q * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $sorted[$low];
        }

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($rank - $low);
    }
}
