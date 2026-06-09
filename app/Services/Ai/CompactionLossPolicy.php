<?php

namespace App\Services\Ai;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;

final class CompactionLossPolicy
{
    private const COVERAGE_FULL = 1.0;

    private const COVERAGE_HIGH_RISK_FLOOR = 0.85;

    private const REASON_TOUCHED_CRITICAL = 'touched_critical_keep_kind';

    private const REASON_COVERAGE_BELOW_0_85 = 'coverage_below_0_85';

    private const REASON_COVERAGE_BELOW_1_0 = 'coverage_below_1_0';

    private const REASON_FORCED_DISCARDS_PRESENT = 'forced_discards_present';

    /**
     * @return array{loss_risk:string,write_allowed:bool,reasons:list<string>}
     */
    public static function classify(
        float $mustKeepCoverage,
        bool $touchedCriticalKind,
        int $forcedDiscardCount,
    ): array {
        $forcedDiscardsPresent = $forcedDiscardCount > 0;

        return [
            'loss_risk' => self::deriveLossRisk($mustKeepCoverage, $touchedCriticalKind, $forcedDiscardsPresent),
            'write_allowed' => $mustKeepCoverage >= self::COVERAGE_FULL && ! $touchedCriticalKind,
            'reasons' => self::reasons($mustKeepCoverage, $touchedCriticalKind, $forcedDiscardsPresent),
        ];
    }

    private static function deriveLossRisk(
        float $mustKeepCoverage,
        bool $touchedCriticalKind,
        bool $forcedDiscardsPresent,
    ): string {
        if ($touchedCriticalKind || $mustKeepCoverage < self::COVERAGE_HIGH_RISK_FLOOR) {
            return AtlasLongHorizonCanon::LOSS_RISK_HIGH;
        }

        if ($mustKeepCoverage < self::COVERAGE_FULL || $forcedDiscardsPresent) {
            return AtlasLongHorizonCanon::LOSS_RISK_MEDIUM;
        }

        return AtlasLongHorizonCanon::LOSS_RISK_LOW;
    }

    /**
     * @return list<string>
     */
    private static function reasons(
        float $mustKeepCoverage,
        bool $touchedCriticalKind,
        bool $forcedDiscardsPresent,
    ): array {
        $reasons = [];

        if ($touchedCriticalKind) {
            $reasons[] = self::REASON_TOUCHED_CRITICAL;
        }

        if ($mustKeepCoverage < self::COVERAGE_HIGH_RISK_FLOOR) {
            $reasons[] = self::REASON_COVERAGE_BELOW_0_85;
        }

        if ($mustKeepCoverage < self::COVERAGE_FULL) {
            $reasons[] = self::REASON_COVERAGE_BELOW_1_0;
        }

        if ($forcedDiscardsPresent) {
            $reasons[] = self::REASON_FORCED_DISCARDS_PRESENT;
        }

        return $reasons;
    }
}
