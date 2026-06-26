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

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @return array<string, string>
     */
    public static function humanContextFromOptions(array $options, array $runtimeGapMatrix, array $runtimeReceipt, array $realProviderSmoke, array $completionAudit): array
    {
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
