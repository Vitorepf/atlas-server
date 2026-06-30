<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Pure auditor. Examines maturity claims against evidence and produces per-dimension
 * verdicts plus an adjusted overall maturity score.
 *
 * Verdict tiers (first match wins per dimension):
 *   proven       — at least one runtime:/live_proof:/live_run: evidence ref present.
 *   weak         — integration/e2e evidence only, or test/unit/phpunit only.
 *   intent_only  — all refs are intent:/doc:/plan:/future:/proposed: (no runtime, no test).
 *   missing      — zero evidence refs.
 *   contradicted — evidence_refs contains a 'contradicts:' or 'refutes:' entry.
 *
 * Audited score multipliers:
 *   proven        → 1.00 × claimed_score
 *   weak (integ.) → 0.70 × claimed_score
 *   weak (test)   → 0.50 × claimed_score
 *   intent_only   → 0.15 × claimed_score
 *   missing       → 0.00
 *   contradicted  → 0.00
 *
 * AC2 — high-score refusal:
 *   When overall_claimed_maturity > HIGH_SCORE_THRESHOLD (0.70), the auditor refuses
 *   the claim if ANY critical dimension is intent_only, missing, or contradicted.
 *   high_score_refused is set to true and refusal_reasons lists each violating dimension.
 *   overall_audited_maturity uses the average of dimension audited scores regardless.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasSelfConstructionMaturityEvidenceAuditor
{
    public const SCHEMA = 'atlas.self_construction.completion.maturity_evidence_auditor.v1';

    private const HIGH_SCORE_THRESHOLD = 0.70;

    private const STRONG_PREFIXES    = ['runtime:', 'live_proof:', 'live_run:'];
    private const MODERATE_PREFIXES  = ['integration:', 'e2e:'];
    private const WEAK_PREFIXES      = ['test:', 'phpunit:', 'unit:'];
    private const INTENT_PREFIXES    = ['intent:', 'doc:', 'plan:', 'future:', 'proposed:'];
    private const REFUTE_PREFIXES    = ['contradicts:', 'refutes:', 'disproves:'];

    private const MULTIPLIERS = [
        'proven'       => 1.00,
        'weak_integ'   => 0.70,
        'weak_test'    => 0.50,
        'intent_only'  => 0.15,
        'missing'      => 0.00,
        'contradicted' => 0.00,
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function audit(array $facts): array
    {
        $claimedOverall = max(0.0, min(1.0, (float) ($facts['overall_claimed_maturity'] ?? 0.0)));
        $claims         = is_array($facts['maturity_claims'] ?? null) ? $facts['maturity_claims'] : [];

        $verdicts        = [];
        $refusalReasons  = [];
        $scoreSum        = 0.0;
        $dimensionCount  = 0;

        foreach ($claims as $claim) {
            $dimension    = (string)  ($claim['dimension']     ?? '');
            $claimedScore = max(0.0, min(1.0, (float) ($claim['claimed_score'] ?? 0.0)));
            $evidenceRefs = array_values(array_map('strval', (array) ($claim['evidence_refs'] ?? [])));
            $critical     = (bool) ($claim['critical'] ?? false);

            [$tier, $reason] = $this->classify($evidenceRefs);
            $multiplier      = self::MULTIPLIERS[$tier] ?? 0.0;
            $auditedScore    = round($claimedScore * $multiplier, 4);

            // Public verdict names collapse the weak sub-tiers.
            $verdict = match ($tier) {
                'weak_integ', 'weak_test' => 'weak',
                default                   => $tier,
            };

            $verdicts[] = [
                'dimension'     => $dimension,
                'verdict'       => $verdict,
                'audited_score' => $auditedScore,
                'reason'        => $reason,
            ];

            $scoreSum       += $auditedScore;
            $dimensionCount += 1;

            // AC2: flag critical dimensions that disqualify a high score.
            if ($critical && in_array($tier, ['intent_only', 'missing', 'contradicted'], true)) {
                $refusalReasons[] = "critical dimension '$dimension' has $verdict evidence: $reason";
            }
        }

        $overallAudited   = $dimensionCount > 0 ? round($scoreSum / $dimensionCount, 4) : 0.0;
        $highScoreRefused = $claimedOverall > self::HIGH_SCORE_THRESHOLD && count($refusalReasons) > 0;

        return [
            'schema_version'           => self::SCHEMA,
            'overall_claimed_maturity' => $claimedOverall,
            'overall_audited_maturity' => $overallAudited,
            'dimension_verdicts'       => $verdicts,
            'high_score_refused'       => $highScoreRefused,
            'refusal_reasons'          => $refusalReasons,
        ];
    }

    /**
     * @param  list<string>  $refs
     * @return array{string, string}  [tier, reason]
     */
    private function classify(array $refs): array
    {
        if (empty($refs)) {
            return ['missing', 'no_evidence_refs'];
        }

        // Contradicted.
        foreach ($refs as $r) {
            foreach (self::REFUTE_PREFIXES as $p) {
                if (str_starts_with($r, $p)) {
                    return ['contradicted', 'evidence_directly_refutes_claim'];
                }
            }
        }

        // Strong.
        foreach ($refs as $r) {
            foreach (self::STRONG_PREFIXES as $p) {
                if (str_starts_with($r, $p)) {
                    return ['proven', 'strong_runtime_evidence'];
                }
            }
        }

        // Moderate.
        foreach ($refs as $r) {
            foreach (self::MODERATE_PREFIXES as $p) {
                if (str_starts_with($r, $p)) {
                    return ['weak_integ', 'integration_evidence_only'];
                }
            }
        }

        // Weak test.
        foreach ($refs as $r) {
            foreach (self::WEAK_PREFIXES as $p) {
                if (str_starts_with($r, $p)) {
                    return ['weak_test', 'test_evidence_only_no_runtime_proof'];
                }
            }
        }

        // Intent / doc only (AC2 trigger).
        return ['intent_only', 'intent_or_doc_only_no_runtime_proof'];
    }
}
