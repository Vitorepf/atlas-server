<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class AmbitionRungPolicy
{
    public const SCHEMA_VERSION = 'atlas.originator.ambition_rung_policy.v1';

    /** @var list<string> */
    public const RUNGS = ['task', 'slice', 'obra', 'salto'];

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function select(array $candidates, array $context): array
    {
        $distribution = self::distribution($candidates);
        $selected = $candidates[0] ?? [];
        $basis = 'flag_disabled';

        if (($context['enabled'] ?? false) === true) {
            $basis = 'not_saturated';
            if (($context['reactive_saturated'] ?? false) === true) {
                $current = AiValueNormalizer::trimmedStringOrNull($context['current_rung'] ?? null) ?? 'task';
                $target = self::nextRung($current);
                $currentBest = self::bestLeverage($candidates);
                foreach ($candidates as $candidate) {
                    $rung = AiValueNormalizer::trimmedStringOrNull($candidate['rung'] ?? null) ?? '';
                    if ($rung === $target && (AiValueNormalizer::finiteFloatOrNull($candidate['leverage'] ?? null) ?? 0.0) >= $currentBest) {
                        $selected = $candidate;
                        $basis = 'rung_up_after_saturation';
                        break;
                    }
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'selected_id' => AiValueNormalizer::trimmedStringOrNull($selected['id'] ?? null) ?? '',
            'selected_rung' => AiValueNormalizer::trimmedStringOrNull($selected['rung'] ?? null) ?? '',
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
            return 'slice';
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
            $best = max($best, AiValueNormalizer::finiteFloatOrNull($candidate['leverage'] ?? null) ?? 0.0);
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
            $rung = AiValueNormalizer::trimmedStringOrNull($candidate['rung'] ?? null);
            if ($rung !== null) {
                $counts[$rung] = ($counts[$rung] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}
