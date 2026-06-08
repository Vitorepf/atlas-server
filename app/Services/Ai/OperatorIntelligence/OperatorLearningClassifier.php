<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningSignal;
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
        $privacy = $this->normalizeFromList((string) ($input['privacy_class'] ?? ''), OperatorLearningSignal::PRIVACY_CLASSES, $this->inferPrivacy($claim));
        $risk = $this->normalizeFromList((string) ($input['risk_level'] ?? ''), OperatorLearningSignal::RISK_LEVELS, $this->inferRisk($claim, $privacy));
        $scopeType = $this->normalizeFromList((string) ($input['scope_type'] ?? ''), OperatorLearningSignal::SCOPE_TYPES, 'global');

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
            'normalized_claim' => $claim,
            'evidence_refs' => $this->arrayValue($input['evidence_refs'] ?? []),
            'privacy_class' => $privacy,
            'risk_level' => $risk,
            'confidence' => $this->confidence($input['confidence'] ?? null, $risk, $privacy),
            'scope_type' => $scopeType,
            'scope_id' => $this->nullableString($input['scope_id'] ?? null),
            'valid_from' => $input['valid_from'] ?? null,
            'valid_until' => $input['valid_until'] ?? null,
            'metadata' => array_merge($this->arrayValue($input['metadata'] ?? []), [
                'classifier' => 'operator_learning_classifier.v1',
                'raw_text_persisted' => false,
            ]),
        ];
    }

    private function sanitizeClaim(string $claim): string
    {
        $claim = trim(preg_replace('/\s+/', ' ', $claim) ?? '');

        return Str::limit($claim, 1000, '');
    }

    private function normalizeTaxonomy(string $taxonomy, string $claim): string
    {
        $taxonomy = strtoupper(trim($taxonomy));
        if (preg_match('/^(SYS|OP|COL)-\d{3}$/', $taxonomy) === 1) {
            return $taxonomy;
        }

        $lower = Str::lower($claim);
        if (str_contains($lower, 'autonom') || str_contains($lower, 'approval') || str_contains($lower, 'aprov')) {
            return 'COL-157';
        }
        if (str_contains($lower, 'curto') || str_contains($lower, 'longo') || str_contains($lower, 'tom') || str_contains($lower, 'resposta')) {
            return 'COL-156';
        }
        if (str_contains($lower, 'gosto') || str_contains($lower, 'prefiro') || str_contains($lower, 'nao gosto')) {
            return 'OP-124';
        }
        if (str_contains($lower, 'nunca') || str_contains($lower, 'nao mexa') || str_contains($lower, 'bloque')) {
            return 'OP-140';
        }

        return 'OP-071';
    }

    private function inferSignalKind(string $claim, string $taxonomy): string
    {
        $lower = Str::lower($claim);
        if (str_contains($lower, 'nunca') || str_contains($lower, 'nao mexa')) {
            return 'operator_boundary';
        }
        if (str_starts_with($taxonomy, 'COL-')) {
            return 'collaboration_preference';
        }

        return 'operator_preference';
    }

    private function inferPrivacy(string $claim): string
    {
        $lower = Str::lower($claim);
        foreach (['senha', 'token', 'secret', 'key', 'credential', 'credencial'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'secret';
            }
        }
        foreach (['saude', 'familia', 'relacionamento', 'dinheiro', 'documento'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'sensitive';
            }
        }

        return 'normal';
    }

    private function inferRisk(string $claim, string $privacy): string
    {
        if (in_array($privacy, ['sensitive', 'secret'], true)) {
            return 'high';
        }

        $lower = Str::lower($claim);
        foreach (['delet', 'apagar', 'overwrite', 'comprar', 'vender', 'publicar', 'enviar'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'high';
            }
        }

        return 'low';
    }

    /**
     * @param  array<int,string>  $allowed
     */
    private function normalizeFromList(string $value, array $allowed, string $default): string
    {
        $value = Str::lower(trim($value));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function confidence(mixed $value, string $risk, string $privacy): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.5;
        $confidence = max(0.0, min(1.0, $confidence));

        if ($risk !== 'low' || $privacy !== 'normal') {
            return min($confidence, 0.74);
        }

        return $confidence;
    }

    private function rawExcerptHash(array $input): ?string
    {
        $raw = $input['raw_excerpt'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return $this->nullableString($input['raw_excerpt_hash'] ?? null);
        }

        return hash('sha256', $raw);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function clean(string $value, string $default): string
    {
        $value = trim($value);

        return $value === '' ? $default : Str::limit($value, 160, '');
    }
}
