<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Runtime Promotion Endgame Verifier v1.
 *
 * Per-rule diagnostic verifier the Endgame service consumes (and that the
 * operator can run directly) before invoking the canonical persistence path
 * AtlasSelfConstructionRuntimePromotionReceiptService::persist. Surfaces
 * structured per-field errors so the operator can fix one mistake at a time.
 * Never persists, never enables runtime, never calls providers.
 */
final class AtlasSelfConstructionRuntimePromotionEndgameVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_endgame_verifier.v1';

    public const MODE = 'read_only_runtime_promotion_endgame_verifier';

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
    ];

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @return array<string, mixed>
     */
    public function verify(array $receipt, array $runtimeGapMatrix = []): array
    {
        $missingFields = [];
        $placeholderFields = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $receipt[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missingFields[] = $field;

                continue;
            }
            if (is_string($value) && str_starts_with(trim($value), '<') && str_ends_with(trim($value), '>')) {
                $placeholderFields[] = $field;
            }
        }

        $invalidHashFields = [];
        foreach (self::REQUIRED_64_HEX_FIELDS as $field) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) ($receipt[$field] ?? '')) !== 1) {
                $invalidHashFields[] = $field;
            }
        }

        $forbiddenFlagsTrue = [];
        foreach (self::FORBIDDEN_FLAGS as $flag) {
            if (($receipt[$flag] ?? false) === true) {
                $forbiddenFlagsTrue[] = $flag;
            }
        }

        $missingAcknowledgements = [];
        foreach (self::REQUIRED_ACKNOWLEDGEMENTS as $ack) {
            if (($receipt[$ack] ?? false) !== true) {
                $missingAcknowledgements[] = $ack;
            }
        }

        $signedBy = (string) ($receipt['signed_by'] ?? '');
        $placeholderSigner = in_array(strtolower(trim($signedBy)), self::PLACEHOLDER_SIGNERS, true);

        $reason = trim((string) ($receipt['reason'] ?? ''));
        $reasonInvalid = mb_strlen($reason) < 32 || str_starts_with($reason, '<');

        $expectedReceiptHash = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);
        $receiptHashMismatch = (string) ($receipt['receipt_hash'] ?? '') !== $expectedReceiptHash;

        $expectedMatrixHash = (string) data_get(
            $runtimeGapMatrix,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt',
            data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
        );
        $expectedBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', '');
        $expectedClosureBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', '');
        $staleMatrixHash = $expectedMatrixHash !== '' && (string) ($receipt['runtime_gap_matrix_hash'] ?? '') !== $expectedMatrixHash;
        $staleBasisHash = $expectedBasisHash !== '' && (string) ($receipt['runtime_promotion_basis_hash'] ?? '') !== $expectedBasisHash;
        $staleClosureBasisHash = $expectedClosureBasisHash !== '' && (string) ($receipt['runtime_promotion_closure_basis_hash'] ?? '') !== $expectedClosureBasisHash;

        $rows = array_values(array_filter((array) data_get($runtimeGapMatrix, 'rows', []), 'is_array'));
        $gapRows = array_values(array_filter($rows, static fn (array $r): bool => ! (bool) ($r['runtime_y'] ?? false)));
        $expectedGapIds = array_values(array_map(static fn (array $r): string => (string) ($r['gap_id'] ?? ''), $gapRows));
        $expectedGraduationHashes = [];
        foreach ($gapRows as $r) {
            $gapId = (string) ($r['gap_id'] ?? '');
            if ($gapId !== '') {
                $expectedGraduationHashes[$gapId] = (string) ($r['graduation_evidence_hash'] ?? '');
            }
        }
        $promotedGapIds = array_values(array_filter((array) ($receipt['promoted_gap_ids'] ?? []), 'is_string'));
        $promotedGapDrift = false;
        if ($expectedGapIds !== [] && $promotedGapIds !== $expectedGapIds) {
            $promotedGapDrift = true;
        }
        if ($expectedGapIds === [] && $promotedGapIds !== []) {
            $promotedGapDrift = true;
        }
        $providedGraduation = (array) ($receipt['graduation_evidence_hashes'] ?? []);
        $missingGraduationHashes = [];
        $graduationHashMismatches = [];
        foreach ($expectedGraduationHashes as $gapId => $hash) {
            $value = (string) ($providedGraduation[$gapId] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $missingGraduationHashes[] = $gapId;

                continue;
            }
            if ($hash !== '' && $value !== $hash) {
                $graduationHashMismatches[] = $gapId;
            }
        }

        $violations = [];
        foreach ($missingFields as $field) {
            $violations[] = ['code' => 'required_field_missing', 'field' => $field];
        }
        foreach ($placeholderFields as $field) {
            $violations[] = ['code' => 'placeholder_field', 'field' => $field];
        }
        foreach ($invalidHashFields as $field) {
            $violations[] = ['code' => 'invalid_64_hex_field', 'field' => $field];
        }
        foreach ($forbiddenFlagsTrue as $flag) {
            $violations[] = ['code' => 'forbidden_flag_true', 'flag' => $flag];
        }
        foreach ($missingAcknowledgements as $ack) {
            $violations[] = ['code' => 'required_acknowledgement_missing', 'ack' => $ack];
        }
        if ($placeholderSigner) {
            $violations[] = ['code' => 'placeholder_signer'];
        }
        if ($reasonInvalid) {
            $violations[] = ['code' => 'reason_too_short_or_placeholder'];
        }
        if ($receiptHashMismatch) {
            $violations[] = ['code' => 'receipt_hash_mismatch'];
        }
        if ($staleMatrixHash) {
            $violations[] = ['code' => 'stale_runtime_gap_matrix_hash'];
        }
        if ($staleBasisHash) {
            $violations[] = ['code' => 'stale_runtime_promotion_basis_hash'];
        }
        if ($staleClosureBasisHash) {
            $violations[] = ['code' => 'stale_runtime_promotion_closure_basis_hash'];
        }
        if ($promotedGapDrift) {
            $violations[] = ['code' => 'promoted_gap_id_drift'];
        }
        foreach ($missingGraduationHashes as $gapId) {
            $violations[] = ['code' => 'missing_graduation_hash', 'gap_id' => $gapId];
        }
        foreach ($graduationHashMismatches as $gapId) {
            $violations[] = ['code' => 'graduation_hash_mismatch', 'gap_id' => $gapId];
        }

        $status = $violations === [] ? 'passed' : 'blocked';
        $canPersist = $status === 'passed';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'can_persist' => $canPersist,
            'required_fields' => self::REQUIRED_FIELDS,
            'required_64_hex_fields' => self::REQUIRED_64_HEX_FIELDS,
            'required_acknowledgements' => self::REQUIRED_ACKNOWLEDGEMENTS,
            'forbidden_flag_list' => self::FORBIDDEN_FLAGS,
            'missing_fields' => $missingFields,
            'placeholder_fields' => $placeholderFields,
            'invalid_hash_fields' => $invalidHashFields,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'acknowledgement_missing' => $missingAcknowledgements,
            'placeholder_signer' => $placeholderSigner,
            'reason_invalid' => $reasonInvalid,
            'receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
            'expected_receipt_hash' => $expectedReceiptHash,
            'receipt_hash_mismatch' => $receiptHashMismatch,
            'stale_runtime_gap_matrix_hash' => $staleMatrixHash,
            'stale_runtime_promotion_basis_hash' => $staleBasisHash,
            'stale_runtime_promotion_closure_basis_hash' => $staleClosureBasisHash,
            'promoted_gap_id_drift' => $promotedGapDrift,
            'missing_graduation_hashes' => $missingGraduationHashes,
            'graduation_hash_mismatches' => $graduationHashMismatches,
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
                ? 'operator_may_persist_through_canonical_runtime_promotion_receipt_service'
                : 'fix_each_listed_violation_recompute_receipt_hash_and_reverify',
            'non_execution_guarantees' => [
                'endgame_verifier_does_not_persist_receipts',
                'endgame_verifier_does_not_enable_runtime',
                'endgame_verifier_does_not_start_process',
                'endgame_verifier_does_not_call_provider',
                'endgame_verifier_does_not_spend_tokens',
                'endgame_verifier_does_not_dispatch',
                'endgame_verifier_does_not_promote_completion',
                'endgame_verifier_does_not_sign_for_operator',
            ],
        ];
        $payload['verifier_hash'] = $this->stableHash($payload);

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
            'required_fields' => self::REQUIRED_FIELDS,
            'required_64_hex_fields' => self::REQUIRED_64_HEX_FIELDS,
            'required_acknowledgements' => self::REQUIRED_ACKNOWLEDGEMENTS,
            'forbidden_flag_list' => self::FORBIDDEN_FLAGS,
            'missing_fields' => self::REQUIRED_FIELDS,
            'placeholder_fields' => [],
            'invalid_hash_fields' => self::REQUIRED_64_HEX_FIELDS,
            'forbidden_flags_true' => [],
            'acknowledgement_missing' => self::REQUIRED_ACKNOWLEDGEMENTS,
            'placeholder_signer' => false,
            'reason_invalid' => true,
            'receipt_hash' => '',
            'expected_receipt_hash' => '',
            'receipt_hash_mismatch' => true,
            'stale_runtime_gap_matrix_hash' => false,
            'stale_runtime_promotion_basis_hash' => false,
            'stale_runtime_promotion_closure_basis_hash' => false,
            'promoted_gap_id_drift' => false,
            'missing_graduation_hashes' => [],
            'graduation_hash_mismatches' => [],
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
                'endgame_verifier_does_not_persist_receipts',
                'endgame_verifier_does_not_enable_runtime',
                'endgame_verifier_does_not_start_process',
                'endgame_verifier_does_not_call_provider',
                'endgame_verifier_does_not_spend_tokens',
                'endgame_verifier_does_not_dispatch',
                'endgame_verifier_does_not_promote_completion',
                'endgame_verifier_does_not_sign_for_operator',
            ],
        ];
        $payload['verifier_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['verifier_hash']);

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
