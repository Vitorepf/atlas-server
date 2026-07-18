<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class ReactiveSaturationSignal
{
    public const SCHEMA_VERSION = 'atlas.originator.reactive_saturation.v1';

    public const MIN_N_PER_WINDOW = 8;

    public const MIN_WINDOWS = 3;
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_REACTIVE_SATURATED = 'reactive_saturated';
    public const FIELD_BASIS = 'basis';
    public const FIELD_PICK_HINT = 'pick_hint';
    public const FIELD_QUEUE_DEPTH = 'queue_depth';
    public const FIELD_TAIL = 'tail';
    public const FIELD_SOURCE = 'source';
    public const FIELD_REPORT_ONLY = 'report_only';
    public const FIELD_DISABLES_REACTIVE_LANE = 'disables_reactive_lane';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_USES_QUEUE_EMPTY_AS_SOLE_SIGNAL = 'uses_queue_empty_as_sole_signal';
    public const FIELD_YIELD = 'yield';
    public const FIELD_FALLING_YIELD_WITH_HYSTERESIS = 'falling_yield_with_hysteresis';
    public const FIELD_INSUFFICIENT_N = 'insufficient_n';
    public const FIELD_BYTE_IDENTICAL_PICK = 'byte_identical_pick';
    public const FIELD_INSUFFICIENT_WINDOWS = 'insufficient_windows';

    /**
     * @param  list<array<string,mixed>>  $windows
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function classify(array $windows, array $context = []): array
    {
        $context = AiValueNormalizer::arrayOrEmpty($context);
        $tail = array_slice($windows, -self::MIN_WINDOWS);
        if (count($tail) < self::MIN_WINDOWS) {
            return self::result(false, self::FIELD_INSUFFICIENT_WINDOWS, self::FIELD_BYTE_IDENTICAL_PICK, $tail, $context);
        }

        foreach ($tail as $window) {
            if ((int) (AiValueNormalizer::finiteFloatOrNull($window['n'] ?? null) ?? 0) < self::MIN_N_PER_WINDOW) {
                return self::result(false, self::FIELD_INSUFFICIENT_N, self::FIELD_BYTE_IDENTICAL_PICK, $tail, $context);
            }
        }

        $yields = array_map(
            static fn (array $window): float => AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_YIELD] ?? null) ?? 0.0,
            $tail,
        );
        $falling = $yields[0] > $yields[1] && $yields[1] > $yields[2];

        return $falling
            ? self::result(true, self::FIELD_FALLING_YIELD_WITH_HYSTERESIS, 'prefer_originated', $tail, $context)
            : self::result(false, 'stable_or_recovering_yield', self::FIELD_BYTE_IDENTICAL_PICK, $tail, $context);
    }

    /**
     * @param  list<array<string,mixed>>  $tail
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private static function result(bool $saturated, string $basis, string $pickHint, array $tail, array $context): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_REACTIVE_SATURATED => $saturated,
            self::FIELD_BASIS => $basis,
            self::FIELD_PICK_HINT => $pickHint,
            self::FIELD_QUEUE_DEPTH => max(0, (int) (AiValueNormalizer::finiteFloatOrNull($context[self::FIELD_QUEUE_DEPTH] ?? null) ?? 0)),
            self::FIELD_TAIL => $tail,
            self::FIELD_SOURCE => [
                self::FIELD_REPORT_ONLY => true,
                self::FIELD_DISABLES_REACTIVE_LANE => false,
                self::FIELD_USES_QUEUE_EMPTY_AS_SOLE_SIGNAL => false,
                self::FIELD_PROVIDER_CALLS_MADE => false,
            ],
        ];
    }
}
