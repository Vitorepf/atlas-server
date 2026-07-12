<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Compounding\CausalLearningPromotion;

/**
 * Builds the governed response to a late adverse observation.
 *
 * This is an intent envelope: it does not mutate a route, claim or queue. The
 * existing promotion owner performs the actual revoke/rollback and the
 * downstream owners consume the negative memory, repair proposal and Rivals
 * evaluation request together, so a late regression cannot disappear as a
 * bare boolean or a successful historical outcome.
 */
final class AtlasExternalBrainLateRegressionResponse
{
    public const SCHEMA = 'atlas.external_brain.late_regression_response.v1';

    /** @param array<string,mixed> $observation @return array<string,mixed> */
    public function respond(CausalLearningPromotion $promotion, array $observation): array
    {
        $reason = trim((string) ($observation['reason'] ?? 'late_regression')) ?: 'late_regression';
        $observedOutcome = trim((string) ($observation['observed_outcome'] ?? 'failure')) ?: 'failure';
        $evidenceRefs = array_values(array_filter(
            array_map('strval', (array) ($observation['evidence_refs'] ?? [])),
            static fn (string $ref): bool => trim($ref) !== '',
        ));

        return [
            'schema' => self::SCHEMA,
            'status' => 'revoke_and_repair',
            'scope' => $promotion->scope,
            'route_rollback' => [
                'required' => true,
                'from_version' => $promotion->activeVersion,
                'to_version' => $promotion->rollbackVersion,
                'reason' => $reason,
            ],
            'negative_outcome_memory' => [
                'status' => 'negative',
                'scope' => $promotion->scope,
                'promotion_hash' => $promotion->candidateHash,
                'observed_outcome' => $observedOutcome,
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
            ],
            'repair_proposal' => [
                'destination' => 'task_fabric_proposal',
                'objective' => 'Repair late regression in '.$promotion->scope.' and revalidate the prior contract.',
                'finding' => 'A promoted reversible policy produced a late adverse outcome.',
                'baseline' => $promotion->previousVersion,
                'expected_structural_delta' => 'Restore the prior safe behavior and add a regression guard.',
                'red_behavior' => $observedOutcome,
                'green_acceptance' => 'Focused regression gate passes and a later observation is non-inferior.',
                'rollback' => 'Keep '.$promotion->rollbackVersion.' active if repair fails.',
                'outcome_metric' => 'Late regression rate returns to the frozen baseline.',
                'evidence_refs' => $evidenceRefs,
            ],
            'rivals_revocation_request' => [
                'required' => true,
                'action' => 'evaluate_claim_revocation',
                'scope' => $promotion->scope,
                'promotion_hash' => $promotion->candidateHash,
                'reason' => $reason,
                'claim_mutated_here' => false,
            ],
        ];
    }
}
