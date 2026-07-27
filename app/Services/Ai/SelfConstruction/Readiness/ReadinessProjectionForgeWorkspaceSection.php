<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/** SC-01 fatia forgeWorkspaceStatus (Obra 4). */
final class ReadinessProjectionForgeWorkspaceSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;
    public function setMother(AtlasSelfConstructionReadinessService $mother): self { $this->mother = $mother; return $this; }
    public function __call(string $name, array $arguments): mixed {
        if ($this->mother === null) throw new \RuntimeException("ReadinessProjectionForgeWorkspaceSection mother not bound");
        $method = new \ReflectionMethod($this->mother, $name);
        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function forgeWorkspaceStatus(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $parallelPlan = $this->parallelSessionPlan($options);
        $readinessGate = $this->multiSessionReadinessGate($options);
        $reservationStatus = $this->reservationStatus($options);
        $completionReadiness = $this->completionReadiness($options);

        $queueEntries = (array) data_get($queuePayload, 'queue.entries', []);
        $availablePacketIds = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($queueEntries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available')
        ));
        $claimedPacketIds = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($queueEntries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'claimed')
        ));
        $completedPacketIds = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($queueEntries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'completed')
        ));

        $workspace = [
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'canonical_name' => 'Obras Shared Workspace',
            'portuguese_label' => 'Workspace Compartilhado de Obras',
            'specialization' => 'Forge Workspace',
            'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            'obra_title' => 'Atlas Self-Construction OS',
            'workspace_type' => 'programming_forge_workspace',
            'objective' => 'Coordinate Atlas Self-Construction work across AI sessions through governed packets, artifacts, reservations, evidence and integration.',
            'source_of_truth' => [
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md',
                'docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md',
                'docs/engineering-knowledge-base/self-construction/packet-queue-contract.md',
                'docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md',
            ],
            'state' => [
                'queue_id' => data_get($queuePayload, 'queue.queue_id'),
                'recommended_packet_id' => data_get($queuePayload, 'queue.recommended_packet_id'),
                'available_packet_ids' => $availablePacketIds,
                'claimed_packet_ids' => $claimedPacketIds,
                'completed_packet_ids' => $completedPacketIds,
                'counts' => [
                    'queue_entries' => data_get($queuePayload, 'queue.entry_count'),
                    'available' => data_get($queuePayload, 'queue.available_count'),
                    'claimed' => data_get($queuePayload, 'queue.claimed_count'),
                    'completed' => data_get($queuePayload, 'queue.completed_count'),
                    'withheld' => data_get($queuePayload, 'queue.withheld_count'),
                    'active_reservations' => data_get($reservationStatus, 'ledger.active_count'),
                    'completed_reservations' => data_get($reservationStatus, 'ledger.completed_count'),
                    'preview_assignable_sessions' => data_get($parallelPlan, 'plan.preview_assignable_count'),
                ],
                'readiness_decision' => data_get($readinessGate, 'gate.decision'),
                'safe_next_instruction' => data_get($readinessGate, 'gate.safe_next_instruction'),
                'completion_status' => data_get($completionReadiness, 'status'),
            ],
            'artifact_bus' => [
                'accepted_artifact_types' => [
                    'mother_contract',
                    'work_packet',
                    'session_bootstrap',
                    'scope_validator_report',
                    'implementation_diff',
                    'test_output',
                    'evidence_report',
                    'integration_note',
                    'completion_receipt',
                ],
                'artifact_exchange_rule' => 'Providers return structured artifacts to the workspace; provider-to-provider loose chat is not source of truth.',
                'normalization_required' => true,
            ],
            'provider_roles' => [
                ['provider' => 'codex', 'role' => 'implementation_worker', 'receives' => 'packet_scope_bootstrap'],
                ['provider' => 'claude', 'role' => 'planner_or_reviewer', 'receives' => 'architecture_and_acceptance_packet'],
                ['provider' => 'gemini', 'role' => 'scout_or_long_context_mapper', 'receives' => 'source_map_and_research_packet'],
                ['provider' => 'local_runtime', 'role' => 'deterministic_gate_runner', 'receives' => 'commands_and_expected_outputs'],
            ],
            'integration_queue' => [
                'ready_for_review_packet_ids' => $completedPacketIds,
                'waiting_for_claim_packet_ids' => $availablePacketIds,
                'active_packet_ids' => $claimedPacketIds,
                'integration_rule' => 'Only completed packets with evidence hashes may enter final integration review.',
            ],
            'minimum_operational_contract' => [
                'objective_and_definition_of_done',
                'canonical_mother_contract',
                'work_split_with_disjoint_scopes',
                'allowed_and_forbidden_files_per_packet',
                'dependency_and_collision_map',
                'provider_role_per_packet',
                'required_gates_and_commands',
                'integration_queue',
                'evidence_normalization_contract',
                'rollback_and_repair_policy',
            ],
            'inspected_hashes' => [
                'queue_hash' => data_get($queuePayload, 'queue_hash'),
                'parallel_plan_hash' => data_get($parallelPlan, 'plan_hash'),
                'readiness_gate_hash' => data_get($readinessGate, 'gate_hash'),
                'reservation_ledger_hash' => data_get($reservationStatus, 'ledger_hash'),
            ],
            'required_operator_commands' => [
                'php artisan atlas:ai:self-construction --forge-workspace-status --json',
                'php artisan atlas:ai:self-construction --packet-queue --json',
                'php artisan atlas:ai:self-construction --multi-session-readiness-gate --json',
                'php artisan atlas:ai:self-construction --agent-launch-plan --json',
                'php artisan atlas:ai:self-construction --agent-start-packet --actor=<actor> --session=<session> --json',
                'php artisan atlas:ai:self-construction --agent-execution-status --json',
                'php artisan atlas:ai:self-construction --agent-integration-report --json',
                'php artisan atlas:ai:self-construction --agent-merge-readiness --json',
                'php artisan atlas:ai:self-construction --agent-final-review-packet --json',
                'php artisan atlas:ai:self-construction --agent-review-decision-template --json',
                'php artisan atlas:ai:self-construction --agent-review-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-signature-request --json',
                'php artisan atlas:ai:self-construction --agent-review-post-signature-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-action-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-action-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-signature-request --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-signature-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-execution-checklist --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-authorization-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-authorization-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-authorization-signature-request --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-authorization-post-signature-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-final-authorization-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-authorizing-action-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-final-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-final-signature-request --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-final-post-signature-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-signed-final-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-signed-final-receipt-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-signed-final-receipt-persistence-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-executor-release-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-executor-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-execution-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signature-request --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-post-signature-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-monitoring-review-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-reenable-review-packet-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-receipt-draft-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signature-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-signature-runbook-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signed-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-preflight-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-observability-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-monitoring-review-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-health-decision-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-receipt-draft-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signature-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-signature-runbook-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signed-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-preflight-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-observability-contract-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-monitoring-review-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-health-decision-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-preflight-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-receipt-draft-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-signed-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-preflight-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-post-persistence-review-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-follow-up-observability-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-evidence-repair-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repaired-evidence-packet-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-review-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-outcome-packet-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-preflight-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-receipt-draft-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-signature-runbook-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-validation-report-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-preflight-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-preflight-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-receipt-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-persistence-review-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-follow-up-observability-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-evidence-repair-request-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repaired-evidence-packet-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repair-review-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-rejection-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-human-escalation-template --json',
                'php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-request-template --json',
                'php artisan atlas:ai:self-construction --codex-start-packet --actor=<actor> --session=<session> --json',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_forge_workspace_status.v1',
            'status' => 'forge_workspace_ready',
            'mode' => 'read_only_forge_workspace_projection',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'dispatch_allowed' => false,
            'workspace' => $workspace,
            'workspace_hash' => $this->stableHash($workspace),
            'non_execution_guarantees' => [
                'forge_workspace_status_does_not_create_obras_product_runtime',
                'forge_workspace_status_does_not_dispatch_agents',
                'forge_workspace_status_does_not_persist_claim',
                'forge_workspace_status_does_not_enable_self_programming',
            ],
            'human_summary' => 'Forge Workspace projection is ready: Atlas Self-Construction OS has a canonical shared workspace view for packets, artifacts, reservations, evidence and integration without dispatching agents.',
        ];
    }
}
