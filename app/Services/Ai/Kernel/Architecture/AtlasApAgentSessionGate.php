<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentSessionGate
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_session_gate.v1';

    public function __construct(
        private readonly AtlasApAgentHandoffPacket $handoffPacket,
        private readonly AtlasApGovernanceRepairProposalContract $repairProposals,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @return array<string,mixed>
     */
    public function gate(
        string $workTitle,
        array $intendedPaths = [],
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $repairs = $this->repairProposals->proposals($docsApPath);
        $handoff = $this->handoffPacket->packet(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $status = $this->status($repairs, $handoff);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_session_gate',
            'authority' => 'ap_agent_session_gate_only_no_file_writes',
            'work_title' => trim($workTitle),
            'intended_path_count' => count($intendedPaths),
            'resolved_target_ap' => $handoff['resolved_target_ap'] ?? null,
            'handoff' => [
                'schema_version' => $handoff['schema_version'],
                'status' => $handoff['status'],
                'resolved_target_ap' => $handoff['resolved_target_ap'],
                'intake_status' => data_get($handoff, 'intake.status'),
                'intake_recommendation' => data_get($handoff, 'intake.recommendation'),
                'completion_failed_count' => data_get($handoff, 'completion_starting_point.failed_count'),
                'next_action' => $handoff['next_action'],
            ],
            'repair_gate' => [
                'schema_version' => $repairs['schema_version'],
                'status' => $repairs['status'],
                'proposal_count' => $repairs['proposal_count'],
                'reasons' => array_values(array_unique(array_map(
                    fn (array $proposal): string => (string) $proposal['reason'],
                    (array) $repairs['proposals'],
                ))),
                'proposals' => $repairs['proposals'],
                'next_action' => $repairs['next_action'],
            ],
            'next_action' => $this->nextAction($status, $repairs, $handoff),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'applies_repairs' => false,
                'creates_ap_docs' => false,
                'edits_ap_docs' => false,
                'replaces_agent_judgement' => false,
                'requires_human_review_for_repair_proposals' => true,
                'requires_completion_checklist_after_work' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $repairs
     * @param  array<string,mixed>  $handoff
     */
    private function status(array $repairs, array $handoff): string
    {
        if (($repairs['status'] ?? null) !== 'ok') {
            return 'blocked_by_documentation_repair';
        }

        return match ($handoff['status'] ?? null) {
            'blocked' => 'blocked_by_handoff',
            'attention' => 'attention',
            'new_ap_ready' => 'ready_for_new_ap_work',
            'existing_ap_ready' => 'ready_for_existing_ap_work',
            default => 'attention',
        };
    }

    /**
     * @param  array<string,mixed>  $repairs
     * @param  array<string,mixed>  $handoff
     */
    private function nextAction(string $status, array $repairs, array $handoff): string
    {
        if ($status === 'blocked_by_documentation_repair') {
            return (string) ($repairs['next_action'] ?? 'review_repair_proposals_before_work');
        }

        if ($status === 'blocked_by_handoff') {
            return (string) ($handoff['next_action'] ?? 'repair_handoff_inputs_before_work');
        }

        if ($status === 'attention') {
            return (string) ($handoff['next_action'] ?? 'review_session_gate_attention_before_work');
        }

        return (string) ($handoff['next_action'] ?? 'execute_scoped_work_and_completion_checklist');
    }
}
