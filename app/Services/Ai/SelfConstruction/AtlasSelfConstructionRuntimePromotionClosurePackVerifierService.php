<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRuntimePromotionClosurePackVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_closure_pack_verifier.v1';

    public const MODE = 'read_only_runtime_promotion_closure_pack_verifier';

    /** @return array<string, mixed> */
    public function verify(array $pack): array
    {
        $violations = [];

        if ((string) ($pack['schema_version'] ?? '') !== AtlasSelfConstructionRuntimePromotionClosurePackService::SCHEMA_VERSION) {
            $violations[] = ['code' => 'closure_pack_schema_version_invalid'];
        }
        foreach (['runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'closure_pack_hash', 'runtime_promotion_receipt_template_hash'] as $field) {
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
