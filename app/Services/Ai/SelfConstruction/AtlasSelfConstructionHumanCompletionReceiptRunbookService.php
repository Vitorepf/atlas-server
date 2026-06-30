<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\ReadinessHash;
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
            'runtime_promotion_receipt_hash',
            'real_provider_smoke_hash',
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
                'evidence_required' => ['runtime_gap_matrix_hash', 'runtime_promotion_receipt_hash'],
            ],
            [
                'id' => 'verify_real_provider_smoke',
                'summary' => 'Confirm the real provider claim-to-completion smoke passed and evidence was persisted.',
                'evidence_required' => ['completion_audit_hash', 'real_provider_smoke_hash'],
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

        $templateHashInput = $humanCompletionTemplate;
        unset($templateHashInput['generated_at'], $templateHashInput['runbook_hash'], $templateHashInput['receipt_id']);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'operator_human_completion_receipt_required',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'required_evidence_fields' => $requiredEvidenceFields,
            'required_acknowledgements' => $requiredAcknowledgements,
            'template_field_count' => count($humanCompletionTemplate),
            'template_hash' => ReadinessHash::stable(ReadinessHash::ksortRecursive($templateHashInput)),
            'steps' => $steps,
            'commands' => [
                'persist_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
                'verify_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'run_completion_audit' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'run_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
                'run_completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'run_completion_audit_diagnostic' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'non_execution_guarantees' => [
                'human_completion_receipt_runbook_does_not_sign_for_operator',
                'human_completion_receipt_runbook_does_not_promote_completion',
                'human_completion_receipt_runbook_does_not_start_codex',
                'human_completion_receipt_runbook_does_not_call_provider',
                'human_completion_receipt_runbook_does_not_dispatch_work',
                'human_completion_receipt_runbook_does_not_spend_tokens',
            ],
        ];
        $runbookHashInput = $payload;
        unset($runbookHashInput['generated_at'], $runbookHashInput['runbook_hash'], $runbookHashInput['receipt_id']);
        $payload['runbook_hash'] = ReadinessHash::stable(ReadinessHash::ksortRecursive($runbookHashInput));

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

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$this->terminalLoopOperationalProofCanonicalBindingPath().' --json';
    }

    private function terminalLoopOperationalProofCanonicalBindingPath(): string
    {
        return 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
    }

    /** @param array<string, mixed> $value */
}
