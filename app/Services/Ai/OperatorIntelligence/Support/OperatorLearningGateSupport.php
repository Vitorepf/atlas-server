<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use App\Models\OperatorLearningCandidate;
use Illuminate\Support\Arr;

/**
 * Pure operator learning gate decision (full-pass peel).
 */
final class OperatorLearningGateSupport
{
    /**
     * @param  array<string, mixed>  $classified
     * @param  array<string, mixed>|null  $taxonomyItem
     * @param  list<string>  $trustedProvenances
     * @return array<string, mixed>
     */
    public static function evaluate(
        array $classified,
        bool $taxonomyAvailable,
        ?array $taxonomyItem,
        array $trustedProvenances,
        float $minAutoApplyConfidence,
        bool $shadowMode,
    ): array {
        $privacy = (string) ($classified['privacy_class'] ?? 'normal');
        $risk = (string) ($classified['risk_level'] ?? 'low');
        $confidence = (float) ($classified['confidence'] ?? 0.5);
        $scope = (string) ($classified['scope_type'] ?? 'global');
        $provenance = (string) Arr::get($classified, 'metadata.auto_apply_provenance', '');

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

        if (! $taxonomyAvailable) {
            $requiresConfirmation = true;
            $reasons[] = 'taxonomy_unavailable_fail_closed';
        }
        $registryHighStakes = (bool) ($taxonomyItem['high_stakes'] ?? false);
        $registrySensitive = (string) ($taxonomyItem['privacy_default'] ?? 'normal') !== 'normal';
        if ($registryHighStakes) {
            $requiresConfirmation = true;
            $reasons[] = 'registry_high_stakes_requires_review';
        }
        if ($registrySensitive) {
            $requiresConfirmation = true;
            $reasons[] = 'registry_sensitive_requires_review';
        }

        $trustedProducer = in_array($provenance, $trustedProvenances, true);
        if (! $trustedProducer) {
            $reasons[] = 'auto_apply_requires_trusted_provenance';
        }

        $autoEligible = ! $requiresConfirmation
            && $trustedProducer
            && $privacy === 'normal'
            && $risk === 'low'
            && ! $registryHighStakes
            && ! $registrySensitive
            && $confidence >= $minAutoApplyConfidence;

        return [
            'status' => $requiresConfirmation ? OperatorLearningCandidate::STATUS_NEEDS_REVIEW : OperatorLearningCandidate::STATUS_CANDIDATE,
            'requires_confirmation' => $requiresConfirmation,
            'auto_apply_eligible' => $autoEligible,
            'gate_receipt' => [
                'schema_version' => 'atlas.operator_learning_gate.v2',
                'requires_confirmation' => $requiresConfirmation,
                'auto_apply_eligible' => $autoEligible,
                'trusted_producer' => $trustedProducer,
                'registry_high_stakes' => $registryHighStakes,
                'registry_sensitive' => $registrySensitive,
                'reasons' => $reasons,
                'shadow_mode' => $shadowMode,
            ],
        ];
    }
}
