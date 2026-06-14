<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosThresholdLadderNormalizer
{
    /**
     * @param  list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>  $bandLadder
     * @return list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    public static function levelLadder(array $bandLadder): array
    {
        if (! array_is_list($bandLadder)) {
            return [];
        }

        $bands = [];

        foreach ($bandLadder as $band) {
            if (! is_array($band) || ! isset($band['level']) || ! is_string($band['level'])) {
                return [];
            }

            $thresholds = self::thresholds($band['thresholds'] ?? null);
            if ($thresholds === null) {
                return [];
            }

            $bands[] = [
                'level' => $band['level'],
                'thresholds' => $thresholds,
            ];
        }

        return $bands;
    }

    /**
     * @param  list<array{band: string, rank: int, thresholds: list<array{metric: string, comparator: string, value: float}>}>  $bandLadder
     * @return list<array{band: string, rank: int, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    public static function rankedBandLadder(array $bandLadder): array
    {
        if (! array_is_list($bandLadder)) {
            return [];
        }

        $bands = [];

        foreach ($bandLadder as $band) {
            if (! is_array($band)
                || ! isset($band['band'], $band['rank'])
                || ! is_string($band['band'])
                || ! is_int($band['rank'])
            ) {
                return [];
            }

            $thresholds = self::thresholds($band['thresholds'] ?? null);
            if ($thresholds === null) {
                return [];
            }

            $bands[] = [
                'band' => $band['band'],
                'rank' => $band['rank'],
                'thresholds' => $thresholds,
            ];
        }

        return $bands;
    }

    /**
     * @return list<array{metric: string, comparator: string, value: float}>|null
     */
    private static function thresholds(mixed $thresholds): ?array
    {
        if (! is_array($thresholds) || ! array_is_list($thresholds)) {
            return null;
        }

        $normalized = [];

        foreach ($thresholds as $threshold) {
            if (! is_array($threshold)
                || ! isset($threshold['metric'], $threshold['comparator'], $threshold['value'])
                || ! is_string($threshold['metric'])
                || ! is_string($threshold['comparator'])
                || (! is_int($threshold['value']) && ! is_float($threshold['value']))
                || ! is_finite((float) $threshold['value'])
            ) {
                return null;
            }

            $normalized[] = [
                'metric' => $threshold['metric'],
                'comparator' => $threshold['comparator'],
                'value' => (float) $threshold['value'],
            ];
        }

        return $normalized;
    }
}
