<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath;

use InvalidArgumentException;

/**
 * Filters reporter rows by a minimum frequency threshold and stamps each emitted row
 * with threshold_used. FACT-only; preserves input order; emits observation rows only.
 */
final class AtlasCortexHotpathThresholdEmitter
{
    /**
     * @param  list<array{fqcn:string,cycle_count:int,window_size:int,frequency:float}>  $rows
     * @return list<array{fqcn:string,cycle_count:int,window_size:int,frequency:float,threshold_used:float}>
     */
    public function emit(array $rows, float $threshold): array
    {
        if (is_nan($threshold)) {
            throw new InvalidArgumentException('threshold_out_of_range: NaN is not a valid threshold');
        }
        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new InvalidArgumentException('threshold_out_of_range: must be between 0.0 and 1.0, got '.$threshold);
        }

        $out = [];
        foreach ($rows as $row) {
            if (! isset($row['frequency']) || ! is_numeric($row['frequency'])) {
                continue;
            }
            if ((float) $row['frequency'] >= $threshold) {
                $row['threshold_used'] = $threshold;
                $out[] = $row;
            }
        }

        return $out;
    }
}
