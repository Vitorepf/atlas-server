<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorEvidence;

/**
 * Inspects evidence fields for the Atlas Self-Construction operator evidence
 * submission readiness service.
 *
 * Extracted from AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService
 * to reduce the god-class. All methods are pure — no instance state.
 */
final class OperatorEvidenceFieldInspector
{
    /** @param array<string, mixed> $smoke */
    public static function missingProviderEvidence(array $smoke): array
    {
        if ($smoke === []) {
            return [];
        }
        $missing = [];
        foreach ([
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
            'cost_event_hash',
            'work_product_manifest_hash',
            'evidence_ledger_hash',
            'continuation_summary_hash',
            'provider_response_hash',
            'operator_approval_receipt_hash',
        ] as $field) {
            $value = trim((string) ($smoke[$field] ?? ''));
            if (self::isPlaceholderValue($value)) {
                $missing[] = 'missing_or_placeholder_'.$field;
            }
        }
        foreach ([
            'provider_call_observed',
            'token_spend_observed',
            'claim_to_completion_observed',
            'work_product_collected',
            'operator_supplied_evidence',
            'real_provider_run_observed_by_operator',
        ] as $flag) {
            if (($smoke[$flag] ?? false) !== true) {
                $missing[] = 'missing_observation_flag_'.$flag;
            }
        }

        return $missing;
    }

    public static function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || str_starts_with($normalized, '<') || str_starts_with($normalized, '__')) {
            return true;
        }

        foreach ([
            'seu_nome',
            'seu nome',
            'operador',
            'motivo real',
            'pelo menos 32 caracteres',
            'substitua',
            'placeholder',
            'todo',
            'synthetic',
            'fixture-only',
            'fixture_only',
            'test_only',
            'test-only',
            'fake',
            'simulated',
            'mock-',
            'dummy',
        ] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function isSecretLike(string $value): bool
    {
        return (bool) preg_match('/SECRET|TOKEN|API_KEY|PASSWORD/i', $value);
    }

    public static function containsPlaceholderOrSecret(mixed $value): bool
    {
        if (is_string($value)) {
            return self::isPlaceholderValue($value) || self::isSecretLike($value);
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if (self::containsPlaceholderOrSecret($v)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @return array<string, string>
     */
    public static function humanContextFromOptions(
        array $options,
        array $runtimeGapMatrix,
        array $runtimeReceipt,
        array $realProviderSmoke,
        array $completionAudit,
    ): array {
        $explicitContext = (array) ($options['human_completion_receipt_context'] ?? []);
        $humanTemplate = (array) data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template', []);

        return [
            'completion_audit_hash' => (string) ($explicitContext['completion_audit_hash'] ?? data_get($completionAudit, 'completion_audit_hash', '')),
            'release_dossier_hash' => (string) ($explicitContext['release_dossier_hash'] ?? data_get($humanTemplate, 'release_dossier_hash', '')),
            'replay_diff_hash' => (string) ($explicitContext['replay_diff_hash'] ?? data_get($humanTemplate, 'replay_diff_hash', '')),
            'runtime_gap_matrix_hash' => (string) ($explicitContext['runtime_gap_matrix_hash'] ?? data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', '')),
            'runtime_promotion_receipt_hash' => (string) ($explicitContext['runtime_promotion_receipt_hash'] ?? data_get($runtimeReceipt, 'receipt_hash', '')),
            'real_provider_smoke_hash' => (string) ($explicitContext['real_provider_smoke_hash'] ?? data_get($realProviderSmoke, 'smoke_hash', '')),
            'certification_status_batch_hash' => (string) ($explicitContext['certification_status_batch_hash'] ?? data_get($humanTemplate, 'certification_status_batch_hash', '')),
        ];
    }

    /**
     * Classify a single evidence field by path, returning a structured record with
     * classification, severity, reason, and repair action.
     *
     * @param  string  $fieldPath   dot-notation path (e.g. "provider_run_id" or "smoke.receipt_hash")
     * @param  mixed   $value       the field value
     * @param  array<string, mixed>  $context   { max_age_seconds?: int, ts?: string, required_proof_fields?: list<string> }
     * @return array{field:string, classification:string, severity:string, reason:string, repair:string}
     */
    public static function inspectEvidenceField(string $fieldPath, mixed $value, array $context = []): array
    {
        $classification = 'valid';
        $severity = 'info';
        $reason = '';
        $repair = '';

        $requiredProofFields = array_map('strval', (array) ($context['required_proof_fields'] ?? []));

        // 1. Missing / empty value.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if (in_array($fieldPath, $requiredProofFields, true)) {
                return [
                    'field' => $fieldPath,
                    'classification' => 'missing_required_proof',
                    'severity' => 'error',
                    'reason' => "Required proof field '{$fieldPath}' is empty",
                    'repair' => "Provide a real {$fieldPath} value from the provider evidence",
                ];
            }

            return [
                'field' => $fieldPath,
                'classification' => 'empty_optional',
                'severity' => 'warning',
                'reason' => "Field '{$fieldPath}' is empty",
                'repair' => 'Either provide a value or explicitly mark as optional',
            ];
        }

        $valueStr = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');

        // 2. Placeholder / self-declared.
        if (self::isPlaceholderValue($valueStr)) {
            return [
                'field' => $fieldPath,
                'classification' => 'placeholder_or_self_declared',
                'severity' => 'error',
                'reason' => "Field '{$fieldPath}' contains placeholder or self-declared value: '{$valueStr}'",
                'repair' => "Replace the placeholder '{$valueStr}' with a real value from provider evidence",
            ];
        }

        // 3. Secret-like.
        if (self::isSecretLike($valueStr)) {
            return [
                'field' => $fieldPath,
                'classification' => 'secret_like',
                'severity' => 'error',
                'reason' => "Field '{$fieldPath}' contains secret-like content",
                'repair' => "Redact or hash the secret value in '{$fieldPath}' before submission",
            ];
        }

        // 4. Stale timestamp.
        $maxAgeSeconds = (int) ($context['max_age_seconds'] ?? 0);
        if ($maxAgeSeconds > 0 && isset($context['ts'])) {
            $tsStr = (string) ($context['ts']);
            $tsInt = is_numeric($tsStr) ? (int) $tsStr : 0;
            if ($tsInt > 0 && (time() - $tsInt) > $maxAgeSeconds) {
                return [
                    'field' => $fieldPath,
                    'classification' => 'stale_timestamp',
                    'severity' => 'warning',
                    'reason' => "Field '{$fieldPath}' timestamp is older than {$maxAgeSeconds}s",
                    'repair' => 'Refresh the evidence to obtain a current timestamp',
                ];
            }
        }

        // 5. Proof-bearing field (required proof field with a value) — checked
        //    BEFORE volatile so a required proof field that is also a hash/_id
        //    suffix classifies as proof_bearing, not volatile.
        if (in_array($fieldPath, $requiredProofFields, true)) {
            return [
                'field' => $fieldPath,
                'classification' => 'proof_bearing',
                'severity' => 'info',
                'reason' => "Field '{$fieldPath}' carries a required proof value",
                'repair' => '',
            ];
        }

        // 6. Volatile field — /_hash or /_id suffix means it references mutable state.
        if (str_ends_with($fieldPath, '_hash') || str_ends_with($fieldPath, '_id')) {
            return [
                'field' => $fieldPath,
                'classification' => 'volatile',
                'severity' => 'info',
                'reason' => "Field '{$fieldPath}' is a volatile debt reference that may change",
                'repair' => "Verify that '{$fieldPath}' still matches the current evidence before submission",
            ];
        }

        return [
            'field' => $fieldPath,
            'classification' => $classification,
            'severity' => $severity,
            'reason' => $reason,
            'repair' => $repair,
        ];
    }

    /**
     * @param  array<string, mixed>  $runtimeReceipt
     */
    public static function staleContextHashes(array $runtimeReceipt, string $currentRuntimeGapMatrixHashForPromotionReceipt, string $currentRuntimePromotionBasisHash, string $currentRuntimePromotionClosureBasisHash): array
    {
        if ($runtimeReceipt === []) {
            return [];
        }
        $stale = [];
        $candidates = [
            'runtime_gap_matrix_hash' => $currentRuntimeGapMatrixHashForPromotionReceipt,
            'runtime_promotion_basis_hash' => $currentRuntimePromotionBasisHash,
            'runtime_promotion_closure_basis_hash' => $currentRuntimePromotionClosureBasisHash,
        ];
        foreach ($candidates as $field => $current) {
            if ($current === '') {
                continue;
            }
            $value = (string) ($runtimeReceipt[$field] ?? '');
            if ($value !== '' && $value !== $current) {
                $stale[] = $field;
            }
        }

        return $stale;
    }
}
