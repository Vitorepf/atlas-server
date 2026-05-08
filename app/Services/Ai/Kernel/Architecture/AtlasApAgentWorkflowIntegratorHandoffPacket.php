<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowIntegratorHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_integrator_handoff_packet.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowHumanDecisionContract $humanDecisionContract,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @return array<string,mixed>
     */
    public function handoff(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        string $decision,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $humanDecision = $this->humanDecisionContract->decide(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );

        $status = $this->status($humanDecision);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_integrator_handoff_packet',
            'authority' => 'ap_agent_workflow_integrator_handoff_only_no_execution',
            'work_title' => $humanDecision['work_title'],
            'resolved_target_ap' => $humanDecision['resolved_target_ap'],
            'integration_scope' => [
                'intended_paths' => array_values($intendedPaths),
                'path_count' => count($intendedPaths),
                'docs_ap_path' => $docsApPath,
                'requested_ap_number' => $requestedApNumber,
                'proposed_slug' => $proposedSlug,
            ],
            'human_decision_summary' => [
                'schema_version' => $humanDecision['schema_version'],
                'status' => $humanDecision['status'],
                'decision' => data_get($humanDecision, 'decision.value'),
                'reason' => data_get($humanDecision, 'decision.reason'),
                'review_packet_status' => data_get($humanDecision, 'review_packet_summary.status'),
                'execution_receipt_status' => data_get($humanDecision, 'review_packet_summary.execution_receipt_status'),
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_handoff' => false,
                'auto_merges_work' => false,
                'approves_without_human_acceptance' => false,
                'overrides_human_decision' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $humanDecision
     */
    private function status(array $humanDecision): string
    {
        return match ($humanDecision['status'] ?? null) {
            'accepted_by_human' => 'ready_for_integrator_review',
            'changes_requested_by_human' => 'return_to_agent_for_repair',
            'rejected_by_human' => 'stopped_by_human_rejection',
            default => 'blocked_by_human_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_integrator_review' => 'integrator_reviews_accepted_work_without_auto_merge',
            'return_to_agent_for_repair' => 'agent_repairs_work_from_human_reason_before_new_review',
            'stopped_by_human_rejection' => 'do_not_continue_until_human_reopens_scope',
            'blocked_by_human_decision_contract' => 'repair_human_decision_contract_before_integrator_handoff',
            default => 'review_integrator_handoff_packet_status',
        };
    }
}
