<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class ReactiveSaturationSignal
{
    public const SCHEMA_VERSION = 'atlas.originator.reactive_saturation.v1';

    public const MIN_N_PER_WINDOW = 8;

    public const MIN_WINDOWS = 3;

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
            if ((int) ($window['n'] ?? 0) < self::MIN_N_PER_WINDOW) {
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
            'schema_version' => self::SCHEMA_VERSION,
            'reactive_saturated' => $saturated,
            'basis' => $basis,
            'pick_hint' => $pickHint,
            'queue_depth' => max(0, (int) ($context['queue_depth'] ?? 0)),
            'tail' => $tail,
            'source' => [
                'report_only' => true,
                'disables_reactive_lane' => false,
                'uses_queue_empty_as_sole_signal' => false,
                'provider_calls_made' => false,
            ],
        ];
    }
}
