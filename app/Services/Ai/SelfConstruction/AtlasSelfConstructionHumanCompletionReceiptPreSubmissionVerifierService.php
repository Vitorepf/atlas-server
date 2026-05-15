<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Read-only diagnostic verifier that runs BEFORE the operator submits a
 * signed Atlas Self-Construction OS-complete receipt to the canonical
 * persistence verifier.
 *
 * Returns a structured matrix of violations; never persists anything.
 */
final class AtlasSelfConstructionHumanCompletionReceiptPreSubmissionVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_pre_submission_verifier.v1';

    public const MODE = 'read_only_human_completion_receipt_pre_submission_verifier';

    private const PLACEHOLDER_SIGNERS = [
        '',
        '<operator>',
        '<operator_name>',
        'operator',
        'human',
        'codex',
        'codex-autosigned',
        'claude',
        'assistant',
        'system',
        'atlas',
    ];

    private const FORBIDDEN_REASON_PATTERNS = [
        '<...>',
        '<operator_reason',
        'todo',
        'placeholder',
        'autosigned',
        'lorem',
    ];

    private const FORBIDDEN_FLAGS = [
        'execution_allowed',
        'dispatch_allowed',
        'provider_call_allowed',
        'token_spend_allowed',
        'adapter_execution_allowed',
        'self_programming_allowed',
        'completion_autopromoted',
    ];

    private const EVIDENCE_HASH_FIELDS = [
        'completion_audit_hash',
        'release_dossier_hash',
        'replay_diff_hash',
        'runtime_gap_matrix_hash',
        'runtime_promotion_receipt_hash',
        'real_provider_smoke_hash',
        'certification_status_batch_hash',
    ];

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function verify(array $receipt, array $context = []): array
    {
        $diagnostics = [];

        $receiptId = trim((string) ($receipt['receipt_id'] ?? ''));
        $signedBy = trim((string) ($receipt['signed_by'] ?? ''));
        $reason = trim((string) ($receipt['reason'] ?? ''));

        if ($receiptId === '') {
            $diagnostics[] = ['code' => 'missing_receipt_id'];
        }
        if ($signedBy === '') {
            $diagnostics[] = ['code' => 'missing_signed_by'];
        } elseif ($this->isPlaceholderSigner($signedBy)) {
            $diagnostics[] = ['code' => 'placeholder_or_fake_signer', 'signed_by' => $signedBy];
        }
        if ($reason === '') {
            $diagnostics[] = ['code' => 'missing_reason'];
        } else {
            $reasonLower = mb_strtolower($reason);
            foreach (self::FORBIDDEN_REASON_PATTERNS as $pattern) {
                if (str_contains($reasonLower, $pattern)) {
                    $diagnostics[] = ['code' => 'placeholder_reason_pattern', 'pattern' => $pattern];
                }
            }
            if (mb_strlen($reason) < 32) {
                $diagnostics[] = ['code' => 'reason_too_short', 'min_length' => 32, 'actual_length' => mb_strlen($reason)];
            }
        }

        foreach (self::EVIDENCE_HASH_FIELDS as $field) {
            $value = (string) ($receipt[$field] ?? '');
            if ($value === '') {
                $diagnostics[] = ['code' => 'missing_'.$field];

                continue;
            }
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $diagnostics[] = ['code' => 'evidence_hash_invalid', 'field' => $field];
            }
            $expected = (string) ($context[$field] ?? '');
            if ($expected !== '' && $value !== $expected) {
                $code = $this->staleHashCode($field);
                $diagnostics[] = ['code' => $code, 'field' => $field, 'expected' => $expected, 'received' => $value];
            }
        }

        $receiptHash = (string) ($receipt['receipt_hash'] ?? '');
        $expectedReceiptHash = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        if ($receiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) !== 1) {
            $diagnostics[] = ['code' => 'receipt_hash_invalid'];
        } elseif ($receiptHash !== $expectedReceiptHash) {
            $diagnostics[] = ['code' => 'receipt_hash_mismatch', 'expected' => $expectedReceiptHash, 'received' => $receiptHash];
        }

        if ((bool) ($receipt['os_complete_approved'] ?? false) !== true) {
            $diagnostics[] = ['code' => 'os_complete_approved_false'];
        }
        if ((bool) ($receipt['operator_reviewed_completion_audit'] ?? false) !== true) {
            $diagnostics[] = ['code' => 'operator_reviewed_completion_audit_false'];
        }
        if ((bool) ($receipt['no_autopromotion_acknowledged'] ?? false) !== true) {
            $diagnostics[] = ['code' => 'no_autopromotion_acknowledged_false'];
        }

        foreach (self::FORBIDDEN_FLAGS as $flag) {
            if ((bool) ($receipt[$flag] ?? false) === true) {
                $diagnostics[] = ['code' => 'forbidden_flag_true', 'flag' => $flag];
            }
        }

        $failedPrereqs = $this->failedPrerequisites($context);
        if ($failedPrereqs !== []) {
            $diagnostics[] = ['code' => 'prerequisites_not_green', 'failed' => $failedPrereqs];
        }

        $status = $diagnostics === [] ? 'passed' : 'blocked';
        $canPersist = $status === 'passed' && $failedPrereqs === [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'receipt_id' => $receiptId,
            'signed_by' => $signedBy,
            'receipt_hash' => $receiptHash,
            'expected_receipt_hash' => $expectedReceiptHash,
            'receipt_hash_matches_payload' => $receiptHash === $expectedReceiptHash,
            'diagnostics' => $diagnostics,
            'diagnostic_count' => count($diagnostics),
            'diagnostic_codes' => array_values(array_unique(array_column($diagnostics, 'code'))),
            'failed_prerequisites' => $failedPrereqs,
            'can_persist' => $canPersist,
            'persistence_blocker' => $canPersist
                ? ''
                : ($failedPrereqs !== []
                    ? 'human_completion_receipt_prerequisites_not_green'
                    : 'human_completion_receipt_pre_submission_verification_failed'),
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'human_completion_receipt_pre_submission_verifier_does_not_persist_receipts',
                'human_completion_receipt_pre_submission_verifier_does_not_sign_for_operator',
                'human_completion_receipt_pre_submission_verifier_does_not_promote_completion',
                'human_completion_receipt_pre_submission_verifier_does_not_call_provider',
                'human_completion_receipt_pre_submission_verifier_does_not_spend_tokens',
                'human_completion_receipt_pre_submission_verifier_does_not_dispatch_work',
            ],
        ];

        $payload['pre_submission_verification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function staleHashCode(string $field): string
    {
        return match ($field) {
            'completion_audit_hash' => 'stale_completion_audit_hash',
            'release_dossier_hash' => 'stale_release_dossier_hash',
            'replay_diff_hash' => 'stale_replay_diff_hash',
            'runtime_gap_matrix_hash' => 'stale_runtime_gap_matrix_hash',
            'runtime_promotion_receipt_hash' => 'stale_runtime_promotion_receipt_hash',
            'real_provider_smoke_hash' => 'stale_real_provider_smoke_hash',
            'certification_status_batch_hash' => 'stale_certification_status_batch_hash',
            default => 'stale_evidence_hash',
        };
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        $normalized = mb_strtolower(trim($signedBy));

        return in_array($normalized, self::PLACEHOLDER_SIGNERS, true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function failedPrerequisites(array $context): array
    {
        $failed = [];
        $prerequisites = (array) ($context['prerequisites'] ?? []);
        foreach ($prerequisites as $name => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((bool) ($row['green'] ?? false) !== true) {
                $failed[] = (string) $name;
            }
        }

        return $failed;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['verified_at'], $payload['pre_submission_verification_hash']);

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
