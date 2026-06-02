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

    /**
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     provider: string,
     *     domain: string,
     *     flow_id: string,
     *     reliability_score: int,
     *     signal: string,
     *     sample_policy: string
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
        // R1: base.
        $score = self::BASE_SCORE;

        // R2: outcome status.
        $score += $outcomeStatus === 'succeeded' ? 25 : -25;

        // R3: tests passed tri-state.
        $score += $testsPassed === true ? 15 : ($testsPassed === false ? -15 : 0);

        // R4: presence of evidence references.
        $score += $evidenceRefsCount > 0 ? 10 : -10;

        // R5: clamp into [0, 100].
        $score = max(self::SCORE_FLOOR, min(self::SCORE_CEILING, $score));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS,
            'provider' => $this->labelOrUnknown($provider),
            'domain' => $this->labelOrUnknown($domain),
            'flow_id' => $this->labelOrUnknown($flowId),
            'reliability_score' => $score,
            // R6: signal banding off the clamped score.
            'signal' => $score >= self::POSITIVE_THRESHOLD
                ? 'positive'
                : ($score >= self::NEUTRAL_THRESHOLD ? 'neutral' : 'negative'),
            'sample_policy' => self::SAMPLE_POLICY,
        ];
    }

    /**
     * R7: trimmed non-empty value, otherwise the 'unknown' fallback.
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
