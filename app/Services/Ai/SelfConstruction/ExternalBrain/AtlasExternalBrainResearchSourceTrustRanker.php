<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic trust ranker for research source candidates.
 *
 * Ranks inputs before they become Atlas tasks: prefers primary docs,
 * measured incident reports, benchmarked patterns, and repo-local evidence.
 * Penalizes hype, stale summaries, missing source dates, and ungrounded
 * architecture claims.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainResearchSourceTrustRanker
{
    public const SCHEMA = 'atlas.external_brain.research_source_trust_ranker.v1';

    public const TIER_HIGH   = 'high';
    public const TIER_MEDIUM = 'medium';
    public const TIER_LOW    = 'low';

    public const USE_ADOPT_DIRECTLY = 'adopt_directly';
    public const USE_REVIEW_FIRST   = 'review_first';
    public const USE_REJECT         = 'reject';

    private const SCORE_PRIMARY_DOCS     = 8.0;
    private const SCORE_REPO_LOCAL       = 7.0;
    private const SCORE_MEASURED_REPORT  = 6.0;
    private const SCORE_BENCHMARKED      = 5.0;
    private const SCORE_BLOG             = 3.0;
    private const SCORE_GENERIC_SUMMARY  = 2.0;

    private const PENALTY_HYPE           = 2.0;
    private const PENALTY_STALE          = 1.5;
    private const PENALTY_UNDATED        = 1.0;
    private const PENALTY_SOURCE_MISSING = 2.5;

    /**
     * @param  array{source_type?:string, has_concrete_claim?:bool, source_date?:string, has_source_url?:bool, is_hype_heavy?:bool, grounding?:string}  $candidate
     * @return array{trust_score:float, trust_tier:string, use_decision:string, penalties:list<string>, grounding_requirements:list<string>}
     */
    public function rank(array $candidate): array
    {
        $score    = 5.0; // neutral baseline
        $penalties = [];
        $groundingReqs = [];

        $type = strtolower(trim((string) ($candidate['source_type'] ?? 'unknown')));

        // ── Base score by source type ──────────────────────────────────────
        $score = match ($type) {
            'primary_documentation'              => self::SCORE_PRIMARY_DOCS,
            'repo_local_evidence'                => self::SCORE_REPO_LOCAL,
            'measured_incident_report'           => self::SCORE_MEASURED_REPORT,
            'benchmarked_pattern'                => self::SCORE_BENCHMARKED,
            'blog'                               => self::SCORE_BLOG,
            'generic_summary', 'summary'         => self::SCORE_GENERIC_SUMMARY,
            default                              => 4.0,
        };

        // ── Concrete measured claim bonus ──────────────────────────────────
        if (($candidate['has_concrete_claim'] ?? false) === true) {
            $score += 1.0;
        }

        // ── Penalties ──────────────────────────────────────────────────────
        if (($candidate['is_hype_heavy'] ?? false) === true) {
            $score -= self::PENALTY_HYPE;
            $penalties[] = 'hype_heavy';
        }

        $dateStr = trim((string) ($candidate['source_date'] ?? ''));
        if ($dateStr === '') {
            $score -= self::PENALTY_UNDATED;
            $penalties[] = 'missing_source_date';
        }

        if (($candidate['has_source_url'] ?? true) === false) {
            $score -= self::PENALTY_SOURCE_MISSING;
            $penalties[] = 'source_url_missing';
        }

        // ── Grounding requirements ─────────────────────────────────────────
        $grounding = strtolower(trim((string) ($candidate['grounding'] ?? '')));
        if ($grounding === '' || $grounding === 'none') {
            $groundingReqs[] = 'requires_repo_local_verification';
        }
        if ($type === 'blog' || $type === 'generic_summary' || $type === 'summary') {
            $groundingReqs[] = 'requires_primary_source_citation';
        }

        $score = max(0.0, min(10.0, round($score, 2)));

        // ── Tier and use decision ──────────────────────────────────────────
        $tier = match (true) {
            $score >= 7.0 => self::TIER_HIGH,
            $score >= 4.0 => self::TIER_MEDIUM,
            default       => self::TIER_LOW,
        };

        $useDecision = match (true) {
            $score >= 7.0 && empty(array_intersect($penalties, ['hype_heavy', 'source_url_missing'])) => self::USE_ADOPT_DIRECTLY,
            $score >= 3.0 => self::USE_REVIEW_FIRST,
            default       => self::USE_REJECT,
        };

        return [
            'trust_score'           => $score,
            'trust_tier'            => $tier,
            'use_decision'          => $useDecision,
            'penalties'             => array_values(array_unique($penalties)),
            'grounding_requirements' => array_values(array_unique($groundingReqs)),
        ];
    }
}
