<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningCandidate;
use Illuminate\Support\Arr;

class OperatorLearningGate
{
    /**
     * Producers whose signals may reach automatic application — a STRUCTURAL gate property,
     * not a producer-local convention. 'comprehension' = the extractor stamps it AFTER its
     * evidence-quote lock + refute pass; 'manual_operator' = the operator explicitly typed
     * the claim (the human IS the verification). The PASSIVE regex chat-detector stamps
     * NEITHER, so its hardcoded-confidence signals can be captured for review but can NEVER
     * auto-apply. Any unmarked / future producer is untrusted by default (fail-closed).
     */
    public const AUTO_APPLY_PROVENANCE = 'comprehension';

    public const AUTO_APPLY_PROVENANCE_MANUAL = 'manual_operator';

    public const TRUSTED_AUTO_APPLY_PROVENANCES = [self::AUTO_APPLY_PROVENANCE, self::AUTO_APPLY_PROVENANCE_MANUAL];

    public function __construct(
        private readonly OperatorTaxonomyRegistry $taxonomy = new OperatorTaxonomyRegistry(),
    ) {}

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
        $taxonomyId = (string) ($classified['taxonomy_item_id'] ?? '');
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

        // Registry-enforced safety (producer-INDEPENDENT): a high-stakes or
        // registry-sensitive taxonomy item can NEVER auto-apply, no matter which
        // producer emitted it or how confident it claims to be. This moves the
        // protection DOWN from the extractor into the universal chokepoint.
        // FAIL-CLOSED: if the canon taxonomy can't be loaded, the high-stakes/sensitive
        // consult would silently return "benign" for every id — so route EVERYTHING to
        // review instead of trusting an empty registry.
        if (! $this->taxonomy->isAvailable()) {
            $requiresConfirmation = true;
            $reasons[] = 'taxonomy_unavailable_fail_closed';
        }
        $item = $this->taxonomy->get($taxonomyId);
        $registryHighStakes = (bool) ($item['high_stakes'] ?? false);
        $registrySensitive = (string) ($item['privacy_default'] ?? 'normal') !== 'normal';
        if ($registryHighStakes) {
            $requiresConfirmation = true;
            $reasons[] = 'registry_high_stakes_requires_review';
        }
        if ($registrySensitive) {
            $requiresConfirmation = true;
            $reasons[] = 'registry_sensitive_requires_review';
        }

        $trustedProducer = in_array($provenance, self::TRUSTED_AUTO_APPLY_PROVENANCES, true);
        if (! $trustedProducer) {
            $reasons[] = 'auto_apply_requires_trusted_provenance';
        }

        $autoEligible = ! $requiresConfirmation
            && $trustedProducer
            && $privacy === 'normal'
            && $risk === 'low'
            && ! $registryHighStakes
            && ! $registrySensitive
            && $confidence >= (float) config('atlas_operator_intelligence.min_auto_apply_confidence', 0.85);

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
                'shadow_mode' => (bool) config('atlas_operator_intelligence.shadow_mode', true),
            ],
        ];
    }
}
