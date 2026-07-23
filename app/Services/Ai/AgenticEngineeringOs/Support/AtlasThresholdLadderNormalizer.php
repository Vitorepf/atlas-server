<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasThresholdLadderNormalizer
{
    public const FIELD_VALUE = 'value';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_RANK = 'rank';
    public const FIELD_METRIC = 'metric';
    public const FIELD_COMPARATOR = 'comparator';
    public const FIELD_LEVEL = 'level';
    public const FIELD_BAND = 'band';
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
            if (! is_array($band)) {
                return [];
            }

            $level = AiValueNormalizer::trimmedStringOrNull($band[self::FIELD_LEVEL] ?? null);
            if ($level === null) {
                return [];
            }

            $thresholds = self::thresholds($band[self::FIELD_THRESHOLDS] ?? null);
            if ($thresholds === null) {
                return [];
            }

            $bands[] = [
                self::FIELD_LEVEL => $level,
                self::FIELD_THRESHOLDS => $thresholds,
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
            if (! is_array($band) || ! isset($band[self::FIELD_RANK]) || ! is_int($band[self::FIELD_RANK])) {
                return [];
            }

            $bandName = AiValueNormalizer::trimmedStringOrNull($band[self::FIELD_BAND] ?? null);
            if ($bandName === null) {
                return [];
            }

            $thresholds = self::thresholds($band[self::FIELD_THRESHOLDS] ?? null);
            if ($thresholds === null) {
                return [];
            }

            $bands[] = [
                self::FIELD_BAND => $bandName,
                self::FIELD_RANK => $band[self::FIELD_RANK],
                self::FIELD_THRESHOLDS => $thresholds,
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
                self::FIELD_METRIC => AiValueNormalizer::trimmedStringOrNull($threshold[self::FIELD_METRIC]) ?? '',
                self::FIELD_COMPARATOR => AiValueNormalizer::trimmedStringOrNull($threshold[self::FIELD_COMPARATOR]) ?? '',
                self::FIELD_VALUE => AiValueNormalizer::finiteFloatOrNull($threshold[self::FIELD_VALUE]) ?? 0.0,
            ];
        }

        return $normalized;
    }

    private static function isThresholdShape(mixed $threshold): bool
    {
        return is_array($threshold)
            && isset($threshold[self::FIELD_METRIC], $threshold[self::FIELD_COMPARATOR], $threshold[self::FIELD_VALUE])
            && AiValueNormalizer::trimmedStringOrNull($threshold[self::FIELD_METRIC]) !== null
            && AiValueNormalizer::trimmedStringOrNull($threshold[self::FIELD_COMPARATOR]) !== null
            && in_array(get_debug_type($threshold[self::FIELD_VALUE]), ['int', 'float'], true)
            && is_finite(AiValueNormalizer::finiteFloatOrNull($threshold[self::FIELD_VALUE]) ?? NAN);
    }
}
