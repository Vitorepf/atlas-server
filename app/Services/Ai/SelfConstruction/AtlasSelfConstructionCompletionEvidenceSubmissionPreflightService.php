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
        $completionAuditBlockerSummary = $this->completionAuditBlockerSummary($completionAudit, $blockerExplainer);

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

        $operatorExecutionPlan = $this->operatorExecutionPlan($orderedSteps, $firstBlocked, $blockerExplainer, $completionAuditBlockerSummary);
        $resumptionCheckpoint = $this->operatorResumptionCheckpoint($orderedSteps, $firstBlocked, $completionAudit, $completionEvidence, $blockerExplainer, $completionAuditBlockerSummary);
        $operatorClosureCommandReplay = $this->operatorClosureCommandReplay($orderedSteps, $firstBlocked, $completionAudit, $completionEvidence, $blockerExplainer, $resumptionCheckpoint);
        $operatorHandoffPacket = $this->operatorHandoffPacket($orderedSteps, $firstBlocked, $blockerExplainer, $completionAuditBlockerSummary, $resumptionCheckpoint, $operatorClosureCommandReplay);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'ordered_steps' => $orderedSteps,
            'completion_audit_blocker_summary' => $completionAuditBlockerSummary,
            'operator_execution_plan' => $operatorExecutionPlan,
            'operator_resumption_checkpoint' => $resumptionCheckpoint,
            'operator_closure_command_replay' => $operatorClosureCommandReplay,
            'operator_handoff_packet' => $operatorHandoffPacket,
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
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $blockerExplainer
     * @return array<string, mixed>
     */
    private function completionAuditBlockerSummary(array $completionAudit, array $blockerExplainer): array
    {
        $explainerBlockersById = collect((array) data_get($blockerExplainer, 'blockers', []))
            ->keyBy(static fn (array $blocker): string => (string) ($blocker['blocker_id'] ?? ''));

        $blockers = array_values(array_map(
            function (array $criterion) use ($explainerBlockersById): array {
                $criterionId = (string) ($criterion['id'] ?? '');
                $explainer = (array) ($explainerBlockersById[$criterionId] ?? []);

                return [
                    'id' => $criterionId,
                    'requirement' => (string) ($criterion['requirement'] ?? ''),
                    'blocker_type' => (string) ($criterion['blocker_type'] ?? 'technical'),
                    'owner' => (string) ($explainer['owner'] ?? ''),
                    'severity' => (string) ($explainer['severity'] ?? ''),
                    'why_blocking' => (string) ($criterion['why_blocking'] ?? ''),
                    'why_not_automatic' => (string) ($explainer['why_it_cannot_be_auto_closed'] ?? ''),
                    'doc_anchor' => (string) ($criterion['doc_anchor'] ?? ''),
                    'remediation_command' => (string) ($criterion['remediation_command'] ?? ''),
                    'expected_receipt_schema' => (string) ($criterion['expected_receipt_schema'] ?? ''),
                    'exact_closure_condition' => (string) ($explainer['exact_closure_condition'] ?? ''),
                    'required_evidence' => (array) ($explainer['required_evidence'] ?? []),
                    'current_evidence_context' => (array) ($explainer['current_evidence_context'] ?? []),
                ];
            },
            (array) data_get($completionAudit, 'failed_criteria_detailed', []),
        ));

        $blockersById = collect($blockers)->keyBy('id')->all();

        return [
            'schema_version' => 'atlas.self_construction.completion_audit_blocker_summary.v1',
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_audit_status' => (string) data_get($completionAudit, 'status', 'unknown'),
            'completion_allowed' => (bool) data_get($completionAudit, 'completion_allowed', false),
            'failed_count' => (int) data_get($completionAudit, 'failed_count', count($blockers)),
            'human_blocker_count' => (int) data_get($completionAudit, 'blocker_classification.human_blocker_count', 0),
            'real_provider_blocker_count' => (int) data_get($completionAudit, 'blocker_classification.real_provider_blocker_count', 0),
            'technical_blocker_count' => (int) data_get($completionAudit, 'blocker_classification.technical_blocker_count', 0),
            'human_blockers' => (array) data_get($completionAudit, 'blocker_classification.human_blockers', []),
            'real_provider_blockers' => (array) data_get($completionAudit, 'blocker_classification.real_provider_blockers', []),
            'technical_blockers' => (array) data_get($completionAudit, 'blocker_classification.technical_blockers', []),
            'blockers' => $blockers,
            'blockers_by_id' => $blockersById,
            'next_action' => (string) data_get($completionAudit, 'next_action', 'continue_implementation_until_failed_completion_criteria_have_real_evidence'),
        ];
    }

    /** @return list<string> */
    private function completionCriteriaForStep(string $stepId): array
    {
        return match ($stepId) {
            'runtime_promotion_receipt' => ['runtime_gap_matrix_all_runtime_y'],
            'real_provider_smoke' => ['end_to_end_real_provider_smoke_green'],
            'completion_evidence_hash_composition' => ['runtime_gap_matrix_all_runtime_y', 'end_to_end_real_provider_smoke_green', 'human_signed_os_complete_receipt_present'],
            'human_completion_receipt' => ['human_signed_os_complete_receipt_present'],
            'final_completion_audit' => ['runtime_gap_matrix_all_runtime_y', 'end_to_end_real_provider_smoke_green', 'human_signed_os_complete_receipt_present'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $completionAuditBlockerSummary
     * @return list<array<string, mixed>>
     */
    private function classifiedBlockersForStep(string $stepId, array $completionAuditBlockerSummary): array
    {
        $blockersById = (array) ($completionAuditBlockerSummary['blockers_by_id'] ?? []);

        return array_values(array_filter(array_map(
            static fn (string $criterionId): array => (array) ($blockersById[$criterionId] ?? []),
            $this->completionCriteriaForStep($stepId),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $orderedSteps
     * @param  array<string, mixed>|null  $firstBlocked
     * @param  array<string, mixed>  $blockerExplainer
     * @return array<string, mixed>
     */
    private function operatorExecutionPlan(array $orderedSteps, ?array $firstBlocked, array $blockerExplainer, array $completionAuditBlockerSummary): array
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
            'completion_audit_blocker_summary' => $completionAuditBlockerSummary,
            'current_step_completion_blockers_classified' => $this->classifiedBlockersForStep($currentStep, $completionAuditBlockerSummary),
            'ordered_command_queue' => array_map(function (array $step) use ($completionAuditBlockerSummary): array {
                $stepId = (string) $step['id'];

                return [
                    'id' => $stepId,
                    'ready' => (bool) $step['ready'],
                    'status' => (string) $step['status'],
                    'draft_or_check_command' => (string) $step['command'],
                    'persist_command' => (string) $step['persist_command'],
                    'required_before' => (array) $step['required_before'],
                    'evidence_hash' => (string) $step['evidence_hash'],
                    'blocks_completion_criteria' => $this->completionCriteriaForStep($stepId),
                    'classified_completion_blockers' => $this->classifiedBlockersForStep($stepId, $completionAuditBlockerSummary),
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
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @param  array<string, mixed>  $blockerExplainer
     * @param  array<string, mixed>  $completionAuditBlockerSummary
     * @return array<string, mixed>
     */
    private function operatorResumptionCheckpoint(
        array $orderedSteps,
        ?array $firstBlocked,
        array $completionAudit,
        array $completionEvidence,
        array $blockerExplainer,
        array $completionAuditBlockerSummary,
    ): array {
        $currentStep = (string) ($firstBlocked['id'] ?? 'final_completion_audit');
        $current = $firstBlocked ?? collect($orderedSteps)->firstWhere('id', 'final_completion_audit') ?? [];
        $checkpoint = [
            'schema_version' => 'atlas.self_construction.operator_final_evidence_resumption_checkpoint.v1',
            'mode' => 'read_only_operator_resumption_checkpoint',
            'current_step' => $currentStep,
            'current_status' => (string) ($current['status'] ?? 'unknown'),
            'next_required_submission' => $currentStep,
            'exact_next_command' => (string) ($current['command'] ?? data_get($blockerExplainer, 'command_plan.completion_audit', '')),
            'exact_next_persist_command' => (string) ($current['persist_command'] ?? ''),
            'can_resume_without_chat_history' => true,
            'requires_fresh_preflight_before_persist' => true,
            'requires_fresh_completion_audit_before_final_receipt' => true,
            'parallel_submission_allowed' => false,
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
            'blocker_explainer_hash' => (string) data_get($blockerExplainer, 'explainer_hash', ''),
            'current_step_evidence_hash' => (string) ($current['evidence_hash'] ?? ''),
            'current_blocks_completion_criteria' => $this->completionCriteriaForStep($currentStep),
            'current_completion_blockers_classified' => $this->classifiedBlockersForStep($currentStep, $completionAuditBlockerSummary),
            'ordered_step_statuses' => array_values(array_map(
                static fn (array $step): array => [
                    'id' => (string) ($step['id'] ?? ''),
                    'status' => (string) ($step['status'] ?? ''),
                    'ready' => (bool) ($step['ready'] ?? false),
                    'required_before' => (array) ($step['required_before'] ?? []),
                    'evidence_hash_present' => (string) ($step['evidence_hash'] ?? '') !== '',
                ],
                $orderedSteps,
            )),
            'resume_commands' => [
                'refresh_submission_preflight' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                'refresh_operator_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'refresh_final_operator_closure_corridor' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'refresh_completion_audit' => (string) data_get($blockerExplainer, 'command_plan.completion_audit', 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json'),
            ],
            'stop_conditions' => [
                'stop_if_current_step_changed_after_resume',
                'stop_if_completion_audit_hash_changed_before_persist',
                'stop_if_required_artifact_hash_is_missing_or_not_64_hex',
                'stop_if_command_contains_placeholder_at_persist_time',
                'stop_if_real_provider_smoke_aborted_or_not_operator_observed',
                'stop_if_any_runtime_or_dispatch_flag_is_true',
            ],
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'resumption_checkpoint_does_not_persist_receipts',
                'resumption_checkpoint_does_not_sign_for_operator',
                'resumption_checkpoint_does_not_call_provider',
                'resumption_checkpoint_does_not_spend_tokens',
                'resumption_checkpoint_does_not_dispatch_work',
                'resumption_checkpoint_does_not_promote_completion',
            ],
        ];
        $checkpoint['resumption_checkpoint_hash'] = $this->stableHash($checkpoint);

        return $checkpoint;
    }

    /**
     * @param  list<array<string, mixed>>  $orderedSteps
     * @param  array<string, mixed>|null  $firstBlocked
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @param  array<string, mixed>  $blockerExplainer
     * @param  array<string, mixed>  $resumptionCheckpoint
     * @return array<string, mixed>
     */
    private function operatorClosureCommandReplay(
        array $orderedSteps,
        ?array $firstBlocked,
        array $completionAudit,
        array $completionEvidence,
        array $blockerExplainer,
        array $resumptionCheckpoint,
    ): array {
        $currentStep = (string) ($firstBlocked['id'] ?? 'final_completion_audit');
        $completionAuditHash = (string) data_get($completionAudit, 'completion_audit_hash', '');
        $completionEvidenceStatusHash = (string) data_get($completionEvidence, 'completion_evidence_status_hash', '');
        $refreshSubmissionPreflight = 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json';
        $refreshCompletionAudit = (string) data_get($blockerExplainer, 'command_plan.completion_audit', 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json');

        $replaySteps = array_values(array_map(function (array $step) use ($refreshSubmissionPreflight, $refreshCompletionAudit): array {
            $stepId = (string) ($step['id'] ?? '');
            $persistCommand = (string) ($step['persist_command'] ?? '');

            return [
                'id' => $stepId,
                'status' => (string) ($step['status'] ?? ''),
                'ready' => (bool) ($step['ready'] ?? false),
                'draft_or_check_command' => (string) ($step['command'] ?? ''),
                'persist_command' => $persistCommand,
                'persist_required' => $persistCommand !== '',
                'required_before' => (array) ($step['required_before'] ?? []),
                'evidence_hash' => (string) ($step['evidence_hash'] ?? ''),
                'must_rerun_after_persist' => $persistCommand === '' ? [] : [
                    $refreshSubmissionPreflight,
                    $refreshCompletionAudit,
                ],
                'blocks_completion_criteria' => $this->completionCriteriaForStep($stepId),
            ];
        }, $orderedSteps));

        $current = collect($replaySteps)->firstWhere('id', $currentStep) ?? [];
        $replay = [
            'schema_version' => 'atlas.self_construction.operator_closure_command_replay.v1',
            'mode' => 'read_only_operator_closure_command_replay',
            'status' => $currentStep === 'final_completion_audit' ? 'ready_to_rerun_completion_audit_when_all_proofs_persisted' : 'blocked_waiting_for_operator_artifact',
            'current_step' => $currentStep,
            'current_step_index' => max(0, array_search($currentStep, array_column($replaySteps, 'id'), true)),
            'next_command' => (string) data_get($current, 'draft_or_check_command', ''),
            'next_persist_command' => (string) data_get($current, 'persist_command', ''),
            'ordered_command_replay' => $replaySteps,
            'replay_step_count' => count($replaySteps),
            'operator_must_follow_order' => true,
            'parallel_submission_allowed' => false,
            'requires_fresh_preflight_before_every_persist' => true,
            'requires_fresh_completion_audit_after_every_persist' => true,
            'can_resume_without_chat_history' => (bool) data_get($resumptionCheckpoint, 'can_resume_without_chat_history', false),
            'resumption_checkpoint_hash' => (string) data_get($resumptionCheckpoint, 'resumption_checkpoint_hash', ''),
            'hash_guards' => [
                'completion_audit_hash_at_replay_build' => $completionAuditHash,
                'completion_evidence_status_hash_at_replay_build' => $completionEvidenceStatusHash,
                'blocker_explainer_hash_at_replay_build' => (string) data_get($blockerExplainer, 'explainer_hash', ''),
                'stop_if_any_guard_hash_changes_before_persist' => true,
            ],
            'proof_commands_after_each_persist' => [
                'submission_preflight' => $refreshSubmissionPreflight,
                'operator_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit' => $refreshCompletionAudit,
            ],
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'stop_conditions' => [
                'stop_if_current_step_changed_after_replay_refresh',
                'stop_if_any_guard_hash_changes_before_persist',
                'stop_if_persist_command_is_empty_for_required_artifact',
                'stop_if_any_required_artifact_hash_is_missing_or_not_64_hex',
                'stop_if_real_provider_smoke_aborts_or_exceeds_operator_kill_switch',
                'stop_if_runtime_or_dispatch_flags_flip_before_human_completion_receipt',
            ],
            'non_execution_guarantees' => [
                'operator_closure_command_replay_does_not_persist_receipts',
                'operator_closure_command_replay_does_not_sign_for_operator',
                'operator_closure_command_replay_does_not_call_provider',
                'operator_closure_command_replay_does_not_spend_tokens',
                'operator_closure_command_replay_does_not_dispatch_work',
                'operator_closure_command_replay_does_not_promote_completion',
            ],
        ];
        $replay['command_replay_hash'] = $this->stableHash($replay);

        return $replay;
    }

    /**
     * @param  list<array<string, mixed>>  $orderedSteps
     * @param  array<string, mixed>|null  $firstBlocked
     * @param  array<string, mixed>  $blockerExplainer
     * @return array<string, mixed>
     */
    private function operatorHandoffPacket(array $orderedSteps, ?array $firstBlocked, array $blockerExplainer, array $completionAuditBlockerSummary, array $resumptionCheckpoint, array $operatorClosureCommandReplay): array
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
            'completion_audit_blocker_summary' => $completionAuditBlockerSummary,
            'resumption_checkpoint_hash' => (string) data_get($resumptionCheckpoint, 'resumption_checkpoint_hash', ''),
            'resumption_checkpoint_current_step' => (string) data_get($resumptionCheckpoint, 'current_step', ''),
            'operator_closure_command_replay_hash' => (string) data_get($operatorClosureCommandReplay, 'command_replay_hash', ''),
            'operator_closure_command_replay_current_step' => (string) data_get($operatorClosureCommandReplay, 'current_step', ''),
            'can_resume_without_chat_history' => (bool) data_get($resumptionCheckpoint, 'can_resume_without_chat_history', false),
            'requires_fresh_preflight_before_persist' => (bool) data_get($resumptionCheckpoint, 'requires_fresh_preflight_before_persist', false),
            'current_blocks_completion_criteria' => $this->completionCriteriaForStep($currentStep),
            'current_completion_blockers_classified' => $this->classifiedBlockersForStep($currentStep, $completionAuditBlockerSummary),
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
