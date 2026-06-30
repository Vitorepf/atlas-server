<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use Carbon\CarbonImmutable;

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

    private const STALE_DAYS_THRESHOLD = 180;

    /**
     * @param  array{source_type?:string, has_concrete_claim?:bool, source_date?:string, has_source_url?:bool, is_hype_heavy?:bool, grounding?:string, as_of?:string}  $candidate
     * @return array{trust_score:float, trust_tier:string, use_decision:string, penalties:list<string>, grounding_requirements:list<string>, rejection_reasons:list<string>}
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
        $hasConcreteClaim = ($candidate['has_concrete_claim'] ?? false) === true;
        $hasSourceUrl = ($candidate['has_source_url'] ?? true) === true;
        $isHypeHeavy = ($candidate['is_hype_heavy'] ?? false) === true;

        if ($dateStr === '') {
            $score -= self::PENALTY_UNDATED;
            $penalties[] = 'missing_source_date';
        } else {
            $sourceDate = $this->safeParseDate($dateStr);
            if ($sourceDate === null) {
                $score -= self::PENALTY_UNDATED;
                $penalties[] = 'invalid_source_date';
            } else {
                $asOfStr = trim((string) ($candidate['as_of'] ?? ''));
                if ($asOfStr === '') {
                    $asOf = CarbonImmutable::now();
                } else {
                    $asOf = $this->safeParseDate($asOfStr);
                    if ($asOf === null) {
                        $penalties[] = 'invalid_as_of';
                        $asOf = CarbonImmutable::now();
                    }
                }
                if ($sourceDate->diffInDays($asOf, false) > self::STALE_DAYS_THRESHOLD) {
                    $score -= self::PENALTY_STALE;
                    $penalties[] = 'stale_source';
                }
            }
        }

        if (! $hasSourceUrl) {
            $score -= self::PENALTY_SOURCE_MISSING;
            $penalties[] = 'source_url_missing';
        }

        // ── Grounding requirements ─────────────────────────────────────────
        $grounding = strtolower(trim((string) ($candidate['grounding'] ?? '')));
        $isReposOrPrimaryGrounded = $grounding !== '' && $grounding !== 'none';
        if (! $isReposOrPrimaryGrounded) {
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

        // High-trust admission requires: concrete claim, source URL, no hype, AND
        // repo-local or primary-source grounding — score alone is never sufficient.
        $adoptionBlockers = [];
        if (! $hasConcreteClaim) {
            $adoptionBlockers[] = 'no_concrete_claim';
        }
        if (! $hasSourceUrl) {
            $adoptionBlockers[] = 'no_source_url';
        }
        if ($isHypeHeavy) {
            $adoptionBlockers[] = 'hype_heavy';
        }
        if (! $isReposOrPrimaryGrounded) {
            $adoptionBlockers[] = 'no_repo_local_or_primary_source_grounding';
        }

        $useDecision = match (true) {
            $score >= 7.0 && $adoptionBlockers === [] => self::USE_ADOPT_DIRECTLY,
            $score >= 3.0 => self::USE_REVIEW_FIRST,
            default       => self::USE_REJECT,
        };

        $rejectionReasons = [];
        if ($useDecision === self::USE_REJECT) {
            if ($score < 3.0) {
                $rejectionReasons[] = 'trust_score_below_admission_floor';
            }
            foreach (array_unique($penalties) as $p) {
                $rejectionReasons[] = 'penalty:'.$p;
            }
        }

        return [
            'schema'                 => self::SCHEMA,
            'trust_score'            => $score,
            'trust_tier'             => $tier,
            'use_decision'           => $useDecision,
            'penalties'              => array_values(array_unique($penalties)),
            'grounding_requirements' => array_values(array_unique($groundingReqs)),
            'rejection_reasons'      => array_values(array_unique($rejectionReasons)),
            'adoption_blockers'      => array_values(array_unique($adoptionBlockers)),
        ];
    }

    private function safeParseDate(string $value): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Carbon::parse() can silently accept garbage and return "now" or an absurd year —
        // reject obviously implausible years rather than trust a malformed string.
        if ($parsed->year < 1990 || $parsed->year > 2200) {
            return null;
        }

        return $parsed;
    }
}
