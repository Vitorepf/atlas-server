<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionHumanCompletionReceiptDraftService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_draft.v1';

    public const MODE = 'read_only_human_completion_receipt_draft';

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $completionAudit, array $completionEvidence, array $options = []): array
    {
        $signedBy = trim((string) ($options['signed_by'] ?? ''));
        $reason = trim((string) ($options['reason'] ?? ''));
        $receiptId = trim((string) ($options['receipt_id'] ?? ''));
        if ($receiptId === '') {
            $receiptId = 'operator-os-complete-draft-'.CarbonImmutable::now()->format('YmdHis');
        }

        $context = $this->context($completionAudit, $completionEvidence);
        $receipt = [
            'receipt_id' => $receiptId,
            'signed_by' => $signedBy === '' ? '<operator>' : $signedBy,
            'reason' => $reason === '' ? '<operator_reason_minimum_32_chars>' : $reason,
            'completion_audit_hash' => $context['completion_audit_hash'],
            'release_dossier_hash' => $context['release_dossier_hash'],
            'replay_diff_hash' => $context['replay_diff_hash'],
            'runtime_gap_matrix_hash' => $context['runtime_gap_matrix_hash'],
            'runtime_promotion_receipt_hash' => $context['runtime_promotion_receipt_hash'],
            'real_provider_smoke_hash' => $context['real_provider_smoke_hash'],
            'certification_status_batch_hash' => $context['certification_status_batch_hash'],
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_autopromoted' => false,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $verification = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($receipt, $context);
        $missingOperatorInputs = [];
        if ($this->isPlaceholderSigner((string) $receipt['signed_by'])) {
            $missingOperatorInputs[] = 'signed_by';
        }
        if (mb_strlen(trim((string) $receipt['reason'])) < 32 || $this->isPlaceholderValue((string) $receipt['reason'])) {
            $missingOperatorInputs[] = 'reason';
        }

        $missingEvidence = array_values(array_filter(array_keys($context), static fn (string $field): bool => preg_match('/^[a-f0-9]{64}$/', (string) $context[$field]) !== 1));
        $failedPrerequisites = $this->failedPrerequisites($completionAudit, $completionEvidence);
        $ready = $missingOperatorInputs === []
            && $missingEvidence === []
            && $failedPrerequisites === []
            && (string) data_get($verification, 'status') === 'passed';
        $persistRequested = (bool) ($options['persist_completion_evidence'] ?? false);
        $persistence = $persistRequested && $ready
            ? (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->persist($receipt, $context)
            : [];
        $persisted = (bool) data_get($persistence, 'persisted', false);
        $persistenceBlocker = '';
        if ($persistRequested && ! $ready) {
            $persistenceBlocker = 'human_completion_receipt_draft_not_ready_for_persistence';
        } elseif ($persistRequested && ! $persisted) {
            $persistenceBlocker = (string) data_get($persistence, 'persistence_blocker', 'human_completion_receipt_persistence_failed');
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $persisted ? 'persisted' : ($ready ? 'ready_for_operator_persistence' : 'blocked_operator_or_evidence_input_required'),
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
            'missing_evidence_hashes' => $missingEvidence,
            'failed_prerequisites' => $failedPrerequisites,
            'context' => $context,
            'completion_audit_status' => (string) data_get($completionAudit, 'status', ''),
            'completion_evidence_status' => (string) data_get($completionEvidence, 'status', ''),
            'persistence_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                $persistRequested ? 'human_completion_receipt_draft_persists_only_after_existing_verifier_passes' : 'human_completion_receipt_draft_does_not_persist_receipt',
                'human_completion_receipt_draft_does_not_sign_for_operator',
                'human_completion_receipt_draft_does_not_start_codex',
                'human_completion_receipt_draft_does_not_call_provider',
                'human_completion_receipt_draft_does_not_dispatch_work',
                'human_completion_receipt_draft_does_not_spend_tokens',
                'human_completion_receipt_draft_does_not_enable_self_programming',
                'human_completion_receipt_draft_does_not_promote_completion',
            ],
            'next_action' => $persisted
                ? 'rerun_completion_audit_to_count_human_completion_receipt'
                : ($ready ? 'operator_may_persist_human_completion_receipt_through_existing_verifier' : 'operator_must_supply_missing_inputs_and_real_evidence_hashes'),
        ];
        $payload['draft_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, string> */
    private function context(array $completionAudit, array $completionEvidence): array
    {
        return [
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'release_dossier_hash' => (string) data_get($completionAudit, 'criteria.1.evidence.hash', ''),
            'replay_diff_hash' => (string) data_get($completionAudit, 'criteria.2.evidence.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', data_get($completionAudit, 'criteria.0.evidence.runtime_gap_matrix_hash', '')),
            'runtime_promotion_receipt_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', ''),
            'real_provider_smoke_hash' => (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', ''),
            'certification_status_batch_hash' => (string) data_get($completionEvidence, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash', ''),
        ];
    }

    /** @return list<string> */
    private function failedPrerequisites(array $completionAudit, array $completionEvidence): array
    {
        $failed = [];
        if ((bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false) !== true) {
            $failed[] = 'runtime_gap_matrix_all_runtime_y';
        }
        if ((string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', '') !== 'passed') {
            $failed[] = 'runtime_promotion_receipt_present';
        }
        if ((string) data_get($completionEvidence, 'real_provider_smoke.status', '') !== 'passed') {
            $failed[] = 'end_to_end_real_provider_smoke_green';
        }
        foreach (['release_dossier_green', 'replay_diff_against_completion_snapshot_green', 'promotion_gate_green', 'mutation_guard_green', 'certification_status_batch_green'] as $criterion) {
            $match = collect((array) data_get($completionAudit, 'criteria', []))->first(static fn (array $row): bool => (string) ($row['id'] ?? '') === $criterion);
            if (! is_array($match) || (bool) ($match['passed'] ?? false) !== true) {
                $failed[] = $criterion;
            }
        }

        return array_values(array_unique($failed));
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
        ], true) || $this->isPlaceholderValue($normalized);
    }

    private function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || str_starts_with($normalized, '<') || str_starts_with($normalized, '__')) {
            return true;
        }

        foreach ([
            'seu_nome',
            'seu nome',
            'operador',
            'motivo real',
            'pelo menos 32 caracteres',
            'substitua',
            'placeholder',
            'todo',
            'synthetic',
            'fixture-only',
            'fixture_only',
            'test_only',
            'test-only',
            'fake',
            'simulated',
            'mock-',
            'dummy',
        ] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['draft_hash'], $payload['verification']['verified_at']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
