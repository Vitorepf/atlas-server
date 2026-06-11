<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;

final class AtlasDevRiskNormalizer
{
    public static function riskLevelCode(string $raw, string $fallback = 'R2'): string
    {
        $candidate = strtoupper(trim($raw));

        return preg_match('/^R[0-5]$/', $candidate) === 1 ? $candidate : $fallback;
    }

    public static function riskWordForLevel(string $riskLevel): string
    {
        return match ($riskLevel) {
            'R0', 'R1' => 'low',
            'R2' => 'medium',
            'R3' => 'high',
            'R4', 'R5' => 'critical',
            default => 'medium',
        };
    }

    public static function runtimeRiskBand(?string $risk): string
    {
        return match ($risk) {
            'low', 'medium', 'high', 'critical' => $risk,
            'p0', 'danger' => 'critical',
            'p1', 'major' => 'high',
            'p2' => 'medium',
            default => 'medium',
        };
    }

    public static function planVisibleRiskBand(string $raw): string
    {
        return match (strtolower(trim($raw))) {
            'high', 'critical' => PlanVisible::RISK_BAND_HIGH,
            'medium' => PlanVisible::RISK_BAND_MEDIUM,
            'low' => PlanVisible::RISK_BAND_LOW,
            default => PlanVisible::RISK_BAND_MEDIUM,
        };
    }
}
