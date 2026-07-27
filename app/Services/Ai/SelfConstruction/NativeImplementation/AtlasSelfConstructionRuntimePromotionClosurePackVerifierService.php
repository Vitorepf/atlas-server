<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRuntimePromotionClosurePackVerifierService
{
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
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_closure_pack_verifier.v1';

    public const MODE = 'read_only_runtime_promotion_closure_pack_verifier';

    /** @return array<string, mixed> */
    public function verify(array $pack): array
    {
        $violations = [];

        if ((string) ($pack['schema_version'] ?? '') !== AtlasSelfConstructionRuntimePromotionClosurePackService::SCHEMA_VERSION) {
            $violations[] = ['code' => 'closure_pack_schema_version_invalid'];
        }
        foreach (['runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'closure_pack_hash', 'runtime_promotion_receipt_template_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) ($pack[$field] ?? '')) !== 1) {
                $violations[] = ['code' => 'required_64_hex_field_invalid', 'field' => $field];
            }
        }

        $expectedPackHash = $this->stableHash($pack);
        if ((string) ($pack['closure_pack_hash'] ?? '') !== $expectedPackHash) {
            $violations[] = ['code' => 'closure_pack_hash_mismatch'];
        }

        $promotedGapIds = array_values(array_filter((array) ($pack['promoted_gap_ids'] ?? []), 'is_string'));
        $graduationHashes = (array) ($pack['graduation_evidence_hashes'] ?? []);
        $evidenceRows = array_values(array_filter((array) ($pack['graduation_evidence'] ?? []), 'is_array'));
        $evidenceGapIds = array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $evidenceRows));
        if ($promotedGapIds === [] || $promotedGapIds !== $evidenceGapIds) {
            $violations[] = ['code' => 'promoted_gap_ids_do_not_match_graduation_evidence_rows'];
        }
        // AC2/AC3/AC4: rollback_notes / per-row evidence_refs are enforced only when the pack
        // opts in — the real closure-pack builder does not populate either field yet, and this
        // verifier must keep accepting its output unchanged until the builder is upgraded to
        // supply them (a separate, out-of-scope change).
        $enforceMinimalEvidenceContract = (bool) ($pack['enforce_minimal_evidence_contract'] ?? false);

        // AC3: an authoritative set of gap ids from the current runtime gap matrix, if declared,
        // catches a "bloated" pack that promotes ids never seen in the real matrix — a pack that
        // is internally self-consistent (its own graduation_evidence agrees with itself) but
        // fabricates gap rows outside anything the matrix actually reported. Opt-in: only
        // enforced when the caller declares this field, so packs that don't supply it (or
        // callers who verify a pack independently of the matrix) are unaffected.
        $matrixGapIds = array_key_exists('runtime_gap_matrix_gap_ids', $pack)
            ? array_values(array_filter((array) $pack['runtime_gap_matrix_gap_ids'], 'is_string'))
            : null;

        foreach ($evidenceRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            $hash = (string) ($row['graduation_evidence_hash'] ?? '');
            if (! (bool) ($row['runtime_y_candidate'] ?? false)) {
                $violations[] = ['code' => 'gap_row_is_not_runtime_y_candidate', 'gap_id' => $gapId];
            }
            if ((bool) ($row['runtime_y'] ?? false)) {
                $violations[] = ['code' => 'gap_row_already_runtime_y_without_closure_receipt', 'gap_id' => $gapId];
            }
            if ((bool) ($row['runtime_enabled'] ?? false)) {
                $violations[] = ['code' => 'runtime_enabled_before_operator_receipt', 'gap_id' => $gapId];
            }
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                $violations[] = ['code' => 'graduation_evidence_hash_invalid', 'gap_id' => $gapId];
            }
            if ((string) ($graduationHashes[$gapId] ?? '') !== $hash) {
                $violations[] = ['code' => 'graduation_evidence_hash_map_mismatch', 'gap_id' => $gapId];
            }
            // AC3/AC4: minimal-required-evidence — a gap row with no evidence_refs at all cannot
            // prove why it graduated, only that a hash exists for it.
            if ($enforceMinimalEvidenceContract && (array) ($row['evidence_refs'] ?? []) === []) {
                $violations[] = ['code' => 'gap_row_missing_evidence_refs', 'gap_id' => $gapId];
            }
            if ($matrixGapIds !== null && $gapId !== '' && ! in_array($gapId, $matrixGapIds, true)) {
                $violations[] = ['code' => 'promoted_gap_id_not_in_runtime_gap_matrix', 'gap_id' => $gapId];
            }
        }

        // AC2/AC3: a closure pack that skips rollback guidance is not minimal-but-complete — it's
        // just minimal. Runtime promotion must always be reversible on record.
        if ($enforceMinimalEvidenceContract && trim((string) ($pack['rollback_notes'] ?? '')) === '') {
            $violations[] = ['code' => 'rollback_notes_missing'];
        }

        // AC2/AC3: changed_files — the minimal evidence contract also requires the pack to name
        // exactly which files it touched, so a reviewer never has to re-derive scope from a
        // diff. Bloat detection (unrelated files) is opt-in via declared_scope_files, mirroring
        // the runtime_gap_matrix_gap_ids pattern above.
        $changedFiles = array_values(array_filter(array_map('strval', (array) ($pack['changed_files'] ?? []))));
        if ($enforceMinimalEvidenceContract && $changedFiles === []) {
            $violations[] = ['code' => 'changed_files_missing'];
        }
        if (array_key_exists('declared_scope_files', $pack)) {
            $declaredScopeFiles = array_values(array_filter(array_map('strval', (array) $pack['declared_scope_files'])));
            foreach ($changedFiles as $file) {
                if (! in_array($file, $declaredScopeFiles, true)) {
                    $violations[] = ['code' => 'closure_pack_includes_unrelated_file', 'file' => $file];
                }
            }
        }

        // AC2/AC3: runnable_gates — the pack must name every gate it claims satisfied, and any
        // named gate that did not pass is a hard block regardless of the opt-in flag (a claimed
        // gate that didn't pass is never safe to ignore).
        $runnableGates = array_values(array_filter((array) ($pack['runnable_gates'] ?? []), 'is_array'));
        if ($enforceMinimalEvidenceContract && $runnableGates === []) {
            $violations[] = ['code' => 'runnable_gates_missing'];
        }
        foreach ($runnableGates as $gate) {
            if ((bool) ($gate['passed'] ?? false) !== true) {
                $violations[] = ['code' => 'runnable_gate_not_passed', 'command' => (string) ($gate['command'] ?? '')];
            }
        }

        $preimage = (array) ($pack['runtime_promotion_receipt_preimage'] ?? []);
        $expectedTemplateHash = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($preimage);
        if ((string) ($pack['runtime_promotion_receipt_template_hash'] ?? '') !== $expectedTemplateHash) {
            $violations[] = ['code' => 'runtime_promotion_receipt_template_hash_mismatch'];
        }
        if ((string) ($preimage['signed_by'] ?? '') !== '<operator>') {
            $violations[] = ['code' => 'operator_signature_must_not_be_claimed_in_closure_pack'];
        }
        if ((string) ($preimage['receipt_id'] ?? '') !== '<operator_runtime_promotion_receipt_id>') {
            $violations[] = ['code' => 'operator_receipt_id_must_not_be_claimed_in_closure_pack'];
        }
        if ((string) ($preimage['receipt_hash'] ?? '') !== '<operator_generated_64_hex_receipt_hash>') {
            $violations[] = ['code' => 'operator_receipt_hash_must_not_be_claimed_in_closure_pack'];
        }
        if ((array) ($preimage['promoted_gap_ids'] ?? []) !== $promotedGapIds) {
            $violations[] = ['code' => 'receipt_preimage_promoted_gap_ids_mismatch'];
        }
        if ((array) ($preimage['graduation_evidence_hashes'] ?? []) !== $graduationHashes) {
            $violations[] = ['code' => 'receipt_preimage_graduation_hashes_mismatch'];
        }
        if ((string) ($preimage['runtime_promotion_closure_basis_hash'] ?? '') !== (string) ($pack['runtime_promotion_closure_basis_hash'] ?? '')) {
            $violations[] = ['code' => 'receipt_preimage_closure_basis_hash_mismatch'];
        }
        foreach (['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'runtime_write_allowed', 'ledger_write_allowed'] as $flag) {
            if ((bool) data_get($pack, $flag, false) === true || (bool) data_get($preimage, $flag, false) === true) {
                $violations[] = ['code' => 'runtime_enabling_flag_forbidden_in_closure_pack', 'flag' => $flag];
            }
        }

        $status = $violations === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'closure_pack_hash' => (string) ($pack['closure_pack_hash'] ?? ''),
            'expected_closure_pack_hash' => $expectedPackHash,
            'closure_pack_hash_matches' => (string) ($pack['closure_pack_hash'] ?? '') === $expectedPackHash,
            'runtime_gap_count' => (int) ($pack['runtime_gap_count'] ?? 0),
            'promoted_gap_ids' => $promotedGapIds,
            'violation_count' => count($violations),
            'violations' => $violations,
            'blockers' => $violations,
            'blocker_count' => count($violations),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'next_action' => $status === 'passed'
                ? 'operator_may_transform_preimage_into_signed_runtime_promotion_receipt'
                : 'fix_runtime_promotion_closure_pack_before_operator_signature',
        ];
        $payload['verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset(
            $payload['generated_at'],
            $payload['verified_at'],
            $payload['closure_pack_hash'],
            $payload['verification_hash'],
            $payload['runtime_promotion_receipt_preimage']['receipt_id'],
        );

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
