<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRuntimePromotionReceiptRunbookService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_receipt_runbook.v1';

    public const MODE = 'read_only_runtime_promotion_receipt_runbook';

    /** @return array<string, mixed> */
    public function build(array $runtimePromotionTemplate): array
    {
        $requiredEvidenceFields = [
            'receipt_id',
            'signed_by',
            'reason',
            'runtime_gap_matrix_hash',
            'runtime_promotion_basis_hash',
            'runtime_promotion_closure_basis_hash',
            'promoted_gap_ids',
            'graduation_evidence_hashes',
            'receipt_hash',
        ];
        $requiredAcknowledgements = [
            'runtime_promotion_approved',
            'operator_reviewed_runtime_graduations',
            'no_runtime_autopromotion_acknowledged',
        ];
        $forbiddenFlags = [
            'execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
        ];
        $steps = [
            [
                'id' => 'review_runtime_gap_matrix',
                'summary' => 'Review the current runtime gap matrix and confirm every promoted gap id matches the current blocked rows.',
                'evidence_required' => ['runtime_gap_matrix_hash', 'promoted_gap_ids'],
            ],
            [
                'id' => 'review_graduation_hashes',
                'summary' => 'Confirm each graduation evidence hash corresponds to the current candidate runtime graduation evidence.',
                'evidence_required' => ['runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'graduation_evidence_hashes'],
            ],
            [
                'id' => 'compute_canonical_receipt_hash',
                'summary' => 'Compute receipt_hash from the completed payload through the canonical completion evidence hash service before persisting.',
                'evidence_required' => ['receipt_hash'],
            ],
            [
                'id' => 'sign_runtime_promotion',
                'summary' => 'Operator signs the runtime promotion receipt while acknowledging that runtime promotion is not direct execution.',
                'evidence_required' => ['signed_by', 'reason', 'runtime_promotion_approved', 'operator_reviewed_runtime_graduations', 'no_runtime_autopromotion_acknowledged'],
            ],
            [
                'id' => 'persist_runtime_promotion_receipt',
                'summary' => 'Persist the runtime promotion receipt through the verifier; never flip runtime flags directly.',
                'evidence_required' => ['all_required_fields_present', 'forbidden_flags_false'],
            ],
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'operator_runtime_promotion_receipt_required',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'required_evidence_fields' => $requiredEvidenceFields,
            'required_acknowledgements' => $requiredAcknowledgements,
            'forbidden_flags' => $forbiddenFlags,
            'template_field_count' => count($runtimePromotionTemplate),
            'template_hash' => $this->stableHash($runtimePromotionTemplate),
            'steps' => $steps,
            'commands' => [
                'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'verify_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'run_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'run_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            ],
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'non_execution_guarantees' => [
                'runtime_promotion_receipt_runbook_does_not_sign_for_operator',
                'runtime_promotion_receipt_runbook_does_not_enable_runtime',
                'runtime_promotion_receipt_runbook_does_not_start_codex',
                'runtime_promotion_receipt_runbook_does_not_call_provider',
                'runtime_promotion_receipt_runbook_does_not_dispatch_work',
                'runtime_promotion_receipt_runbook_does_not_spend_tokens',
            ],
        ];
        $payload['runbook_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
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
