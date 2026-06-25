<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Compounding;

/**
 * Pure tracker. Computes cycle-over-cycle FACTS over a chronologically-ordered list of cycle outcome
 * facts (the per-cycle aggregate produced from AtlasSelfConstructionCompoundingOutcomeProjection):
 *
 *   throughput_delta            : passed_count[i] - passed_count[i-1]
 *   blocker_decay               : blockers_count[i-1] - blockers_count[i]   (positive = decay = good)
 *   rework_decay                : rework_count[i-1] - rework_count[i]
 *   evidence_completeness_delta : completeness[i] - completeness[i-1]       (0.0–1.0 fractions)
 *
 * Emits a `trend_label` per dimension (improving / flat / regressing) — FACTS only; no single vanity score.
 */
final class AtlasSelfConstructionCompoundingVelocityTracker
{
    public const SCHEMA = 'atlas.self_construction.compounding_velocity.v1';

    public const TREND_IMPROVING = 'improving';

    public const TREND_FLAT = 'flat';

    public const TREND_REGRESSING = 'regressing';

    /**
     * @param  list<array<string,mixed>>  $cycleFacts  list of per-cycle aggregates in chronological order
     * @return array<string,mixed>
     */
    public function track(array $cycleFacts): array
    {
        $rows = [];
        $previous = null;
        foreach (array_values($cycleFacts) as $idx => $current) {
            if (! is_array($current)) {
                continue;
            }
            $cycleId = (string) ($current['cycle_id'] ?? ('cycle-'.$idx));
            $passed = (int) ($current['passed_count'] ?? 0);
            $blockers = (int) ($current['blockers_count'] ?? 0);
            $rework = (int) ($current['rework_count'] ?? 0);
            $completeness = (float) ($current['evidence_completeness'] ?? 0.0);

            if ($previous === null) {
                $rows[] = [
                    'cycle_id' => $cycleId,
                    'throughput_delta' => 0,
                    'throughput_trend' => self::TREND_FLAT,
                    'blocker_decay' => 0,
                    'blocker_trend' => self::TREND_FLAT,
                    'rework_decay' => 0,
                    'rework_trend' => self::TREND_FLAT,
                    'evidence_completeness_delta' => 0.0,
                    'evidence_trend' => self::TREND_FLAT,
                ];
            } else {
                $tDelta = $passed - (int) $previous['passed_count'];
                $bDecay = (int) $previous['blockers_count'] - $blockers;
                $rDecay = (int) $previous['rework_count'] - $rework;
                $eDelta = $completeness - (float) $previous['evidence_completeness'];

                $rows[] = [
                    'cycle_id' => $cycleId,
                    'throughput_delta' => $tDelta,
                    'throughput_trend' => $this->trendInt($tDelta),
                    'blocker_decay' => $bDecay,
                    'blocker_trend' => $this->trendInt($bDecay),
                    'rework_decay' => $rDecay,
                    'rework_trend' => $this->trendInt($rDecay),
                    'evidence_completeness_delta' => $eDelta,
                    'evidence_trend' => $this->trendFloat($eDelta),
                ];
            }
            $previous = ['passed_count' => $passed, 'blockers_count' => $blockers, 'rework_count' => $rework, 'evidence_completeness' => $completeness];
        }

        return [
            'schema_version' => self::SCHEMA,
            'rows' => $rows,
        ];
    }

    private function trendInt(int $delta): string
    {
        return $delta > 0 ? self::TREND_IMPROVING : ($delta < 0 ? self::TREND_REGRESSING : self::TREND_FLAT);
    }

    private function trendFloat(float $delta): string
    {
        if ($delta > 1e-9) {
            return self::TREND_IMPROVING;
        }
        if ($delta < -1e-9) {
            return self::TREND_REGRESSING;
        }

        return self::TREND_FLAT;
    }
}
