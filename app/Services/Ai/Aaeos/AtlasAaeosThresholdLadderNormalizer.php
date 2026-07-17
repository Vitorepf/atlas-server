<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Ai\Support\AiValueNormalizer;

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

            $level = AiValueNormalizer::trimmedString($band['level']);
            if ($level === '') {
                return [];
            }

            $thresholds = self::thresholds($band['thresholds'] ?? null);
            if ($thresholds === null) {
                return [];
            }

            $bands[] = [
                'level' => $level,
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

            $bandName = AiValueNormalizer::trimmedString($band['band']);
            if ($bandName === '') {
                return [];
            }

            $thresholds = self::thresholds($band['thresholds'] ?? null);
            if ($thresholds === null) {
                return [];
            }

            $bands[] = [
                'band' => $bandName,
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
            if (! self::isThresholdShape($threshold)) {
                return null;
            }

            $normalized[] = [
                'metric' => AiValueNormalizer::trimmedString($threshold['metric']),
                'comparator' => AiValueNormalizer::trimmedString($threshold['comparator']),
                'value' => AiValueNormalizer::finiteFloatOrNull($threshold['value']) ?? 0.0,
            ];
        }

        return $normalized;
    }

    private static function isThresholdShape(mixed $threshold): bool
    {
        return is_array($threshold)
            && isset($threshold['metric'], $threshold['comparator'], $threshold['value'])
            && is_string($threshold['metric'])
            && AiValueNormalizer::trimmedString($threshold['metric']) !== ''
            && is_string($threshold['comparator'])
            && AiValueNormalizer::trimmedString($threshold['comparator']) !== ''
            && in_array(get_debug_type($threshold['value']), ['int', 'float'], true)
            && is_finite(AiValueNormalizer::finiteFloatOrNull($threshold['value']) ?? NAN);
    }
}
