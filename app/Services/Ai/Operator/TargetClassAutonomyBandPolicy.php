<?php

declare(strict_types=1);

namespace App\Services\Ai\Operator;

final class TargetClassAutonomyBandPolicy
{
    public const SCHEMA_VERSION = 'atlas.operator.target_class_autonomy_band.v1';

    public const MIN_SAMPLE = 10;

    /**
     * @param  array<string,mixed>  $history
     * @return array<string,mixed>
     */
    public static function compile(string $targetClass, array $history): array
    {
        $n = (int) ($history['n'] ?? 0);
        if ($n < self::MIN_SAMPLE) {
            return self::result($targetClass, null, 'insufficient_n');
        }
        if ((int) ($history['reverts'] ?? 0) > 0) {
            return self::result($targetClass, 'low', 'revert_seen');
        }

        $band = 'high';
        $basis = 'clean_acceptance_history';
        if ($targetClass === 'migrations') {
            $band = 'medium';
            $basis = 'migration_safety_floor';
        }

        return self::result($targetClass, $band, $basis);
    }

    /**
     * @return array<string,mixed>
     */
    private static function result(string $targetClass, ?string $band, string $basis): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'effect' => $band === null ? null : 'autonomy_limit',
            'target_class' => $targetClass,
            'band' => $band,
            'basis' => $basis,
            'reverse_handle' => $band === null ? null : 'target_class_autonomy:'.$targetClass,
            'source' => [
                'derived_from_operator_history' => true,
                'migration_floor_wins' => true,
                'caller_declared_band_allowed' => false,
            ],
        ];
    }
}
