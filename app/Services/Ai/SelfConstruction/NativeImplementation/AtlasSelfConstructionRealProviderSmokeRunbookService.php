<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

final class AtlasSelfConstructionRealProviderSmokeRunbookService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_runbook.v1';

    public const MODE = 'read_only_real_provider_smoke_runbook';

    /** @return array<string, mixed> */
    public function build(array $realProviderSmokeTemplate): array
    {
        $requiredEvidenceFields = [
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
            'smoke_hash',
            'operator_approval_receipt_hash',
            'evidence_ledger_hash',
            'work_product_manifest_hash',
            'cost_event_hash',
            'continuation_summary_hash',
            'provider_response_hash',
        ];
        $requiredObservationFlags = [
            'provider_call_observed',
            'token_spend_observed',
            'claim_to_completion_observed',
            'work_product_collected',
            'operator_supplied_evidence',
            'real_provider_run_observed_by_operator',
        ];
        $forbiddenFlags = [
            'provider_called_by_atlas',
            'token_spent_by_atlas',
            'dispatch_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
            'completion_claim_promoted_without_receipt',
        ];
        $steps = [
            [
                'id' => 'operator_approval',
                'summary' => 'Operator approves a bounded real provider claim-to-completion smoke before any provider call.',
                'evidence_required' => ['operator_approval_receipt_hash'],
            ],
            [
                'id' => 'run_single_packet',
                'summary' => 'Run exactly one scoped task packet through the real provider path and record provider run identity.',
                'evidence_required' => ['provider_run_id', 'task_packet_id', 'provider_response_hash'],
            ],
            [
                'id' => 'collect_work_product_and_cost',
                'summary' => 'Collect work product manifest, cost event and continuation summary from the observed run.',
                'evidence_required' => ['work_product_manifest_hash', 'cost_event_hash', 'continuation_summary_hash'],
            ],
            [
                'id' => 'write_evidence_ledger',
                'summary' => 'Write or attach the evidence ledger hash proving the smoke observations.',
                'evidence_required' => ['evidence_ledger_hash', 'smoke_hash'],
            ],
            [
                'id' => 'persist_certification_payload',
                'summary' => 'Persist the completed smoke payload only through the verifier.',
                'evidence_required' => ['all_required_fields_present', 'all_required_observations_true', 'operator_acknowledgements_true', 'forbidden_flags_false'],
            ],
        ];
        $preflight = [
            'operator_must_explicitly_approve_before_any_provider_call' => true,
            'operator_approval_receipt_required' => true,
            'kill_switch_must_be_understood_before_run' => true,
            'token_budget_must_be_declared_before_run' => true,
            'workspace_must_be_isolated_before_run' => true,
            'required_preflight_evidence_fields' => [
                'operator_approval_receipt_hash',
                'task_packet_id',
                'provider_run_id',
            ],
            'commands' => [
                'inspect_completion_audit_before_running' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'inspect_terminal_loop_operational_proof_before_running' => $this->terminalLoopOperationalProofCommand(),
                'inspect_release_dossier_before_running' => 'php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-status --json',
                'inspect_offline_harness_before_running' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
            ],
        ];
        $killSwitch = [
            'abort_command' => 'operator may abort the real provider run at any time; Atlas does not own provider processes',
            'atlas_side_abort_diagnostic_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/aborted-smoke-preimage.json --json',
            'cancel_must_record_aborted_status' => true,
            'token_ceiling_hard_stop' => 'if provider exceeds the declared token ceiling, abort; do not persist a passed smoke',
            'wallclock_ceiling_seconds_recommended' => 1800,
            'forbidden_after_abort' => [
                'persist_passed_real_provider_smoke',
                'persist_human_completion_receipt',
                'promote_completion_claim',
                'auto_retry_without_operator_approval',
            ],
        ];
        $rollbackExpectations = [
            'no_atlas_owned_state_mutated' => true,
            'aborted_smoke_must_be_recorded_as_non_passing_operator_evidence' => true,
            'aborted_smoke_must_not_be_persisted_as_passed_completion_evidence' => true,
            'no_completion_evidence_persistence_for_aborted_smoke' => true,
            'partial_evidence_treated_as_aborted' => true,
            'required_post_abort_evidence' => [
                'operator_approval_receipt_hash',
                'aborted_status',
                'aborted_reason',
                'partial_provider_run_id_if_any',
            ],
            'next_actions_after_abort' => [
                'inspect_provider_billing_separately',
                'inspect_workspace_for_partial_writes',
                'reset_workspace_before_retry',
                'rerun_completion_audit_to_confirm_no_false_pass',
            ],
            'rerun_completion_audit_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'rerun_completion_audit_with_terminal_loop_operational_proof_command' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
        ];
        $tokenCostCaptureRequirements = [
            'required_cost_event_fields' => ['provider_run_id', 'model', 'input_tokens', 'output_tokens', 'cost_usd'],
            'cost_event_hash_required' => true,
            'cost_event_must_be_stored_before_smoke_passes' => true,
            'cost_event_command' => 'php artisan atlas:ai:self-construction --agent-cost-event --actor=<operator> --session=<session> --model=<model> --input-tokens=<n> --output-tokens=<n> --cost-usd=<n> --json',
        ];
        $workProductCollectionRequirements = [
            'required_work_product_fields' => ['artifact_type', 'artifact_path', 'artifact_hash'],
            'work_product_manifest_hash_required' => true,
            'work_product_must_be_collected_before_smoke_passes' => true,
            'work_product_command' => 'php artisan atlas:ai:self-construction --agent-work-product --actor=<operator> --session=<session> --artifact-type=<type> --artifact-path=<path> --artifact-hash=<sha256> --summary="<short>" --json',
        ];
        $templateForHash = $realProviderSmokeTemplate;
        unset($templateForHash['generated_at'], $templateForHash['runbook_hash']);
        $templateHash = hash('sha256', (string) json_encode(ReadinessHash::ksortRecursive($templateForHash), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'operator_real_provider_smoke_required',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'required_evidence_fields' => $requiredEvidenceFields,
            'required_observation_flags' => $requiredObservationFlags,
            'forbidden_flags' => $forbiddenFlags,
            'preflight' => $preflight,
            'kill_switch' => $killSwitch,
            'rollback_expectations' => $rollbackExpectations,
            'token_cost_capture_requirements' => $tokenCostCaptureRequirements,
            'work_product_collection_requirements' => $workProductCollectionRequirements,
            'template_field_count' => count($realProviderSmokeTemplate),
            'template_hash' => $templateHash,
            'steps' => $steps,
            'commands' => [
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
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
                'real_provider_smoke_runbook_does_not_call_provider',
                'real_provider_smoke_runbook_does_not_spend_tokens',
                'real_provider_smoke_runbook_does_not_dispatch_work',
                'real_provider_smoke_runbook_does_not_start_codex',
                'real_provider_smoke_runbook_does_not_promote_completion',
            ],
        ];
        $payloadForHash = $payload;
        unset($payloadForHash['generated_at'], $payloadForHash['runbook_hash']);
        $payload['runbook_hash'] = hash('sha256', (string) json_encode(ReadinessHash::ksortRecursive($payloadForHash), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
