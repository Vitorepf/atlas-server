<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningSignal;
use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningClassifySupport;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

class OperatorLearningClassifier
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function classify(array $input): array
    {
        $claim = $this->sanitizeClaim((string) ($input['claim'] ?? $input['normalized_claim'] ?? $input['raw_excerpt'] ?? ''));
        $taxonomy = $this->normalizeTaxonomy((string) ($input['taxonomy_item_id'] ?? ''), $claim);
        // Privacy RAISE-ONLY: the more restrictive of the caller's class and the keyword
        // scan — a model claiming 'normal' can never lower an inferred 'sensitive'.
        $privacy = $this->privacyRaiseOnly(
            $this->normalizeFromList((string) ($input['privacy_class'] ?? ''), OperatorLearningSignal::PRIVACY_CLASSES, 'normal'),
            $this->inferPrivacy($claim)
        );
        $risk = $this->normalizeFromList((string) ($input['risk_level'] ?? ''), OperatorLearningSignal::RISK_LEVELS, $this->inferRisk($claim, $privacy));
        $scopeType = $this->normalizeFromList((string) ($input['scope_type'] ?? ''), OperatorLearningSignal::SCOPE_TYPES, 'global');
        $inferenceType = in_array((string) ($input['inference_type'] ?? 'explicit'), ['explicit', 'implicit'], true)
            ? (string) ($input['inference_type'] ?? 'explicit')
            : 'explicit';
        $tier = (string) ($input['confidence_tier'] ?? '');
        // Redact the durable text at rest when not 'normal' — the DB row, not the
        // projection, is the privacy boundary (the claim flows to candidate + profile).
        $storedClaim = $this->redactIfSensitive($claim, $privacy);

        return [
            'operator_id' => $this->clean((string) ($input['operator_id'] ?? config('atlas_operator_intelligence.default_operator_id', 'default')), 'default'),
            'taxonomy_item_id' => $taxonomy,
            'signal_kind' => $this->clean((string) ($input['signal_kind'] ?? $this->inferSignalKind($claim, $taxonomy)), 'operator_preference'),
            'source_type' => $this->clean((string) ($input['source_type'] ?? 'manual'), 'manual'),
            'source_ref_type' => $this->nullableString($input['source_ref_type'] ?? null),
            'source_ref_id' => $this->nullableString($input['source_ref_id'] ?? null),
            'trace_id' => $this->nullableString($input['trace_id'] ?? null),
            'session_id' => $this->nullableString($input['session_id'] ?? null),
            'raw_excerpt_hash' => $this->rawExcerptHash($input),
            'normalized_claim' => $storedClaim,
            'evidence_refs' => $this->arrayValue($input['evidence_refs'] ?? []),
            'privacy_class' => $privacy,
            'risk_level' => $risk,
            'confidence' => $this->confidence($input['confidence'] ?? null, $risk, $privacy, $inferenceType, $tier),
            'scope_type' => $scopeType,
            'scope_id' => $this->nullableString($input['scope_id'] ?? null),
            'valid_from' => $input['valid_from'] ?? null,
            'valid_until' => $input['valid_until'] ?? null,
            'metadata' => array_merge($this->arrayValue($input['metadata'] ?? []), [
                'classifier' => 'operator_learning_classifier.v1',
                'raw_text_persisted' => false,
                'redacted_at_rest' => $storedClaim !== $claim,
                'inference_type' => $inferenceType,
            ]),
        ];
    }

    private function sanitizeClaim(string $claim): string
    {
        return OperatorLearningClassifySupport::sanitizeClaim($claim);
    }

    private function normalizeTaxonomy(string $taxonomy, string $claim): string
    {
        return OperatorLearningClassifySupport::normalizeTaxonomy($taxonomy, $claim);
    }

    private function inferSignalKind(string $claim, string $taxonomy): string
    {
        return OperatorLearningClassifySupport::inferSignalKind($claim, $taxonomy);
    }

    private function inferPrivacy(string $claim): string
    {
        return OperatorLearningClassifySupport::inferPrivacy($claim);
    }

    private function inferRisk(string $claim, string $privacy): string
    {
        return OperatorLearningClassifySupport::inferRisk($claim, $privacy);
    }

    /**
     * @param  array<int,string>  $allowed
     */
    private function normalizeFromList(string $value, array $allowed, string $default): string
    {
        return OperatorLearningClassifySupport::normalizeFromList($value, $allowed, $default);
    }

    private function confidence(mixed $value, string $risk, string $privacy, string $inferenceType = 'explicit', string $tier = ''): float
    {
        return OperatorLearningClassifySupport::confidence($value, $risk, $privacy, $inferenceType, $tier);
    }

    /** Redact the durable claim text when its privacy class is not 'normal'. */
    private function redactIfSensitive(string $claim, string $privacy): string
    {
        return OperatorLearningClassifySupport::redactIfSensitive($claim, $privacy);
    }

    /** Return the MORE restrictive of two privacy classes (escalate-only). */
    private function privacyRaiseOnly(string $a, string $b): string
    {
        return OperatorLearningClassifySupport::privacyRaiseOnly($a, $b);
    }

    private function rawExcerptHash(array $input): ?string
    {
        return OperatorLearningClassifySupport::rawExcerptHash($input);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return OperatorLearningClassifySupport::arrayValue($value);
    }

    private function nullableString(mixed $value): ?string
    {
        return OperatorLearningClassifySupport::nullableString($value);
    }

    private function clean(string $value, string $default): string
    {
        return OperatorLearningClassifySupport::clean($value, $default);
    }
}
