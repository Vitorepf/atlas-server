<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class AmbitionRungPolicy
{
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

    public const BASIS_FLAG_DISABLED = 'flag_disabled';

    public const BASIS_NOT_SATURATED = 'not_saturated';

    public const BASIS_RUNG_UP_AFTER_SATURATION = 'rung_up_after_saturation';

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
            if (($context['reactive_saturated'] ?? false) === true) {
                $current = AiValueNormalizer::trimmedStringOrNull($context['current_rung'] ?? null) ?? self::RUNG_TASK;
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
            'schema_version' => self::SCHEMA_VERSION,
            'selected_id' => AiValueNormalizer::trimmedStringOrNull($selected['id'] ?? null) ?? '',
            'selected_rung' => AiValueNormalizer::trimmedStringOrNull($selected[self::FIELD_RUNG] ?? null) ?? '',
            'basis' => $basis,
            'rung_distribution' => $distribution,
            'source' => [
                'rung_series_informational' => true,
                'rung_series_used_as_score' => false,
                'scope_has_ceiling' => false,
                'provider_calls_made' => false,
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
