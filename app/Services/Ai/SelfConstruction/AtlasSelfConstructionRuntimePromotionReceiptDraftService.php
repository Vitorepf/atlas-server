<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRuntimePromotionReceiptDraftService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_receipt_draft.v1';

    public const MODE = 'read_only_runtime_promotion_receipt_draft';

    /**
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $runtimeGapMatrix, array $options = []): array
    {
        $rows = (array) data_get($runtimeGapMatrix, 'rows', []);
        $gapRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) ($row['runtime_y'] ?? false) === false,
        ));
        $candidateRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false) === true
                && (bool) ($row['runtime_y'] ?? false) === false,
        ));
        $signedBy = trim((string) ($options['signed_by'] ?? ''));
        $reason = trim((string) ($options['reason'] ?? ''));
        $receiptId = trim((string) ($options['receipt_id'] ?? ''));
        if ($receiptId === '') {
            $receiptId = 'operator-runtime-promotion-draft-'.CarbonImmutable::now()->format('YmdHis');
        }

        $graduationHashes = [];
        foreach ($gapRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $graduationHashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }

        $receipt = [
            'receipt_id' => $receiptId,
            'signed_by' => $signedBy === '' ? '<operator>' : $signedBy,
            'reason' => $reason === '' ? '<operator_reason_minimum_32_chars>' : $reason,
            'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            'promoted_gap_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows)),
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
            receipt: $receipt,
            rows: $rows,
            expectedRuntimePromotionBasisHash: (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
            expectedRuntimeGapMatrixHash: (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            expectedRuntimePromotionClosureBasisHash: (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            loadLatestWhenEmpty: false,
        );
        $missingOperatorInputs = [];
        if ($this->isPlaceholderSigner((string) $receipt['signed_by'])) {
            $missingOperatorInputs[] = 'signed_by';
        }
        if (mb_strlen(trim((string) $receipt['reason'])) < 32 || str_starts_with((string) $receipt['reason'], '<')) {
            $missingOperatorInputs[] = 'reason';
        }

        $ready = $missingOperatorInputs === [] && (string) data_get($verification, 'status') === 'passed';
        $persistRequested = (bool) ($options['persist_runtime_promotion_receipt'] ?? false);
        $persistence = $persistRequested && $ready
            ? (new AtlasSelfConstructionRuntimePromotionReceiptService)->persist(
                receipt: $receipt,
                rows: $rows,
                expectedRuntimePromotionBasisHash: (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                expectedRuntimeGapMatrixHash: (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                expectedRuntimePromotionClosureBasisHash: (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            )
            : [];
        $persisted = (bool) data_get($persistence, 'persisted', false);
        $persistenceBlocker = '';
        if ($persistRequested && ! $ready) {
            $persistenceBlocker = 'runtime_promotion_receipt_draft_not_ready_for_persistence';
        } elseif ($persistRequested && ! $persisted) {
            $persistenceBlocker = (string) data_get($persistence, 'persistence_blocker', 'runtime_promotion_receipt_persistence_failed');
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $persisted ? 'persisted' : ($ready ? 'ready_for_operator_persistence' : 'blocked_operator_input_required'),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'receipt_payload' => $receipt,
            'receipt_hash' => (string) $receipt['receipt_hash'],
            'verification' => $verification,
            'persistence' => $persistence,
            'persistence_requested' => $persistRequested,
            'persisted' => $persisted,
            'persistence_blocker' => $persistenceBlocker,
            'receipt_path' => (string) data_get($persistence, 'receipt_path', ''),
            'missing_operator_inputs' => $missingOperatorInputs,
            'candidate_gap_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $candidateRows)),
            'candidate_count' => count($candidateRows),
            'blocked_gap_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows)),
            'blocked_gap_count' => count($gapRows),
            'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            'persistence_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                $persistRequested ? 'runtime_promotion_receipt_draft_persists_only_after_existing_verifier_passes' : 'runtime_promotion_receipt_draft_does_not_persist_receipt',
                'runtime_promotion_receipt_draft_does_not_sign_for_operator',
                'runtime_promotion_receipt_draft_does_not_enable_runtime',
                'runtime_promotion_receipt_draft_does_not_start_codex',
                'runtime_promotion_receipt_draft_does_not_call_provider',
                'runtime_promotion_receipt_draft_does_not_dispatch_work',
                'runtime_promotion_receipt_draft_does_not_spend_tokens',
            ],
            'next_action' => $persisted
                ? 'rerun_completion_evidence_status_to_apply_persisted_runtime_promotion_receipt'
                : ($ready
                ? 'operator_may_persist_receipt_through_existing_verifier'
                : 'operator_must_supply_real_signed_by_and_reason'),
        ];
        $payload['draft_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        return in_array(strtolower(trim($signedBy)), [
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

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['draft_hash'], $payload['verification']['verified_at']);

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
