<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class AmbitionRungPolicy
{
    public const FIELD_ID = 'id';
    public const FIELD_RUNG_DISTRIBUTION = 'rung_distribution';
    public const SCHEMA_VERSION = 'atlas.originator.ambition_rung_policy.v1';

    /** @var list<string> */
    public const RUNG_TASK = 'task';

    public const RUNG_SLICE = 'slice';

    public const RUNG_OBRA = 'obra';

    public const RUNG_SALTO = 'salto';

    public const RUNGS = [self::RUNG_TASK, self::RUNG_SLICE, self::RUNG_OBRA, self::RUNG_SALTO];

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_RUNG = 'rung';
    public const FIELD_LEVERAGE = 'leverage';
    public const FIELD_BASIS = 'basis';
    public const FIELD_CURRENT_RUNG = 'current_rung';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_REACTIVE_SATURATED = 'reactive_saturated';

    public const BASIS_FLAG_DISABLED = 'flag_disabled';

    public const BASIS_NOT_SATURATED = 'not_saturated';

    public const BASIS_RUNG_UP_AFTER_SATURATION = 'rung_up_after_saturation';
    public const FIELD_SELECTED_RUNG = 'selected_rung';
    public const FIELD_SCOPE_HAS_CEILING = 'scope_has_ceiling';
    public const FIELD_RUNG_SERIES_INFORMATIONAL = 'rung_series_informational';
    public const FIELD_RUNG_SERIES_USED_AS_SCORE = 'rung_series_used_as_score';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SELECTED_ID = 'selected_id';
    public const FIELD_SOURCE = 'source';

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function select(array $candidates, array $context): array
    {
        $distribution = self::distribution($candidates);
        $selected = $candidates[0] ?? [];
        $basis = self::BASIS_FLAG_DISABLED;

        if (($context[self::FIELD_ENABLED] ?? false) === true) {
            $basis = self::BASIS_NOT_SATURATED;
            if (($context[self::FIELD_REACTIVE_SATURATED] ?? false) === true) {
                $current = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_CURRENT_RUNG] ?? null) ?? self::RUNG_TASK;
                $target = self::nextRung($current);
                $currentBest = self::bestLeverage($candidates);
                foreach ($candidates as $candidate) {
                    $rung = AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_RUNG] ?? null) ?? '';
                    if ($rung === $target && (AiValueNormalizer::finiteFloatOrNull($candidate[self::FIELD_LEVERAGE] ?? null) ?? 0.0) >= $currentBest) {
                        $selected = $candidate;
                        $basis = self::BASIS_RUNG_UP_AFTER_SATURATION;
                        break;
                    }
                }
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_SELECTED_ID => AiValueNormalizer::trimmedStringOrNull($selected[self::FIELD_ID] ?? null) ?? '',
            self::FIELD_SELECTED_RUNG => AiValueNormalizer::trimmedStringOrNull($selected[self::FIELD_RUNG] ?? null) ?? '',
            self::FIELD_BASIS => $basis,
            self::FIELD_RUNG_DISTRIBUTION => $distribution,
            self::FIELD_SOURCE => [
                self::FIELD_RUNG_SERIES_INFORMATIONAL => true,
                self::FIELD_RUNG_SERIES_USED_AS_SCORE => false,
                self::FIELD_SCOPE_HAS_CEILING => false,
                self::FIELD_PROVIDER_CALLS_MADE => false,
            ],
        ];
    }

    private static function nextRung(string $rung): string
    {
        $index = array_search($rung, self::RUNGS, true);
        if ($index === false) {
            return self::RUNG_SLICE;
        }

        return self::RUNGS[min(count(self::RUNGS) - 1, $index + 1)];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     */
    private static function bestLeverage(array $candidates): float
    {
        $best = 0.0;
        foreach ($candidates as $candidate) {
            $best = max($best, AiValueNormalizer::finiteFloatOrNull($candidate[self::FIELD_LEVERAGE] ?? null) ?? 0.0);
        }

        return $best;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,int>
     */
    private static function distribution(array $candidates): array
    {
        $counts = [];
        foreach ($candidates as $candidate) {
            $rung = AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_RUNG] ?? null);
            if ($rung !== null) {
                $counts[$rung] = ($counts[$rung] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}
