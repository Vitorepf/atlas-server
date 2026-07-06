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
 *   blind_copy               — is_blind_copy === true (no concrete Atlas fit reasoning given)
 *   hype_only                — is_hype_only === true (no evidence beyond enthusiasm)
 *   dependency_heavy         — is_dependency_heavy === true (drags in unverified externals)
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
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
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
    public const REJECTION_BLIND_COPY               = 'blind_copy';
    public const REJECTION_HYPE_ONLY                = 'hype_only';
    public const REJECTION_DEPENDENCY_HEAVY         = 'dependency_heavy';
    public const REJECTION_MISSING_ROLLBACK_OR_GUARDRAIL = 'missing_rollback_or_guardrail';
    public const REJECTION_OVER_ENGINEERED          = 'over_engineered';

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

            $entry = $this->evaluatePattern(
                $pattern,
                $giveBackThreshold,
                $duplicateThreshold,
                $weakAcceptanceThreshold,
                $positiveThreshold,
                $minEvidenceCount,
                $minDestinationFit,
                $maxAdaptationRisk,
            );

            $results[] = $entry['result'];
            if ($entry['accepted_transfer'] !== null) {
                $acceptedTransfers[] = $entry['accepted_transfer'];
            }
            if ($entry['rejected_transfer'] !== null) {
                $rejectedTransfers[] = $entry['rejected_transfer'];
            }
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
     * Single transfer-decision circuit: derives evidence_counts, rejection reasons,
     * transfer_decision, transfer_score, task_spec_hint, and injection_rule from ONE
     * pattern in ONE pass — so a pattern can never carry a decision, hint, and
     * injection rule that disagree with each other.
     *
     * @param  array<string,mixed>  $pattern
     * @return array{result:array<string,mixed>, accepted_transfer:?array<string,mixed>, rejected_transfer:?array<string,mixed>}
     */
    private function evaluatePattern(
        array $pattern,
        float $giveBackThreshold,
        float $duplicateThreshold,
        float $weakAcceptanceThreshold,
        float $positiveThreshold,
        int   $minEvidenceCount,
        float $minDestinationFit,
        float $maxAdaptationRisk,
    ): array {
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
        $structuralLeverage  = isset($pattern['expected_structural_leverage']) ? (float) $pattern['expected_structural_leverage'] : null;

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

        $acceptedTransfer = null;
        $rejectedTransfer = null;

        if ($rejectionReasons !== []) {
            $decision = self::DECISION_REJECTED;
            $rejectedTransfer = [
                'pattern_id'        => $patternId,
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
                $transferScore = $this->computeTransferScore($destinationFit, $adaptationRisk, $structuralLeverage, $crossOutcomes, $minEvidenceCount, $positiveThreshold);
                $acceptedTransfer = [
                    'pattern_id'              => $patternId,
                    'source_area'             => $sourceArea,
                    'destination_area'        => $destinationArea,
                    'transfer_score'          => $transferScore,
                    'required_adaptations'    => $requiredAdaptations,
                    'proof_of_source_success' => $proofOfSuccess,
                    'destination_safety_floor' => [
                        'give_back_threshold'       => $giveBackThreshold,
                        'duplicate_rate_threshold'  => $duplicateThreshold,
                        'weak_acceptance_threshold' => $weakAcceptanceThreshold,
                    ],
                    'negative_outcome_summary' => $this->negativeOutcomeSummary($crossOutcomes),
                    // AC: a simple, high-fit pattern becomes a transfer_candidate with a
                    // named implementation lane and the proof floor it must keep clearing.
                    'transfer_candidate' => true,
                    'implementation_lane' => $requiredAdaptations !== [] ? 'adapted_transfer_lane' : 'direct_transfer_lane',
                    'proof_floor' => [
                        'min_evidence_count'          => $minEvidenceCount,
                        'positive_outcomes_threshold' => $positiveThreshold,
                    ],
                ];
            } else {
                $rejectedTransfer = [
                    'pattern_id'        => $patternId,
                    'rejection_reason'  => $decision,
                ];
            }
        }

        return [
            'result' => [
                'pattern_id'          => $patternId,
                'transfer_decision'   => $decision,
                'source_task_classes' => $sourceClasses,
                'target_task_classes' => $targetClasses,
                'evidence_counts'     => $evidenceCounts,
                'injection_rule'      => $this->injectionRule($decision, $minEvidenceCount),
                'adaptation_requirements' => $requiredAdaptations,
                'first_task_spec_hint'    => $this->firstTaskSpecHint($decision, $patternId, $destinationArea, $requiredAdaptations, $rejectionReasons),
            ],
            'accepted_transfer' => $acceptedTransfer,
            'rejected_transfer' => $rejectedTransfer,
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

        // A transfer decided under the destination-fit pipeline (AC2) must also carry a concrete
        // way back — a rollback path or guardrail hint — or a bad transfer has no safety net.
        if ($destinationFit !== null) {
            $rollbackPath = array_key_exists('rollback_path', $pattern) ? trim((string) $pattern['rollback_path']) : '';
            $guardrailHints = is_array($pattern['guardrail_hints'] ?? null) ? array_filter($pattern['guardrail_hints']) : [];
            if ($rollbackPath === '' && $guardrailHints === []) {
                $reasons[] = self::REJECTION_MISSING_ROLLBACK_OR_GUARDRAIL;
            }
        }

        if ($adaptationRisk !== null && $adaptationRisk > $maxAdaptationRisk) {
            $reasons[] = self::REJECTION_HIGH_ADAPTATION_RISK;
        }

        if (array_key_exists('is_blind_copy', $pattern) && (bool) $pattern['is_blind_copy']) {
            $reasons[] = self::REJECTION_BLIND_COPY;
        }

        if (array_key_exists('is_hype_only', $pattern) && (bool) $pattern['is_hype_only']) {
            $reasons[] = self::REJECTION_HYPE_ONLY;
        }

        if (array_key_exists('is_dependency_heavy', $pattern) && (bool) $pattern['is_dependency_heavy']) {
            $reasons[] = self::REJECTION_DEPENDENCY_HEAVY;
        }

        // AC: an over-engineered pattern is downgraded regardless of external popularity —
        // is_popular_externally (or any similar hype signal) is never read as a positive
        // override here; only is_over_engineered itself decides.
        if (array_key_exists('is_over_engineered', $pattern) && (bool) $pattern['is_over_engineered']) {
            $reasons[] = self::REJECTION_OVER_ENGINEERED;
        }

        return $reasons;
    }

    /**
     * transfer_score = destination_fit * (1 - adaptation_risk), optionally averaged with
     * expected_structural_leverage when that field is explicitly provided.
     * Falls back to average positive_ratio of sufficient-evidence outcomes.
     */
    private function computeTransferScore(
        ?float $destinationFit,
        ?float $adaptationRisk,
        ?float $structuralLeverage,
        array  $outcomes,
        int    $minEvidenceCount,
        float  $positiveThreshold,
    ): float {
        if ($destinationFit !== null) {
            $fitRiskScore = $destinationFit * (1.0 - ($adaptationRisk ?? 0.0));

            if ($structuralLeverage !== null) {
                return round(($fitRiskScore + $structuralLeverage) / 2.0, 4);
            }

            return round($fitRiskScore, 4);
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

    /**
     * Aggregates the worst observed negative-outcome rates across all cross-class outcomes
     * so an accepted transfer still shows its safety picture, not just a pass/fail verdict.
     *
     * @param  list<array<string,mixed>>  $outcomes
     * @return array{max_give_back_rate:float, max_duplicate_rate:float, max_weak_acceptance_rate:float}
     */
    private function negativeOutcomeSummary(array $outcomes): array
    {
        $maxGiveBack = 0.0;
        $maxDuplicate = 0.0;
        $maxWeakAcceptance = 0.0;

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }
            $maxGiveBack = max($maxGiveBack, (float) ($outcome['give_back_rate'] ?? 0.0));
            $maxDuplicate = max($maxDuplicate, (float) ($outcome['duplicate_rate'] ?? 0.0));
            $maxWeakAcceptance = max($maxWeakAcceptance, (float) ($outcome['weak_acceptance_rate'] ?? 0.0));
        }

        return [
            'max_give_back_rate' => $maxGiveBack,
            'max_duplicate_rate' => $maxDuplicate,
            'max_weak_acceptance_rate' => $maxWeakAcceptance,
        ];
    }

    /**
     * @param  list<string>  $requiredAdaptations
     * @param  list<string>  $rejectionReasons
     */
    private function firstTaskSpecHint(
        string $decision,
        string $patternId,
        string $destinationArea,
        array  $requiredAdaptations,
        array  $rejectionReasons,
    ): string {
        return match ($decision) {
            self::DECISION_TRANSFERABLE => $requiredAdaptations !== []
                ? "implement_adaptation:{$patternId}->{$destinationArea}:{$requiredAdaptations[0]}"
                : "implement_direct_transfer:{$patternId}->{$destinationArea}",
            self::DECISION_LOCAL_ONLY => "scope_pattern_to_source_classes:{$patternId}",
            self::DECISION_NEEDS_MORE_EVIDENCE => "collect_more_evidence:{$patternId}",
            self::DECISION_RETIRE => "retire_pattern:{$patternId}",
            self::DECISION_REJECTED => 'address_rejection:'.$patternId.':'.($rejectionReasons[0] ?? 'unknown'),
            default => "review_pattern:{$patternId}",
        };
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
