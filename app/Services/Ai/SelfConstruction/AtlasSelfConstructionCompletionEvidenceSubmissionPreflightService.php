<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_evidence_submission_preflight.v1';

    public const MODE = 'read_only_completion_evidence_submission_preflight';

    /** @return array<string, mixed> */
    public function build(array $completionAudit, array $completionEvidence, array $blockerExplainer): array
    {
        $runtimeReceiptReady = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status') === 'passed'
            && (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false);
        $realProviderSmokeReady = (string) data_get($completionEvidence, 'real_provider_smoke.status') === 'passed';
        $humanReceiptReady = (string) data_get($completionEvidence, 'human_signed_completion_receipt.status') === 'passed';
        $completionAuditReady = (string) data_get($completionAudit, 'status') === 'complete'
            && (bool) data_get($completionAudit, 'completion_allowed', false);

        $orderedSteps = [
            $this->step(
                id: 'runtime_promotion_receipt',
                status: (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'blocked'),
                ready: $runtimeReceiptReady,
                command: (string) data_get($blockerExplainer, 'command_plan.draft_runtime_promotion_receipt', ''),
                persistCommand: (string) data_get($blockerExplainer, 'command_plan.persist_runtime_promotion_receipt', ''),
                requiredBefore: [],
                evidenceHash: (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', ''),
                blockers: (array) data_get($completionEvidence, 'runtime_gap_matrix.blocked_gap_ids', []),
            ),
            $this->step(
                id: 'real_provider_smoke',
                status: (string) data_get($completionEvidence, 'real_provider_smoke.status', 'blocked'),
                ready: $realProviderSmokeReady,
                command: (string) data_get($blockerExplainer, 'command_plan.draft_real_provider_smoke', ''),
                persistCommand: (string) data_get($blockerExplainer, 'command_plan.persist_real_provider_smoke', ''),
                requiredBefore: ['runtime_promotion_receipt'],
                evidenceHash: (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', ''),
                blockers: (array) data_get($completionEvidence, 'real_provider_smoke.violations', []),
            ),
            $this->step(
                id: 'completion_evidence_hash_composition',
                status: $runtimeReceiptReady && $realProviderSmokeReady ? 'ready_for_operator_hash_composition' : 'waiting_for_runtime_receipt_and_real_provider_smoke',
                ready: $runtimeReceiptReady && $realProviderSmokeReady,
                command: (string) data_get($blockerExplainer, 'command_plan.compose_completion_evidence_hashes', ''),
                persistCommand: '',
                requiredBefore: ['runtime_promotion_receipt', 'real_provider_smoke'],
                evidenceHash: '',
                blockers: [],
            ),
            $this->step(
                id: 'human_completion_receipt',
                status: (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', 'blocked'),
                ready: $humanReceiptReady,
                command: (string) data_get($blockerExplainer, 'command_plan.draft_human_completion_receipt', ''),
                persistCommand: (string) data_get($blockerExplainer, 'command_plan.persist_human_completion_receipt', ''),
                requiredBefore: ['runtime_promotion_receipt', 'real_provider_smoke', 'completion_evidence_hash_composition'],
                evidenceHash: (string) data_get($completionEvidence, 'human_signed_completion_receipt.receipt_hash', ''),
                blockers: (array) data_get($completionEvidence, 'human_signed_completion_receipt.violations', []),
            ),
            $this->step(
                id: 'final_completion_audit',
                status: (string) data_get($completionAudit, 'status', 'incomplete'),
                ready: $completionAuditReady,
                command: (string) data_get($blockerExplainer, 'command_plan.completion_audit', ''),
                persistCommand: '',
                requiredBefore: ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
                evidenceHash: (string) data_get($completionAudit, 'completion_audit_hash', ''),
                blockers: (array) data_get($completionAudit, 'failed_criteria', []),
            ),
        ];

        $firstBlocked = collect($orderedSteps)->first(
            static fn (array $step): bool => $step['ready'] !== true && $step['id'] !== 'completion_evidence_hash_composition',
        );
        $readySteps = count(array_filter($orderedSteps, static fn (array $step): bool => (bool) $step['ready']));
        $status = $firstBlocked === null ? 'ready_for_final_completion_audit' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'ordered_steps' => $orderedSteps,
            'operator_execution_plan' => $this->operatorExecutionPlan($orderedSteps, $firstBlocked, $blockerExplainer),
            'operator_handoff_packet' => $this->operatorHandoffPacket($orderedSteps, $firstBlocked, $blockerExplainer),
            'step_count' => count($orderedSteps),
            'ready_step_count' => $readySteps,
            'blocked_step_count' => count($orderedSteps) - $readySteps,
            'next_required_submission' => $firstBlocked['id'] ?? 'rerun_completion_audit',
            'next_required_command' => (string) ($firstBlocked['command'] ?? data_get($blockerExplainer, 'command_plan.completion_audit', '')),
            'next_required_persist_command' => (string) ($firstBlocked['persist_command'] ?? ''),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
            'blocker_explainer_hash' => (string) data_get($blockerExplainer, 'explainer_hash', ''),
            'operator_required' => true,
            'real_provider_required' => in_array('end_to_end_real_provider_smoke_green', (array) data_get($completionAudit, 'failed_criteria', []), true),
            'non_execution_guarantees' => [
                'completion_evidence_submission_preflight_does_not_persist_receipts',
                'completion_evidence_submission_preflight_does_not_call_provider',
                'completion_evidence_submission_preflight_does_not_spend_tokens',
                'completion_evidence_submission_preflight_does_not_dispatch_work',
                'completion_evidence_submission_preflight_does_not_enable_runtime',
                'completion_evidence_submission_preflight_does_not_promote_completion',
            ],
        ];
        $payload['submission_preflight_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $requiredBefore
     * @param  array<int|string, mixed>  $blockers
     * @return array<string, mixed>
     */
    private function step(string $id, string $status, bool $ready, string $command, string $persistCommand, array $requiredBefore, string $evidenceHash, array $blockers): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'ready' => $ready,
            'command' => $command,
            'persist_command' => $persistCommand,
            'required_before' => $requiredBefore,
            'evidence_hash' => $evidenceHash,
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orderedSteps
     * @param  array<string, mixed>|null  $firstBlocked
     * @param  array<string, mixed>  $blockerExplainer
     * @return array<string, mixed>
     */
    private function operatorExecutionPlan(array $orderedSteps, ?array $firstBlocked, array $blockerExplainer): array
    {
        $stepsById = collect($orderedSteps)->keyBy('id');
        $currentStep = (string) ($firstBlocked['id'] ?? 'final_completion_audit');

        return [
            'plan_version' => 'atlas.self_construction.operator_final_evidence_execution_plan.v1',
            'current_step' => $currentStep,
            'current_step_ready' => (bool) data_get($stepsById, $currentStep.'.ready', false),
            'operator_must_follow_order' => true,
            'parallel_submission_allowed' => false,
            'why_not_parallel' => 'Runtime promotion, real provider smoke and final human completion receipt are hash-bound in sequence; submitting them out of order risks stale signatures or same-command completion promotion.',
            'ordered_command_queue' => array_map(static function (array $step): array {
                return [
                    'id' => (string) $step['id'],
                    'ready' => (bool) $step['ready'],
                    'status' => (string) $step['status'],
                    'draft_or_check_command' => (string) $step['command'],
                    'persist_command' => (string) $step['persist_command'],
                    'required_before' => (array) $step['required_before'],
                    'evidence_hash' => (string) $step['evidence_hash'],
                ];
            }, $orderedSteps),
            'stop_conditions' => [
                'stop_if_any_command_returns_non_zero',
                'stop_if_receipt_hash_does_not_match_payload',
                'stop_if_current_completion_audit_hash_changes_before_persist',
                'stop_if_real_provider_smoke_aborts_or_exceeds_operator_kill_switch',
                'stop_if_any_runtime_enabling_flag_is_true_before_final_human_receipt',
            ],
            'required_reruns_after_each_persist' => [
                'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'final_success_command' => (string) data_get($blockerExplainer, 'command_plan.completion_audit', 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json'),
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'never_automatic' => [
                'operator_signature_not_generated_by_atlas',
                'real_provider_smoke_not_run_by_atlas',
                'human_completion_receipt_not_persisted_before_runtime_and_smoke_green',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orderedSteps
     * @param  array<string, mixed>|null  $firstBlocked
     * @param  array<string, mixed>  $blockerExplainer
     * @return array<string, mixed>
     */
    private function operatorHandoffPacket(array $orderedSteps, ?array $firstBlocked, array $blockerExplainer): array
    {
        $currentStep = (string) ($firstBlocked['id'] ?? 'final_completion_audit');
        $current = $firstBlocked ?? collect($orderedSteps)->firstWhere('id', 'final_completion_audit') ?? [];
        $requiredInputsByStep = [
            'runtime_promotion_receipt' => [
                'operator_signed_runtime_promotion_receipt_json',
                'runtime_gap_matrix_hash',
                'runtime_promotion_basis_hash',
                'runtime_promotion_closure_basis_hash',
                'receipt_hash',
            ],
            'real_provider_smoke' => [
                'operator_approved_real_provider_smoke_json',
                'provider_run_id',
                'task_packet_id',
                'operator_approval_receipt_hash',
                'evidence_ledger_hash',
                'work_product_manifest_hash',
                'cost_event_hash',
                'continuation_summary_hash',
                'provider_response_hash',
                'smoke_hash',
            ],
            'completion_evidence_hash_composition' => [
                'persisted_runtime_promotion_receipt_json',
                'persisted_real_provider_smoke_json',
                'draft_human_completion_receipt_json',
            ],
            'human_completion_receipt' => [
                'operator_signed_human_completion_receipt_json',
                'runtime_promotion_receipt_hash',
                'real_provider_smoke_hash',
                'completion_audit_hash',
                'receipt_hash',
            ],
            'final_completion_audit' => [
                'runtime_promotion_receipt_persisted',
                'real_provider_smoke_persisted',
                'human_completion_receipt_persisted',
            ],
        ];

        $packet = [
            'schema_version' => 'atlas.self_construction.operator_final_evidence_handoff_packet.v1',
            'current_step' => $currentStep,
            'current_status' => (string) ($current['status'] ?? 'unknown'),
            'current_step_ready' => (bool) ($current['ready'] ?? false),
            'next_draft_or_check_command' => (string) ($current['command'] ?? data_get($blockerExplainer, 'command_plan.completion_audit', '')),
            'next_persist_command' => (string) ($current['persist_command'] ?? ''),
            'required_before_current_step' => (array) ($current['required_before'] ?? []),
            'required_operator_inputs' => $requiredInputsByStep[$currentStep] ?? [],
            'current_blocker_count' => (int) ($current['blocker_count'] ?? 0),
            'current_blockers' => (array) ($current['blockers'] ?? []),
            'ordered_step_ids' => array_map(static fn (array $step): string => (string) $step['id'], $orderedSteps),
            'proof_commands_after_each_persist' => [
                'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'final_success_command' => (string) data_get($blockerExplainer, 'command_plan.completion_audit', 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json'),
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'operator_must_follow_order' => true,
            'parallel_submission_allowed' => false,
            'handoff_stop_conditions' => [
                'stop_if_current_step_is_not_the_step_being_submitted',
                'stop_if_any_required_input_is_placeholder',
                'stop_if_any_required_hash_is_not_64_hex',
                'stop_if_any_persist_command_returns_non_zero',
                'stop_if_completion_audit_hash_changes_without_regenerating_downstream_receipts',
                'stop_if_real_provider_smoke_aborts_or_hits_kill_switch',
                'stop_if_runtime_or_dispatch_flags_flip_before_human_completion_receipt',
            ],
            'non_execution_guarantees' => [
                'operator_handoff_packet_does_not_persist_receipts',
                'operator_handoff_packet_does_not_sign_for_operator',
                'operator_handoff_packet_does_not_call_provider',
                'operator_handoff_packet_does_not_spend_tokens',
                'operator_handoff_packet_does_not_dispatch_work',
                'operator_handoff_packet_does_not_promote_completion',
            ],
        ];
        $packet['handoff_packet_hash'] = $this->stableHash($packet);

        return $packet;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['submission_preflight_hash']);

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
