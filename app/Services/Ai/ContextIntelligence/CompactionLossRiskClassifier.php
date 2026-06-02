<?php

declare(strict_types=1);

namespace App\Services\Ai\ContextIntelligence;

/**
 * Pure classifier mirroring AiCompactionService::deriveLossRisk plus the
 * write gate (AiCompactionService.php:601 / :382). Given the must-keep
 * coverage, whether a forced discard touched a critical keep kind, and the
 * number of forced discards, it derives the loss risk band, whether a write
 * is allowed, and the human-readable list of conditions that fired.
 *
 * Zero constructor dependencies, no I/O — every field is computed from the
 * method inputs via the same ordered rules as the source service.
 */
final class CompactionLossRiskClassifier
{
    private const SCHEMA_VERSION = 'atlas.context_intelligence.compaction_loss_risk.v1';

    private const LOSS_RISK_HIGH = 'high';

    private const LOSS_RISK_MEDIUM = 'medium';

    private const LOSS_RISK_LOW = 'low';

    private const COVERAGE_FULL = 1.0;

    private const COVERAGE_HIGH_RISK_FLOOR = 0.85;

    private const REASON_TOUCHED_CRITICAL = 'touched_critical_keep_kind';

    private const REASON_COVERAGE_BELOW_0_85 = 'coverage_below_0_85';

    private const REASON_COVERAGE_BELOW_1_0 = 'coverage_below_1_0';

    private const REASON_FORCED_DISCARDS_PRESENT = 'forced_discards_present';

    /**
     * @return array{
     *     schema_version: string,
     *     loss_risk: string,
     *     write_allowed: bool,
     *     reasons: list<string>
     * }
     */
    public function classify(
        float $mustKeepCoverage,
        bool $touchedCriticalKind,
        int $forcedDiscardCount,
    ): array {
        $forcedDiscardsPresent = $forcedDiscardCount > 0;

        $reasons = $this->reasons(
            $mustKeepCoverage,
            $touchedCriticalKind,
            $forcedDiscardsPresent,
        );

        $lossRisk = $this->deriveLossRisk(
            $mustKeepCoverage,
            $touchedCriticalKind,
            $forcedDiscardsPresent,
        );

        $writeAllowed = $mustKeepCoverage >= self::COVERAGE_FULL && ! $touchedCriticalKind;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'loss_risk' => $lossRisk,
            'write_allowed' => $writeAllowed,
            'reasons' => $reasons,
        ];
    }

    private function deriveLossRisk(
        float $mustKeepCoverage,
        bool $touchedCriticalKind,
        bool $forcedDiscardsPresent,
    ): string {
        if ($touchedCriticalKind || $mustKeepCoverage < self::COVERAGE_HIGH_RISK_FLOOR) {
            return self::LOSS_RISK_HIGH;
        }

        if ($mustKeepCoverage < self::COVERAGE_FULL || $forcedDiscardsPresent) {
            return self::LOSS_RISK_MEDIUM;
        }

        return self::LOSS_RISK_LOW;
    }

    /**
     * @return list<string>
     */
    private function reasons(
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
