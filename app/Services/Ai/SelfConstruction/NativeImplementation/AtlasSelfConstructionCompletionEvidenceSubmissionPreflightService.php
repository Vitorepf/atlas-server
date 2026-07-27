<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

final class AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.completion_evidence_submission_preflight.v1';

    public const MODE = 'read_only_completion_evidence_submission_preflight';

    private const REQUIRED_TERMINAL_LOOP_END_TO_END_CONTRACT_CAPABILITIES = [
        'auto_replenishment',
        'validation',
        'leases',
        'evidence',
        'retomada',
        'lane_isolation',
        'cycle_supervision',
        'operator_handoff',
    ];

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
                command: $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
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
        $terminalLoopClosureProof = $this->terminalLoopClosureProofPacket($completionAudit, $completionEvidence, $blockerExplainer);
        $operatorCommandSurface = $this->operatorCommandSurface([
            'ordered_steps' => $orderedSteps,
            'operator_execution_plan' => $operatorExecutionPlan,
            'operator_resumption_checkpoint' => $resumptionCheckpoint,
            'operator_closure_command_replay' => $operatorClosureCommandReplay,
            'operator_handoff_packet' => $operatorHandoffPacket,
            'terminal_loop_closure_proof' => $terminalLoopClosureProof,
        ]);
        $closureArtifactSequence = $this->closureArtifactSequence(
            runtimeReceiptReady: $runtimeReceiptReady,
            realProviderSmokeReady: $realProviderSmokeReady,
            humanReceiptReady: $humanReceiptReady,
            completionAuditReady: $completionAuditReady,
            blockerExplainer: $blockerExplainer,
        );
        $promptToArtifactChecklist = $this->promptToArtifactChecklist($closureArtifactSequence);
        $prePersistGuardrailSequence = $this->prePersistGuardrailSequence($firstBlocked, $blockerExplainer);

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
            'terminal_loop_closure_proof' => $terminalLoopClosureProof,
            'operator_command_surface' => $operatorCommandSurface,
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => $this->stableHash($closureArtifactSequence),
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => count(array_filter($promptToArtifactChecklist, static fn (array $row): bool => (bool) $row['passed'])),
            'prompt_to_artifact_checklist_hash' => $this->stableHash($promptToArtifactChecklist),
            'pre_persist_guardrail_sequence' => $prePersistGuardrailSequence,
            'pre_persist_guardrail_count' => count($prePersistGuardrailSequence),
            'pre_persist_guardrail_hash' => $this->stableHash($prePersistGuardrailSequence),
            'step_count' => count($orderedSteps),
            'ready_step_count' => $readySteps,
            'blocked_step_count' => count($orderedSteps) - $readySteps,
            'next_required_submission' => $firstBlocked['id'] ?? 'rerun_completion_audit',
            'next_required_command' => (string) ($firstBlocked['command'] ?? $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer)),
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
     * @param  array<string, mixed>  $blockerExplainer
     * @return list<array<string, mixed>>
     */
    private function closureArtifactSequence(bool $runtimeReceiptReady, bool $realProviderSmokeReady, bool $humanReceiptReady, bool $completionAuditReady, array $blockerExplainer): array
    {
        return [
            [
                'order' => 1,
                'artifact' => 'runtime_promotion_receipt',
                'requirement' => 'runtime_gap_matrix_all_runtime_y',
                'blocker_type' => 'human',
                'status' => $runtimeReceiptReady ? 'passed' : 'blocked',
                'passed' => $runtimeReceiptReady,
                'expected_receipt_schema' => 'atlas.self_construction.runtime_promotion_receipt.v1',
                'draft_command' => (string) data_get($blockerExplainer, 'command_plan.draft_runtime_promotion_receipt', ''),
                'persist_command' => (string) data_get($blockerExplainer, 'command_plan.persist_runtime_promotion_receipt', ''),
                'evidence_source' => 'runtime_gap_matrix',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 2,
                'artifact' => 'real_provider_smoke',
                'requirement' => 'end_to_end_real_provider_smoke_green',
                'blocker_type' => 'real_provider',
                'status' => $realProviderSmokeReady ? 'passed' : 'blocked',
                'passed' => $realProviderSmokeReady,
                'expected_receipt_schema' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'draft_command' => (string) data_get($blockerExplainer, 'command_plan.draft_real_provider_smoke', ''),
                'persist_command' => (string) data_get($blockerExplainer, 'command_plan.persist_real_provider_smoke', ''),
                'evidence_source' => 'real_provider_smoke',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'order' => 3,
                'artifact' => 'human_completion_receipt',
                'requirement' => 'human_signed_os_complete_receipt_present',
                'blocker_type' => 'human',
                'status' => $humanReceiptReady ? 'passed' : 'blocked',
                'passed' => $humanReceiptReady,
                'expected_receipt_schema' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'draft_command' => (string) data_get($blockerExplainer, 'command_plan.draft_human_completion_receipt', ''),
                'persist_command' => (string) data_get($blockerExplainer, 'command_plan.persist_human_completion_receipt', ''),
                'evidence_source' => 'human_completion_receipt',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 4,
                'artifact' => 'final_completion_audit',
                'requirement' => 'completion_audit_authorizes_completion_claim',
                'blocker_type' => $completionAuditReady ? 'none' : 'derived',
                'status' => $completionAuditReady ? 'passed' : 'blocked_until_operator_evidence_green',
                'passed' => $completionAuditReady,
                'expected_receipt_schema' => 'atlas.self_construction.os_completion_audit.v1',
                'draft_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'persist_command' => '',
                'evidence_source' => 'completion_audit',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $closureArtifactSequence
     * @return list<array<string, mixed>>
     */
    private function promptToArtifactChecklist(array $closureArtifactSequence): array
    {
        return array_map(static fn (array $row): array => [
            'requirement' => (string) $row['requirement'],
            'artifact' => (string) $row['artifact'],
            'status' => (string) $row['status'],
            'passed' => (bool) $row['passed'],
            'blocker_type' => (string) $row['blocker_type'],
            'expected_receipt_schema' => (string) $row['expected_receipt_schema'],
            'evidence_source' => (string) $row['evidence_source'],
            'command' => (string) $row['draft_command'],
            'persist_command' => (string) $row['persist_command'],
        ], $closureArtifactSequence);
    }

    /**
     * @param  array<string, mixed>|null  $firstBlocked
     * @param  array<string, mixed>  $blockerExplainer
     * @return list<array<string, mixed>>
     */
    private function prePersistGuardrailSequence(?array $firstBlocked, array $blockerExplainer): array
    {
        $currentArtifact = (string) data_get($firstBlocked, 'id', 'final_completion_audit');
        $currentPersistCommand = (string) data_get($firstBlocked, 'persist_command', '');
        $currentVerificationCommand = $this->currentArtifactVerificationCommand($currentArtifact);

        $sequence = [
            [
                'order' => 1,
                'step' => 'inspect_self_construction_handoff',
                'artifact' => $currentArtifact,
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-handoff-status --json',
                'required_before_persist' => true,
                'blocks_persist_if_fails' => true,
                'requires_operator_payload' => false,
            ],
            [
                'order' => 2,
                'step' => 'verify_release_dossier_current',
                'artifact' => $currentArtifact,
                'command' => 'php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-status --json',
                'required_before_persist' => true,
                'blocks_persist_if_fails' => true,
                'requires_operator_payload' => false,
            ],
            [
                'order' => 3,
                'step' => 'verify_certification_status_batch_green',
                'artifact' => $currentArtifact,
                'command' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-status-batch-status --json',
                'required_before_persist' => true,
                'blocks_persist_if_fails' => true,
                'requires_operator_payload' => false,
            ],
            [
                'order' => 4,
                'step' => 'rerun_submission_preflight',
                'artifact' => $currentArtifact,
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                'required_before_persist' => true,
                'blocks_persist_if_fails' => true,
                'requires_operator_payload' => false,
            ],
        ];

        if ($currentArtifact === 'real_provider_smoke') {
            $sequence[] = [
                'order' => count($sequence) + 1,
                'step' => 'review_real_provider_smoke_offline_harness',
                'artifact' => $currentArtifact,
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
                'required_before_persist' => true,
                'blocks_persist_if_fails' => true,
                'requires_operator_payload' => false,
            ];
        }

        if ($currentVerificationCommand !== '') {
            $sequence[] = [
                'order' => count($sequence) + 1,
                'step' => 'verify_current_artifact_endgame',
                'artifact' => $currentArtifact,
                'command' => $currentVerificationCommand,
                'required_before_persist' => true,
                'blocks_persist_if_fails' => true,
                'requires_operator_payload' => true,
            ];
        }

        if ($currentPersistCommand !== '') {
            $sequence[] = [
                'order' => count($sequence) + 1,
                'step' => 'persist_current_operator_artifact',
                'artifact' => $currentArtifact,
                'command' => $currentPersistCommand,
                'required_before_persist' => false,
                'blocks_persist_if_fails' => true,
                'requires_operator_payload' => true,
            ];
        }

        $sequence[] = [
            'order' => count($sequence) + 1,
            'step' => 'rerun_completion_audit_with_terminal_loop_proof',
            'artifact' => $currentArtifact,
            'command' => $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
            'required_before_persist' => false,
            'blocks_persist_if_fails' => true,
            'requires_operator_payload' => false,
        ];

        return array_values($sequence);
    }

    private function currentArtifactVerificationCommand(string $currentArtifact): string
    {
        return match ($currentArtifact) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-verifier-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-endgame-verifier-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-endgame-verifier-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            default => '',
        };
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
        foreach ((array) data_get($completionAudit, 'failed_criteria', []) as $criterionId) {
            $criterionId = (string) $criterionId;
            if ($criterionId === '' || isset($blockersById[$criterionId])) {
                continue;
            }

            $explainer = (array) ($explainerBlockersById[$criterionId] ?? []);
            $blockersById[$criterionId] = [
                'id' => $criterionId,
                'requirement' => $criterionId,
                'blocker_type' => match ($criterionId) {
                    'runtime_gap_matrix_all_runtime_y', 'human_signed_os_complete_receipt_present' => 'human',
                    'end_to_end_real_provider_smoke_green' => 'real_provider',
                    default => 'technical',
                },
                'owner' => (string) ($explainer['owner'] ?? ''),
                'severity' => (string) ($explainer['severity'] ?? ''),
                'why_blocking' => '',
                'why_not_automatic' => (string) ($explainer['why_it_cannot_be_auto_closed'] ?? ''),
                'doc_anchor' => '',
                'remediation_command' => '',
                'expected_receipt_schema' => '',
                'exact_closure_condition' => (string) ($explainer['exact_closure_condition'] ?? ''),
                'required_evidence' => (array) ($explainer['required_evidence'] ?? []),
                'current_evidence_context' => (array) ($explainer['current_evidence_context'] ?? []),
            ];
        }
        $blockers = array_values($blockersById);

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
                $this->terminalLoopOperationalProofCommand(),
                'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
            ],
            'final_success_command' => $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
            'effective_final_success_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
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
            'exact_next_command' => (string) ($current['command'] ?? $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer)),
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
                'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'refresh_submission_preflight' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                'refresh_operator_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'refresh_final_operator_closure_corridor' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'refresh_completion_audit' => $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
                'effective_refresh_completion_audit' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
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
        $refreshCompletionAudit = $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer);
        $effectiveRefreshCompletionAudit = $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand();

        $replaySteps = array_values(array_map(function (array $step) use ($refreshSubmissionPreflight, $refreshCompletionAudit, $effectiveRefreshCompletionAudit): array {
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
                    $this->terminalLoopOperationalProofCommand(),
                    $refreshSubmissionPreflight,
                    $refreshCompletionAudit,
                    $effectiveRefreshCompletionAudit,
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
                'terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'submission_preflight' => $refreshSubmissionPreflight,
                'operator_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit' => $refreshCompletionAudit,
                'effective_completion_audit' => $effectiveRefreshCompletionAudit,
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
            'next_draft_or_check_command' => (string) ($current['command'] ?? $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer)),
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
                'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
                $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            ],
            'final_success_command' => $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
            'effective_final_success_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
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

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @param  array<string, mixed>  $blockerExplainer
     * @return array<string, mixed>
     */
    private function terminalLoopClosureProofPacket(array $completionAudit, array $completionEvidence, array $blockerExplainer): array
    {
        $packet = [
            'schema_version' => 'atlas.self_construction.terminal_loop_closure_proof_packet.v1',
            'mode' => 'read_only_terminal_loop_closure_proof_packet',
            'status' => 'operator_or_ci_should_refresh_before_final_persist',
            'proof_command' => $this->terminalLoopOperationalProofCommand(),
            'proof_binding_persist_command' => $this->terminalLoopOperationalProofBindingPersistCommand(),
            'audit_command_with_binding' => $this->completionAuditWithTerminalLoopOperationalProofCommand($blockerExplainer),
            'audit_command_with_canonical_binding' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'effective_audit_command_with_binding' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'expected_binding_artifact_path' => $this->terminalLoopOperationalProofBindingArtifactPath(),
            'required_before_final_completion_receipt' => true,
            'required_before_human_completion_receipt_persist' => true,
            'required_end_to_end_contract_capabilities' => self::REQUIRED_TERMINAL_LOOP_END_TO_END_CONTRACT_CAPABILITIES,
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
            'blocker_explainer_hash' => (string) data_get($blockerExplainer, 'explainer_hash', ''),
            'acceptance_criteria' => [
                'operational_proof_status_passed',
                'operational_readiness_matrix_all_true',
                'post_cycle_cycle_supervisor_review_evidence',
                'post_cycle_end_to_end_contract_available',
                'post_cycle_end_to_end_contract_covers_required_loop_surfaces',
                'completion_audit_binding_packet_ready',
                'provider_token_dispatch_flags_false',
            ],
            'operator_steps' => [
                'run_terminal_loop_operational_proof_status',
                'persist_completion_audit_binding_packet_to_canonical_operator_submission_path',
                'rerun_completion_audit_with_agent_control_plane_terminal_loop_operational_proof_json',
                'continue_runtime_promotion_real_provider_smoke_human_receipt_sequence_only_if_audit_still_reports_expected_blockers',
            ],
            'stop_conditions' => [
                'stop_if_operational_proof_status_is_not_passed',
                'stop_if_operational_readiness_matrix_has_failed_rows',
                'stop_if_post_cycle_cycle_supervisor_is_not_review_evidence',
                'stop_if_post_cycle_end_to_end_contract_is_not_available',
                'stop_if_post_cycle_end_to_end_contract_has_missing_loop_surfaces',
                'stop_if_binding_packet_can_mark_completion',
                'stop_if_provider_or_token_or_dispatch_flags_are_true',
            ],
            'non_execution_guarantees' => [
                'terminal_loop_closure_proof_packet_does_not_run_the_proof',
                'terminal_loop_closure_proof_packet_does_not_persist_receipts',
                'terminal_loop_closure_proof_packet_does_not_call_provider',
                'terminal_loop_closure_proof_packet_does_not_spend_tokens',
                'terminal_loop_closure_proof_packet_does_not_promote_completion',
            ],
        ];
        $packet['terminal_loop_closure_proof_packet_hash'] = $this->stableHash($packet);

        return $packet;
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    private function operatorCommandSurface(array $sections): array
    {
        $definition = Artisan::all()['atlas:ai:self-construction']->getDefinition();
        $deprecatedOptionAliases = [
            'runtime-gap-matrix',
            'runtime-promotion-receipt-draft',
            'runtime-promotion-receipt-runbook',
            'human-completion-receipt-closure-execution-pack',
            'operator-evidence-submission-readiness',
        ];
        $commands = [];
        $missingOptions = [];
        $legacyAliasHits = [];

        foreach ($this->collectArtisanCommands($sections) as $command) {
            $options = $this->extractCommandOptions($command);
            $missing = [];
            $deprecatedAliases = [];

            foreach ($options as $option) {
                if (! $definition->hasOption($option)) {
                    $missing[] = $option;
                    $missingOptions[] = $option;
                }
                if (in_array($option, $deprecatedOptionAliases, true)) {
                    $deprecatedAliases[] = $option;
                    $legacyAliasHits[] = $option;
                }
            }

            $commands[] = [
                'command' => $command,
                'options' => $options,
                'option_count' => count($options),
                'all_options_available' => $missing === [],
                'missing_options' => array_values(array_unique($missing)),
                'legacy_aliases_detected' => array_values(array_unique($deprecatedAliases)),
            ];
        }

        $missingOptions = array_values(array_unique($missingOptions));
        $legacyAliasHits = array_values(array_unique($legacyAliasHits));
        $surface = [
            'schema_version' => 'atlas.self_construction.completion_evidence_submission_preflight.command_surface.v1',
            'status' => $missingOptions === [] && $legacyAliasHits === [] ? 'available' : 'blocked',
            'all_commands_available' => $missingOptions === [],
            'legacy_alias_free' => $legacyAliasHits === [],
            'command_count' => count($commands),
            'missing_option_count' => count($missingOptions),
            'missing_options' => $missingOptions,
            'legacy_alias_count' => count($legacyAliasHits),
            'legacy_aliases_detected' => $legacyAliasHits,
            'commands' => $commands,
        ];
        $surface['command_surface_hash'] = $this->stableHash($surface);

        return $surface;
    }

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    private function terminalLoopOperationalProofBindingPersistCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json';
    }

    /** @param array<string, mixed> $blockerExplainer */
    private function completionAuditWithTerminalLoopOperationalProofCommand(array $blockerExplainer): string
    {
        return (string) data_get(
            $blockerExplainer,
            'command_plan.completion_audit_with_terminal_loop_operational_proof',
            'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json',
        );
    }

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$this->terminalLoopOperationalProofBindingArtifactPath().' --json';
    }

    private function terminalLoopOperationalProofBindingArtifactPath(): string
    {
        return 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
    }

    /**
     * @return list<string>
     */
    private function collectArtisanCommands(mixed $value): array
    {
        if (is_string($value)) {
            return str_starts_with($value, 'php artisan atlas:ai:self-construction ')
                ? [$value]
                : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $commands = [];
        foreach ($value as $entry) {
            array_push($commands, ...$this->collectArtisanCommands($entry));
        }

        return array_values(array_unique($commands));
    }

    /**
     * @return list<string>
     */
    private function extractCommandOptions(string $command): array
    {
        preg_match_all('/(?:^|\s)--([A-Za-z0-9][A-Za-z0-9-]*)(?=\s|=|$)/', $command, $matches);

        return array_values(array_unique(array_map('strval', $matches[1] ?? [])));
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset(
            $payload['generated_at'],
            $payload['submission_preflight_hash'],
            $payload['command_surface_hash'],
            $payload['terminal_loop_closure_proof_packet_hash'],
        );

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
