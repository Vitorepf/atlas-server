<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionHumanCompletionReceiptRunbookService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_runbook.v1';

    public const MODE = 'read_only_human_completion_receipt_runbook';

    /** @return array<string, mixed> */
    public function build(array $humanCompletionTemplate): array
    {
        $requiredEvidenceFields = [
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
        $requiredAcknowledgements = [
            'os_complete_approved',
            'operator_reviewed_completion_audit',
            'no_autopromotion_acknowledged',
        ];
        $steps = [
            [
                'id' => 'verify_runtime_promotion',
                'summary' => 'Confirm the runtime gap matrix is runtime=Y after a verified runtime promotion receipt.',
                'evidence_required' => ['runtime_gap_matrix_hash'],
            ],
            [
                'id' => 'verify_real_provider_smoke',
                'summary' => 'Confirm the real provider claim-to-completion smoke passed and evidence was persisted.',
                'evidence_required' => ['completion_audit_hash'],
            ],
            [
                'id' => 'review_release_dossier_and_replay',
                'summary' => 'Review the green release dossier, replay diff, mutation guard and certification batch.',
                'evidence_required' => ['release_dossier_hash', 'replay_diff_hash', 'certification_status_batch_hash'],
            ],
            [
                'id' => 'sign_completion_receipt',
                'summary' => 'Operator signs the OS-complete receipt only after all completion audit criteria are green.',
                'evidence_required' => ['receipt_id', 'signed_by', 'reason', 'receipt_hash'],
            ],
            [
                'id' => 'persist_completion_receipt',
                'summary' => 'Persist the signed receipt through the verifier; never promote completion directly.',
                'evidence_required' => ['os_complete_approved', 'operator_reviewed_completion_audit', 'no_autopromotion_acknowledged'],
            ],
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'operator_human_completion_receipt_required',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'required_evidence_fields' => $requiredEvidenceFields,
            'required_acknowledgements' => $requiredAcknowledgements,
            'template_field_count' => count($humanCompletionTemplate),
            'template_hash' => $this->stableHash($humanCompletionTemplate),
            'steps' => $steps,
            'commands' => [
                'persist_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
                'verify_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'run_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'non_execution_guarantees' => [
                'human_completion_receipt_runbook_does_not_sign_for_operator',
                'human_completion_receipt_runbook_does_not_promote_completion',
                'human_completion_receipt_runbook_does_not_start_codex',
                'human_completion_receipt_runbook_does_not_call_provider',
                'human_completion_receipt_runbook_does_not_dispatch_work',
                'human_completion_receipt_runbook_does_not_spend_tokens',
            ],
        ];
        $payload['runbook_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['runbook_hash']);
        unset($payload['receipt_id']);

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
