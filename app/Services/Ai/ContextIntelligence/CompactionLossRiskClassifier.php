<?php

declare(strict_types=1);

namespace App\Services\Ai\ContextIntelligence;

use App\Services\Ai\CompactionLossPolicy;

/**
 * Pure schema wrapper around the shared compaction loss policy. Given the
 * must-keep coverage, whether a forced discard touched a critical keep kind,
 * and the number of forced discards, it emits the ACIE contract for the risk
 * band, write gate, and the human-readable list of conditions that fired.
 *
 * Zero constructor dependencies, no I/O — every field is computed from the
 * method inputs via the shared compaction loss policy.
 */
final class CompactionLossRiskClassifier
{
    private const SCHEMA_VERSION = 'atlas.context_intelligence.compaction_loss_risk.v1';

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
        $policy = CompactionLossPolicy::classify(
            $mustKeepCoverage,
            $touchedCriticalKind,
            $forcedDiscardCount,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            ...$policy,
        ];
    }
}
