<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor\Judgment;

/**
 * Pure scorer mirroring AtlasAemorJudgmentService::providerSkillReliability.
 *
 * Single-episode reliability signal for a provider skill observation. This is an
 * observed per-episode signal, never a global ranking: callers must treat the
 * result as one data point, not a leaderboard verdict.
 */
final class AemorProviderSkillReliabilityScorer
{
    private const SCHEMA_VERSION = 'atlas.aemor.provider_skill_reliability.v1';

    private const STATUS = 'observed';

    private const SAMPLE_POLICY = 'single_episode_signal_not_global_ranking';

    private const BASE_SCORE = 50;

    private const SCORE_FLOOR = 0;

    private const SCORE_CEILING = 100;

    private const POSITIVE_THRESHOLD = 75;

    private const NEUTRAL_THRESHOLD = 45;

    private const UNKNOWN = 'unknown';

    private const EVIDENCE_FLOOR = 1;

    private const UNKNOWN_LABEL_PENALTY = 5;

    /**
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     provider: string,
     *     domain: string,
     *     flow_id: string,
     *     reliability_score: int,
     *     signal: string,
     *     sample_policy: string,
     *     evidence_floor_met: bool,
     *     label_completeness: float,
     *     reliability_blockers: list<string>
     * }
     */
    public function score(
        string $outcomeStatus,
        ?bool $testsPassed,
        int $evidenceRefsCount,
        ?string $provider,
        ?string $domain,
        ?string $flowId
    ): array {
        $providerLabel = $this->labelOrUnknown($provider);
        $domainLabel   = $this->labelOrUnknown($domain);
        $flowLabel     = $this->labelOrUnknown($flowId);

        // R1: base.
        $score = self::BASE_SCORE;

        // R2: outcome status.
        $score += $outcomeStatus === 'succeeded' ? 25 : -25;

        // R3: tests passed tri-state.
        $score += $testsPassed === true ? 15 : ($testsPassed === false ? -15 : 0);

        // R4: presence of evidence references.
        $score += $evidenceRefsCount > 0 ? 10 : -10;

        // R5: unknown-label penalties — missing context degrades reliability.
        $unknownCount = ($providerLabel === self::UNKNOWN ? 1 : 0)
            + ($domainLabel   === self::UNKNOWN ? 1 : 0)
            + ($flowLabel     === self::UNKNOWN ? 1 : 0);
        $score -= $unknownCount * self::UNKNOWN_LABEL_PENALTY;

        // R6: clamp into [0, 100].
        $score = max(self::SCORE_FLOOR, min(self::SCORE_CEILING, $score));

        // R7: evidence floor — one green status without any evidence refs cannot inflate reliability.
        $evidenceFloorMet = $evidenceRefsCount >= self::EVIDENCE_FLOOR;
        $reliabilityBlockers = $evidenceFloorMet ? [] : ['evidence_floor_not_met'];

        // R8: signal banding, with blocker cap: blockers prevent a positive signal.
        $rawSignal = $score >= self::POSITIVE_THRESHOLD
            ? 'positive'
            : ($score >= self::NEUTRAL_THRESHOLD ? 'neutral' : 'negative');
        $signal = ($reliabilityBlockers !== [] && $rawSignal === 'positive') ? 'neutral' : $rawSignal;

        return [
            'schema_version'      => self::SCHEMA_VERSION,
            'status'              => self::STATUS,
            'provider'            => $providerLabel,
            'domain'              => $domainLabel,
            'flow_id'             => $flowLabel,
            'reliability_score'   => $score,
            'signal'              => $signal,
            'sample_policy'       => self::SAMPLE_POLICY,
            'evidence_floor_met'  => $evidenceFloorMet,
            'label_completeness'  => (float) round((3 - $unknownCount) / 3, 2),
            'reliability_blockers' => $reliabilityBlockers,
        ];
    }

    /**
     * R9: trimmed non-empty value, otherwise the 'unknown' fallback.
     */
    private function labelOrUnknown(?string $value): string
    {
        if ($value === null) {
            return self::UNKNOWN;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? self::UNKNOWN : $trimmed;
    }
}
