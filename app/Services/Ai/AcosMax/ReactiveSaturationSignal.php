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
            return self::result(false, 'insufficient_windows', 'byte_identical_pick', $tail, $context);
        }

        foreach ($tail as $window) {
            if ((int) (AiValueNormalizer::finiteFloatOrNull($window['n'] ?? null) ?? 0) < self::MIN_N_PER_WINDOW) {
                return self::result(false, 'insufficient_n', 'byte_identical_pick', $tail, $context);
            }
        }

        $yields = array_map(
            static fn (array $window): float => AiValueNormalizer::finiteFloatOrNull($window['yield'] ?? null) ?? 0.0,
            $tail,
        );
        $falling = $yields[0] > $yields[1] && $yields[1] > $yields[2];

        return $falling
            ? self::result(true, 'falling_yield_with_hysteresis', 'prefer_originated', $tail, $context)
            : self::result(false, 'stable_or_recovering_yield', 'byte_identical_pick', $tail, $context);
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
                'disables_reactive_lane' => false,
                'uses_queue_empty_as_sole_signal' => false,
                'provider_calls_made' => false,
            ],
        ];
    }
}
