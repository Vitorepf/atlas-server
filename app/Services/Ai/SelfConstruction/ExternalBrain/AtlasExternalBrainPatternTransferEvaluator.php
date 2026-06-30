<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Evaluates whether decision patterns distilled from frontier or successful scaffolded runs
 * are safe and useful to transfer across task classes before injection into smaller-model runbooks.
 *
 * DECISION PRIORITY (first match wins, per-pattern):
 *   retire               — any target-class outcome exceeds a harm threshold
 *                          (give_back_rate > give_back_threshold
 *                           OR duplicate_rate > duplicate_rate_threshold
 *                           OR weak_acceptance_rate > weak_acceptance_threshold)
 *   needs_more_evidence  — max evidence_count across all outcomes < min_evidence_count
 *   transferable         — all target-class positive_ratios >= positive_outcomes_threshold
 *   local_only           — fallback (works in source class but not across all targets)
 *
 * DEFAULT THRESHOLDS:
 *   give_back_threshold          = 0.30
 *   duplicate_rate_threshold     = 0.20
 *   weak_acceptance_threshold    = 0.40
 *   positive_outcomes_threshold  = 0.70
 *   min_evidence_count           = 3
 *   min_destination_fit          = 0.60   (AC2)
 *   max_adaptation_risk          = 0.50   (AC2)
 *
 * PRE-TRANSFER REJECTION CHECKS (AC2, applied when field is explicitly provided):
 *   no_source_evidence      — source_evidence_count === 0
 *   missing_behaviour_contract — has_behaviour_contract === false
 *   low_destination_fit     — destination_fit_score < min_destination_fit
 *   high_adaptation_risk    — adaptation_risk > max_adaptation_risk
 *   Any reason fires → decision = 'rejected' (trumps retire/needs_more_evidence/…)
 *
 * INJECTION RULES (per decision):
 *   transferable         → inject into all target_task_classes runbooks
 *   local_only           → inject only into source_task_classes runbooks
 *   needs_more_evidence  → withhold until evidence_count >= min_evidence_count
 *   retire               → do not inject; remove from pattern registry
 *   rejected             → do not inject; rejected during pre-check
 *
 * OUTPUT:
 *   { schema, results, accepted_transfers (sorted by transfer_score desc), rejected_transfers }
 *
 *   accepted transfer fields: pattern_id, source_area, destination_area, transfer_score,
 *     required_adaptations, proof_of_source_success
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainPatternTransferEvaluator
{
    public const SCHEMA = 'atlas.external_brain.pattern_transfer_evaluator.v1';

    public const DECISION_TRANSFERABLE        = 'transferable';
    public const DECISION_LOCAL_ONLY          = 'local_only';
    public const DECISION_NEEDS_MORE_EVIDENCE = 'needs_more_evidence';
    public const DECISION_RETIRE              = 'retire';
    public const DECISION_REJECTED            = 'rejected';

    public const REJECTION_NO_SOURCE_EVIDENCE       = 'no_source_evidence';
    public const REJECTION_LOW_DESTINATION_FIT      = 'low_destination_fit';
    public const REJECTION_HIGH_ADAPTATION_RISK     = 'high_adaptation_risk';
    public const REJECTION_MISSING_BEHAVIOUR_CONTRACT = 'missing_behaviour_contract';

    private const DEFAULT_GIVE_BACK_THRESHOLD         = 0.30;
    private const DEFAULT_DUPLICATE_RATE_THRESHOLD    = 0.20;
    private const DEFAULT_WEAK_ACCEPTANCE_THRESHOLD   = 0.40;
    private const DEFAULT_POSITIVE_OUTCOMES_THRESHOLD = 0.70;
    private const DEFAULT_MIN_EVIDENCE_COUNT          = 3;
    private const DEFAULT_MIN_DESTINATION_FIT         = 0.60;
    private const DEFAULT_MAX_ADAPTATION_RISK         = 0.50;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $patterns    = is_array($input['patterns'] ?? null) ? $input['patterns'] : [];
        $thresholds  = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];

        $giveBackThreshold        = (float) ($thresholds['give_back_threshold'] ?? self::DEFAULT_GIVE_BACK_THRESHOLD);
        $duplicateThreshold       = (float) ($thresholds['duplicate_rate_threshold'] ?? self::DEFAULT_DUPLICATE_RATE_THRESHOLD);
        $weakAcceptanceThreshold  = (float) ($thresholds['weak_acceptance_threshold'] ?? self::DEFAULT_WEAK_ACCEPTANCE_THRESHOLD);
        $positiveThreshold        = (float) ($thresholds['positive_outcomes_threshold'] ?? self::DEFAULT_POSITIVE_OUTCOMES_THRESHOLD);
        $minEvidenceCount         = (int) ($thresholds['min_evidence_count'] ?? self::DEFAULT_MIN_EVIDENCE_COUNT);
        $minDestinationFit        = (float) ($thresholds['min_destination_fit'] ?? self::DEFAULT_MIN_DESTINATION_FIT);
        $maxAdaptationRisk        = (float) ($thresholds['max_adaptation_risk'] ?? self::DEFAULT_MAX_ADAPTATION_RISK);

        $results           = [];
        $acceptedTransfers = [];
        $rejectedTransfers = [];

        foreach ($patterns as $pattern) {
            if (! is_array($pattern) || ! isset($pattern['pattern_id'])) {
                continue;
            }

            $patternId           = (string) $pattern['pattern_id'];
            $sourceClasses       = is_array($pattern['source_task_classes'] ?? null) ? $pattern['source_task_classes'] : [];
            $targetClasses       = is_array($pattern['target_task_classes'] ?? null) ? $pattern['target_task_classes'] : [];
            $crossOutcomes       = is_array($pattern['cross_class_outcomes'] ?? null) ? $pattern['cross_class_outcomes'] : [];
            $sourceArea          = (string) ($pattern['source_area'] ?? '');
            $destinationArea     = (string) ($pattern['destination_area'] ?? '');
            $requiredAdaptations = is_array($pattern['required_adaptations'] ?? null) ? $pattern['required_adaptations'] : [];
            $proofOfSuccess      = isset($pattern['proof_of_source_success']) ? (string) $pattern['proof_of_source_success'] : null;
            $destinationFit      = isset($pattern['destination_fit_score']) ? (float) $pattern['destination_fit_score'] : null;
            $adaptationRisk      = isset($pattern['adaptation_risk']) ? (float) $pattern['adaptation_risk'] : null;

            // Collect evidence counts per task class.
            $evidenceCounts = [];
            foreach ($crossOutcomes as $outcome) {
                if (is_array($outcome) && isset($outcome['task_class'])) {
                    $evidenceCounts[(string) $outcome['task_class']] = (int) ($outcome['evidence_count'] ?? 0);
                }
            }

            // AC2: pre-transfer rejection checks (only when field is explicitly present).
            $rejectionReasons = $this->preTransferRejections(
                $pattern,
                $destinationFit,
                $adaptationRisk,
                $minDestinationFit,
                $maxAdaptationRisk,
            );

            if ($rejectionReasons !== []) {
                $decision = self::DECISION_REJECTED;
                $rejectedTransfers[] = [
                    'pattern_id'       => $patternId,
                    'rejection_reasons' => $rejectionReasons,
                ];
            } else {
                $decision = $this->decide(
                    $crossOutcomes,
                    $giveBackThreshold,
                    $duplicateThreshold,
                    $weakAcceptanceThreshold,
                    $positiveThreshold,
                    $minEvidenceCount,
                );

                if ($decision === self::DECISION_TRANSFERABLE) {
                    $transferScore     = $this->computeTransferScore($destinationFit, $adaptationRisk, $crossOutcomes, $minEvidenceCount, $positiveThreshold);
                    $acceptedTransfers[] = [
                        'pattern_id'           => $patternId,
                        'source_area'          => $sourceArea,
                        'destination_area'     => $destinationArea,
                        'transfer_score'       => $transferScore,
                        'required_adaptations' => $requiredAdaptations,
                        'proof_of_source_success' => $proofOfSuccess,
                    ];
                } else {
                    $rejectedTransfers[] = [
                        'pattern_id'      => $patternId,
                        'rejection_reason' => $decision,
                    ];
                }
            }

            $results[] = [
                'pattern_id'          => $patternId,
                'transfer_decision'   => $decision,
                'source_task_classes' => $sourceClasses,
                'target_task_classes' => $targetClasses,
                'evidence_counts'     => $evidenceCounts,
                'injection_rule'      => $this->injectionRule($decision, $minEvidenceCount),
            ];
        }

        // Rank accepted transfers by transfer_score descending, then pattern_id for determinism.
        usort($acceptedTransfers, static fn (array $a, array $b): int =>
            abs($b['transfer_score'] - $a['transfer_score']) < 0.0001
                ? strcmp($a['pattern_id'], $b['pattern_id'])
                : ($b['transfer_score'] <=> $a['transfer_score'])
        );

        return [
            'schema'             => self::SCHEMA,
            'results'            => $results,
            'accepted_transfers' => $acceptedTransfers,
            'rejected_transfers' => $rejectedTransfers,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     */
    private function decide(
        array $outcomes,
        float $giveBackThreshold,
        float $duplicateThreshold,
        float $weakAcceptanceThreshold,
        float $positiveThreshold,
        int   $minEvidenceCount,
    ): string {
        // 1. Retire: any outcome breaches a harm threshold.
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }
            $giveBackRate       = (float) ($outcome['give_back_rate'] ?? 0.0);
            $duplicateRate      = (float) ($outcome['duplicate_rate'] ?? 0.0);
            $weakAcceptanceRate = (float) ($outcome['weak_acceptance_rate'] ?? 0.0);

            if ($giveBackRate > $giveBackThreshold
                || $duplicateRate > $duplicateThreshold
                || $weakAcceptanceRate > $weakAcceptanceThreshold
            ) {
                return self::DECISION_RETIRE;
            }
        }

        // 2. Needs more evidence: no outcome has enough evidence.
        $maxEvidence = 0;
        foreach ($outcomes as $outcome) {
            if (is_array($outcome)) {
                $maxEvidence = max($maxEvidence, (int) ($outcome['evidence_count'] ?? 0));
            }
        }
        if ($maxEvidence < $minEvidenceCount) {
            return self::DECISION_NEEDS_MORE_EVIDENCE;
        }

        // 3. Transferable: all outcomes with sufficient evidence meet the positive threshold.
        if ($outcomes !== []) {
            $allPositive = true;
            foreach ($outcomes as $outcome) {
                if (! is_array($outcome)) {
                    continue;
                }
                $evidenceCount  = (int) ($outcome['evidence_count'] ?? 0);
                $positiveRatio  = (float) ($outcome['positive_ratio'] ?? 0.0);

                if ($evidenceCount >= $minEvidenceCount && $positiveRatio < $positiveThreshold) {
                    $allPositive = false;
                    break;
                }
            }

            if ($allPositive) {
                return self::DECISION_TRANSFERABLE;
            }
        }

        // 4. Local only: fallback.
        return self::DECISION_LOCAL_ONLY;
    }

    /**
     * AC2: pre-transfer rejection checks (field must be explicitly present to trigger).
     *
     * @return list<string>
     */
    private function preTransferRejections(
        array  $pattern,
        ?float $destinationFit,
        ?float $adaptationRisk,
        float  $minDestinationFit,
        float  $maxAdaptationRisk,
    ): array {
        $reasons = [];

        if (array_key_exists('source_evidence_count', $pattern) && (int) $pattern['source_evidence_count'] === 0) {
            $reasons[] = self::REJECTION_NO_SOURCE_EVIDENCE;
        }

        if (array_key_exists('has_behaviour_contract', $pattern) && ! $pattern['has_behaviour_contract']) {
            $reasons[] = self::REJECTION_MISSING_BEHAVIOUR_CONTRACT;
        }

        if ($destinationFit !== null && $destinationFit < $minDestinationFit) {
            $reasons[] = self::REJECTION_LOW_DESTINATION_FIT;
        }

        if ($adaptationRisk !== null && $adaptationRisk > $maxAdaptationRisk) {
            $reasons[] = self::REJECTION_HIGH_ADAPTATION_RISK;
        }

        return $reasons;
    }

    /**
     * transfer_score = destination_fit * (1 - adaptation_risk).
     * Falls back to average positive_ratio of sufficient-evidence outcomes.
     */
    private function computeTransferScore(
        ?float $destinationFit,
        ?float $adaptationRisk,
        array  $outcomes,
        int    $minEvidenceCount,
        float  $positiveThreshold,
    ): float {
        if ($destinationFit !== null) {
            return round($destinationFit * (1.0 - ($adaptationRisk ?? 0.0)), 4);
        }

        // Fallback: average positive_ratio of sufficient-evidence outcomes.
        $ratios = [];
        foreach ($outcomes as $outcome) {
            if (is_array($outcome) && (int) ($outcome['evidence_count'] ?? 0) >= $minEvidenceCount) {
                $ratios[] = (float) ($outcome['positive_ratio'] ?? 0.0);
            }
        }

        return $ratios !== [] ? round(array_sum($ratios) / count($ratios), 4) : 0.0;
    }

    private function injectionRule(string $decision, int $minEvidenceCount): string
    {
        return match ($decision) {
            self::DECISION_TRANSFERABLE        => 'inject into all target_task_classes runbooks',
            self::DECISION_LOCAL_ONLY          => 'inject only into source_task_classes runbooks',
            self::DECISION_NEEDS_MORE_EVIDENCE => "withhold until evidence_count >= {$minEvidenceCount}",
            self::DECISION_RETIRE              => 'do not inject; remove from pattern registry',
            self::DECISION_REJECTED            => 'do not inject; rejected during pre-check',
            default                            => 'unknown',
        };
    }
}
