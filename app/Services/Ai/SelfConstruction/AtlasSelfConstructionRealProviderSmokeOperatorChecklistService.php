<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeOperatorChecklistService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_operator_checklist.v1';

    public const MODE = 'read_only_real_provider_smoke_operator_checklist';

    /** @return array<string, mixed> */
    public function build(): array
    {
        $phases = [
            $this->beforeProviderCall(),
            $this->duringProviderCall(),
            $this->afterProviderCall(),
            $this->evidenceCollection(),
            $this->verifierSubmission(),
            $this->persistence(),
            $this->auditRerun(),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'phases' => $phases,
            'phase_ids' => array_map(static fn (array $phase): string => (string) $phase['id'], $phases),
            'item_count' => array_sum(array_map(static fn (array $phase): int => count((array) $phase['items']), $phases)),
            'anti_cheat_policy' => [
                'no_synthetic_provider_run_accepted' => true,
                'operator_must_observe_provider_call' => true,
                'persistence_requires_explicit_flag' => true,
            ],
            'non_execution_guarantees' => [
                'does_not_call_provider' => true,
                'does_not_spend_tokens' => true,
                'does_not_dispatch' => true,
                'does_not_persist_smoke' => true,
                'does_not_promote_completion' => true,
            ],
            'persistence_allowed_here' => false,
        ];
        $payload['checklist_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function beforeProviderCall(): array
    {
        return [
            'id' => 'before_provider_call',
            'summary' => 'Get explicit operator approval and scope before any provider request.',
            'items' => [
                [
                    'id' => 'sign_operator_approval_receipt',
                    'rule' => 'Operator signs an approval receipt for exactly one bounded real provider smoke.',
                    'evidence_field' => 'operator_approval_receipt_hash',
                    'stop_condition' => 'do_not_proceed_without_approval_receipt_hash',
                ],
                [
                    'id' => 'select_single_task_packet',
                    'rule' => 'Select exactly one task packet for claim-to-completion exercise.',
                    'evidence_field' => 'task_packet_id',
                    'stop_condition' => 'stop_if_more_than_one_packet_selected',
                ],
                [
                    'id' => 'declare_observer_identity',
                    'rule' => 'Declare who is observing the smoke (operator or reviewer, never a placeholder).',
                    'evidence_field' => 'observed_by',
                    'stop_condition' => 'stop_if_observer_is_placeholder_or_codex',
                ],
            ],
            'commands' => [
                'review_offline_harness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function duringProviderCall(): array
    {
        return [
            'id' => 'during_provider_call',
            'summary' => 'Observe the live provider call and capture the run identity.',
            'items' => [
                [
                    'id' => 'capture_provider_run_id',
                    'rule' => 'Capture provider_run_id assigned by the real provider during the live call.',
                    'evidence_field' => 'provider_run_id',
                    'stop_condition' => 'stop_if_provider_run_id_missing',
                ],
                [
                    'id' => 'confirm_provider_call_observed',
                    'rule' => 'Confirm provider_call_observed=true with a human-visible artifact.',
                    'evidence_field' => 'provider_call_observed',
                    'stop_condition' => 'stop_if_provider_call_not_observed',
                ],
                [
                    'id' => 'confirm_token_spend_observed',
                    'rule' => 'Confirm token_spend_observed=true with the provider response usage metadata.',
                    'evidence_field' => 'token_spend_observed',
                    'stop_condition' => 'stop_if_no_token_spend_visible',
                ],
            ],
            'commands' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function afterProviderCall(): array
    {
        return [
            'id' => 'after_provider_call',
            'summary' => 'Verify the claim-to-completion outcome and lock the observation flags.',
            'items' => [
                [
                    'id' => 'confirm_claim_to_completion_observed',
                    'rule' => 'Confirm the packet went from claim to completion under the smoke.',
                    'evidence_field' => 'claim_to_completion_observed',
                    'stop_condition' => 'stop_if_packet_did_not_reach_completion',
                ],
                [
                    'id' => 'confirm_work_product_collected',
                    'rule' => 'Confirm work_product_collected=true after capturing the manifest.',
                    'evidence_field' => 'work_product_collected',
                    'stop_condition' => 'stop_if_no_work_product_captured',
                ],
                [
                    'id' => 'reject_atlas_runtime_flags',
                    'rule' => 'Ensure provider_called_by_atlas, token_spent_by_atlas, dispatch_allowed, adapter_execution_allowed, self_programming_allowed and completion_claim_promoted_without_receipt are all false.',
                    'evidence_field' => 'forbidden_flags',
                    'stop_condition' => 'stop_if_any_forbidden_flag_true',
                ],
            ],
            'commands' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceCollection(): array
    {
        return [
            'id' => 'evidence_collection',
            'summary' => 'Hash every required artifact deterministically.',
            'items' => [
                [
                    'id' => 'hash_provider_response',
                    'rule' => 'Hash the provider response payload to 64-hex.',
                    'evidence_field' => 'provider_response_hash',
                    'stop_condition' => 'stop_if_provider_response_hash_invalid',
                ],
                [
                    'id' => 'hash_cost_event',
                    'rule' => 'Hash the cost event from the operator-side ledger.',
                    'evidence_field' => 'cost_event_hash',
                    'stop_condition' => 'stop_if_cost_event_hash_invalid',
                ],
                [
                    'id' => 'hash_work_product_manifest',
                    'rule' => 'Hash the work product manifest captured during the run.',
                    'evidence_field' => 'work_product_manifest_hash',
                    'stop_condition' => 'stop_if_work_product_manifest_hash_invalid',
                ],
                [
                    'id' => 'hash_continuation_summary',
                    'rule' => 'Hash the continuation summary written after the run.',
                    'evidence_field' => 'continuation_summary_hash',
                    'stop_condition' => 'stop_if_continuation_summary_hash_invalid',
                ],
                [
                    'id' => 'hash_evidence_ledger',
                    'rule' => 'Hash the evidence ledger entry that records the smoke.',
                    'evidence_field' => 'evidence_ledger_hash',
                    'stop_condition' => 'stop_if_evidence_ledger_hash_invalid',
                ],
            ],
            'commands' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function verifierSubmission(): array
    {
        return [
            'id' => 'verifier_submission',
            'summary' => 'Compute smoke_hash and run the pre-submission verifier before persistence.',
            'items' => [
                [
                    'id' => 'compute_smoke_hash',
                    'rule' => 'Compute smoke_hash via AtlasSelfConstructionCompletionEvidenceHashService::realProviderSmokeHash.',
                    'evidence_field' => 'smoke_hash',
                    'stop_condition' => 'stop_if_smoke_hash_mismatch',
                ],
                [
                    'id' => 'run_pre_submission_verifier',
                    'rule' => 'Run the pre-submission verifier and resolve every diagnostic before submitting.',
                    'evidence_field' => 'pre_submission_diagnostics',
                    'stop_condition' => 'stop_if_pre_submission_diagnostics_non_empty',
                ],
            ],
            'commands' => [
                'run_pre_submission_verifier' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-pre-submission-verifier-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function persistence(): array
    {
        return [
            'id' => 'persistence',
            'summary' => 'Persist the smoke only through the existing certifier and only with explicit flag.',
            'items' => [
                [
                    'id' => 'persist_via_explicit_flag',
                    'rule' => 'Use --persist-completion-evidence and only after pre-submission verifier passes.',
                    'evidence_field' => 'smoke_persistence',
                    'stop_condition' => 'never_persist_without_explicit_flag',
                ],
            ],
            'commands' => [
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function auditRerun(): array
    {
        return [
            'id' => 'audit_rerun',
            'summary' => 'Re-run completion evidence status, refresh Terminal Loop Operational Proof, and rerun completion audit to count the new smoke.',
            'items' => [
                [
                    'id' => 'rerun_completion_evidence_status',
                    'rule' => 'Refresh completion evidence status so the new smoke is counted.',
                    'evidence_field' => 'completion_evidence_status',
                    'stop_condition' => 'stop_if_status_not_refreshed',
                ],
                [
                    'id' => 'refresh_terminal_loop_operational_proof',
                    'rule' => 'Refresh Terminal Loop Operational Proof and retain its audit binding packet before any final completion audit.',
                    'evidence_field' => 'terminal_loop_operational_proof_audit_binding_packet',
                    'stop_condition' => 'stop_if_terminal_loop_operational_proof_not_passed',
                ],
                [
                    'id' => 'rerun_completion_audit',
                    'rule' => 'Re-run completion audit with the Terminal Loop Operational Proof binding and confirm end_to_end_real_provider_smoke_green is no longer in failed_criteria.',
                    'evidence_field' => 'completion_audit',
                    'stop_condition' => 'stop_if_blocker_still_failing',
                ],
            ],
            'commands' => [
                'rerun_completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'rerun_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            ],
        ];
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
        unset($payload['generated_at'], $payload['checklist_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
