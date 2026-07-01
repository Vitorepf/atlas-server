<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Cost-aware proof gate: spends verification effort where it buys the most autonomy guarantee
 * instead of applying the same evidence bar to every compression change. Risk level, blast
 * radius, and capability criticality combine into a score that selects an evidence tier —
 * minimal, standard, or deep. unit_tests is a baseline requirement at every tier; no tier ever
 * drops below it, so a "minimal" budget still proves the change, it just doesn't demand the
 * expensive extras a low-risk, well-covered change doesn't need.
 *
 * Input contract:
 *   risk_level?:              string  ('low'|'medium'|'high', default 'low')
 *   blast_radius?:            int     (count of affected files/consumers, default 0)
 *   capability_criticality?:  string  ('low'|'medium'|'critical', default 'low')
 *
 * SCORE (0..6): risk(0/1/2) + blast_radius_band(0/1/2 for <=1/2-5/>5) + criticality(0/1/2)
 * TIER: score>=4 → deep, score>=2 → standard, else minimal.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionEvidenceBudgeter
{
    public const SCHEMA = 'atlas.external_brain.compression_evidence_budgeter.v1';

    public const TIER_MINIMAL  = 'minimal';
    public const TIER_STANDARD = 'standard';
    public const TIER_DEEP     = 'deep';

    /** @var list<string> Always required, regardless of tier — the floor no budget may drop below. */
    private const BASELINE_EVIDENCE = ['unit_tests'];

    private const STANDARD_EXTRA_EVIDENCE = ['integration_tests', 'behavior_lock_proof'];

    private const DEEP_EXTRA_EVIDENCE = ['rollback_evidence', 'manual_review_receipt'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, score:int, evidence_tier:string, required_evidence:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $riskLevel   = strtolower(trim((string) ($facts['risk_level'] ?? 'low')));
        $blastRadius = max(0, (int) ($facts['blast_radius'] ?? 0));
        $criticality = strtolower(trim((string) ($facts['capability_criticality'] ?? 'low')));

        $score = $this->riskWeight($riskLevel) + $this->blastRadiusWeight($blastRadius) + $this->criticalityWeight($criticality);

        $tier = match (true) {
            $score >= 4 => self::TIER_DEEP,
            $score >= 2 => self::TIER_STANDARD,
            default     => self::TIER_MINIMAL,
        };

        $requiredEvidence = self::BASELINE_EVIDENCE;
        if ($tier === self::TIER_STANDARD) {
            $requiredEvidence = array_merge($requiredEvidence, self::STANDARD_EXTRA_EVIDENCE);
        } elseif ($tier === self::TIER_DEEP) {
            $requiredEvidence = array_merge($requiredEvidence, self::STANDARD_EXTRA_EVIDENCE, self::DEEP_EXTRA_EVIDENCE);
        }

        return [
            'schema'             => self::SCHEMA,
            'score'              => $score,
            'evidence_tier'      => $tier,
            'required_evidence'  => $requiredEvidence,
        ];
    }

    private function riskWeight(string $riskLevel): int
    {
        return match ($riskLevel) {
            'high'   => 2,
            'medium' => 1,
            default  => 0,
        };
    }

    private function blastRadiusWeight(int $blastRadius): int
    {
        return match (true) {
            $blastRadius > 5 => 2,
            $blastRadius >= 2 => 1,
            default => 0,
        };
    }

    private function criticalityWeight(string $criticality): int
    {
        return match ($criticality) {
            'critical' => 2,
            'medium'   => 1,
            default    => 0,
        };
    }
}
