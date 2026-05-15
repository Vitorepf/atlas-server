<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionHumanCompletionReceiptVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_verifier.v1';

    public const MODE = 'read_only_human_completion_receipt_verifier';

    /** @return array<string, mixed> */
    public function verify(array $receipt, array $context = []): array
    {
        $base = (new AtlasSelfConstructionHumanSignedCompletionReceiptService)->verify($receipt);
        $violations = (array) data_get($base, 'violations', []);

        if (in_array(strtolower((string) ($receipt['signed_by'] ?? '')), ['', '<operator>', 'codex', 'codex-autosigned', 'assistant', 'system'], true)) {
            $violations[] = ['code' => 'human_completion_receipt_signer_invalid_or_placeholder'];
        }
        foreach (['completion_audit_hash', 'release_dossier_hash', 'replay_diff_hash', 'runtime_gap_matrix_hash', 'runtime_promotion_receipt_hash', 'real_provider_smoke_hash', 'certification_status_batch_hash'] as $field) {
            $expected = (string) data_get($context, $field, '');
            if ($expected !== '' && (string) ($receipt[$field] ?? '') !== $expected) {
                $violations[] = ['code' => 'context_hash_mismatch', 'field' => $field];
            }
        }
        foreach (['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_autopromoted'] as $flag) {
            if ((bool) ($receipt[$flag] ?? false) === true) {
                $violations[] = ['code' => 'forbidden_flag_in_human_completion_receipt', 'flag' => $flag];
            }
        }

        $status = $violations === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'base_status' => (string) data_get($base, 'status'),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
            'expected_receipt_hash' => (string) data_get($base, 'expected_receipt_hash'),
            'receipt_hash_matches_payload' => (bool) data_get($base, 'receipt_hash_matches_payload', false),
            'violation_count' => count($violations),
            'violations' => $violations,
            'completion_claim_allowed' => $status === 'passed',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function persist(array $receipt, array $context = []): array
    {
        $verification = $this->verify($receipt, $context);
        if ((string) $verification['status'] !== 'passed') {
            return $verification + [
                'persisted' => false,
                'persistence_blocker' => 'human_completion_receipt_strong_verification_failed',
            ];
        }

        $persisted = (new AtlasSelfConstructionHumanSignedCompletionReceiptService)->persist($receipt);

        return $verification + [
            'persisted' => (bool) data_get($persisted, 'persisted', false),
            'receipt_path' => (string) data_get($persisted, 'receipt_path', ''),
        ];
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
