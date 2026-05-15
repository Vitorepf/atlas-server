<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionHumanSignedCompletionReceiptService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_signed_completion_receipt.v1';

    public const MODE = 'read_only_human_signed_completion_receipt_verification';

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-construction/os-completion/human-signed-receipts';

    /** @return array<string, mixed> */
    public function verify(array $receipt = []): array
    {
        if ($receipt === []) {
            $receipt = $this->latestReceipt();
        }

        $required = [
            'receipt_id',
            'signed_by',
            'reason',
            'completion_audit_hash',
            'release_dossier_hash',
            'replay_diff_hash',
            'runtime_gap_matrix_hash',
            'certification_status_batch_hash',
            'receipt_hash',
        ];
        $missing = [];
        foreach ($required as $field) {
            if (trim((string) ($receipt[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        $receiptHash = (string) ($receipt['receipt_hash'] ?? '');
        $hashFormatValid = preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;
        $expectedReceiptHash = $this->hashes()->humanCompletionReceiptHash($receipt);
        $approved = (bool) ($receipt['os_complete_approved'] ?? false);
        $declaresNoAutopromotion = (bool) ($receipt['no_autopromotion_acknowledged'] ?? false);
        $declaresOperatorReviewed = (bool) ($receipt['operator_reviewed_completion_audit'] ?? false);

        $violations = [];
        foreach ($missing as $field) {
            $violations[] = ['code' => 'required_receipt_field_missing', 'field' => $field];
        }
        if (! $hashFormatValid) {
            $violations[] = ['code' => 'receipt_hash_invalid'];
        }
        if ($receiptHash !== $expectedReceiptHash) {
            $violations[] = ['code' => 'receipt_hash_mismatch'];
        }
        if (! $approved) {
            $violations[] = ['code' => 'os_complete_approval_missing'];
        }
        if (! $declaresNoAutopromotion) {
            $violations[] = ['code' => 'no_autopromotion_acknowledgement_missing'];
        }
        if (! $declaresOperatorReviewed) {
            $violations[] = ['code' => 'operator_review_acknowledgement_missing'];
        }

        $status = $violations === [] ? 'passed' : 'blocked_missing_operator_receipt';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'signed_by' => (string) ($receipt['signed_by'] ?? ''),
            'reason_present' => trim((string) ($receipt['reason'] ?? '')) !== '',
            'receipt_hash' => $receiptHash,
            'expected_receipt_hash' => $expectedReceiptHash,
            'receipt_hash_valid' => $hashFormatValid,
            'receipt_hash_matches_payload' => $receiptHash === $expectedReceiptHash,
            'os_complete_approved' => $approved,
            'operator_reviewed_completion_audit' => $declaresOperatorReviewed,
            'no_autopromotion_acknowledged' => $declaresNoAutopromotion,
            'evidence_hashes' => [
                'completion_audit_hash' => (string) ($receipt['completion_audit_hash'] ?? ''),
                'release_dossier_hash' => (string) ($receipt['release_dossier_hash'] ?? ''),
                'replay_diff_hash' => (string) ($receipt['replay_diff_hash'] ?? ''),
                'runtime_gap_matrix_hash' => (string) ($receipt['runtime_gap_matrix_hash'] ?? ''),
                'certification_status_batch_hash' => (string) ($receipt['certification_status_batch_hash'] ?? ''),
            ],
            'violations' => $violations,
            'violation_count' => count($violations),
            'completion_claim_allowed' => $status === 'passed',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'next_action' => $status === 'passed'
                ? 'allow_completion_audit_to_count_human_signed_receipt'
                : 'operator_must_sign_os_complete_receipt_with_current_evidence_hashes',
        ];
        $payload['receipt_verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function persist(array $receipt): array
    {
        $verification = $this->verify($receipt);
        if ((string) $verification['status'] !== 'passed') {
            return $verification + [
                'persisted' => false,
                'persistence_blocker' => 'receipt_verification_failed',
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

    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['receipt_verification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function hashes(): AtlasSelfConstructionCompletionEvidenceHashService
    {
        return new AtlasSelfConstructionCompletionEvidenceHashService;
    }

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
