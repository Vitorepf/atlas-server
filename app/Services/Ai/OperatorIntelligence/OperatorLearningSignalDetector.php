<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningClassifySupport;
use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningDetectSupport;

class OperatorLearningSignalDetector
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    public function detect(string $input, array $context = []): ?array
    {
        $claim = OperatorLearningDetectSupport::matchingSentence($input);
        if ($claim === null) {
            return null;
        }

        $normalized = OperatorLearningDetectSupport::normalizeForMatch($claim);
        $kind = OperatorLearningDetectSupport::signalKind($normalized);
        $taxonomy = OperatorLearningDetectSupport::taxonomy($normalized, $kind);
        $confidence = OperatorLearningDetectSupport::confidence($normalized, $kind);
        $privacy = OperatorLearningClassifySupport::inferPrivacy($normalized);
        $risk = OperatorLearningClassifySupport::inferRisk($normalized, $privacy);
        $scope = OperatorLearningDetectSupport::scopeType($normalized);

        return [
            'operator_id' => (string) ($context['operator_id'] ?? config('atlas_operator_intelligence.default_operator_id', 'default')),
            'claim' => $claim,
            'raw_excerpt' => $claim,
            'taxonomy_item_id' => $taxonomy,
            'signal_kind' => $kind,
            'privacy_class' => $privacy,
            'risk_level' => $risk,
            'confidence' => $confidence,
            'scope_type' => $scope,
            'profile_key' => OperatorLearningDetectSupport::profileKey($normalized, $taxonomy, $kind),
            'effect' => OperatorLearningDetectSupport::effect($taxonomy, $kind),
            'metadata' => [
                'detector' => 'operator_learning_signal_detector.v1',
                'matched_pattern_family' => OperatorLearningDetectSupport::matchedFamily($normalized),
                'raw_text_persisted' => false,
                // L3-9 #8: this passive regex detector emits hardcoded confidence
                // (0.91/0.92) that clears the 0.85 auto-apply floor. It must NEVER carry
                // auto-apply provenance it did not earn. We STAMP a non-auto-apply
                // provenance UNCONDITIONALLY — never copy `auto_apply_provenance` from the
                // (spoofable) caller context — so a caller cannot forge `comprehension`/
                // `manual_operator` to push a passive signal past the gate. The gate stays
                // the structural authority; this closes the producer-side spoof hole.
                'auto_apply_provenance' => OperatorLearningGate::PASSIVE_DETECTOR_PROVENANCE,
            ],
        ];
    }
}
