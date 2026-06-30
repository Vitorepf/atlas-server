<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\ReadinessHash;
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

        if ($this->isPlaceholderSigner((string) ($receipt['signed_by'] ?? ''))) {
            $violations[] = ['code' => 'human_completion_receipt_signer_invalid_or_placeholder'];
        }
        if ($this->isPlaceholderReason((string) ($receipt['reason'] ?? ''))) {
            $violations[] = ['code' => 'human_completion_receipt_reason_placeholder'];
        }
        if ($this->isExternalCompletionClaimReason((string) ($receipt['reason'] ?? ''))) {
            $violations[] = ['code' => 'human_completion_receipt_reason_relies_on_external_agent_claim'];
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
