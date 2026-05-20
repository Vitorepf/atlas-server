<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Runtime Promotion Receipt Pre-Submission Verifier v1.
 *
 * Rich diagnostic verifier the operator runs *before* persisting a runtime
 * promotion receipt. It exists alongside the canonical
 * AtlasSelfConstructionRuntimePromotionReceiptService::verify, but never
 * persists, never enables runtime, and emits per-rule violations so the
 * operator can fix one mistake at a time. The canonical verifier remains the
 * authority for persistence.
 */
final class AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_receipt_pre_submission_verifier.v1';

    public const MODE = 'read_only_runtime_promotion_receipt_pre_submission_verifier';

    private const REQUIRED_FIELDS = [
        'receipt_id',
        'signed_by',
        'reason',
        'runtime_gap_matrix_hash',
        'runtime_promotion_basis_hash',
        'runtime_promotion_closure_basis_hash',
        'promoted_gap_ids',
        'graduation_evidence_hashes',
        'receipt_hash',
    ];

    private const REQUIRED_64_HEX_FIELDS = [
        'runtime_gap_matrix_hash',
        'runtime_promotion_basis_hash',
        'runtime_promotion_closure_basis_hash',
        'receipt_hash',
    ];

    private const REQUIRED_ACKNOWLEDGEMENTS = [
        'runtime_promotion_approved',
        'operator_reviewed_runtime_graduations',
        'no_runtime_autopromotion_acknowledged',
    ];

    private const FORBIDDEN_FLAGS = [
        'execution_allowed',
        'dispatch_allowed',
        'provider_call_allowed',
        'token_spend_allowed',
        'adapter_execution_allowed',
        'self_programming_allowed',
    ];

    private const PLACEHOLDER_SIGNERS = [
        '',
        '<operator>',
        'operator',
        'human',
        'codex',
        'assistant',
        'system',
        'claude',
        'codex-autosigned',
        'atlas',
        'seu_nome',
        'seu nome',
        '<operador>',
        'operador',
    ];

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $matrix
     * @return array<string, mixed>
     */
    public function verify(array $receipt, array $matrix = []): array
    {
        $violations = [];
        $missingFields = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $receipt[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missingFields[] = $field;
                $violations[] = ['code' => 'required_field_missing', 'field' => $field];
            }
        }

        $invalidHashes = [];
        foreach (self::REQUIRED_64_HEX_FIELDS as $field) {
            $value = (string) ($receipt[$field] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $invalidHashes[] = $field;
                $violations[] = ['code' => 'invalid_64_hex_value', 'field' => $field];
            }
        }

        $signedBy = (string) ($receipt['signed_by'] ?? '');
        $placeholderSigner = $this->isPlaceholderSigner($signedBy);
        if ($placeholderSigner) {
            $violations[] = ['code' => 'placeholder_signer'];
        }

        $reason = trim((string) ($receipt['reason'] ?? ''));
        if (mb_strlen($reason) < 32 || $this->isPlaceholderReason($reason)) {
            $violations[] = ['code' => 'reason_too_short_or_placeholder'];
        }

        $expectedReceiptHash = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);
        $receiptHashMatches = (string) ($receipt['receipt_hash'] ?? '') === $expectedReceiptHash;
        if (! $receiptHashMatches) {
            $violations[] = ['code' => 'receipt_hash_mismatch'];
        }

        $forbiddenFlagsTrue = [];
        foreach (self::FORBIDDEN_FLAGS as $flag) {
            if (($receipt[$flag] ?? false) === true) {
                $forbiddenFlagsTrue[] = $flag;
                $violations[] = ['code' => 'forbidden_flag_true', 'flag' => $flag];
            }
        }

        $missingAcks = [];
        foreach (self::REQUIRED_ACKNOWLEDGEMENTS as $ack) {
            if (($receipt[$ack] ?? false) !== true) {
                $missingAcks[] = $ack;
                $violations[] = ['code' => 'required_acknowledgement_missing', 'ack' => $ack];
            }
        }

        $expectedMatrixHash = (string) data_get($matrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', data_get($matrix, 'runtime_gap_matrix_hash', ''));
        $expectedBasisHash = (string) data_get($matrix, 'runtime_promotion_basis_hash', '');
        $expectedClosureBasisHash = (string) data_get($matrix, 'runtime_promotion_closure_basis_hash', '');
        $staleMatrixHash = $expectedMatrixHash !== '' && (string) ($receipt['runtime_gap_matrix_hash'] ?? '') !== $expectedMatrixHash;
        $staleBasisHash = $expectedBasisHash !== '' && (string) ($receipt['runtime_promotion_basis_hash'] ?? '') !== $expectedBasisHash;
        $staleClosureBasisHash = $expectedClosureBasisHash !== '' && (string) ($receipt['runtime_promotion_closure_basis_hash'] ?? '') !== $expectedClosureBasisHash;
        if ($staleMatrixHash) {
            $violations[] = ['code' => 'stale_runtime_gap_matrix_hash'];
        }
        if ($staleBasisHash) {
            $violations[] = ['code' => 'stale_runtime_promotion_basis_hash'];
        }
        if ($staleClosureBasisHash) {
            $violations[] = ['code' => 'stale_runtime_promotion_closure_basis_hash'];
        }

        $rows = array_values(array_filter((array) data_get($matrix, 'rows', []), 'is_array'));
        $gapRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false)));
        $expectedGapIds = array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows));
        $expectedGraduationHashes = [];
        foreach ($gapRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $expectedGraduationHashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }
        $promotedGapIds = array_values(array_filter((array) ($receipt['promoted_gap_ids'] ?? []), 'is_string'));
        $providedGraduationHashes = (array) ($receipt['graduation_evidence_hashes'] ?? []);
        $promotedGapDrift = false;
        if ($promotedGapIds !== $expectedGapIds) {
            $promotedGapDrift = true;
            $violations[] = ['code' => 'promoted_gap_id_drift'];
        }
        $graduationHashMismatchGaps = [];
        foreach ($expectedGraduationHashes as $gapId => $hash) {
            $provided = (string) ($providedGraduationHashes[$gapId] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $provided) !== 1) {
                $graduationHashMismatchGaps[] = $gapId;
                $violations[] = ['code' => 'missing_graduation_evidence_hash', 'gap_id' => $gapId];

                continue;
            }
            if ($hash !== '' && $provided !== $hash) {
                $graduationHashMismatchGaps[] = $gapId;
                $violations[] = ['code' => 'graduation_evidence_hash_mismatch', 'gap_id' => $gapId];
            }
        }

        $status = $violations === [] ? 'passed' : 'blocked';
        $canPersist = $status === 'passed';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'can_persist' => $canPersist,
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'signed_by' => $signedBy,
            'receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
            'expected_receipt_hash' => $expectedReceiptHash,
            'receipt_hash_matches_payload' => $receiptHashMatches,
            'placeholder_signer' => $placeholderSigner,
            'missing_fields' => $missingFields,
            'invalid_64_hex_fields' => $invalidHashes,
            'missing_required_acknowledgements' => $missingAcks,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'stale_runtime_gap_matrix_hash' => $staleMatrixHash,
            'stale_runtime_promotion_basis_hash' => $staleBasisHash,
            'stale_runtime_promotion_closure_basis_hash' => $staleClosureBasisHash,
            'promoted_gap_drift' => $promotedGapDrift,
            'graduation_hash_mismatch_gaps' => array_values(array_unique($graduationHashMismatchGaps)),
            'promoted_gap_ids' => $promotedGapIds,
            'expected_gap_ids' => $expectedGapIds,
            'expected_graduation_evidence_hashes' => $expectedGraduationHashes,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedMatrixHash,
            'expected_runtime_promotion_basis_hash' => $expectedBasisHash,
            'expected_runtime_promotion_closure_basis_hash' => $expectedClosureBasisHash,
            'violations' => $violations,
            'violation_count' => count($violations),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'persistence_blocked' => ! $canPersist,
            'next_action' => $canPersist
                ? 'operator_may_persist_receipt_through_canonical_runtime_promotion_receipt_service'
                : 'fix_each_listed_violation_then_recompute_receipt_hash_and_verify_again',
            'non_execution_guarantees' => [
                'pre_submission_verifier_does_not_persist_receipts',
                'pre_submission_verifier_does_not_enable_runtime',
                'pre_submission_verifier_does_not_start_process',
                'pre_submission_verifier_does_not_call_provider',
                'pre_submission_verifier_does_not_spend_tokens',
                'pre_submission_verifier_does_not_dispatch',
                'pre_submission_verifier_does_not_promote_completion',
                'pre_submission_verifier_does_not_sign_for_operator',
            ],
        ];
        $payload['verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function emptyVerification(): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'not_supplied',
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'can_persist' => false,
            'receipt_id' => '',
            'signed_by' => '',
            'receipt_hash' => '',
            'expected_receipt_hash' => '',
            'receipt_hash_matches_payload' => false,
            'placeholder_signer' => false,
            'missing_fields' => self::REQUIRED_FIELDS,
            'invalid_64_hex_fields' => self::REQUIRED_64_HEX_FIELDS,
            'missing_required_acknowledgements' => self::REQUIRED_ACKNOWLEDGEMENTS,
            'forbidden_flags_true' => [],
            'stale_runtime_gap_matrix_hash' => false,
            'stale_runtime_promotion_basis_hash' => false,
            'stale_runtime_promotion_closure_basis_hash' => false,
            'promoted_gap_drift' => false,
            'graduation_hash_mismatch_gaps' => [],
            'promoted_gap_ids' => [],
            'expected_gap_ids' => [],
            'expected_graduation_evidence_hashes' => [],
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => '',
            'expected_runtime_promotion_basis_hash' => '',
            'expected_runtime_promotion_closure_basis_hash' => '',
            'violations' => [['code' => 'no_runtime_promotion_receipt_supplied']],
            'violation_count' => 1,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'persistence_blocked' => true,
            'next_action' => 'operator_must_supply_runtime_promotion_receipt_json',
            'non_execution_guarantees' => [
                'pre_submission_verifier_does_not_persist_receipts',
                'pre_submission_verifier_does_not_enable_runtime',
                'pre_submission_verifier_does_not_start_process',
                'pre_submission_verifier_does_not_call_provider',
                'pre_submission_verifier_does_not_spend_tokens',
                'pre_submission_verifier_does_not_dispatch',
                'pre_submission_verifier_does_not_promote_completion',
                'pre_submission_verifier_does_not_sign_for_operator',
            ],
        ];
        $payload['verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        return in_array(strtolower(trim($signedBy)), self::PLACEHOLDER_SIGNERS, true);
    }

    private function isPlaceholderReason(string $reason): bool
    {
        $normalized = strtolower(trim($reason));
        if ($normalized === '' || str_starts_with($normalized, '<')) {
            return true;
        }

        foreach ([
            'operator reason',
            'minimum_32_chars',
            'pelo menos 32 caracteres',
            'motivo real',
            'substitua',
            'placeholder',
            'todo',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['verification_hash']);

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
