<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Operator Evidence Submission Readiness v1.
 *
 * Takes optional operator-supplied payloads (runtime promotion receipt, real
 * provider smoke, human completion receipt) and reports a strictly read-only
 * diagnostic of what is missing before the operator can rerun the completion
 * audit. It uses the canonical verifiers as a library; it never persists,
 * never calls providers, never spends tokens.
 */
final class AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_submission_readiness.v1';

    public const MODE = 'read_only_operator_evidence_submission_readiness';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $runtimeReceipt = (array) ($options['runtime_promotion_receipt'] ?? []);
        $realProviderSmoke = (array) ($options['real_provider_smoke'] ?? []);
        $completionReceipt = (array) ($options['completion_receipt'] ?? []);

        $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix();
        $rows = (array) data_get($runtimeGapMatrix, 'rows', []);
        $expectedRuntimeGapMatrixHashForPromotionReceipt = (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', '');
        $runtimePromotionBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', '');
        $runtimePromotionClosureBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', '');

        $runtimeVerification = $runtimeReceipt === []
            ? $this->emptyVerification('runtime_promotion_receipt_not_supplied')
            : (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
                receipt: $runtimeReceipt,
                rows: $rows,
                expectedRuntimePromotionBasisHash: $runtimePromotionBasisHash,
                expectedRuntimeGapMatrixHash: $expectedRuntimeGapMatrixHashForPromotionReceipt,
                expectedRuntimePromotionClosureBasisHash: $runtimePromotionClosureBasisHash,
                loadLatestWhenEmpty: false,
            );

        $smokeVerification = $realProviderSmoke === []
            ? $this->emptyVerification('real_provider_smoke_not_supplied')
            : (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($realProviderSmoke);

        $humanContext = $this->humanContextFromOptions($options, $runtimeGapMatrix, $runtimeReceipt, $realProviderSmoke);
        $humanVerification = $completionReceipt === []
            ? $this->emptyVerification('human_completion_receipt_not_supplied')
            : (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($completionReceipt, $humanContext);

        $hashComposition = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'runtime_promotion_receipt' => $runtimeReceipt,
            'real_provider_smoke' => $realProviderSmoke,
            'completion_receipt' => $completionReceipt,
        ]);

        $runtimePassed = (string) ($runtimeVerification['status'] ?? '') === 'passed';
        $smokePassed = (string) ($smokeVerification['status'] ?? '') === 'passed';
        $humanPassed = (string) ($humanVerification['status'] ?? '') === 'passed';

        $humanReceiptOutOfOrder = $completionReceipt !== [] && (! $runtimePassed || ! $smokePassed);

        $diagnostics = [
            'runtime_promotion_receipt' => $this->diagnosticRow(
                supplied: $runtimeReceipt !== [],
                verification: $runtimeVerification,
                composedHash: (string) data_get($hashComposition, 'runtime_promotion_receipt.computed_hash', ''),
                placeholders: (array) data_get($hashComposition, 'runtime_promotion_receipt.placeholder_fields', []),
                forbiddenFlagsTrue: (array) data_get($hashComposition, 'runtime_promotion_receipt.runtime_enabling_flags_true', []),
                forbiddenFlagList: ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed'],
            ),
            'real_provider_smoke' => $this->diagnosticRow(
                supplied: $realProviderSmoke !== [],
                verification: $smokeVerification,
                composedHash: (string) data_get($hashComposition, 'real_provider_smoke.computed_hash', ''),
                placeholders: (array) data_get($hashComposition, 'real_provider_smoke.placeholder_fields', []),
                forbiddenFlagsTrue: (array) data_get($hashComposition, 'real_provider_smoke.runtime_enabling_flags_true', []),
                forbiddenFlagList: ['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_claim_promoted_without_receipt'],
            ),
            'human_completion_receipt' => $this->diagnosticRow(
                supplied: $completionReceipt !== [],
                verification: $humanVerification,
                composedHash: (string) data_get($hashComposition, 'human_completion_receipt.computed_hash', ''),
                placeholders: (array) data_get($hashComposition, 'human_completion_receipt.placeholder_fields', []),
                forbiddenFlagsTrue: (array) data_get($hashComposition, 'human_completion_receipt.runtime_enabling_flags_true', []),
                forbiddenFlagList: ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_autopromoted'],
            ),
        ];

        if ($humanReceiptOutOfOrder) {
            $diagnostics['human_completion_receipt']['errors'][] = 'human_completion_receipt_supplied_before_runtime_and_smoke_green';
            $diagnostics['human_completion_receipt']['ready'] = false;
        }

        $missingProviderEvidence = $this->missingProviderEvidence($realProviderSmoke);
        if ($missingProviderEvidence !== []) {
            $diagnostics['real_provider_smoke']['errors'] = array_values(array_unique(array_merge(
                (array) $diagnostics['real_provider_smoke']['errors'],
                $missingProviderEvidence,
            )));
        }

        $staleContextHashes = $this->staleContextHashes(
            runtimeReceipt: $runtimeReceipt,
            currentRuntimeGapMatrixHashForPromotionReceipt: $expectedRuntimeGapMatrixHashForPromotionReceipt,
            currentRuntimePromotionBasisHash: $runtimePromotionBasisHash,
            currentRuntimePromotionClosureBasisHash: $runtimePromotionClosureBasisHash,
        );

        $nextRequired = match (true) {
            $runtimeReceipt === [] => 'runtime_promotion_receipt',
            ! $runtimePassed => 'runtime_promotion_receipt',
            $realProviderSmoke === [] => 'real_provider_smoke',
            ! $smokePassed => 'real_provider_smoke',
            $completionReceipt === [] => 'human_completion_receipt',
            ! $humanPassed => 'human_completion_receipt',
            default => 'rerun_completion_audit',
        };

        $status = match ($nextRequired) {
            'rerun_completion_audit' => 'ready_for_completion_audit_rerun',
            default => $runtimeReceipt === [] && $realProviderSmoke === [] && $completionReceipt === []
                ? 'no_input'
                : 'incomplete',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'next_required' => $nextRequired,
            'next_required_command' => $this->nextRequiredCommand($nextRequired),
            'diagnostics' => $diagnostics,
            'runtime_promotion_receipt_passed' => $runtimePassed,
            'real_provider_smoke_passed' => $smokePassed,
            'human_completion_receipt_passed' => $humanPassed,
            'human_receipt_out_of_order' => $humanReceiptOutOfOrder,
            'stale_context_hashes' => $staleContextHashes,
            'hash_composition' => [
                'composer_hash' => (string) data_get($hashComposition, 'composer_hash', ''),
                'status' => (string) data_get($hashComposition, 'status', ''),
                'runtime_promotion_receipt_hash' => (string) data_get($hashComposition, 'runtime_promotion_receipt.computed_hash', ''),
                'real_provider_smoke_hash' => (string) data_get($hashComposition, 'real_provider_smoke.computed_hash', ''),
                'human_completion_receipt_hash' => (string) data_get($hashComposition, 'human_completion_receipt.computed_hash', ''),
            ],
            'current_runtime_context' => [
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
                'runtime_gap_count' => (int) data_get($runtimeGapMatrix, 'runtime_gap_count', 0),
            ],
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'operator_evidence_submission_readiness_does_not_persist_receipts',
                'operator_evidence_submission_readiness_does_not_call_provider',
                'operator_evidence_submission_readiness_does_not_spend_tokens',
                'operator_evidence_submission_readiness_does_not_dispatch_work',
                'operator_evidence_submission_readiness_does_not_enable_runtime',
                'operator_evidence_submission_readiness_does_not_sign_for_operator',
                'operator_evidence_submission_readiness_does_not_promote_completion',
            ],
        ];
        $payload['submission_readiness_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $verification
     * @param  list<string>  $placeholders
     * @param  list<string>  $forbiddenFlagsTrue
     * @param  list<string>  $forbiddenFlagList
     * @return array<string, mixed>
     */
    private function diagnosticRow(
        bool $supplied,
        array $verification,
        string $composedHash,
        array $placeholders,
        array $forbiddenFlagsTrue,
        array $forbiddenFlagList,
    ): array {
        $status = (string) ($verification['status'] ?? 'not_supplied');
        $ready = $supplied && $status === 'passed' && $placeholders === [] && $forbiddenFlagsTrue === [];
        $errors = [];
        if (! $supplied) {
            $errors[] = 'not_supplied';
        } else {
            if ($status !== 'passed') {
                $errors[] = 'verifier_status_'.$status;
            }
            foreach ($placeholders as $field) {
                $errors[] = 'placeholder_field_'.$field;
            }
            foreach ($forbiddenFlagsTrue as $flag) {
                $errors[] = 'forbidden_flag_true_'.$flag;
            }
        }

        return [
            'supplied' => $supplied,
            'status' => $status,
            'ready' => $ready,
            'composed_hash' => $composedHash,
            'placeholders' => $placeholders,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'forbidden_flag_list' => $forbiddenFlagList,
            'violations' => (array) data_get($verification, 'violations', []),
            'violation_count' => (int) data_get($verification, 'violation_count', 0),
            'errors' => $errors,
        ];
    }

    /** @return list<string> */
    private function missingProviderEvidence(array $smoke): array
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
            if ($value === '' || str_starts_with($value, '<')) {
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

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @return array<string, string>
     */
    private function humanContextFromOptions(array $options, array $runtimeGapMatrix, array $runtimeReceipt, array $realProviderSmoke): array
    {
        $explicitContext = (array) ($options['human_completion_receipt_context'] ?? []);

        return [
            'completion_audit_hash' => (string) ($explicitContext['completion_audit_hash'] ?? ''),
            'release_dossier_hash' => (string) ($explicitContext['release_dossier_hash'] ?? ''),
            'replay_diff_hash' => (string) ($explicitContext['replay_diff_hash'] ?? ''),
            'runtime_gap_matrix_hash' => (string) ($explicitContext['runtime_gap_matrix_hash'] ?? data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', '')),
            'runtime_promotion_receipt_hash' => (string) ($explicitContext['runtime_promotion_receipt_hash'] ?? data_get($runtimeReceipt, 'receipt_hash', '')),
            'real_provider_smoke_hash' => (string) ($explicitContext['real_provider_smoke_hash'] ?? data_get($realProviderSmoke, 'smoke_hash', '')),
            'certification_status_batch_hash' => (string) ($explicitContext['certification_status_batch_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $runtimeReceipt
     * @return list<string>
     */
    private function staleContextHashes(array $runtimeReceipt, string $currentRuntimeGapMatrixHashForPromotionReceipt, string $currentRuntimePromotionBasisHash, string $currentRuntimePromotionClosureBasisHash): array
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

    private function nextRequiredCommand(string $nextRequired): string
    {
        return match ($nextRequired) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private function emptyVerification(string $reason): array
    {
        return [
            'status' => 'not_supplied',
            'reason' => $reason,
            'violations' => [],
            'violation_count' => 0,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['submission_readiness_hash']);
        unset($payload['diagnostics']['runtime_promotion_receipt']['violations']);
        unset($payload['diagnostics']['real_provider_smoke']['violations']);
        unset($payload['diagnostics']['human_completion_receipt']['violations']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
