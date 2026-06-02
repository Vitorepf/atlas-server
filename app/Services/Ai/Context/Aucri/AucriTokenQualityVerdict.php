<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Aucri;

final class AucriTokenQualityVerdict
{
    private const SCHEMA_VERSION = 'atlas.aucri.token_quality_verdict.v1';

    private const EPSILON = 1e-9;

    private const REASON_NO_REDUCTION = 'no_token_reduction';
    private const REASON_MUST_KEEP_BELOW = 'must_keep_below_1_0';
    private const REASON_QUALITY_REGRESSED = 'quality_regressed';
    private const REASON_EVIDENCE_REGRESSED = 'evidence_coverage_regressed';
    private const REASON_SAFE_REDUCTION = 'safe_token_reduction';

    private const VERDICT_PROMOTE = 'promote';
    private const VERDICT_REVERT = 'revert';
    private const VERDICT_NO_CHANGE = 'no_change';

    /**
     * Apply the AUCRI signature law: promote an optimization only when the
     * input token footprint falls AND no quality, must-keep or evidence gate
     * regresses. Every field is computed from the arguments; this verdict
     * performs no I/O, no clock reads and no randomness.
     *
     * Rule order (first matching verdict wins):
     *   (1) after >= before          -> no_change  ['no_token_reduction']
     *   (2) mustKeepCoverage < 1.0    -> revert     'must_keep_below_1_0' (hard veto)
     *   (3) qualityAfter regressed    -> revert     'quality_regressed'
     *   (4) evidence coverage dropped -> revert     'evidence_coverage_regressed'
     *   (5) ratio = round(max(0, before-after)/max(1, before), 3)
     *   (6) no veto + ratio > 0 + no regression -> promote 'safe_token_reduction'
     *   (7) any veto forces revert; vetoes (2)-(4) accumulate, must_keep first.
     *
     * A sub-threshold drop (ratio rounds to 0.0) is no_change, not promote,
     * because rule (6) requires ratio > 0; promote always implies a real cut.
     *
     * @return array{schema_version: string, verdict: string, token_reduction_ratio: float, reasons: list<string>}
     */
    public function decide(
        int $inputTokensBefore,
        int $inputTokensAfter,
        float $qualityBefore,
        float $qualityAfter,
        float $mustKeepCoverage,
        float $evidenceCoverageBefore,
        float $evidenceCoverageAfter,
    ): array {
        // Rule (5): the reduction ratio is always computed. The numerator is
        // floored at zero so the field honours its documented 0..1 bound when
        // there is no reduction (a larger "after" never yields a negative ratio),
        // and the quotient is capped at 1.0 so a malformed negative "after"/"before"
        // (saved gap larger than the floored denominator) cannot push the field
        // past its documented 0..1 upper bound either.
        $tokensSaved = max(0, $inputTokensBefore - $inputTokensAfter);
        $ratio = round(min(1.0, $tokensSaved / max(1, $inputTokensBefore)), 3);

        // Rule (1): no token reduction -> no_change wins before any veto check.
        if ($inputTokensAfter >= $inputTokensBefore) {
            return $this->verdict(self::VERDICT_NO_CHANGE, $ratio, [self::REASON_NO_REDUCTION]);
        }

        // Tokens fell: rules (2)-(4) are still evaluated and accumulate so a
        // false saving is rejected. must_keep is listed first by construction.
        $reasons = [];

        if ($mustKeepCoverage < 1.0) {
            $reasons[] = self::REASON_MUST_KEEP_BELOW;
        }

        if ($qualityAfter < $qualityBefore - self::EPSILON) {
            $reasons[] = self::REASON_QUALITY_REGRESSED;
        }

        if ($evidenceCoverageAfter < $evidenceCoverageBefore - self::EPSILON) {
            $reasons[] = self::REASON_EVIDENCE_REGRESSED;
        }

        // Rule (7): any accumulated veto forces revert regardless of the ratio
        // (a hard veto still rejects a sub-threshold drop, so it precedes rule 6).
        if ($reasons !== []) {
            return $this->verdict(self::VERDICT_REVERT, $ratio, $reasons);
        }

        // Rule (6) requires ratio > 0: a drop too small to survive 3-decimal
        // rounding is no_change, not a contradictory promote at zero ratio.
        if ($ratio <= 0.0) {
            return $this->verdict(self::VERDICT_NO_CHANGE, $ratio, [self::REASON_NO_REDUCTION]);
        }

        // Rule (6): tokens fell, ratio > 0, no veto and no regression -> promote.
        return $this->verdict(self::VERDICT_PROMOTE, $ratio, [self::REASON_SAFE_REDUCTION]);
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema_version: string, verdict: string, token_reduction_ratio: float, reasons: list<string>}
     */
    private function verdict(string $verdict, float $ratio, array $reasons): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'token_reduction_ratio' => $ratio,
            'reasons' => $reasons,
        ];
    }
}
