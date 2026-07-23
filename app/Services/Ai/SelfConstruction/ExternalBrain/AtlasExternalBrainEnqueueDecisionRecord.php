<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure record gate that prevents structurally valid but low-value batches from
 * being recorded as legitimate admissions by requiring value_density and
 * expected_compounding_effect evidence for enqueue decisions.
 *
 * Defer, consolidate, and reject still require decline_reason but not enqueue-only evidence.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainEnqueueDecisionRecord
{
    public const SCHEMA = 'atlas.external_brain.enqueue_decision_record.v1';

    public const DECISION_ENQUEUE = 'enqueue';
    public const DECISION_DEFER = 'defer';
    public const DECISION_CONSOLIDATE = 'consolidate';
    public const DECISION_REJECT = 'reject';

    /**
     * @param  array{
     *   decision?:string,
     *   validation_evidence?:array{
     *     value_density?:?float,
     *     expected_compounding_effect?:?string,
     *   },
     *   decline_reason?:?string,
     *   operator_visible_reason?:?string,
     * }  $input
     * @return array{
     *   schema:string,
     *   accepted:bool,
     *   record:array<string,mixed>,
     *   blockers:list<string>,
     * }
     */
    public function record(array $input): array
    {
        $decision = (string) ($input['decision'] ?? '');
        $evidence = $input['validation_evidence'] ?? [];
        $declineReason = $input['decline_reason'] ?? null;
        $operatorReason = $input['operator_visible_reason'] ?? null;

        // Non-enqueue decisions require decline_reason but not enqueue evidence
        if ($decision !== self::DECISION_ENQUEUE) {
            if ($declineReason === null || (is_string($declineReason) && trim($declineReason) === '')) {
                return $this->fail(['missing:decline_reason']);
            }

            return [
                'schema' => self::SCHEMA,
                'accepted' => true,
                'record' => [
                    'decision' => $decision,
                    'decline_reason' => $declineReason,
                    'operator_visible_reason' => $operatorReason ?? $declineReason,
                ],
                'blockers' => [],
            ];
        }

        // Enqueue: require value_density and expected_compounding_effect
        $blockers = [];

        $valueDensity = $evidence['value_density'] ?? null;
        $compoundingEffect = $evidence['expected_compounding_effect'] ?? null;

        if ($valueDensity === null || (is_float($valueDensity) && $valueDensity <= 0.0)) {
            $blockers[] = 'missing:value_density';
        }

        if ($compoundingEffect === null || (is_string($compoundingEffect) && trim($compoundingEffect) === '')) {
            $blockers[] = 'missing:expected_compounding_effect';
        }

        // why-this-task-now fields
        $alternativesConsidered = array_values(array_map('strval', (array) ($input['alternatives_considered'] ?? [])));
        $chosenLeverageReason = (string) ($input['chosen_leverage_reason'] ?? '');
        $rejectedPaddingRisk = (string) ($input['rejected_padding_risk'] ?? '');
        $expectedProofPath = (string) ($input['expected_proof_path'] ?? '');
        $duplicateCheckSummary = (string) ($input['duplicate_check_summary'] ?? '');

        // expected_proof_path is mandatory for enqueue
        if ($expectedProofPath === '') {
            $blockers[] = 'missing:expected_proof_path';
        }

        // chosen_leverage_reason required when alternatives were considered
        if ($alternativesConsidered !== [] && $chosenLeverageReason === '') {
            $blockers[] = 'missing:chosen_leverage_reason';
        }

        // invalid flag: lack of proof path or cannot explain why task outranks alternatives
        $invalid = $expectedProofPath === '' || ($alternativesConsidered !== [] && $chosenLeverageReason === '');

        if (count($blockers) > 0) {
            return $this->fail($blockers);
        }

        return [
            'schema' => self::SCHEMA,
            'accepted' => true,
            'record' => [
                'decision' => self::DECISION_ENQUEUE,
                'value_density' => $valueDensity,
                'expected_compounding_effect' => $compoundingEffect,
                'operator_visible_reason' => $operatorReason ?? 'value_density=' . $valueDensity,
                'alternatives_considered' => $alternativesConsidered,
                'chosen_leverage_reason' => $chosenLeverageReason,
                'rejected_padding_risk' => $rejectedPaddingRisk,
                'expected_proof_path' => $expectedProofPath,
                'duplicate_check_summary' => $duplicateCheckSummary,
                'invalid' => $invalid,
            ],
            'blockers' => [],
        ];
    }

    /** @param  list<string>  $blockers */
    private function fail(array $blockers): array
    {
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'accepted' => false,
            'record' => [],
            'blockers' => $blockers,
        ];
    }
}
