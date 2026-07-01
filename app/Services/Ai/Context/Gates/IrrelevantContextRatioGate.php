<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Gates;

final class IrrelevantContextRatioGate
{
    private const SCHEMA_VERSION = 'atlas.context.irrelevant_ratio_gate.v1';

    private const MIN_CEILING = PHP_FLOAT_EPSILON;

    /**
     * @return array{schema_version: string, irrelevant_ratio: float, max_allowed: float, status: string, reason: string}
     */
    public function evaluate(float $irrelevantRatio, float $maxAllowed = 0.05): array
    {
        $ratio = $this->clampRatio($irrelevantRatio);
        $ceiling = $this->clampCeiling($maxAllowed);

        $blocked = $ratio > $ceiling;

        // Severity band: within ceiling is clean; a breach up to 2x the ceiling is a mild
        // (warn/trim) breach; beyond that is catastrophic and the pack should be rebuilt.
        $severity = match (true) {
            ! $blocked => 'clean',
            $ratio <= $ceiling * 2 => 'warn',
            default => 'catastrophic',
        };
        $recommendedAction = match ($severity) {
            'clean' => 'use_context',
            'warn' => 'trim_context',
            default => 'rebuild_context_pack',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'irrelevant_ratio' => $ratio,
            'max_allowed' => $ceiling,
            'status' => $blocked ? 'blocked' : 'ready',
            'reason' => $blocked ? 'irrelevant_ratio_exceeds_ceiling' : 'irrelevant_ratio_within_ceiling',
            'severity' => $severity,
            'recommended_action' => $recommendedAction,
        ];
    }

    private function clampRatio(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private function clampCeiling(float $value): float
    {
        if ($value <= 0.0) {
            return self::MIN_CEILING;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
