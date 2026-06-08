<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningCandidate;

class OperatorLearningGate
{
    /**
     * @param  array<string,mixed>  $classified
     * @return array<string,mixed>
     */
    public function evaluate(array $classified): array
    {
        $privacy = (string) ($classified['privacy_class'] ?? 'normal');
        $risk = (string) ($classified['risk_level'] ?? 'low');
        $confidence = (float) ($classified['confidence'] ?? 0.5);
        $scope = (string) ($classified['scope_type'] ?? 'global');

        $reasons = [];
        $requiresConfirmation = false;

        if ($privacy !== 'normal') {
            $requiresConfirmation = true;
            $reasons[] = 'privacy_requires_review:'.$privacy;
        }
        if (in_array($risk, ['medium', 'high', 'critical'], true)) {
            $requiresConfirmation = true;
            $reasons[] = 'risk_requires_review:'.$risk;
        }
        if ($confidence < 0.75) {
            $requiresConfirmation = true;
            $reasons[] = 'low_confidence_requires_review';
        }
        if ($scope === 'global' && $confidence < 0.9) {
            $requiresConfirmation = true;
            $reasons[] = 'global_scope_needs_high_confidence';
        }

        $autoEligible = ! $requiresConfirmation
            && $privacy === 'normal'
            && $risk === 'low'
            && $confidence >= (float) config('atlas_operator_intelligence.min_auto_apply_confidence', 0.85);

        return [
            'status' => $requiresConfirmation ? OperatorLearningCandidate::STATUS_NEEDS_REVIEW : OperatorLearningCandidate::STATUS_CANDIDATE,
            'requires_confirmation' => $requiresConfirmation,
            'auto_apply_eligible' => $autoEligible,
            'gate_receipt' => [
                'schema_version' => 'atlas.operator_learning_gate.v1',
                'requires_confirmation' => $requiresConfirmation,
                'auto_apply_eligible' => $autoEligible,
                'reasons' => $reasons,
                'shadow_mode' => (bool) config('atlas_operator_intelligence.shadow_mode', true),
            ],
        ];
    }
}
