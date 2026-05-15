<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionRuntimePromotionReceiptService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_receipt.v1';

    public const MODE = 'read_only_runtime_promotion_receipt_verification';

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-construction/os-completion/runtime-promotion-receipts';

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function verify(
        array $receipt = [],
        array $rows = [],
        string $expectedRuntimePromotionBasisHash = '',
        string $expectedRuntimeGapMatrixHash = '',
        string $expectedRuntimePromotionClosureBasisHash = '',
        bool $loadLatestWhenEmpty = true,
    ): array {
        if ($receipt === [] && $loadLatestWhenEmpty) {
            $receipt = $this->latestReceipt();
        }

        $candidateRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false) === true
                && (bool) ($row['runtime_y'] ?? false) === false,
        ));
        $expectedGapIds = array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $candidateRows));
        $expectedGraduationHashes = [];
        foreach ($candidateRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $expectedGraduationHashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }

        $promotedGapIds = array_values(array_filter((array) ($receipt['promoted_gap_ids'] ?? []), 'is_string'));
        $providedGraduationHashes = (array) ($receipt['graduation_evidence_hashes'] ?? []);
        $violations = [];
        foreach (['receipt_id', 'signed_by', 'reason', 'runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'receipt_hash'] as $field) {
            if (trim((string) ($receipt[$field] ?? '')) === '') {
                $violations[] = ['code' => 'required_runtime_promotion_receipt_field_missing', 'field' => $field];
            }
        }
        if ($this->isPlaceholderSigner((string) ($receipt['signed_by'] ?? ''))) {
            $violations[] = ['code' => 'runtime_promotion_receipt_signer_must_be_real_operator'];
        }
        if (mb_strlen(trim((string) ($receipt['reason'] ?? ''))) < 32) {
            $violations[] = ['code' => 'runtime_promotion_receipt_reason_too_short'];
        }
        if (count($promotedGapIds) !== count(array_unique($promotedGapIds))) {
            $violations[] = ['code' => 'promoted_gap_ids_contain_duplicates'];
        }
        if ($expectedGapIds === [] || $promotedGapIds !== $expectedGapIds) {
            $violations[] = ['code' => 'promoted_gap_ids_do_not_match_runtime_gap_matrix'];
        }
        foreach ($candidateRows as $row) {
            if ((bool) ($row['runtime_enabled'] ?? false)) {
                $violations[] = ['code' => 'runtime_enabled_before_runtime_promotion_receipt', 'gap_id' => (string) ($row['gap_id'] ?? '')];
            }
        }
        foreach (array_keys($providedGraduationHashes) as $gapId) {
            if (! in_array((string) $gapId, $expectedGapIds, true)) {
                $violations[] = ['code' => 'graduation_hash_for_unknown_gap_id', 'gap_id' => (string) $gapId];
            }
        }
        foreach ($expectedGraduationHashes as $gapId => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                $violations[] = ['code' => 'expected_graduation_hash_missing', 'gap_id' => $gapId];

                continue;
            }
            if ((string) ($providedGraduationHashes[$gapId] ?? '') !== $hash) {
                $violations[] = ['code' => 'graduation_hash_mismatch', 'gap_id' => $gapId];
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['runtime_gap_matrix_hash'] ?? '')) !== 1) {
            $violations[] = ['code' => 'runtime_gap_matrix_hash_invalid'];
        }
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['runtime_promotion_basis_hash'] ?? '')) !== 1) {
            $violations[] = ['code' => 'runtime_promotion_basis_hash_invalid'];
        }
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['runtime_promotion_closure_basis_hash'] ?? '')) !== 1) {
            $violations[] = ['code' => 'runtime_promotion_closure_basis_hash_invalid'];
        }
        if ($expectedRuntimePromotionBasisHash !== '' && (string) ($receipt['runtime_promotion_basis_hash'] ?? '') !== $expectedRuntimePromotionBasisHash) {
            $violations[] = ['code' => 'runtime_promotion_basis_hash_mismatch'];
        }
        if ($expectedRuntimeGapMatrixHash !== '' && (string) ($receipt['runtime_gap_matrix_hash'] ?? '') !== $expectedRuntimeGapMatrixHash) {
            $violations[] = ['code' => 'runtime_gap_matrix_hash_mismatch'];
        }
        if ($expectedRuntimePromotionClosureBasisHash !== '' && (string) ($receipt['runtime_promotion_closure_basis_hash'] ?? '') !== $expectedRuntimePromotionClosureBasisHash) {
            $violations[] = ['code' => 'runtime_promotion_closure_basis_hash_mismatch'];
        }
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['receipt_hash'] ?? '')) !== 1) {
            $violations[] = ['code' => 'receipt_hash_invalid'];
        }
        $expectedReceiptHash = $this->hashes()->runtimePromotionReceiptHash($receipt);
        if ((string) ($receipt['receipt_hash'] ?? '') !== $expectedReceiptHash) {
            $violations[] = ['code' => 'receipt_hash_mismatch'];
        }
        foreach (['runtime_promotion_approved', 'operator_reviewed_runtime_graduations', 'no_runtime_autopromotion_acknowledged'] as $flag) {
            if ((bool) ($receipt[$flag] ?? false) !== true) {
                $violations[] = ['code' => 'required_runtime_promotion_acknowledgement_missing', 'flag' => $flag];
            }
        }
        foreach (['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed'] as $flag) {
            if ((bool) ($receipt[$flag] ?? false) === true) {
                $violations[] = ['code' => 'runtime_enabling_flag_forbidden_in_promotion_receipt', 'flag' => $flag];
            }
        }

        $status = $violations === [] ? 'passed' : 'blocked_missing_runtime_promotion_receipt';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'signed_by' => (string) ($receipt['signed_by'] ?? ''),
            'reason_present' => trim((string) ($receipt['reason'] ?? '')) !== '',
            'runtime_gap_matrix_hash' => (string) ($receipt['runtime_gap_matrix_hash'] ?? ''),
            'expected_runtime_gap_matrix_hash' => $expectedRuntimeGapMatrixHash,
            'runtime_promotion_basis_hash' => (string) ($receipt['runtime_promotion_basis_hash'] ?? ''),
            'expected_runtime_promotion_basis_hash' => $expectedRuntimePromotionBasisHash,
            'runtime_promotion_closure_basis_hash' => (string) ($receipt['runtime_promotion_closure_basis_hash'] ?? ''),
            'expected_runtime_promotion_closure_basis_hash' => $expectedRuntimePromotionClosureBasisHash,
            'receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
            'expected_receipt_hash' => $expectedReceiptHash,
            'receipt_hash_matches_payload' => (string) ($receipt['receipt_hash'] ?? '') === $expectedReceiptHash,
            'promoted_gap_ids' => $promotedGapIds,
            'expected_gap_ids' => $expectedGapIds,
            'graduation_evidence_hashes' => $providedGraduationHashes,
            'expected_graduation_evidence_hashes' => $expectedGraduationHashes,
            'runtime_promotion_approved' => (bool) ($receipt['runtime_promotion_approved'] ?? false),
            'operator_reviewed_runtime_graduations' => (bool) ($receipt['operator_reviewed_runtime_graduations'] ?? false),
            'no_runtime_autopromotion_acknowledged' => (bool) ($receipt['no_runtime_autopromotion_acknowledged'] ?? false),
            'violations' => $violations,
            'violation_count' => count($violations),
            'runtime_promotion_allowed' => $status === 'passed',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'next_action' => $status === 'passed'
                ? 'allow_runtime_gap_matrix_to_count_signed_runtime_promotions'
                : 'operator_must_sign_runtime_promotion_receipt_for_current_graduation_hashes',
        ];
        $payload['receipt_verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function persist(
        array $receipt,
        array $rows,
        string $expectedRuntimePromotionBasisHash = '',
        string $expectedRuntimeGapMatrixHash = '',
        string $expectedRuntimePromotionClosureBasisHash = '',
    ): array {
        $verification = $this->verify($receipt, $rows, $expectedRuntimePromotionBasisHash, $expectedRuntimeGapMatrixHash, $expectedRuntimePromotionClosureBasisHash);
        if ((string) $verification['status'] !== 'passed') {
            return $verification + [
                'persisted' => false,
                'persistence_blocker' => 'runtime_promotion_receipt_verification_failed',
            ];
        }

        $receiptHash = (string) $verification['receipt_hash'];
        $path = self::STORAGE_PREFIX.'/'.$receiptHash.'.json';
        $stored = $receipt + [
            'schema_version' => self::SCHEMA_VERSION,
            'persisted_at' => CarbonImmutable::now()->toIso8601String(),
        ];
        Storage::disk(self::STORAGE_DISK)->put($path, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->appendRegistry([
            'receipt_id' => (string) $verification['receipt_id'],
            'receipt_hash' => $receiptHash,
            'path' => $path,
            'persisted_at' => (string) $stored['persisted_at'],
        ]);

        return $verification + [
            'persisted' => true,
            'receipt_path' => $path,
        ];
    }

    /** @return array<string, mixed> */
    private function latestReceipt(): array
    {
        $registry = $this->registry();
        $latest = end($registry);
        if (! is_array($latest)) {
            return [];
        }

        $path = (string) ($latest['path'] ?? '');
        if ($path === '' || ! Storage::disk(self::STORAGE_DISK)->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk(self::STORAGE_DISK)->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string, mixed>> */
    private function registry(): array
    {
        $path = self::STORAGE_PREFIX.'/registry.json';
        if (! Storage::disk(self::STORAGE_DISK)->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk(self::STORAGE_DISK)->get($path), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<string, mixed> $entry */
    private function appendRegistry(array $entry): void
    {
        $registry = array_values(array_filter(
            $this->registry(),
            static fn (array $candidate): bool => (string) ($candidate['receipt_hash'] ?? '') !== (string) $entry['receipt_hash'],
        ));
        $registry[] = $entry;
        Storage::disk(self::STORAGE_DISK)->put(self::STORAGE_PREFIX.'/registry.json', json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['receipt_verification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function hashes(): AtlasSelfConstructionCompletionEvidenceHashService
    {
        return new AtlasSelfConstructionCompletionEvidenceHashService;
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        $normalized = strtolower(trim($signedBy));

        return in_array($normalized, [
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
        ], true);
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
