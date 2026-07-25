<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;

/**
 * Pure grade/trust/next-action policy for Self-Improvement result ledger (full-pass peel).
 */
final class ResultLedgerGradeSupport
{
    /**
     * @param  array<string,mixed>  $delta
     * @param  array<string,mixed>  $invariant
     * @param  array<string,mixed>  $regression
     * @param  array<string,mixed>  $context
     */
    public static function deriveGrade(array $delta, array $invariant, array $regression, array $context): string
    {
        $evidenceStrength = self::evidenceStrength($context);
        if ($evidenceStrength === 'invalid') {
            return AtlasSelfImprovementResultLedgerService::GRADE_INVALID;
        }

        $invariantPassed = ($invariant['status'] ?? 'unknown') === 'passed';
        $regressionStatus = (string) ($regression['status'] ?? 'unknown');
        if (! $invariantPassed) {
            return AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED;
        }
        if ($regressionStatus === 'blocked') {
            return AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED;
        }

        $hardRegression = (bool) ($delta['hard_regression_detected'] ?? false);
        if ($hardRegression) {
            return AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED;
        }

        $recommendation = (string) ($delta['recommendation'] ?? 'hold');
        if ($recommendation === AtlasSelfImprovementDeltaScorecardService::RECOMMEND_ROLLBACK) {
            return AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED;
        }

        $normalized = (float) ($delta['normalized_score'] ?? 0.0);
        if ($normalized >= 25.0 && $recommendation === AtlasSelfImprovementDeltaScorecardService::RECOMMEND_PROMOTE) {
            return AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT;
        }
        if ($normalized > 0.0) {
            return AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED;
        }

        return AtlasSelfImprovementResultLedgerService::GRADE_NEUTRAL;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public static function evidenceStrength(array $context): string
    {
        $refs = (array) ($context['evidence_refs'] ?? []);
        $explicit = is_string($context['evidence_strength'] ?? null) ? trim((string) $context['evidence_strength']) : '';
        if ($explicit !== '' && in_array($explicit, ['strong', 'moderate', 'weak', 'invalid'], true)) {
            return $explicit;
        }
        $count = count(array_filter($refs, static fn (mixed $r): bool => is_string($r) && $r !== ''));

        return match (true) {
            $count === 0 => 'invalid',
            $count === 1 => 'weak',
            $count <= 3 => 'moderate',
            default => 'strong',
        };
    }

    public static function trustDeltaFor(string $grade): float
    {
        return match ($grade) {
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT => 1.0,
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED => 0.4,
            AtlasSelfImprovementResultLedgerService::GRADE_NEUTRAL => 0.0,
            AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED => -0.5,
            AtlasSelfImprovementResultLedgerService::GRADE_INVALID => -0.2,
            default => 0.0,
        };
    }

    public static function trustOutcomeFor(string $grade): string
    {
        return match ($grade) {
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT,
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_IMPROVED,
            AtlasSelfImprovementResultLedgerService::GRADE_NEUTRAL => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_NEUTRAL,
            AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_REGRESSED,
            AtlasSelfImprovementResultLedgerService::GRADE_INVALID => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_INVALID_EVIDENCE,
            default => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_NEUTRAL,
        };
    }

    public static function nextActionFor(string $grade): string
    {
        return match ($grade) {
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT => 'consider_promoting_rule_candidate_with_human_review',
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED => 'broaden_scope_or_continue_capability_in_next_cycle',
            AtlasSelfImprovementResultLedgerService::GRADE_NEUTRAL => 'gather_more_evidence_or_re-evaluate_proposal_value',
            AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED => 'rollback_or_replan_proposal_repair_regression',
            AtlasSelfImprovementResultLedgerService::GRADE_INVALID => 'gather_evidence_then_re-measure_result',
            default => 'inspect_result_entry_and_decide_manually',
        };
    }

    public static function learningConfidenceFor(string $grade): float
    {
        return match ($grade) {
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT => 0.9,
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED => 0.7,
            AtlasSelfImprovementResultLedgerService::GRADE_NEUTRAL => 0.4,
            AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED => 0.2,
            AtlasSelfImprovementResultLedgerService::GRADE_INVALID => 0.0,
            default => 0.3,
        };
    }
}
