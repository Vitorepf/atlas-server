<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
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
    use IsPlaceholderReasonShared;

    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
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
        'seu_nome',
        'seu nome',
        '<operador>',
        'operador',
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
        $reasonInvalid = mb_strlen($reason) < 32 || $this->isPlaceholderReason($reason);

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

        // AC2: rollback proof — only enforced when the caller supplies an expected hash to check
        // against (same opt-in pattern as the stale-hash checks above), so callers that don't yet
        // pass rollback evidence see zero behavior change.
        $expectedRollbackProofHash = (string) data_get($runtimeGapMatrix, 'rollback_proof_hash', '');
        $providedRollbackProofHash = (string) ($receipt['rollback_proof_hash'] ?? '');
        $rollbackProofMissing = $expectedRollbackProofHash !== '' && $providedRollbackProofHash === '';
        $rollbackProofMismatch = $expectedRollbackProofHash !== '' && $providedRollbackProofHash !== '' && $providedRollbackProofHash !== $expectedRollbackProofHash;

        $noGapMatrixSupplied = $runtimeGapMatrix === [];

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
        if ($rollbackProofMissing) {
            $violations[] = ['code' => 'rollback_proof_missing'];
        }
        if ($rollbackProofMismatch) {
            $violations[] = ['code' => 'rollback_proof_mismatch'];
        }

        // AC4: the smallest next repair action for every blocker, attached per-violation.
        foreach ($violations as &$violation) {
            $violation['repair_action'] = $this->repairActionFor($violation);
        }
        unset($violation);

        // AC3: non-blocking warnings — verification ran, but with reduced confidence.
        $warnings = [];
        if ($noGapMatrixSupplied) {
            $warnings[] = ['code' => 'no_runtime_gap_matrix_supplied', 'detail' => 'stale-hash and gap-drift checks could not run without a gap matrix'];
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
            'warnings' => $warnings,
            'rollback_proof_hash' => $providedRollbackProofHash,
            'expected_rollback_proof_hash' => $expectedRollbackProofHash,
            'rollback_proof_missing' => $rollbackProofMissing,
            'rollback_proof_mismatch' => $rollbackProofMismatch,
            'evidence_refs' => [
                'receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
                'runtime_gap_matrix_hash' => (string) ($receipt['runtime_gap_matrix_hash'] ?? ''),
                'runtime_promotion_basis_hash' => (string) ($receipt['runtime_promotion_basis_hash'] ?? ''),
                'runtime_promotion_closure_basis_hash' => (string) ($receipt['runtime_promotion_closure_basis_hash'] ?? ''),
                'rollback_proof_hash' => $providedRollbackProofHash,
            ],
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
            'violations' => [['code' => 'no_runtime_promotion_receipt_supplied', 'repair_action' => 'operator_must_supply_runtime_promotion_receipt_json']],
            'violation_count' => 1,
            'warnings' => [],
            'rollback_proof_hash' => '',
            'expected_rollback_proof_hash' => '',
            'rollback_proof_missing' => false,
            'rollback_proof_mismatch' => false,
            'evidence_refs' => [
                'receipt_hash' => '',
                'runtime_gap_matrix_hash' => '',
                'runtime_promotion_basis_hash' => '',
                'runtime_promotion_closure_basis_hash' => '',
                'rollback_proof_hash' => '',
            ],
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

    /**
     * AC4: the smallest concrete next step to clear one violation.
     *
     * @param  array<string, mixed>  $violation
     */
    private function repairActionFor(array $violation): string
    {
        $code = (string) ($violation['code'] ?? '');

        return match ($code) {
            'required_field_missing' => "set receipt['".$violation['field']."']",
            'placeholder_field' => "replace receipt['".$violation['field']."'] with a real, non-placeholder value",
            'invalid_64_hex_field' => "recompute a 64-hex sha256 for receipt['".$violation['field']."']",
            'forbidden_flag_true' => "set receipt['".$violation['flag']."'] back to false",
            'required_acknowledgement_missing' => "set receipt['".$violation['ack']."'] = true after operator review",
            'placeholder_signer' => 'sign with the operator\'s real name, not a placeholder',
            'reason_too_short_or_placeholder' => 'write a real reason of at least 32 characters, not a placeholder',
            'receipt_hash_mismatch' => 'recompute receipt_hash via AtlasSelfConstructionCompletionEvidenceHashService',
            'stale_runtime_gap_matrix_hash' => 'refresh runtime_gap_matrix_hash to match the current gap matrix',
            'stale_runtime_promotion_basis_hash' => 'refresh runtime_promotion_basis_hash to match the current gap matrix',
            'stale_runtime_promotion_closure_basis_hash' => 'refresh runtime_promotion_closure_basis_hash to match the current gap matrix',
            'promoted_gap_id_drift' => "recompute promoted_gap_ids to match the current gap matrix's ungraduated rows",
            'missing_graduation_hash' => "supply a 64-hex graduation_evidence_hash for gap '".$violation['gap_id']."'",
            'graduation_hash_mismatch' => "recompute the graduation_evidence_hash for gap '".$violation['gap_id']."' to match the matrix",
            'rollback_proof_missing' => 'supply rollback_proof_hash matching the required rollback proof',
            'rollback_proof_mismatch' => 'recompute rollback_proof_hash to match the expected rollback proof',
            default => 'review this violation and correct the receipt field it names',
        };
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['verifier_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
