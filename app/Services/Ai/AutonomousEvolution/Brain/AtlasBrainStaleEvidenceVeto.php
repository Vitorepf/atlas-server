<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * STALE EVIDENCE VETO — adversarial-critique organ. Given a finding's newest supporting evidence
 * age in seconds and a freshness threshold, vetoes the finding when its evidence is older than the
 * threshold. Adversarial because it kills "supported by old data" claims that quietly slip through
 * when the world has moved on.
 *
 * Pure decision over already-computed age. No IO. Pétreo: réu would widen the threshold so its
 * own stale findings stayed alive.
 */
final class AtlasBrainStaleEvidenceVeto
{
    public const SCHEMA = 'atlas.brain.stale_evidence_veto.v1';

    public const DEFAULT_FRESHNESS_SECONDS = 3600;

    /**
     * @return array{schema:string, verdict:string, age_seconds:int, threshold_seconds:int, reason:string}
     */
    public function review(int $evidenceAgeSeconds, int $thresholdSeconds = self::DEFAULT_FRESHNESS_SECONDS): array
    {
        if ($evidenceAgeSeconds < 0) {
            return $this->reply('accept', $evidenceAgeSeconds, $thresholdSeconds, 'future_dated_skipped');
        }
        if ($evidenceAgeSeconds <= $thresholdSeconds) {
            return $this->reply('accept', $evidenceAgeSeconds, $thresholdSeconds, 'within_freshness_window');
        }

        return $this->reply('veto', $evidenceAgeSeconds, $thresholdSeconds, sprintf('evidence is %ds old, threshold %ds', $evidenceAgeSeconds, $thresholdSeconds));
    }

    /**
     * Review a finding backed by multiple evidence pieces. The NEWEST (minimum age) governs:
     * if even the freshest evidence is stale, the finding is vetoed.
     *
     * @param  list<int>  $evidenceAgesSeconds
     * @return array{schema:string, verdict:string, age_seconds:int, threshold_seconds:int, reason:string}
     */
    public function reviewNewest(array $evidenceAgesSeconds, int $thresholdSeconds = self::DEFAULT_FRESHNESS_SECONDS): array
    {
        if ($evidenceAgesSeconds === []) {
            return $this->reply('veto', 0, $thresholdSeconds, 'no_evidence_supplied');
        }

        return $this->review(min($evidenceAgesSeconds), $thresholdSeconds);
    }

    private function reply(string $verdict, int $age, int $threshold, string $reason): array
    {
        return ['schema' => self::SCHEMA, 'verdict' => $verdict, 'age_seconds' => $age, 'threshold_seconds' => $threshold, 'reason' => $reason];
    }
}
