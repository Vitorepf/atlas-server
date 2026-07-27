<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionHumanCompletionReceiptVerifierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_verifier.v1';

    public const MODE = 'read_only_human_completion_receipt_verifier';

    private const CONTEXT_HASH_FIELDS = [
        'completion_audit_hash', 'release_dossier_hash', 'replay_diff_hash', 'runtime_gap_matrix_hash',
        'runtime_promotion_receipt_hash', 'real_provider_smoke_hash', 'certification_status_batch_hash',
    ];

    private const FORBIDDEN_RUNTIME_FLAGS = [
        'execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed',
        'adapter_execution_allowed', 'self_programming_allowed', 'completion_autopromoted',
    ];

    /** @return array<string, mixed> */
    public function verify(array $receipt, array $context = []): array
    {
        $base = (new AtlasSelfConstructionHumanSignedCompletionReceiptService)->verify($receipt);
        $violations = (array) data_get($base, 'violations', []);
        $fieldMatrix = [];

        $signerPlaceholder = $this->isPlaceholderSigner((string) ($receipt['signed_by'] ?? ''));
        if ($signerPlaceholder) {
            $violations[] = ['code' => 'human_completion_receipt_signer_invalid_or_placeholder'];
        }
        $fieldMatrix[] = $this->field('signed_by', ! $signerPlaceholder, $signerPlaceholder ? 'human_completion_receipt_signer_invalid_or_placeholder' : null);

        $reasonPlaceholder = $this->isPlaceholderReason((string) ($receipt['reason'] ?? ''));
        $reasonExternalClaim = $this->isExternalCompletionClaimReason((string) ($receipt['reason'] ?? ''));
        if ($reasonPlaceholder) {
            $violations[] = ['code' => 'human_completion_receipt_reason_placeholder'];
        }
        if ($reasonExternalClaim) {
            $violations[] = ['code' => 'human_completion_receipt_reason_relies_on_external_agent_claim'];
        }
        $reasonOk = ! $reasonPlaceholder && ! $reasonExternalClaim;
        $fieldMatrix[] = $this->field(
            'reason',
            $reasonOk,
            $reasonOk ? null : ($reasonPlaceholder ? 'human_completion_receipt_reason_placeholder' : 'human_completion_receipt_reason_relies_on_external_agent_claim'),
        );

        $receiptHashOk = (bool) data_get($base, 'receipt_hash_matches_payload', false);
        $fieldMatrix[] = $this->field('receipt_hash', $receiptHashOk, $receiptHashOk ? null : 'human_completion_receipt_hash_mismatch');

        foreach (self::CONTEXT_HASH_FIELDS as $field) {
            $expected = (string) data_get($context, $field, '');
            $matches = $expected === '' || (string) ($receipt[$field] ?? '') === $expected;
            if (! $matches) {
                $violations[] = ['code' => 'context_hash_mismatch', 'field' => $field];
            }
            $fieldMatrix[] = $this->field($field, $matches, $matches ? null : 'context_hash_mismatch');
        }

        foreach (self::FORBIDDEN_RUNTIME_FLAGS as $flag) {
            $flagSet = (bool) ($receipt[$flag] ?? false) === true;
            if ($flagSet) {
                $violations[] = ['code' => 'forbidden_flag_in_human_completion_receipt', 'flag' => $flag];
            }
            $fieldMatrix[] = $this->field($flag, ! $flagSet, $flagSet ? 'forbidden_flag_in_human_completion_receipt' : null);
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
            'field_verification_matrix' => $fieldMatrix,
            'completion_claim_allowed' => $status === 'passed',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $verifierHashInput = $payload;
        unset($verifierHashInput['verified_at'], $verifierHashInput['verification_hash']);
        $payload['verification_hash'] = ReadinessHash::stable(ReadinessHash::ksortRecursive($verifierHashInput));

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

    /** @return array<string, mixed> */
    private function field(string $name, bool $ok, ?string $blockingReason): array
    {
        return ['field' => $name, 'ok' => $ok, 'blocking_reason' => $blockingReason];
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        return in_array(strtolower(trim($signedBy)), [
            '',
            '<operator>',
            '<operator_name>',
            '<operator_full_name>',
            'operator',
            'human',
            'codex',
            'codex-autosigned',
            'claude',
            'assistant',
            'system',
            'atlas',
            'seu_nome',
            'seu nome',
            '<operador>',
            'operador',
        ], true);
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
            'autosigned',
            'lorem',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isExternalCompletionClaimReason(string $reason): bool
    {
        $normalized = strtolower(trim($reason));
        foreach ([
            'gemini disse',
            'gemini said',
            'claude disse',
            'claude said',
            'codex disse',
            'codex said',
            'agente externo disse',
            'external agent said',
            'external completion claim',
            'claim externo',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
}
