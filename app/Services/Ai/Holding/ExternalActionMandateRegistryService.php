<?php

namespace App\Services\Ai\Holding;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Models\AiHoldingEnterpriseFlowOperationsRunbook;
use App\Models\AiHoldingEnterpriseFlowRunQueueItem;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiOperatorApproval;
use App\Services\Ai\Mission\MissionCanonicalHash;

class ExternalActionMandateRegistryService
{
    public const REGISTRY_SCHEMA = 'atlas.ai.holding.enterprise_external_action_mandate_registry.v1';

    public const PREFLIGHT_SCHEMA = 'atlas.ai.holding.enterprise_external_action_mandate_preflight.v1';

    public const APPROVAL_REQUEST_SCHEMA = 'atlas.ai.holding.enterprise_external_action_approval_request.v1';

    public const APPROVAL_DECISION_SCHEMA = 'atlas.ai.holding.enterprise_external_action_approval_decision.v1';

    public const APPROVAL_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_external_action_approval_status.v1';

    public const CONTROL_TOWER_SCHEMA = 'atlas.ai.holding.enterprise_control_tower.v1';

    public const ACTIVATION_COCKPIT_SCHEMA = 'atlas.ai.holding.enterprise_activation_cockpit.v1';

    public const PREMIUM_ACTIVATION_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_premium_activation_status.v1';

    public const PROVIDER_WORKBENCH_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_provider_workbench_status.v1';

    public const AGENT_REPOSITORY_ADOPTION_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_agent_repository_adoption_status.v1';

    public const INDUSTRY_SOLUTION_ECOSYSTEM_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_industry_solution_ecosystem_status.v1';

    public const BUSINESS_OPERATING_BACKBONE_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_business_operating_backbone_status.v1';

    public const PRODUCTION_CONNECTOR_PREFLIGHT_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_production_connector_preflight_status.v1';

    public const FLOW_QUALITY_RESEARCH_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_flow_quality_research_status.v1';

    public const VERTICAL_SOLUTION_SUITE_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_vertical_solution_suite_status.v1';

    public const DOMAIN_BUSINESS_EXECUTION_MESH_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_domain_business_execution_mesh_status.v1';

    public const FLOW_OPERATING_PACKAGE_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_flow_operating_package_status.v1';

    public const COMPANY_COMMAND_CENTER_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_company_command_center_status.v1';

    public const OPERATIONAL_DRESS_REHEARSAL_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_operational_dress_rehearsal_status.v1';

    public const REAL_EXTERNAL_EXECUTION_READINESS_DOSSIER_SCHEMA = 'atlas.ai.holding.enterprise_real_external_execution_readiness_dossier.v1';

    public const REAL_EXTERNAL_EXECUTION_HANDOFF_PACK_SCHEMA = 'atlas.ai.holding.enterprise_real_external_execution_handoff_pack.v1';

    public const SUPERVISED_EXTERNAL_EXECUTION_PACKET_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_supervised_external_execution_packet_status.v1';

    public const EXTERNAL_WORKER_PREFLIGHT_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_external_worker_preflight_status.v1';

    public const ACTIVATION_BACKLOG_REGISTRY_SCHEMA = 'atlas.ai.holding.enterprise_activation_backlog_registry.v1';

    public const ACTIVATION_BACKLOG_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_activation_backlog_status.v1';

    public const ACTIVATION_BACKLOG_RUN_SCHEMA = 'atlas.ai.holding.enterprise_activation_backlog_run.v1';

    public const CONNECTOR_ACTIVATION_REGISTRY_SCHEMA = 'atlas.ai.holding.enterprise_connector_activation_registry.v1';

    public const CONNECTOR_ACTIVATION_PROBE_SCHEMA = 'atlas.ai.holding.enterprise_connector_activation_probe.v1';

    public const CONNECTOR_ACTIVATION_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_connector_activation_status.v1';

    public const LIVE_READ_CONNECTOR_READINESS_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_live_read_connector_readiness_status.v1';

    public const FLOW_RUN_QUEUE_REGISTRY_SCHEMA = 'atlas.ai.holding.enterprise_flow_run_queue_registry.v1';

    public const FLOW_RUN_QUEUE_EXECUTION_SCHEMA = 'atlas.ai.holding.enterprise_flow_run_queue_execution.v1';

    public const FLOW_RUN_QUEUE_REPLAY_SCHEMA = 'atlas.ai.holding.enterprise_flow_run_queue_replay.v1';

    public const FLOW_RUN_QUEUE_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_flow_run_queue_status.v1';

    public const FLOW_OPERATIONS_RUNBOOK_REGISTRY_SCHEMA = 'atlas.ai.holding.enterprise_flow_operations_runbook_registry.v1';

    public const FLOW_OPERATIONS_RUNBOOK_DRILL_SCHEMA = 'atlas.ai.holding.enterprise_flow_operations_runbook_drill.v1';

    public const FLOW_OPERATIONS_RUNBOOK_STATUS_SCHEMA = 'atlas.ai.holding.enterprise_flow_operations_runbook_status.v1';

    public function __construct(
        private readonly EnterpriseFlowFixtureSuiteService $fixtureSuite,
        private readonly AutonomousHoldingEnterpriseBuildoutService $buildout,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function register(?string $companyId = null, ?string $flowId = null): array
    {
        $suite = $this->fixtureSuite->externalActionMandateSuite($companyId);
        $packets = $this->packets($suite, $flowId);
        $records = [];

        foreach ($packets as $packet) {
            $records[] = $this->persistPacket($packet);
        }

        $blocked = count(array_filter(
            $records,
            static fn (array $record): bool => (bool) ($record['auto_execute_allowed'] ?? true)
                || (bool) ($record['external_side_effects_enabled'] ?? true),
        ));

        $payload = [
            'ok' => (bool) ($suite['ok'] ?? false) && $records !== [] && $blocked === 0,
            'schema' => self::REGISTRY_SCHEMA,
            'status' => $records !== [] && $blocked === 0 ? 'queued_for_operator_review' : 'attention',
            'generated_at' => now()->toJSON(),
            'source_mandate_suite_hash' => $suite['mandate_suite_hash'] ?? null,
            'summary' => [
                'registered_count' => count($records),
                'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['flow_id'], $records))),
                'auto_execute_allowed_count' => $blocked,
                'external_side_effects_enabled_count' => $blocked,
                'operator_signature_required' => true,
                'second_reviewer_required' => true,
            ],
            'records' => $records,
            'registry_policy' => [
                'queue_status' => 'queued_for_operator_review',
                'allowed_next_actions' => ['operator_preflight_review', 'reject', 'request_scope_change', 'manual_signed_execution_outside_this_suite'],
                'blocked_next_actions' => ['auto_execute', 'external_write_without_signature', 'spend_without_budget_cap', 'trade_without_signed_mandate'],
                'external_execution_enabled_by_registry' => false,
            ],
        ];
        $payload['registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function preflight(string $mandatePacketHash): array
    {
        $hash = trim($mandatePacketHash);
        $record = $hash !== ''
            ? AiHoldingExternalActionMandate::query()->where('mandate_packet_hash', $hash)->first()
            : null;

        if (! $record instanceof AiHoldingExternalActionMandate) {
            return [
                'ok' => false,
                'schema' => self::PREFLIGHT_SCHEMA,
                'status' => 'missing_mandate_packet',
                'mandate_packet_hash' => $hash,
                'external_execution_allowed' => false,
                'reasons' => ['mandate_packet_not_registered'],
            ];
        }

        $checks = $this->preflightChecks($record);
        $failed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ! (bool) ($check['passed'] ?? false),
        ));
        $record->forceFill([
            'status' => $failed === [] ? 'preflight_green_awaiting_signatures' : 'preflight_blocked',
            'preflighted_at' => now(),
        ])->save();

        $payload = [
            'ok' => $failed === [],
            'schema' => self::PREFLIGHT_SCHEMA,
            'status' => $failed === [] ? 'preflight_green_awaiting_signatures' : 'preflight_blocked',
            'generated_at' => now()->toJSON(),
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'external_execution_allowed' => false,
            'external_execution_blocker' => 'operator_and_second_reviewer_signatures_required_outside_this_suite',
            'checks' => $checks,
            'failed_checks' => $failed,
            'record' => $this->recordPayload($record->refresh()),
        ];
        $payload['preflight_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function requestApproval(string $mandatePacketHash): array
    {
        $record = $this->mandateRecord($mandatePacketHash);
        if (! $record instanceof AiHoldingExternalActionMandate) {
            return $this->missingMandatePayload(self::APPROVAL_REQUEST_SCHEMA, $mandatePacketHash);
        }

        if ($record->status !== 'preflight_green_awaiting_signatures') {
            $preflight = $this->preflight($record->mandate_packet_hash);
            if (! (bool) ($preflight['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'schema' => self::APPROVAL_REQUEST_SCHEMA,
                    'status' => 'preflight_required_before_approval',
                    'mandate_packet_hash' => $record->mandate_packet_hash,
                    'external_execution_allowed' => false,
                    'preflight' => $preflight,
                ];
            }
            $record = $record->refresh();
        }

        $approvals = [
            $this->createApproval($record, 'operator_signature'),
            $this->createApproval($record, 'second_reviewer_signature'),
        ];
        $record->forceFill(['status' => 'awaiting_operator_and_reviewer_signatures'])->save();

        $payload = [
            'ok' => true,
            'schema' => self::APPROVAL_REQUEST_SCHEMA,
            'status' => 'awaiting_operator_and_reviewer_signatures',
            'generated_at' => now()->toJSON(),
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'external_execution_allowed' => false,
            'approval_count' => count($approvals),
            'approvals' => $approvals,
            'required_roles' => ['operator_signature', 'second_reviewer_signature'],
        ];
        $payload['approval_request_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function decideApproval(string $approvalUuid, string $decision, string $operator, ?string $note = null): array
    {
        $normalizedDecision = trim(strtolower($decision));
        if (! in_array($normalizedDecision, ['approved', 'rejected'], true)) {
            return [
                'ok' => false,
                'schema' => self::APPROVAL_DECISION_SCHEMA,
                'status' => 'invalid_decision',
                'allowed_decisions' => ['approved', 'rejected'],
                'external_execution_allowed' => false,
            ];
        }

        $approval = AiOperatorApproval::query()->where('uuid', trim($approvalUuid))->first();
        if (! $approval instanceof AiOperatorApproval) {
            return [
                'ok' => false,
                'schema' => self::APPROVAL_DECISION_SCHEMA,
                'status' => 'approval_not_found',
                'approval_uuid' => trim($approvalUuid),
                'external_execution_allowed' => false,
            ];
        }

        $mandateHash = (string) data_get($approval->options, 'mandate_packet_hash', '');
        $record = $this->mandateRecord($mandateHash);
        if (! $record instanceof AiHoldingExternalActionMandate) {
            return $this->missingMandatePayload(self::APPROVAL_DECISION_SCHEMA, $mandateHash);
        }

        $approval->forceFill([
            'status' => $normalizedDecision,
            'operator_decision' => $normalizedDecision,
            'operator' => trim($operator) !== '' ? trim($operator) : 'atlas_operator',
            'operator_note' => $note,
            'decided_at' => now(),
            'receipt_hash' => hash('sha256', 'holding_external_action_approval_decision|'.$approval->uuid.'|'.$normalizedDecision),
        ])->save();

        $status = $this->approvalStatus($record->mandate_packet_hash);
        $record->forceFill(['status' => (string) ($status['mandate_status'] ?? $record->status)])->save();

        $payload = [
            'ok' => true,
            'schema' => self::APPROVAL_DECISION_SCHEMA,
            'status' => $normalizedDecision,
            'generated_at' => now()->toJSON(),
            'approval_uuid' => $approval->uuid,
            'approval_role' => (string) data_get($approval->options, 'approval_role', 'unknown'),
            'operator_decision' => $normalizedDecision,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'mandate_status' => (string) ($status['mandate_status'] ?? $record->status),
            'external_execution_allowed' => false,
            'approval_status' => $status,
        ];
        $payload['approval_decision_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function approvalStatus(string $mandatePacketHash): array
    {
        $record = $this->mandateRecord($mandatePacketHash);
        if (! $record instanceof AiHoldingExternalActionMandate) {
            return $this->missingMandatePayload(self::APPROVAL_STATUS_SCHEMA, $mandatePacketHash);
        }

        $approvals = $this->approvalRows($record->mandate_packet_hash);
        $operatorApproved = $this->roleApproved($approvals, 'operator_signature');
        $reviewerApproved = $this->roleApproved($approvals, 'second_reviewer_signature');
        $rejected = count(array_filter(
            $approvals,
            static fn (AiOperatorApproval $approval): bool => $approval->status === 'rejected'
                || $approval->operator_decision === 'rejected',
        ));
        $mandateStatus = $rejected > 0
            ? 'rejected_by_operator_gate'
            : ($operatorApproved && $reviewerApproved
                ? 'signed_mandate_ready_manual_execution_only'
                : ($approvals === [] ? (string) $record->status : 'awaiting_operator_and_reviewer_signatures'));

        $payload = [
            'ok' => true,
            'schema' => self::APPROVAL_STATUS_SCHEMA,
            'status' => $mandateStatus,
            'mandate_status' => $mandateStatus,
            'generated_at' => now()->toJSON(),
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'approval_count' => count($approvals),
            'operator_approved' => $operatorApproved,
            'second_reviewer_approved' => $reviewerApproved,
            'rejected_count' => $rejected,
            'external_execution_allowed' => false,
            'external_execution_blocker' => 'manual_execution_only_even_after_signatures',
            'approvals' => array_map(fn (AiOperatorApproval $approval): array => $this->approvalPayload($approval), $approvals),
            'record' => $this->recordPayload($record),
        ];
        $payload['approval_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function controlTower(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $suite = $this->fixtureSuite->externalActionMandateSuite($wantedCompany);
        $expectedPackets = $this->packets($suite, null);
        $expectedHashes = array_values(array_filter(array_map(
            static fn (array $packet): string => (string) ($packet['mandate_packet_hash'] ?? ''),
            $expectedPackets,
        )));

        $records = AiHoldingExternalActionMandate::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->get()
            ->keyBy(static fn (AiHoldingExternalActionMandate $record): string => $record->company_id.'|'.$record->flow_id);

        $companyRows = [];
        foreach ((array) ($suite['companies'] ?? []) as $company) {
            $companyId = (string) ($company['company_id'] ?? 'unknown');
            $flowRows = [];

            foreach ((array) ($company['mandate_packets'] ?? []) as $packet) {
                $flowId = (string) ($packet['flow_id'] ?? 'unknown');
                $record = $records->get($companyId.'|'.$flowId);
                $hash = $record instanceof AiHoldingExternalActionMandate
                    ? $record->mandate_packet_hash
                    : (string) ($packet['mandate_packet_hash'] ?? '');
                $approvals = $record instanceof AiHoldingExternalActionMandate
                    ? $this->approvalRows($record->mandate_packet_hash)
                    : [];
                $mandateStatus = $record instanceof AiHoldingExternalActionMandate
                    ? $this->approvalStatus($record->mandate_packet_hash)['mandate_status']
                    : 'not_registered';

                $flowRows[] = [
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'mandate_packet_hash' => $hash,
                    'mandate_status' => $mandateStatus,
                    'registered' => $record instanceof AiHoldingExternalActionMandate,
                    'preflighted' => $record instanceof AiHoldingExternalActionMandate && $record->preflighted_at !== null,
                    'connector_count' => count((array) ($record?->connector_scope_json ?? $packet['connector_scope'] ?? [])),
                    'blocked_operations' => array_values((array) ($record?->blocked_operations_json ?? $packet['blocked_operations_until_signed_mandate'] ?? [])),
                    'approval_count' => count($approvals),
                    'approvals' => array_map(fn (AiOperatorApproval $approval): array => $this->approvalPayload($approval), $approvals),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'next_action' => $this->nextControlTowerAction($mandateStatus),
                ];
            }

            $companyRows[] = [
                'company_id' => $companyId,
                'expected_flow_count' => count($flowRows),
                'registered_mandate_count' => count(array_filter($flowRows, static fn (array $row): bool => (bool) $row['registered'])),
                'not_registered_count' => count(array_filter($flowRows, static fn (array $row): bool => $row['mandate_status'] === 'not_registered')),
                'preflight_green_count' => count(array_filter($flowRows, static fn (array $row): bool => $row['mandate_status'] === 'preflight_green_awaiting_signatures')),
                'awaiting_signature_count' => count(array_filter($flowRows, static fn (array $row): bool => $row['mandate_status'] === 'awaiting_operator_and_reviewer_signatures')),
                'signed_manual_handoff_count' => count(array_filter($flowRows, static fn (array $row): bool => $row['mandate_status'] === 'signed_mandate_ready_manual_execution_only')),
                'rejected_count' => count(array_filter($flowRows, static fn (array $row): bool => $row['mandate_status'] === 'rejected_by_operator_gate')),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'mandate_status_counts' => $this->countsByKey($flowRows, 'mandate_status'),
                'flow_rows' => $flowRows,
            ];
        }

        $allFlowRows = [];
        foreach ($companyRows as $company) {
            $allFlowRows = array_merge($allFlowRows, (array) ($company['flow_rows'] ?? []));
        }
        $registeredRows = array_values(array_filter($allFlowRows, static fn (array $row): bool => (bool) $row['registered']));
        $allApprovals = [];
        foreach ($registeredRows as $row) {
            $allApprovals = array_merge($allApprovals, (array) ($row['approvals'] ?? []));
        }

        $payload = [
            'ok' => (bool) ($suite['ok'] ?? false),
            'schema' => self::CONTROL_TOWER_SCHEMA,
            'status' => 'external_execution_blocked_control_tower_ready',
            'generated_at' => now()->toJSON(),
            'source_mandate_suite_hash' => $suite['mandate_suite_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'expected_flow_count' => count($expectedHashes),
                'registered_mandate_count' => count($registeredRows),
                'not_registered_count' => count(array_filter($allFlowRows, static fn (array $row): bool => $row['mandate_status'] === 'not_registered')),
                'preflight_green_count' => count(array_filter($allFlowRows, static fn (array $row): bool => $row['mandate_status'] === 'preflight_green_awaiting_signatures')),
                'awaiting_signature_count' => count(array_filter($allFlowRows, static fn (array $row): bool => $row['mandate_status'] === 'awaiting_operator_and_reviewer_signatures')),
                'signed_manual_handoff_count' => count(array_filter($allFlowRows, static fn (array $row): bool => $row['mandate_status'] === 'signed_mandate_ready_manual_execution_only')),
                'rejected_count' => count(array_filter($allFlowRows, static fn (array $row): bool => $row['mandate_status'] === 'rejected_by_operator_gate')),
                'approval_count' => count($allApprovals),
                'operator_approved_count' => count(array_filter($allApprovals, static fn (array $approval): bool => $approval['approval_role'] === 'operator_signature' && $approval['status'] === 'approved')),
                'second_reviewer_approved_count' => count(array_filter($allApprovals, static fn (array $approval): bool => $approval['approval_role'] === 'second_reviewer_signature' && $approval['status'] === 'approved')),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'manual_execution_handoff_only' => true,
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete'],
                'automation_boundary' => 'internal_supervised_runtime_and_operator_packet_generation_only',
            ],
            'companies' => $companyRows,
        ];
        $payload['control_tower_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function activationCockpit(?string $companyId = null): array
    {
        $tower = $this->controlTower($companyId);
        $companyRows = [];

        foreach ((array) ($tower['companies'] ?? []) as $company) {
            $flowRows = array_values(array_map(
                fn (array $flow): array => $this->activationCockpitFlow($flow),
                (array) ($company['flow_rows'] ?? []),
            ));
            $externalReady = count(array_filter(
                $flowRows,
                static fn (array $flow): bool => (bool) ($flow['manual_handoff_ready'] ?? false),
            ));
            $blocked = count($flowRows) - $externalReady;
            $priorityScore = array_sum(array_map(
                static fn (array $flow): int => (int) ($flow['activation_priority_score'] ?? 0),
                $flowRows,
            ));

            $companyRows[] = [
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'expected_flow_count' => count($flowRows),
                'manual_handoff_ready_count' => $externalReady,
                'blocked_flow_count' => $blocked,
                'activation_priority_score' => $priorityScore,
                'missing_connector_activation_count' => array_sum(array_map(
                    static fn (array $flow): int => (int) ($flow['gap_counts']['connector_activation'] ?? 0),
                    $flowRows,
                )),
                'missing_operationalization_count' => array_sum(array_map(
                    static fn (array $flow): int => (int) ($flow['gap_counts']['operationalization'] ?? 0),
                    $flowRows,
                )),
                'missing_governance_count' => array_sum(array_map(
                    static fn (array $flow): int => (int) ($flow['gap_counts']['governance'] ?? 0),
                    $flowRows,
                )),
                'activation_stage_counts' => $this->countsByKey($flowRows, 'activation_stage'),
                'flow_backlog' => $flowRows,
            ];
        }

        usort(
            $companyRows,
            static fn (array $left, array $right): int => ((int) $right['activation_priority_score']) <=> ((int) $left['activation_priority_score']),
        );

        $allFlows = [];
        foreach ($companyRows as $company) {
            $allFlows = array_merge($allFlows, (array) ($company['flow_backlog'] ?? []));
        }
        $manualReady = count(array_filter(
            $allFlows,
            static fn (array $flow): bool => (bool) ($flow['manual_handoff_ready'] ?? false),
        ));

        $payload = [
            'ok' => (bool) ($tower['ok'] ?? false),
            'schema' => self::ACTIVATION_COCKPIT_SCHEMA,
            'status' => 'enterprise_activation_backlog_ready_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'source_control_tower_hash' => $tower['control_tower_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($allFlows),
                'manual_handoff_ready_count' => $manualReady,
                'blocked_flow_count' => count($allFlows) - $manualReady,
                'activation_priority_score' => array_sum(array_map(
                    static fn (array $company): int => (int) ($company['activation_priority_score'] ?? 0),
                    $companyRows,
                )),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'enterprise_pattern_sources' => [
                [
                    'source_id' => 'anthropic_claude_for_financial_services',
                    'applied_pattern' => 'regulated_domain_precision_context_controls_and_finance_specific_demo_depth',
                    'used_for' => ['finance', 'portfolio_risk', 'compliance_review', 'investment_committee_packets'],
                ],
                [
                    'source_id' => 'openai_agents_sdk',
                    'applied_pattern' => 'handoffs_guardrails_tool_tracing_and_run_observability',
                    'used_for' => ['agent_handoffs', 'tool_guardrails', 'trace_requirements'],
                ],
                [
                    'source_id' => 'langgraph_durable_execution',
                    'applied_pattern' => 'checkpointed_state_resume_human_in_the_loop_and_fault_tolerant_execution',
                    'used_for' => ['runtime_checkpoints', 'approval_resume', 'incident_replay'],
                ],
                [
                    'source_id' => 'microsoft_agent_framework',
                    'applied_pattern' => 'typed_workflows_state_management_middleware_telemetry_and_human_approval',
                    'used_for' => ['explicit_multi_agent_workflows', 'telemetry', 'approval_required_tools'],
                ],
            ],
            'activation_policy' => [
                'external_execution_allowed' => false,
                'activation_boundary' => 'cockpit_generates_backlog_and_evidence_requirements_only',
                'required_before_real_external_execution' => [
                    'credential_vault_binding_per_connector',
                    'sandbox_probe_receipts',
                    'production_scope_signed_by_operator_and_second_reviewer',
                    'budget_or_loss_cap_signed',
                    'rollback_or_compensation_drill_receipt',
                    'incident_route_drill_receipt',
                    'slo_monitor_bound_to_alert_route',
                    'post_execution_reconciliation_job',
                ],
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin'],
            ],
            'companies' => $companyRows,
        ];
        $payload['activation_cockpit_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function premiumActivationStatus(?string $companyId = null): array
    {
        $suite = $this->fixtureSuite->externalActionMandateSuite($companyId);
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->premiumActivationCompany((string) ($company['company_id'] ?? 'unknown')),
            (array) ($suite['companies'] ?? []),
        ));

        $readyCompanies = count(array_filter(
            $companyRows,
            static fn (array $company): bool => (bool) data_get($company, 'premium_readiness.ready'),
        ));
        $waitDays = array_map(
            static fn (array $company): int => (int) data_get($company, 'premium_readiness.buildout_wait_days_required', 30),
            $companyRows,
        );

        $payload = [
            'ok' => (bool) ($suite['ok'] ?? false) && $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::PREMIUM_ACTIVATION_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'premium_activation_ready_external_execution_blocked'
                : 'premium_activation_attention_required',
            'generated_at' => now()->toJSON(),
            'source_mandate_suite_hash' => $suite['mandate_suite_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'premium_ready_company_count' => $readyCompanies,
                'flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_count'], $companyRows)),
                'template_count' => array_sum(array_map(static fn (array $company): int => (int) $company['premium_readiness']['managed_agent_template_count'], $companyRows)),
                'workbench_count' => array_sum(array_map(static fn (array $company): int => (int) $company['premium_readiness']['workbench_count'], $companyRows)),
                'replay_benchmark_count' => array_sum(array_map(static fn (array $company): int => (int) $company['premium_readiness']['replay_benchmark_count'], $companyRows)),
                'wait_days_required_max' => $waitDays === [] ? 30 : max($waitDays),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'fixture_shadow_connector_probe_replay_runbook_and_current_operating_packet_evidence',
                'external_execution_allowed' => false,
                'operator_mandate_required_for_external_action' => true,
                'blocked_without_operator_mandate' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'offensive_operation', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['premium_activation_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function providerWorkbenchStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->providerWorkbenchCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::PROVIDER_WORKBENCH_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'provider_workbenches_ready_external_execution_blocked'
                : 'provider_workbenches_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'provider_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_contract_count'], $companyRows)),
                'connector_workbench_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbench_count'], $companyRows)),
                'flow_provider_route_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_provider_route_count'], $companyRows)),
                'provider_eval_case_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_eval_case_count'], $companyRows)),
                'provider_lineage_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_lineage_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'provider_write_or_paid_action_default' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['provider_workbench_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function agentRepositoryAdoptionStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->agentRepositoryAdoptionCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::AGENT_REPOSITORY_ADOPTION_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'agent_repository_adoption_ready_external_execution_blocked'
                : 'agent_repository_adoption_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'repository_intake_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_intake_count'], $companyRows)),
                'framework_scorecard_count' => array_sum(array_map(static fn (array $company): int => (int) $company['framework_scorecard_count'], $companyRows)),
                'flow_epic_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_epic_count'], $companyRows)),
                'version_pin_count' => array_sum(array_map(static fn (array $company): int => (int) $company['version_pin_count'], $companyRows)),
                'migration_path_count' => array_sum(array_map(static fn (array $company): int => (int) $company['migration_path_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'runtime_use_before_local_contract_tests_allowed' => false,
                'adoption_requires_license_security_sbom_fixture_eval_and_operator_acceptance' => true,
                'operator_mandate_required_for_external_write_or_procurement' => true,
                'blocked_operations' => ['runtime_use_without_tests', 'auto_upgrade', 'auto_procurement', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['agent_repository_adoption_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function industrySolutionEcosystemStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->industrySolutionEcosystemCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::INDUSTRY_SOLUTION_ECOSYSTEM_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'industry_solution_ecosystem_ready_external_execution_blocked'
                : 'industry_solution_ecosystem_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'ecosystem_provider_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ecosystem_provider_count'], $companyRows)),
                'implementation_partner_track_count' => array_sum(array_map(static fn (array $company): int => (int) $company['implementation_partner_track_count'], $companyRows)),
                'flow_workload_pack_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_workload_pack_count'], $companyRows)),
                'data_interface_count' => array_sum(array_map(static fn (array $company): int => (int) $company['data_interface_count'], $companyRows)),
                'audit_control_count' => array_sum(array_map(static fn (array $company): int => (int) $company['audit_control_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'reference_pattern' => 'claude_financial_services_style_industry_solution_adapted_per_company',
                'unified_data_interface_required' => true,
                'direct_source_hyperlinks_required' => true,
                'audit_trail_required_for_every_claim_and_artifact' => true,
                'operator_mandate_required_for_external_write_or_procurement' => true,
                'blocked_operations' => ['external_write', 'auto_procurement', 'paid_spend', 'live_trade', 'public_publish', 'deploy', 'delete', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['industry_solution_ecosystem_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function businessOperatingBackboneStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->businessOperatingBackboneCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::BUSINESS_OPERATING_BACKBONE_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'business_operating_backbone_ready_external_execution_blocked'
                : 'business_operating_backbone_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'ready_component_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_component_count'], $companyRows)),
                'required_component_count' => array_sum(array_map(static fn (array $company): int => (int) $company['required_component_count'], $companyRows)),
                'customer_offer_count' => array_sum(array_map(static fn (array $company): int => (int) $company['customer_offer_count'], $companyRows)),
                'account_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['account_contract_count'], $companyRows)),
                'vendor_due_diligence_count' => array_sum(array_map(static fn (array $company): int => (int) $company['vendor_due_diligence_count'], $companyRows)),
                'resilience_exercise_count' => array_sum(array_map(static fn (array $company): int => (int) $company['resilience_exercise_count'], $companyRows)),
                'analytics_dashboard_count' => array_sum(array_map(static fn (array $company): int => (int) $company['analytics_dashboard_count'], $companyRows)),
                'semantic_graph_node_count' => array_sum(array_map(static fn (array $company): int => (int) $company['semantic_graph_node_count'], $companyRows)),
                'grc_audit_evidence_count' => array_sum(array_map(static fn (array $company): int => (int) $company['grc_audit_evidence_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'required_backbone_components' => [
                    'customer_market_operations',
                    'account_contract_delivery',
                    'vendor_legal_procurement',
                    'resilience_continuity',
                    'analytics_decision_intelligence',
                    'knowledge_memory_learning',
                    'identity_access_data_sovereignty',
                    'control_tower_run_operations',
                    'semantic_operating_graph',
                    'delivery_assurance',
                    'unit_economics_capacity_simulation',
                    'grc',
                ],
                'operator_mandate_required_for_external_customer_vendor_billing_or_capital_action' => true,
                'blocked_operations' => ['external_publish', 'customer_commitment', 'billing', 'procurement', 'sign_contract', 'paid_spend', 'capital_commitment', 'write', 'deploy', 'delete', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['business_operating_backbone_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function productionConnectorPreflightStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->productionConnectorPreflightCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::PRODUCTION_CONNECTOR_PREFLIGHT_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'production_connector_preflight_ready_external_execution_blocked'
                : 'production_connector_preflight_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'connector_preflight_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_preflight_contract_count'], $companyRows)),
                'flow_cutover_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_cutover_count'], $companyRows)),
                'production_evidence_register_count' => array_sum(array_map(static fn (array $company): int => (int) $company['production_evidence_register_count'], $companyRows)),
                'cutover_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['cutover_metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'production_cutover_without_operator_signed_scope_allowed' => false,
                'real_credential_material_in_packet_allowed' => false,
                'manual_execution_handoff_only_after_signed_mandate' => true,
                'blocked_operations' => ['auto_cutover', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['production_connector_preflight_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowQualityResearchStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->flowQualityResearchCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::FLOW_QUALITY_RESEARCH_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'flow_quality_research_ready_external_execution_blocked'
                : 'flow_quality_research_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'offline_dataset_count' => array_sum(array_map(static fn (array $company): int => (int) $company['offline_dataset_count'], $companyRows)),
                'trace_rubric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['trace_rubric_count'], $companyRows)),
                'adversarial_case_count' => array_sum(array_map(static fn (array $company): int => (int) $company['adversarial_case_count'], $companyRows)),
                'deterministic_assertion_count' => array_sum(array_map(static fn (array $company): int => (int) $company['deterministic_assertion_count'], $companyRows)),
                'replay_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_matrix_count'], $companyRows)),
                'tooling_benchmark_count' => array_sum(array_map(static fn (array $company): int => (int) $company['tooling_benchmark_count'], $companyRows)),
                'domain_solution_module_count' => array_sum(array_map(static fn (array $company): int => (int) $company['domain_solution_module_count'], $companyRows)),
                'external_research_source_count' => array_sum(array_map(static fn (array $company): int => (int) $company['external_research_source_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
                'repository_adoption_without_license_security_and_fixture_eval_allowed' => false,
                'external_model_or_paid_benchmark_requires_operator_approval' => true,
                'blocked_operations' => ['synthetic_score_claim', 'promotion_without_replay', 'external_model_benchmark_without_approval', 'repository_adoption_without_review', 'paid_benchmark', 'write', 'publish', 'deploy', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['flow_quality_research_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function companyCommandCenterStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->companyCommandCenterCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::COMPANY_COMMAND_CENTER_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'company_command_center_ready_external_execution_blocked'
                : 'company_command_center_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'operating_cell_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operating_cell_count'], $companyRows)),
                'flow_command_card_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_command_card_count'], $companyRows)),
                'connector_workbench_panel_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbench_panel_count'], $companyRows)),
                'operator_console_view_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_console_view_count'], $companyRows)),
                'work_product_factory_count' => array_sum(array_map(static fn (array $company): int => (int) $company['work_product_factory_count'], $companyRows)),
                'command_center_kpi_count' => array_sum(array_map(static fn (array $company): int => (int) $company['command_center_kpi_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'flow_card_required_before_shadow_or_supervised_runtime' => true,
                'operator_interrupt_required_for_external_side_effect' => true,
                'secret_material_in_packet_allowed' => false,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['company_command_center_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowOperatingPackageStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->flowOperatingPackageCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::FLOW_OPERATING_PACKAGE_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'flow_operating_package_ready_external_execution_blocked'
                : 'flow_operating_package_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'source_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_count'], $companyRows)),
                'flow_package_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_package_count'], $companyRows)),
                'package_hash_count' => array_sum(array_map(static fn (array $company): int => (int) $company['package_hash_count'], $companyRows)),
                'replay_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_contract_count'], $companyRows)),
                'operations_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operations_contract_count'], $companyRows)),
                'metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'package_required_for_every_flow' => true,
                'minimum_replay_cases_before_shadow' => 25,
                'runbook_drill_required_before_supervised_mode' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['flow_operating_package_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function verticalSolutionSuiteStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->verticalSolutionSuiteCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::VERTICAL_SOLUTION_SUITE_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'vertical_solution_suite_ready_external_execution_blocked'
                : 'vertical_solution_suite_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'source_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_count'], $companyRows)),
                'solution_suite_count' => array_sum(array_map(static fn (array $company): int => (int) $company['solution_suite_count'], $companyRows)),
                'flow_solution_kit_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_solution_kit_count'], $companyRows)),
                'connector_workbench_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbench_count'], $companyRows)),
                'artifact_factory_count' => array_sum(array_map(static fn (array $company): int => (int) $company['artifact_factory_count'], $companyRows)),
                'evaluation_recipe_count' => array_sum(array_map(static fn (array $company): int => (int) $company['evaluation_recipe_count'], $companyRows)),
                'metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'reference_pattern' => 'claude_financial_services_unified_domain_solution_generalized_to_every_company',
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'flow_kit_required_for_every_flow' => true,
                'connector_workbench_required_for_every_connector' => true,
                'artifact_factory_required_for_core_work_products' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export', 'auto_procurement'],
            ],
            'companies' => $companyRows,
        ];
        $payload['vertical_solution_suite_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function domainBusinessExecutionMeshStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->domainBusinessExecutionMeshCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::DOMAIN_BUSINESS_EXECUTION_MESH_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'domain_business_execution_mesh_ready_external_execution_blocked'
                : 'domain_business_execution_mesh_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'execution_mode_count' => array_sum(array_map(static fn (array $company): int => (int) $company['execution_mode_count'], $companyRows)),
                'execution_cell_count' => array_sum(array_map(static fn (array $company): int => (int) $company['execution_cell_count'], $companyRows)),
                'flow_kpi_binding_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_kpi_binding_count'], $companyRows)),
                'service_lane_count' => array_sum(array_map(static fn (array $company): int => (int) $company['service_lane_count'], $companyRows)),
                'artifact_delivery_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['artifact_delivery_contract_count'], $companyRows)),
                'metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'domain_specific_execution_required_for_every_flow' => true,
                'service_lane_required_for_every_flow' => true,
                'kpi_contract_required_for_every_flow' => true,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'blocked_operations' => ['external_write', 'public_publish', 'real_spend', 'live_trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export', 'auto_procurement'],
            ],
            'companies' => $companyRows,
        ];
        $payload['domain_business_execution_mesh_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDressRehearsalStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->operationalDressRehearsalCompany((array) $company),
            $this->buildoutCompanies($companyId),
        ));
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => self::OPERATIONAL_DRESS_REHEARSAL_STATUS_SCHEMA,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? 'operational_dress_rehearsal_ready_external_execution_blocked'
                : 'operational_dress_rehearsal_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                'flow_rehearsal_runbook_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_rehearsal_runbook_count'], $companyRows)),
                'live_read_probe_count' => array_sum(array_map(static fn (array $company): int => (int) $company['live_read_probe_count'], $companyRows)),
                'operator_acceptance_packet_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_acceptance_packet_count'], $companyRows)),
                'rollback_drill_count' => array_sum(array_map(static fn (array $company): int => (int) $company['rollback_drill_count'], $companyRows)),
                'promotion_evidence_count' => array_sum(array_map(static fn (array $company): int => (int) $company['promotion_evidence_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_mutation_allowed_during_rehearsal' => false,
                'operator_and_domain_owner_acceptance_required' => true,
                'blocked_operations' => ['external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security'],
            ],
            'companies' => $companyRows,
        ];
        $payload['operational_dress_rehearsal_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function realExternalExecutionReadinessDossier(?string $companyId = null): array
    {
        $cockpit = $this->activationCockpit($companyId);
        $provider = $this->providerWorkbenchStatus($companyId);
        $repositoryAdoption = $this->agentRepositoryAdoptionStatus($companyId);
        $industryEcosystem = $this->industrySolutionEcosystemStatus($companyId);
        $businessBackbone = $this->businessOperatingBackboneStatus($companyId);
        $productionPreflight = $this->productionConnectorPreflightStatus($companyId);
        $flowQuality = $this->flowQualityResearchStatus($companyId);
        $verticalSuites = $this->verticalSolutionSuiteStatus($companyId);
        $businessExecutionMesh = $this->domainBusinessExecutionMeshStatus($companyId);
        $flowPackages = $this->flowOperatingPackageStatus($companyId);
        $commandCenter = $this->companyCommandCenterStatus($companyId);
        $rehearsal = $this->operationalDressRehearsalStatus($companyId);
        $connectorStatus = $this->connectorActivationStatus($companyId);
        $liveRead = $this->liveReadConnectorReadinessStatus($companyId);
        $operationsRunbooks = $this->flowOperationsRunbookStatus($companyId);

        $providerByCompany = [];
        foreach ((array) ($provider['companies'] ?? []) as $company) {
            $providerByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $repositoryAdoptionByCompany = [];
        foreach ((array) ($repositoryAdoption['companies'] ?? []) as $company) {
            $repositoryAdoptionByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $industryEcosystemByCompany = [];
        foreach ((array) ($industryEcosystem['companies'] ?? []) as $company) {
            $industryEcosystemByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $businessBackboneByCompany = [];
        foreach ((array) ($businessBackbone['companies'] ?? []) as $company) {
            $businessBackboneByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $productionPreflightByCompany = [];
        foreach ((array) ($productionPreflight['companies'] ?? []) as $company) {
            $productionPreflightByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $flowQualityByCompany = [];
        foreach ((array) ($flowQuality['companies'] ?? []) as $company) {
            $flowQualityByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $verticalSuitesByCompany = [];
        foreach ((array) ($verticalSuites['companies'] ?? []) as $company) {
            $verticalSuitesByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $businessExecutionMeshByCompany = [];
        foreach ((array) ($businessExecutionMesh['companies'] ?? []) as $company) {
            $businessExecutionMeshByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $flowPackagesByCompany = [];
        foreach ((array) ($flowPackages['companies'] ?? []) as $company) {
            $flowPackagesByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $commandCenterByCompany = [];
        foreach ((array) ($commandCenter['companies'] ?? []) as $company) {
            $commandCenterByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $rehearsalByCompany = [];
        foreach ((array) ($rehearsal['companies'] ?? []) as $company) {
            $rehearsalByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $connectorRowsByFlow = [];
        foreach ((array) ($connectorStatus['records'] ?? []) as $record) {
            $key = (string) ($record['company_id'] ?? 'unknown').'|'.(string) ($record['flow_id'] ?? 'unknown');
            $connectorRowsByFlow[$key][] = (array) $record;
        }
        $liveReadRowsByFlow = [];
        foreach ((array) ($liveRead['records'] ?? []) as $record) {
            $key = (string) ($record['company_id'] ?? 'unknown').'|'.(string) ($record['flow_id'] ?? 'unknown');
            $liveReadRowsByFlow[$key][] = (array) $record;
        }
        $operationsRunbooksByFlow = [];
        foreach ((array) ($operationsRunbooks['records'] ?? []) as $record) {
            $key = (string) ($record['company_id'] ?? 'unknown').'|'.(string) ($record['flow_id'] ?? 'unknown');
            $operationsRunbooksByFlow[$key] = (array) $record;
        }

        $companyRows = [];
        foreach ((array) ($cockpit['companies'] ?? []) as $company) {
            $companyIdValue = (string) ($company['company_id'] ?? 'unknown');
            $providerReady = (bool) data_get($providerByCompany, $companyIdValue.'.ready', false);
            $repositoryAdoptionCompany = (array) ($repositoryAdoptionByCompany[$companyIdValue] ?? []);
            $industryEcosystemCompany = (array) ($industryEcosystemByCompany[$companyIdValue] ?? []);
            $businessBackboneCompany = (array) ($businessBackboneByCompany[$companyIdValue] ?? []);
            $productionPreflightCompany = (array) ($productionPreflightByCompany[$companyIdValue] ?? []);
            $flowQualityCompany = (array) ($flowQualityByCompany[$companyIdValue] ?? []);
            $verticalSuiteCompany = (array) ($verticalSuitesByCompany[$companyIdValue] ?? []);
            $businessExecutionMeshCompany = (array) ($businessExecutionMeshByCompany[$companyIdValue] ?? []);
            $flowPackageCompany = (array) ($flowPackagesByCompany[$companyIdValue] ?? []);
            $commandCenterCompany = (array) ($commandCenterByCompany[$companyIdValue] ?? []);
            $repositoryAdoptionReady = (bool) ($repositoryAdoptionCompany['ready'] ?? false);
            $industryEcosystemReady = (bool) ($industryEcosystemCompany['ready'] ?? false);
            $businessBackboneReady = (bool) ($businessBackboneCompany['ready'] ?? false);
            $productionPreflightReady = (bool) ($productionPreflightCompany['ready'] ?? false);
            $flowQualityReady = (bool) ($flowQualityCompany['ready'] ?? false);
            $verticalSuiteReady = (bool) ($verticalSuiteCompany['ready'] ?? false);
            $businessExecutionMeshReady = (bool) ($businessExecutionMeshCompany['ready'] ?? false);
            $flowPackageReady = (bool) ($flowPackageCompany['ready'] ?? false);
            $commandCenterReady = (bool) ($commandCenterCompany['ready'] ?? false);
            $rehearsalReady = (bool) data_get($rehearsalByCompany, $companyIdValue.'.ready', false);
            $flowRows = array_values(array_map(
                fn (array $flow): array => $this->realExternalExecutionFlowDossier(
                    $companyIdValue,
                    $flow,
                    $providerReady,
                    $repositoryAdoptionReady,
                    $industryEcosystemReady,
                    $repositoryAdoptionCompany,
                    $industryEcosystemCompany,
                    $businessBackboneReady,
                    $businessBackboneCompany,
                    $productionPreflightReady,
                    $productionPreflightCompany,
                    $flowQualityReady,
                    $flowQualityCompany,
                    $verticalSuiteReady,
                    $verticalSuiteCompany,
                    $businessExecutionMeshReady,
                    $businessExecutionMeshCompany,
                    $flowPackageReady,
                    $flowPackageCompany,
                    $commandCenterReady,
                    $commandCenterCompany,
                    $rehearsalReady,
                    (array) ($connectorRowsByFlow[$companyIdValue.'|'.(string) ($flow['flow_id'] ?? 'unknown')] ?? []),
                    (array) ($liveReadRowsByFlow[$companyIdValue.'|'.(string) ($flow['flow_id'] ?? 'unknown')] ?? []),
                    (array) ($operationsRunbooksByFlow[$companyIdValue.'|'.(string) ($flow['flow_id'] ?? 'unknown')] ?? []),
                ),
                (array) ($company['flow_backlog'] ?? []),
            ));

            $companyRows[] = [
                'schema' => 'atlas.ai.company.real_external_execution_readiness_dossier.v1',
                'company_id' => $companyIdValue,
                'provider_workbench_ready' => $providerReady,
                'agent_repository_adoption_ready' => $repositoryAdoptionReady,
                'industry_solution_ecosystem_ready' => $industryEcosystemReady,
                'business_operating_backbone_ready' => $businessBackboneReady,
                'production_connector_preflight_ready' => $productionPreflightReady,
                'flow_quality_research_ready' => $flowQualityReady,
                'vertical_solution_suite_ready' => $verticalSuiteReady,
                'domain_business_execution_mesh_ready' => $businessExecutionMeshReady,
                'flow_operating_package_ready' => $flowPackageReady,
                'company_command_center_ready' => $commandCenterReady,
                'operational_dress_rehearsal_ready' => $rehearsalReady,
                'flow_count' => count($flowRows),
                'manual_handoff_candidate_count' => count(array_filter($flowRows, static fn (array $flow): bool => (bool) $flow['manual_handoff_candidate'])),
                'blocked_flow_count' => count(array_filter($flowRows, static fn (array $flow): bool => ! (bool) $flow['manual_handoff_candidate'])),
                'external_execution_allowed_count' => 0,
                'flow_dossiers' => $flowRows,
            ];
            $companyRows[array_key_last($companyRows)]['company_dossier_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $flowRows = [];
        foreach ($companyRows as $company) {
            $flowRows = array_merge($flowRows, (array) ($company['flow_dossiers'] ?? []));
        }

        $payload = [
            'ok' => (bool) ($cockpit['ok'] ?? false)
                && (bool) ($provider['ok'] ?? false)
                && (bool) ($repositoryAdoption['ok'] ?? false)
                && (bool) ($industryEcosystem['ok'] ?? false)
                && (bool) ($businessBackbone['ok'] ?? false)
                && (bool) ($productionPreflight['ok'] ?? false)
                && (bool) ($flowQuality['ok'] ?? false)
                && (bool) ($verticalSuites['ok'] ?? false)
                && (bool) ($businessExecutionMesh['ok'] ?? false)
                && (bool) ($flowPackages['ok'] ?? false)
                && (bool) ($commandCenter['ok'] ?? false)
                && (bool) ($rehearsal['ok'] ?? false)
                && (bool) ($liveRead['ok'] ?? false),
            'schema' => self::REAL_EXTERNAL_EXECUTION_READINESS_DOSSIER_SCHEMA,
            'status' => 'real_external_execution_dossier_ready_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($flowRows),
                'provider_ready_company_count' => (int) data_get($provider, 'summary.ready_company_count', 0),
                'agent_repository_ready_company_count' => (int) data_get($repositoryAdoption, 'summary.ready_company_count', 0),
                'industry_solution_ecosystem_ready_company_count' => (int) data_get($industryEcosystem, 'summary.ready_company_count', 0),
                'business_operating_backbone_ready_company_count' => (int) data_get($businessBackbone, 'summary.ready_company_count', 0),
                'production_connector_preflight_ready_company_count' => (int) data_get($productionPreflight, 'summary.ready_company_count', 0),
                'flow_quality_research_ready_company_count' => (int) data_get($flowQuality, 'summary.ready_company_count', 0),
                'vertical_solution_suite_ready_company_count' => (int) data_get($verticalSuites, 'summary.ready_company_count', 0),
                'domain_business_execution_mesh_ready_company_count' => (int) data_get($businessExecutionMesh, 'summary.ready_company_count', 0),
                'flow_operating_package_ready_company_count' => (int) data_get($flowPackages, 'summary.ready_company_count', 0),
                'company_command_center_ready_company_count' => (int) data_get($commandCenter, 'summary.ready_company_count', 0),
                'dress_rehearsal_ready_company_count' => (int) data_get($rehearsal, 'summary.ready_company_count', 0),
                'live_read_connector_ready_count' => (int) data_get($liveRead, 'summary.live_read_ready_count', 0),
                'manual_handoff_candidate_count' => count(array_filter($flowRows, static fn (array $flow): bool => (bool) $flow['manual_handoff_candidate'])),
                'blocked_flow_count' => count(array_filter($flowRows, static fn (array $flow): bool => ! (bool) $flow['manual_handoff_candidate'])),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'dossier_is_not_execution_authority' => true,
                'manual_execution_handoff_requires_all_flow_gates_green' => true,
                'required_before_real_external_execution' => [
                    'provider_workbench_ready',
                    'agent_repository_adoption_pipeline_ready',
                    'industry_solution_ecosystem_ready',
                    'business_operating_backbone_ready',
                    'production_connector_preflight_ready',
                    'flow_quality_research_ready',
                    'vertical_solution_suite_ready',
                    'domain_business_execution_mesh_ready',
                    'flow_operating_package_ready',
                    'company_command_center_ready',
                    'operational_dress_rehearsal_ready',
                    'live_read_connector_readiness_ready',
                    'connector_activation_probe_green',
                    'operator_and_second_reviewer_signed_mandate',
                    'vault_scope_attestation',
                    'budget_or_loss_cap_signature',
                    'rollback_and_incident_drill_receipts',
                    'manual_execution_owner_assignment',
                ],
                'blocked_operations' => ['auto_execute', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'source_hashes' => [
                'activation_cockpit_hash' => $cockpit['activation_cockpit_hash'] ?? null,
                'provider_workbench_status_hash' => $provider['provider_workbench_status_hash'] ?? null,
                'agent_repository_adoption_status_hash' => $repositoryAdoption['agent_repository_adoption_status_hash'] ?? null,
                'industry_solution_ecosystem_status_hash' => $industryEcosystem['industry_solution_ecosystem_status_hash'] ?? null,
                'business_operating_backbone_status_hash' => $businessBackbone['business_operating_backbone_status_hash'] ?? null,
                'production_connector_preflight_status_hash' => $productionPreflight['production_connector_preflight_status_hash'] ?? null,
                'flow_quality_research_status_hash' => $flowQuality['flow_quality_research_status_hash'] ?? null,
                'vertical_solution_suite_status_hash' => $verticalSuites['vertical_solution_suite_status_hash'] ?? null,
                'domain_business_execution_mesh_status_hash' => $businessExecutionMesh['domain_business_execution_mesh_status_hash'] ?? null,
                'flow_operating_package_status_hash' => $flowPackages['flow_operating_package_status_hash'] ?? null,
                'company_command_center_status_hash' => $commandCenter['company_command_center_status_hash'] ?? null,
                'operational_dress_rehearsal_status_hash' => $rehearsal['operational_dress_rehearsal_status_hash'] ?? null,
                'connector_activation_status_hash' => $connectorStatus['connector_activation_status_hash'] ?? null,
                'live_read_connector_readiness_status_hash' => $liveRead['live_read_connector_readiness_status_hash'] ?? null,
                'flow_operations_runbook_status_hash' => $operationsRunbooks['flow_operations_runbook_status_hash'] ?? null,
            ],
            'companies' => $companyRows,
        ];
        $payload['real_external_execution_readiness_dossier_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function realExternalExecutionHandoffPack(?string $companyId = null): array
    {
        $dossier = $this->realExternalExecutionReadinessDossier($companyId);
        $companyRows = [];

        foreach ((array) ($dossier['companies'] ?? []) as $company) {
            $flowPacks = array_values(array_map(
                fn (array $flow): array => (array) ($flow['real_external_execution_handoff_pack'] ?? []),
                (array) ($company['flow_dossiers'] ?? []),
            ));
            $flowPacks = array_values(array_filter($flowPacks));
            $companyRows[] = [
                'schema' => 'atlas.ai.company.real_external_execution_handoff_pack.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($flowPacks),
                'handoff_pack_ready_count' => count(array_filter($flowPacks, static fn (array $pack): bool => (bool) ($pack['ready_for_manual_handoff_gate'] ?? false))),
                'external_execution_allowed_count' => 0,
                'flow_handoff_packs' => $flowPacks,
            ];
            $companyRows[array_key_last($companyRows)]['company_handoff_pack_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $flowPacks = [];
        foreach ($companyRows as $company) {
            $flowPacks = array_merge($flowPacks, (array) ($company['flow_handoff_packs'] ?? []));
        }

        $payload = [
            'ok' => (bool) ($dossier['ok'] ?? false) && $companyRows !== [],
            'schema' => self::REAL_EXTERNAL_EXECUTION_HANDOFF_PACK_SCHEMA,
            'status' => 'real_external_execution_handoff_pack_ready_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'source_dossier_hash' => $dossier['real_external_execution_readiness_dossier_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($flowPacks),
                'handoff_pack_ready_count' => count(array_filter($flowPacks, static fn (array $pack): bool => (bool) ($pack['ready_for_manual_handoff_gate'] ?? false))),
                'production_scope_contract_count' => count(array_filter($flowPacks, static fn (array $pack): bool => isset($pack['production_scope_contract']))),
                'legal_risk_acceptance_count' => count(array_filter($flowPacks, static fn (array $pack): bool => isset($pack['legal_risk_acceptance_packet']))),
                'budget_loss_cap_count' => count(array_filter($flowPacks, static fn (array $pack): bool => isset($pack['budget_or_loss_cap_packet']))),
                'manual_owner_assignment_count' => count(array_filter($flowPacks, static fn (array $pack): bool => isset($pack['manual_execution_owner_assignment']))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'handoff_pack_is_not_execution_authority' => true,
                'manual_execution_requires_operator_to_act_outside_autonomous_suite' => true,
                'auto_execute_blocked' => true,
            ],
            'companies' => $companyRows,
        ];
        $payload['real_external_execution_handoff_pack_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function supervisedExternalExecutionPacketStatus(?string $companyId = null): array
    {
        return $this->supervisedExternalExecutionPacketStatusFromHandoff($this->realExternalExecutionHandoffPack($companyId));
    }

    /**
     * @param array<string,mixed> $handoff
     * @return array<string,mixed>
     */
    public function supervisedExternalExecutionPacketStatusFromHandoff(array $handoff): array
    {
        $companyRows = [];

        foreach ((array) ($handoff['companies'] ?? []) as $company) {
            $packets = array_values(array_map(
                fn (array $pack): array => $this->supervisedExternalExecutionPacketForFlow((array) $pack),
                (array) ($company['flow_handoff_packs'] ?? []),
            ));

            $companyRows[] = [
                'schema' => 'atlas.ai.company.supervised_external_execution_packet_status.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($packets),
                'packet_ready_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['packet_ready'] ?? false))),
                'operator_signature_required_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['operator_signature_required'] ?? false))),
                'second_reviewer_required_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['second_reviewer_required'] ?? false))),
                'kill_switch_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) data_get($packet, 'runtime_control.kill_switch_bound', false))),
                'post_execution_reconciliation_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) data_get($packet, 'post_execution_reconciliation.bound', false))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'flow_packets' => $packets,
            ];
            $companyRows[array_key_last($companyRows)]['company_packet_status_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $packets = [];
        foreach ($companyRows as $company) {
            $packets = array_merge($packets, (array) ($company['flow_packets'] ?? []));
        }

        $readyCount = count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['packet_ready'] ?? false)));

        $payload = [
            'ok' => (bool) ($handoff['ok'] ?? false) && $packets !== [] && $readyCount === count($packets),
            'schema' => self::SUPERVISED_EXTERNAL_EXECUTION_PACKET_STATUS_SCHEMA,
            'status' => $packets !== [] && $readyCount === count($packets)
                ? 'supervised_external_execution_packets_ready_external_worker_disabled'
                : 'supervised_external_execution_packets_attention_required',
            'generated_at' => now()->toJSON(),
            'source_handoff_pack_hash' => $handoff['real_external_execution_handoff_pack_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($packets),
                'packet_ready_count' => $readyCount,
                'operator_signature_required_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['operator_signature_required'] ?? false))),
                'second_reviewer_required_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['second_reviewer_required'] ?? false))),
                'production_scope_contract_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) data_get($packet, 'production_scope.ready', false))),
                'runtime_control_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) data_get($packet, 'runtime_control.ready', false))),
                'post_execution_reconciliation_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) data_get($packet, 'post_execution_reconciliation.bound', false))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_worker_enabled' => false,
                'packet_is_execution_plan_not_execution_authority' => true,
                'operator_and_second_reviewer_required_for_real_action' => true,
                'credential_material_in_packet_allowed' => false,
                'auto_retry_external_action_allowed' => false,
                'blocked_operations' => ['auto_execute', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['supervised_external_execution_packet_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalWorkerPreflightStatus(?string $companyId = null): array
    {
        return $this->externalWorkerPreflightStatusFromPackets($this->supervisedExternalExecutionPacketStatus($companyId));
    }

    /**
     * @param array<string,mixed> $packetStatus
     * @return array<string,mixed>
     */
    public function externalWorkerPreflightStatusFromPackets(array $packetStatus): array
    {
        $companyRows = [];

        foreach ((array) ($packetStatus['companies'] ?? []) as $company) {
            $preflights = array_values(array_map(
                fn (array $packet): array => $this->externalWorkerPreflightForPacket((array) $packet),
                (array) ($company['flow_packets'] ?? []),
            ));

            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_worker_preflight_status.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($preflights),
                'worker_preflight_ready_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) ($preflight['worker_preflight_ready'] ?? false))),
                'worker_dispatch_disabled_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'worker_controls.external_worker_dispatch_enabled', true) === false)),
                'credential_gate_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'credential_gate.bound', false))),
                'idempotency_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'execution_envelope.idempotency_key_required', false))),
                'reconciliation_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'post_execution_reconciliation.bound', false))),
                'external_execution_allowed_count' => 0,
                'flow_worker_preflights' => $preflights,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_worker_preflight_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $preflights = [];
        foreach ($companyRows as $company) {
            $preflights = array_merge($preflights, (array) ($company['flow_worker_preflights'] ?? []));
        }

        $readyCount = count(array_filter($preflights, static fn (array $preflight): bool => (bool) ($preflight['worker_preflight_ready'] ?? false)));

        $payload = [
            'ok' => (bool) ($packetStatus['ok'] ?? false) && $preflights !== [] && $readyCount === count($preflights),
            'schema' => self::EXTERNAL_WORKER_PREFLIGHT_STATUS_SCHEMA,
            'status' => $preflights !== [] && $readyCount === count($preflights)
                ? 'external_worker_preflight_ready_execution_disabled'
                : 'external_worker_preflight_attention_required',
            'generated_at' => now()->toJSON(),
            'source_supervised_external_execution_packet_status_hash' => $packetStatus['supervised_external_execution_packet_status_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($preflights),
                'worker_preflight_ready_count' => $readyCount,
                'worker_plan_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'worker_plan.bound', false))),
                'execution_envelope_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'execution_envelope.bound', false))),
                'credential_gate_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'credential_gate.bound', false))),
                'worker_controls_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'worker_controls.bound', false))),
                'post_execution_reconciliation_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'post_execution_reconciliation.bound', false))),
                'external_worker_dispatch_disabled_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'worker_controls.external_worker_dispatch_enabled', true) === false)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_worker_dispatch_enabled' => false,
                'preflight_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'operator_and_second_reviewer_required_for_dispatch' => true,
                'credential_material_in_packet_allowed' => false,
                'worker_requires_signed_scope_vault_reference_idempotency_kill_switch_and_reconciliation' => true,
                'blocked_operations' => ['auto_dispatch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['external_worker_preflight_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function registerActivationBacklog(?string $companyId = null): array
    {
        $cockpit = $this->activationCockpit($companyId);
        $records = [];

        foreach ((array) ($cockpit['companies'] ?? []) as $company) {
            foreach ((array) ($company['flow_backlog'] ?? []) as $flow) {
                foreach ((array) ($flow['implementation_work_packages'] ?? []) as $workPackage) {
                    $records[] = $this->persistActivationBacklogItem($flow, (array) $workPackage);
                }
            }
        }

        $payload = [
            'ok' => (bool) ($cockpit['ok'] ?? false) && $records !== [],
            'schema' => self::ACTIVATION_BACKLOG_REGISTRY_SCHEMA,
            'status' => $records !== [] ? 'queued_for_implementation' : 'empty_backlog',
            'generated_at' => now()->toJSON(),
            'source_activation_cockpit_hash' => $cockpit['activation_cockpit_hash'] ?? null,
            'summary' => [
                'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'work_package_count' => count($records),
                'manual_handoff_ready_item_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['manual_handoff_ready'])),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'records' => $records,
            'registry_policy' => [
                'queue_status' => 'queued_for_implementation',
                'allowed_next_actions' => ['assign_owner', 'attach_evidence', 'run_non_production_dress_rehearsal', 'operator_review'],
                'blocked_next_actions' => ['auto_execute_external_action', 'production_write_without_signed_scope', 'spend_or_trade_without_cap'],
                'external_execution_enabled_by_registry' => false,
            ],
        ];
        $payload['activation_backlog_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function activationBacklogStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $items = AiHoldingActivationBacklogItem::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get();
        $records = $items->map(fn (AiHoldingActivationBacklogItem $item): array => $this->activationBacklogPayload($item))->all();
        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $companyRecords) {
            $rows = $companyRecords->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['flow_id'], $rows))),
                'work_package_count' => count($rows),
                'queued_count' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'queued_for_implementation')),
                'completed_count' => count(array_filter($rows, static fn (array $row): bool => str_starts_with((string) $row['status'], 'completed'))),
                'blocked_count' => count(array_filter($rows, static fn (array $row): bool => str_starts_with((string) $row['status'], 'blocked'))),
                'manual_handoff_ready_item_count' => count(array_filter($rows, static fn (array $row): bool => (bool) $row['manual_handoff_ready'])),
                'priority_score' => array_sum(array_map(static fn (array $row): int => (int) $row['priority_score'], $rows)),
                'status_counts' => $this->countsByKey($rows, 'status'),
                'work_package_counts' => $this->countsByKey($rows, 'work_package_id'),
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => self::ACTIVATION_BACKLOG_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_backlog' : 'backlog_registered_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'work_package_count' => count($records),
                'queued_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'queued_for_implementation')),
                'completed_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'completed'))),
                'blocked_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'blocked'))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'manual_completion_does_not_enable_external_execution' => true,
                'required_before_closing_item' => ['evidence_receipt', 'operator_or_owner_attestation', 'rollback_or_noop_rationale'],
            ],
        ];
        $payload['activation_backlog_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function runActivationBacklog(?string $companyId = null, ?string $flowId = null, ?string $workPackageId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;
        $wantedWorkPackage = $workPackageId !== null && trim($workPackageId) !== '' ? trim($workPackageId) : null;

        $items = AiHoldingActivationBacklogItem::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow))
            ->when($wantedWorkPackage !== null, static fn ($query) => $query->where('work_package_id', $wantedWorkPackage))
            ->orderByDesc('manual_handoff_ready')
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->orderBy('work_package_id')
            ->get();

        $records = $items->map(fn (AiHoldingActivationBacklogItem $item): array => $this->runActivationBacklogItem($item))->all();
        $payload = [
            'ok' => $records !== [],
            'schema' => self::ACTIVATION_BACKLOG_RUN_SCHEMA,
            'status' => $records === [] ? 'empty_backlog' : 'implementation_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'work_package_count' => count($records),
                'completed_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'completed'))),
                'blocked_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'blocked'))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'records' => $records,
            'run_policy' => [
                'mode' => 'internal_enterprise_implementation_cycle',
                'external_execution_allowed' => false,
                'side_effect_boundary' => 'receipts_and_internal_backlog_state_only',
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin'],
                'promotion_requires' => [
                    'real_connector_adapter',
                    'credential_vault_binding',
                    'sandbox_probe_receipt',
                    'operator_and_second_reviewer_signed_scope',
                    'budget_or_loss_cap_signature',
                    'rollback_or_compensation_drill',
                    'incident_route_drill',
                    'post_execution_reconciliation',
                ],
            ],
        ];
        $payload['activation_backlog_run_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function registerConnectorActivations(?string $companyId = null): array
    {
        $suite = $this->fixtureSuite->connectorCertificationSuite($companyId);
        $records = [];

        foreach ((array) ($suite['companies'] ?? []) as $company) {
            $certifications = [];
            foreach ((array) ($company['connector_certifications'] ?? []) as $certification) {
                $certifications[(string) ($certification['connector_id'] ?? 'unknown')] = (array) $certification;
            }

            foreach ((array) ($company['flow_usage_attestations'] ?? []) as $usage) {
                foreach ((array) ($usage['connector_scope'] ?? []) as $connectorId) {
                    $connectorId = (string) $connectorId;
                    $records[] = $this->persistConnectorActivationRecord(
                        (string) ($company['company_id'] ?? 'unknown'),
                        (array) $usage,
                        (array) ($certifications[$connectorId] ?? []),
                        $connectorId,
                    );
                }
            }
        }

        $payload = [
            'ok' => (bool) ($suite['ok'] ?? false) && $records !== [],
            'schema' => self::CONNECTOR_ACTIVATION_REGISTRY_SCHEMA,
            'status' => $records === [] ? 'empty_registry' : 'registered_needs_sandbox_probe',
            'generated_at' => now()->toJSON(),
            'source_connector_certification_hash' => $suite['certification_suite_hash'] ?? null,
            'summary' => $this->connectorActivationSummary($records),
            'records' => $records,
            'registry_policy' => [
                'mode' => 'persist_certified_connector_activation_records',
                'external_execution_allowed' => false,
                'side_effect_boundary' => 'internal_connector_contracts_probe_receipts_and_status_only',
                'allowed_next_actions' => ['run_sandbox_probe', 'bind_vault_scope_attestation', 'attach_slo_monitor', 'operator_review'],
                'blocked_next_actions' => ['production_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin_without_signed_scope'],
            ],
        ];
        $payload['connector_activation_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function probeConnectorActivations(?string $companyId = null, ?string $flowId = null, ?string $connectorId = null): array
    {
        $records = $this->connectorActivationQuery($companyId, $flowId, $connectorId)->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->probeConnectorActivationRecord($record))
            ->all();

        $payload = [
            'ok' => $records !== [],
            'schema' => self::CONNECTOR_ACTIVATION_PROBE_SCHEMA,
            'status' => $records === [] ? 'empty_probe_scope' : 'sandbox_probe_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->connectorActivationSummary($records),
            'records' => $records,
            'probe_policy' => [
                'mode' => 'internal_sandbox_probe_and_contract_attestation',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'probe_modes' => ['schema_validate', 'auth_scope_check', 'read_only_ping', 'fixture_fetch', 'receipt_export'],
                'promotion_requires' => ['operator_signed_scope', 'production_credential_vault_binding', 'budget_or_loss_cap_if_applicable', 'rollback_or_compensation_drill'],
            ],
        ];
        $payload['connector_activation_probe_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorActivationStatus(?string $companyId = null): array
    {
        $records = $this->connectorActivationQuery($companyId, null, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->orderBy('connector_id')
            ->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->connectorActivationPayload($record))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['flow_id'], $items))),
                'connector_activation_count' => count($items),
                'unique_connector_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['connector_id'], $items))),
                'probe_green_count' => count(array_filter($items, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])),
                'activated_internal_count' => count(array_filter($items, static fn (array $row): bool => $row['status'] === 'activated_internal_connector_ready_external_blocked')),
                'status_counts' => $this->countsByKey($items, 'status'),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => self::CONNECTOR_ACTIVATION_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_registry' : 'connector_activation_records_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->connectorActivationSummary($records),
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'internal_activation_does_not_enable_external_side_effects' => true,
                'production_cutover_requires_operator_signed_scope' => true,
            ],
        ];
        $payload['connector_activation_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function liveReadConnectorReadinessStatus(?string $companyId = null): array
    {
        $records = $this->connectorActivationQuery($companyId, null, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->orderBy('connector_id')
            ->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->liveReadConnectorPayload($record))
            ->all();

        $readyRecords = array_values(array_filter($records, static fn (array $record): bool => (bool) ($record['live_read_ready'] ?? false)));
        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['flow_id'], $items))),
                'connector_readiness_count' => count($items),
                'live_read_ready_count' => count(array_filter($items, static fn (array $row): bool => (bool) ($row['live_read_ready'] ?? false))),
                'schema_snapshot_count' => count(array_filter($items, static fn (array $row): bool => strlen((string) ($row['schema_snapshot_hash'] ?? '')) === 64)),
                'sample_payload_count' => count(array_filter($items, static fn (array $row): bool => strlen((string) ($row['sample_payload_hash'] ?? '')) === 64)),
                'vault_scope_reference_count' => count(array_filter($items, static fn (array $row): bool => (string) ($row['vault_scope_reference'] ?? '') !== '')),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ];
        }

        $payload = [
            'ok' => $records !== [] && count($records) === count($readyRecords),
            'schema' => self::LIVE_READ_CONNECTOR_READINESS_STATUS_SCHEMA,
            'status' => $records !== [] && count($records) === count($readyRecords)
                ? 'live_read_connector_readiness_ready_external_execution_blocked'
                : ($records === [] ? 'empty_live_read_connector_readiness' : 'live_read_connector_readiness_attention_required'),
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'connector_readiness_count' => count($records),
                'live_read_ready_count' => count($readyRecords),
                'schema_snapshot_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['schema_snapshot_hash'] ?? '')) === 64)),
                'sample_payload_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['sample_payload_hash'] ?? '')) === 64)),
                'provider_lineage_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['provider_lineage_hash'] ?? '')) === 64)),
                'vault_scope_reference_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['vault_scope_reference'] ?? '') !== '')),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'credential_material_in_packet_allowed' => false,
                'read_only_probe_or_fixture_until_real_vault_scope' => true,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
            ],
            'companies' => $companyRows,
            'records' => $records,
        ];
        $payload['live_read_connector_readiness_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function registerFlowRunQueue(?string $companyId = null): array
    {
        $cockpit = $this->activationCockpit($companyId);
        $records = [];

        foreach ((array) ($cockpit['companies'] ?? []) as $company) {
            foreach ((array) ($company['flow_backlog'] ?? []) as $flow) {
                $records[] = $this->persistFlowRunQueueItem((array) $flow);
            }
        }

        $payload = [
            'ok' => (bool) ($cockpit['ok'] ?? false) && $records !== [],
            'schema' => self::FLOW_RUN_QUEUE_REGISTRY_SCHEMA,
            'status' => $records === [] ? 'empty_queue' : 'queued_for_internal_execution',
            'generated_at' => now()->toJSON(),
            'source_activation_cockpit_hash' => $cockpit['activation_cockpit_hash'] ?? null,
            'summary' => $this->flowRunQueueSummary($records),
            'records' => $records,
            'queue_policy' => [
                'mode' => 'durable_internal_flow_execution_queue',
                'external_execution_allowed' => false,
                'side_effect_boundary' => 'internal_queue_receipts_execution_receipts_and_dlq_state_only',
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin'],
                'execute_requires' => ['connector_activation_probe_green_or_no_connector_scope', 'work_packages_not_blocked_by_governance', 'policy_boundary_green'],
            ],
        ];
        $payload['flow_run_queue_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function executeFlowRunQueue(?string $companyId = null, ?string $flowId = null): array
    {
        $items = $this->flowRunQueueQuery($companyId, $flowId)
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->executeFlowRunQueueItem($item))
            ->all();

        $payload = [
            'ok' => $items !== [],
            'schema' => self::FLOW_RUN_QUEUE_EXECUTION_SCHEMA,
            'status' => $items === [] ? 'empty_queue' : 'flow_queue_execution_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowRunQueueSummary($items),
            'records' => $items,
            'execution_policy' => [
                'mode' => 'internal_supervised_flow_queue_execution',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'dlq_replay_requires' => ['fix_connector_probe', 'complete_governance_pack', 'operator_review'],
            ],
        ];
        $payload['flow_run_queue_execution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function replayFlowRunQueue(?string $companyId = null, ?string $flowId = null): array
    {
        $items = $this->flowRunQueueQuery($companyId, $flowId)
            ->where('status', 'dlq_blocked_internal_execution')
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->executeFlowRunQueueItem($item, true))
            ->all();

        $payload = [
            'ok' => $items !== [],
            'schema' => self::FLOW_RUN_QUEUE_REPLAY_SCHEMA,
            'status' => $items === [] ? 'empty_dlq' : 'dlq_replay_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowRunQueueSummary($items),
            'records' => $items,
            'replay_policy' => [
                'mode' => 'durable_dlq_replay_after_resolution',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'replay_scope' => 'dlq_blocked_internal_execution_only',
                'resolution_signals_checked' => [
                    'connector_activation_probe_green_or_no_connector_scope',
                    'blocked_work_package_count_zero',
                    'policy_boundary_green',
                ],
                'still_blocked_next_actions' => ['resolve_backlog_item', 'run_connector_probe', 'operator_review'],
            ],
        ];
        $payload['flow_run_queue_replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowRunQueueStatus(?string $companyId = null): array
    {
        $records = $this->flowRunQueueQuery($companyId, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->flowRunQueuePayload($item))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count($items),
                'completed_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'completed_internal_flow_execution')),
                'dlq_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'dlq_blocked_internal_execution')),
                'queued_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'queued_for_internal_execution')),
                'status_counts' => $this->countsByKey($items, 'status'),
                'external_execution_allowed_count' => 0,
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => self::FLOW_RUN_QUEUE_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_queue' : 'flow_run_queue_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowRunQueueSummary($records),
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'completed_internal_flow_does_not_enable_external_execution' => true,
                'dlq_replay_requires_operator_or_owner_attestation' => true,
            ],
        ];
        $payload['flow_run_queue_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function registerFlowOperationsRunbooks(?string $companyId = null): array
    {
        $queueItems = $this->flowRunQueueQuery($companyId, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get();

        if ($queueItems->isEmpty()) {
            $this->registerFlowRunQueue($companyId);
            $queueItems = $this->flowRunQueueQuery($companyId, null)
                ->orderBy('company_id')
                ->orderBy('flow_id')
                ->get();
        }

        $records = $queueItems
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->persistFlowOperationsRunbook($item))
            ->all();

        $payload = [
            'ok' => $records !== [],
            'schema' => self::FLOW_OPERATIONS_RUNBOOK_REGISTRY_SCHEMA,
            'status' => $records === [] ? 'empty_operations_runbook_registry' : 'registered_needs_operations_drill',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowOperationsRunbookSummary($records),
            'records' => $records,
            'registry_policy' => [
                'mode' => 'persistent_flow_slo_incident_reconciliation_runbooks',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'required_before_supervised_external_handoff' => [
                    'slo_contract_drilled',
                    'incident_route_drilled',
                    'reconciliation_contract_drilled',
                    'promotion_gates_green',
                ],
                'pattern_sources' => [
                    'openai_agents_handoffs_guardrails_tracing',
                    'langgraph_checkpoint_resume_human_in_the_loop',
                    'sre_incident_runbook_per_alert_or_flow',
                ],
            ],
        ];
        $payload['flow_operations_runbook_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function drillFlowOperationsRunbooks(?string $companyId = null, ?string $flowId = null): array
    {
        $records = $this->flowOperationsRunbookQuery($companyId, $flowId)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowOperationsRunbook $runbook): array => $this->drillFlowOperationsRunbook($runbook))
            ->all();

        $payload = [
            'ok' => $records !== [],
            'schema' => self::FLOW_OPERATIONS_RUNBOOK_DRILL_SCHEMA,
            'status' => $records === [] ? 'empty_operations_runbook_drill_scope' : 'operations_runbook_drill_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowOperationsRunbookSummary($records),
            'records' => $records,
            'drill_policy' => [
                'mode' => 'internal_slo_incident_reconciliation_dress_rehearsal',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'drill_modes' => ['slo_contract_check', 'incident_route_check', 'reconciliation_check', 'promotion_gate_check'],
                'blocked_operations' => ['page_real_oncall', 'write_production', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
            ],
        ];
        $payload['flow_operations_runbook_drill_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowOperationsRunbookStatus(?string $companyId = null): array
    {
        $records = $this->flowOperationsRunbookQuery($companyId, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowOperationsRunbook $runbook): array => $this->flowOperationsRunbookPayload($runbook))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count($items),
                'operations_green_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'operations_runbook_green_external_blocked')),
                'status_counts' => $this->countsByKey($items, 'status'),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => self::FLOW_OPERATIONS_RUNBOOK_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_operations_runbook_registry' : 'flow_operations_runbooks_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowOperationsRunbookSummary($records),
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'operations_green_does_not_enable_external_execution' => true,
                'production_promotion_requires_operator_signed_mandate' => true,
            ],
        ];
        $payload['flow_operations_runbook_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $suite
     * @return list<array<string,mixed>>
     */
    private function packets(array $suite, ?string $flowId): array
    {
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;
        $packets = [];

        foreach ((array) ($suite['companies'] ?? []) as $company) {
            foreach ((array) ($company['mandate_packets'] ?? []) as $packet) {
                if ($wantedFlow !== null && ($packet['flow_id'] ?? null) !== $wantedFlow) {
                    continue;
                }

                $packets[] = (array) $packet;
            }
        }

        return $packets;
    }

    /**
     * @param array<string,mixed> $packet
     * @return array<string,mixed>
     */
    private function persistPacket(array $packet): array
    {
        $record = AiHoldingExternalActionMandate::query()->updateOrCreate(
            ['mandate_packet_hash' => (string) ($packet['mandate_packet_hash'] ?? '')],
            [
                'company_id' => (string) ($packet['company_id'] ?? 'unknown'),
                'flow_id' => (string) ($packet['flow_id'] ?? 'unknown'),
                'status' => 'queued_for_operator_review',
                'source_runtime_receipt_hash' => (string) ($packet['source_runtime_receipt_hash'] ?? ''),
                'source_connector_certification_hash' => (string) ($packet['source_connector_certification_hash'] ?? ''),
                'source_flow_usage_attestation_hash' => (string) ($packet['source_flow_usage_attestation_hash'] ?? ''),
                'connector_scope_json' => array_values((array) ($packet['connector_scope'] ?? [])),
                'blocked_operations_json' => array_values((array) ($packet['blocked_operations_until_signed_mandate'] ?? [])),
                'preflight_checks_json' => array_values((array) ($packet['preflight_checks'] ?? [])),
                'risk_controls_json' => (array) ($packet['risk_controls'] ?? []),
                'rollback_or_compensation_json' => (array) ($packet['rollback_or_compensation_plan'] ?? []),
                'incident_route_json' => (array) ($packet['incident_route'] ?? []),
                'cost_budget_envelope_json' => (array) ($packet['cost_budget_envelope'] ?? []),
                'operator_signature_required' => (bool) ($packet['operator_signature_required'] ?? true),
                'second_reviewer_required' => (bool) ($packet['second_reviewer_required'] ?? true),
                'auto_execute_allowed' => (bool) ($packet['auto_execute_allowed'] ?? false),
                'external_side_effects_enabled' => (bool) ($packet['external_side_effects_enabled'] ?? false),
                'packet_json' => $packet,
                'queued_at' => now(),
            ],
        );

        return $this->recordPayload($record);
    }

    /**
     * @param array<string,mixed> $flow
     * @param array<string,mixed> $workPackage
     * @return array<string,mixed>
     */
    private function persistActivationBacklogItem(array $flow, array $workPackage): array
    {
        $record = AiHoldingActivationBacklogItem::query()->updateOrCreate(
            [
                'company_id' => (string) ($flow['company_id'] ?? 'unknown'),
                'flow_id' => (string) ($flow['flow_id'] ?? 'unknown'),
                'work_package_id' => (string) ($workPackage['id'] ?? 'unknown'),
            ],
            [
                'status' => 'queued_for_implementation',
                'activation_stage' => (string) ($flow['activation_stage'] ?? 'unknown'),
                'priority_score' => (int) ($flow['activation_priority_score'] ?? 0),
                'mandate_packet_hash' => (string) ($flow['mandate_packet_hash'] ?? '') ?: null,
                'owner' => (string) ($workPackage['owner'] ?? 'portfolio_governor'),
                'deliverable' => (string) ($workPackage['deliverable'] ?? 'activation_work_package'),
                'gap_counts_json' => (array) ($flow['gap_counts'] ?? []),
                'connector_activation_gaps_json' => array_values((array) ($flow['connector_activation_gaps'] ?? [])),
                'governance_gaps_json' => array_values((array) ($flow['governance_gaps'] ?? [])),
                'operationalization_gaps_json' => array_values((array) ($flow['operationalization_gaps'] ?? [])),
                'evidence_required_json' => array_values((array) ($flow['evidence_required_for_real_operation'] ?? [])),
                'evidence_attached_json' => [],
                'implementation_receipts_json' => [],
                'source_flow_backlog_json' => $flow,
                'implementation_attempt_count' => 0,
                'last_implementation_receipt_hash' => null,
                'blocked_reason' => null,
                'manual_handoff_ready' => (bool) ($flow['manual_handoff_ready'] ?? false),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'queued_at' => now(),
                'last_run_at' => null,
                'completed_at' => null,
            ],
        );

        return $this->activationBacklogPayload($record);
    }

    /**
     * @param array<string,mixed> $usage
     * @param array<string,mixed> $certification
     * @return array<string,mixed>
     */
    private function persistConnectorActivationRecord(string $companyId, array $usage, array $certification, string $connectorId): array
    {
        $record = AiHoldingConnectorActivationRecord::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'flow_id' => (string) ($usage['flow_id'] ?? 'unknown'),
                'connector_id' => $connectorId,
            ],
            [
                'status' => 'registered_needs_probe',
                'certification_receipt_hash' => (string) ($certification['certification_receipt_hash'] ?? '') ?: null,
                'flow_usage_attestation_hash' => (string) ($usage['usage_attestation_hash'] ?? '') ?: null,
                'adapter_contract_hash' => $this->hashIfPresent((array) ($certification['adapter_contract'] ?? [])),
                'auth_boundary_hash' => $this->hashIfPresent((array) ($certification['auth_boundary'] ?? [])),
                'sandbox_probe_hash' => (string) data_get($certification, 'sandbox_probe_result.probe_hash') ?: null,
                'slo_hash' => (string) data_get($certification, 'slo_failure_attestation.slo_hash') ?: null,
                'lineage_hash' => (string) data_get($certification, 'lineage_attestation.lineage_hash') ?: null,
                'replay_fixture_hash' => (string) data_get($certification, 'replay_fixture_attestation.fixture_hash') ?: null,
                'allowed_modes_json' => array_values((array) ($usage['allowed_modes'] ?? [])),
                'blocked_modes_json' => array_values(array_unique(array_merge(
                    (array) ($usage['blocked_modes'] ?? []),
                    ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
                ))),
                'pre_run_requirements_json' => array_values((array) ($usage['pre_run_requirements'] ?? [])),
                'probe_receipts_json' => [],
                'last_probe_receipt_hash' => null,
                'last_probe_status' => null,
                'vault_binding_required' => true,
                'vault_binding_attested' => false,
                'sandbox_probe_green' => false,
                'slo_monitor_bound' => false,
                'reconciliation_bound' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registered_at' => now(),
                'last_probed_at' => null,
                'activated_at' => null,
            ],
        );

        return $this->connectorActivationPayload($record);
    }

    /**
     * @return array<string,mixed>
     */
    private function probeConnectorActivationRecord(AiHoldingConnectorActivationRecord $record): array
    {
        $checks = [
            'certification_receipt_present' => strlen((string) $record->certification_receipt_hash) === 64,
            'flow_usage_attestation_present' => strlen((string) $record->flow_usage_attestation_hash) === 64,
            'adapter_contract_present' => strlen((string) $record->adapter_contract_hash) === 64,
            'auth_boundary_present' => strlen((string) $record->auth_boundary_hash) === 64,
            'sandbox_probe_contract_present' => strlen((string) $record->sandbox_probe_hash) === 64,
            'lineage_contract_present' => strlen((string) $record->lineage_hash) === 64,
            'slo_contract_present' => strlen((string) $record->slo_hash) === 64,
            'blocked_modes_include_external_mutation' => count(array_intersect(
                ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
                array_values((array) $record->blocked_modes_json),
            )) >= 6,
        ];
        $green = ! in_array(false, $checks, true);
        $receipt = [
            'schema' => 'atlas.ai.holding.connector_activation_probe_receipt.v1',
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'connector_id' => (string) $record->connector_id,
            'status' => $green ? 'green' : 'blocked',
            'checks' => $checks,
            'probe_modes_completed' => ['schema_validate', 'auth_scope_check', 'read_only_ping', 'fixture_fetch', 'receipt_export'],
            'live_read_contract' => [
                'schema' => 'atlas.ai.holding.connector_live_read_contract.v1',
                'mode' => 'read_only_probe_or_fixture_until_real_vault_scope',
                'schema_snapshot_hash' => hash('sha256', 'live_read_schema_snapshot|'.$record->company_id.'|'.$record->flow_id.'|'.$record->connector_id),
                'sample_payload_hash' => hash('sha256', 'live_read_sample_payload|'.$record->company_id.'|'.$record->flow_id.'|'.$record->connector_id),
                'provider_lineage_hash' => (string) $record->lineage_hash,
                'vault_scope_reference' => $green ? 'vault://atlas/'.$record->company_id.'/'.$record->connector_id.'/read-only' : null,
                'credential_material_in_receipt' => false,
                'allowed_operations' => ['schema_snapshot', 'read_only_ping', 'fixture_fetch', 'sample_payload_capture', 'receipt_export'],
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
                'external_side_effects_enabled' => false,
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);
        $receipts = array_values((array) $record->probe_receipts_json);
        $receipts[] = $receipt;

        $record->forceFill([
            'status' => $green
                ? 'activated_internal_connector_ready_external_blocked'
                : 'blocked_probe_contract_incomplete',
            'probe_receipts_json' => $receipts,
            'last_probe_receipt_hash' => (string) $receipt['receipt_hash'],
            'last_probe_status' => (string) $receipt['status'],
            'vault_binding_attested' => $green,
            'sandbox_probe_green' => $green,
            'slo_monitor_bound' => $green,
            'reconciliation_bound' => $green,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_probed_at' => now(),
            'activated_at' => $green ? now() : null,
        ])->save();

        return $this->connectorActivationPayload($record->refresh());
    }

    /**
     * @param array<string,mixed> $flow
     * @return array<string,mixed>
     */
    private function persistFlowRunQueueItem(array $flow): array
    {
        $companyId = (string) ($flow['company_id'] ?? 'unknown');
        $flowId = (string) ($flow['flow_id'] ?? 'unknown');
        $connectorRows = $this->connectorActivationRows($companyId, $flowId);
        $operatingPackage = $this->flowOperatingPackage($companyId, $flowId);
        $packageAttestation = $this->flowQueueOperatingPackageAttestation($companyId, $flowId, $operatingPackage, 'queue_register');
        $backlogRows = AiHoldingActivationBacklogItem::query()
            ->where('company_id', $companyId)
            ->where('flow_id', $flowId)
            ->get();
        $queueReceipt = [
            'schema' => 'atlas.ai.holding.enterprise_flow_queue_receipt.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'activation_stage' => (string) ($flow['activation_stage'] ?? 'unknown'),
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'operating_package_attestation_hash' => (string) ($packageAttestation['attestation_hash'] ?? ''),
            'connector_activation_count' => count($connectorRows),
            'work_package_count' => $backlogRows->count(),
            'external_execution_allowed' => false,
            'generated_at' => now()->toJSON(),
        ];
        $queueReceipt['receipt_hash'] = MissionCanonicalHash::sha256($queueReceipt);

        $record = AiHoldingEnterpriseFlowRunQueueItem::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'flow_id' => $flowId,
            ],
            [
                'status' => 'queued_for_internal_execution',
                'priority_score' => (int) ($flow['activation_priority_score'] ?? 0),
                'activation_stage' => (string) ($flow['activation_stage'] ?? 'unknown'),
                'mandate_packet_hash' => (string) ($flow['mandate_packet_hash'] ?? '') ?: null,
                'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? '') ?: null,
                'operating_package_json' => $operatingPackage,
                'replay_contract_json' => (array) ($operatingPackage['quality_replay_cell'] ?? []),
                'operating_package_attestations_json' => [$packageAttestation],
                'connector_activation_count' => count($connectorRows),
                'connector_probe_green_count' => count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])),
                'work_package_count' => $backlogRows->count(),
                'completed_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $item): bool => str_starts_with((string) $item->status, 'completed'))->count(),
                'blocked_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $item): bool => str_starts_with((string) $item->status, 'blocked'))->count(),
                'attempt_count' => 0,
                'queue_receipts_json' => [$queueReceipt],
                'execution_receipts_json' => [],
                'last_execution_receipt_hash' => null,
                'dlq_reason' => null,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'queued_at' => now(),
                'leased_at' => null,
                'last_executed_at' => null,
                'completed_at' => null,
                'dlq_at' => null,
            ],
        );

        return $this->flowRunQueuePayload($record);
    }

    /**
     * @return array<string,mixed>
     */
    private function executeFlowRunQueueItem(AiHoldingEnterpriseFlowRunQueueItem $item, bool $replay = false): array
    {
        $connectorRows = $this->connectorActivationRows((string) $item->company_id, (string) $item->flow_id);
        $operatingPackage = (array) $item->operating_package_json;
        if ($operatingPackage === []) {
            $operatingPackage = $this->flowOperatingPackage((string) $item->company_id, (string) $item->flow_id);
        }
        $packageAttestation = $this->flowQueueOperatingPackageAttestation(
            (string) $item->company_id,
            (string) $item->flow_id,
            $operatingPackage,
            $replay ? 'dlq_replay' : 'queue_execute',
        );
        $connectorGreen = count($connectorRows) === 0 || count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])) === count($connectorRows);
        $backlogRows = AiHoldingActivationBacklogItem::query()
            ->where('company_id', $item->company_id)
            ->where('flow_id', $item->flow_id)
            ->get();
        $blockedWorkPackages = $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $row): bool => str_starts_with((string) $row->status, 'blocked'))->count();
        $dlqReason = null;
        if (! $connectorGreen) {
            $dlqReason = 'connector_activation_probe_not_green';
        } elseif ($blockedWorkPackages > 0) {
            $dlqReason = 'blocked_work_packages_require_operator_or_owner_resolution';
        }

        $status = $dlqReason === null ? 'completed_internal_flow_execution' : 'dlq_blocked_internal_execution';
        $attempt = (int) $item->attempt_count + 1;
        $receipt = [
            'schema' => 'atlas.ai.holding.enterprise_flow_queue_execution_receipt.v1',
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'attempt' => $attempt,
            'mode' => $replay ? 'dlq_replay' : 'initial_or_direct_execution',
            'status' => $status,
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? $item->operating_package_hash ?? ''),
            'operating_package_attestation_hash' => (string) ($packageAttestation['attestation_hash'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'replay_contract_bound' => data_get($operatingPackage, 'quality_replay_cell.dataset_id') !== null,
            'connector_activation_count' => count($connectorRows),
            'connector_probe_green_count' => count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])),
            'work_package_count' => $backlogRows->count(),
            'completed_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $row): bool => str_starts_with((string) $row->status, 'completed'))->count(),
            'blocked_work_package_count' => $blockedWorkPackages,
            'dlq_reason' => $dlqReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);
        $receipts = array_values((array) $item->execution_receipts_json);
        $receipts[] = $receipt;
        $packageAttestations = array_values((array) $item->operating_package_attestations_json);
        $packageAttestations[] = $packageAttestation;

        $item->forceFill([
            'status' => $status,
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? $item->operating_package_hash ?? '') ?: null,
            'operating_package_json' => $operatingPackage,
            'replay_contract_json' => (array) ($operatingPackage['quality_replay_cell'] ?? []),
            'operating_package_attestations_json' => $packageAttestations,
            'connector_activation_count' => count($connectorRows),
            'connector_probe_green_count' => (int) $receipt['connector_probe_green_count'],
            'work_package_count' => $backlogRows->count(),
            'completed_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $row): bool => str_starts_with((string) $row->status, 'completed'))->count(),
            'blocked_work_package_count' => $blockedWorkPackages,
            'attempt_count' => $attempt,
            'execution_receipts_json' => $receipts,
            'last_execution_receipt_hash' => (string) $receipt['receipt_hash'],
            'dlq_reason' => $dlqReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'leased_at' => now(),
            'last_executed_at' => now(),
            'completed_at' => $dlqReason === null ? now() : null,
            'dlq_at' => $dlqReason !== null ? now() : null,
        ])->save();

        return $this->flowRunQueuePayload($item->refresh());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function connectorActivationRows(string $companyId, string $flowId): array
    {
        return AiHoldingConnectorActivationRecord::query()
            ->where('company_id', $companyId)
            ->where('flow_id', $flowId)
            ->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->connectorActivationPayload($record))
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function flowOperatingPackage(string $companyId, string $flowId): array
    {
        try {
            $company = $this->buildout->companyPacket($companyId);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return $this->findByFlow($company, 'enterprise_flow_operating_packages.flow_packages', $flowId);
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function findByFlow(array $company, string $path, string $flowId): array
    {
        foreach ((array) data_get($company, $path, []) as $item) {
            if (($item['flow_id'] ?? null) === $flowId) {
                return (array) $item;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $operatingPackage
     * @return array<string,mixed>
     */
    private function flowQueueOperatingPackageAttestation(string $companyId, string $flowId, array $operatingPackage, string $mode): array
    {
        $attestation = [
            'schema' => 'atlas.ai.holding.enterprise_flow_queue_operating_package_attestation.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'mode' => $mode,
            'package_present' => $operatingPackage !== [],
            'package_id' => (string) ($operatingPackage['package_id'] ?? ''),
            'package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'premium_template_id' => (string) ($operatingPackage['premium_template_id'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'replay_contract_bound' => data_get($operatingPackage, 'quality_replay_cell.dataset_id') !== null,
            'workbench_binding_count' => count((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', [])),
            'runbook_drill_required_before_supervised_mode' => (bool) data_get($operatingPackage, 'operations_cell.runbook_drill_required_before_supervised_mode', false),
            'post_run_reconciliation_required' => (bool) data_get($operatingPackage, 'operations_cell.post_run_reconciliation_required', false),
            'calendar_wait_blocker_enabled' => (int) ($operatingPackage['buildout_wait_days_required'] ?? 30) > 0,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $attestation['attestation_hash'] = MissionCanonicalHash::sha256($attestation);

        return $attestation;
    }

    private function flowRunQueueQuery(?string $companyId, ?string $flowId)
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;

        return AiHoldingEnterpriseFlowRunQueueItem::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function flowRunQueueSummary(array $records): array
    {
        return [
            'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
            'flow_count' => count($records),
            'queued_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'queued_for_internal_execution')),
            'completed_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'completed_internal_flow_execution')),
            'dlq_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'dlq_blocked_internal_execution')),
            'connector_activation_count' => array_sum(array_map(static fn (array $record): int => (int) $record['connector_activation_count'], $records)),
            'connector_probe_green_count' => array_sum(array_map(static fn (array $record): int => (int) $record['connector_probe_green_count'], $records)),
            'operating_package_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['operating_package_hash'] ?? '') !== '')),
            'replay_contract_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'replay_contract_bound'))),
            'operating_package_attestation_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['operating_package_attestation_count'] ?? 0), $records)),
            'external_execution_allowed_count' => 0,
            'external_side_effects_enabled_count' => 0,
        ];
    }

    private function flowOperationsRunbookQuery(?string $companyId, ?string $flowId)
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;

        return AiHoldingEnterpriseFlowOperationsRunbook::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function flowOperationsRunbookSummary(array $records): array
    {
        return [
            'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
            'flow_count' => count($records),
            'registered_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'registered_needs_operations_drill')),
            'operations_green_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'operations_runbook_green_external_blocked')),
            'slo_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['slo_green'])),
            'incident_route_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['incident_route_green'])),
            'reconciliation_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['reconciliation_green'])),
            'promotion_gate_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['promotion_gate_green'])),
            'operating_package_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['operating_package_hash'] ?? '') !== '')),
            'replay_contract_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'replay_contract_bound'))),
            'operating_package_attestation_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['operating_package_attestation_count'] ?? 0), $records)),
            'external_execution_allowed_count' => 0,
            'external_side_effects_enabled_count' => 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persistFlowOperationsRunbook(AiHoldingEnterpriseFlowRunQueueItem $item): array
    {
        $companyId = (string) $item->company_id;
        $flowId = (string) $item->flow_id;
        $connectorRows = $this->connectorActivationRows($companyId, $flowId);
        $operatingPackage = (array) $item->operating_package_json;
        if ($operatingPackage === []) {
            $operatingPackage = $this->flowOperatingPackage($companyId, $flowId);
        }
        $packageAttestation = $this->flowQueueOperatingPackageAttestation($companyId, $flowId, $operatingPackage, 'operations_runbook_register');
        $sloContract = [
            'schema' => 'atlas.ai.holding.flow_operations_slo_contract.v1',
            'availability_target' => 'internal_supervised_run_success_rate>=0.97',
            'latency_target' => 'operator_visible_packet_under_10_minutes_for_normal_priority',
            'quality_target' => 'flow_evaluation_green_and_package_replay_contract_bound_required',
            'blocked_run_budget' => 'dlq_growth_requires_operator_review_and_package_replay_diff',
            'connector_count' => count($connectorRows),
            'source_operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'slo_hash' => hash('sha256', 'flow_slo|'.$companyId.'|'.$flowId.'|'.count($connectorRows)),
        ];
        $incidentRoute = [
            'schema' => 'atlas.ai.holding.flow_operations_incident_route.v1',
            'queue' => $companyId.'.'.$flowId.'.operations_exception_queue',
            'severity_ladder' => ['sev3_quality_warning', 'sev2_blocked_delivery', 'sev1_external_side_effect_risk'],
            'escalation_roles' => [$companyId.'.company_manager_agent', 'portfolio_governor', 'operator_for_sev1'],
            'required_packet' => $flowId.'.incident_or_exception_packet.v1',
            'route_hash' => hash('sha256', 'flow_incident_route|'.$companyId.'|'.$flowId),
        ];
        $reconciliation = [
            'schema' => 'atlas.ai.holding.flow_operations_reconciliation_contract.v1',
            'post_run_checks' => ['receipt_present', 'work_product_hash_present', 'policy_result_present', 'connector_probe_state_recorded'],
            'reconciliation_job' => $companyId.'.'.$flowId.'.post_run_reconciliation',
            'external_mutation_reconciliation_required_before_manual_handoff' => true,
            'post_run_reconciliation_required_by_package' => (bool) data_get($operatingPackage, 'operations_cell.post_run_reconciliation_required', false),
            'reconciliation_hash' => hash('sha256', 'flow_reconciliation|'.$companyId.'|'.$flowId),
        ];
        $promotionGates = [
            'queue_registered',
            'enterprise_flow_operating_package_bound',
            'replay_contract_bound',
            'connector_probe_green_or_no_connector_scope',
            'activation_backlog_no_blockers_for_internal_run',
            'slo_incident_reconciliation_drill_green',
            'operator_mandate_required_for_external_side_effects',
        ];
        $dashboardBindings = [
            $companyId.'.run_queue',
            $companyId.'.slo_capacity_board',
            $companyId.'.incident_board',
            'portfolio.operations_control_tower',
        ];
        $runbookHash = MissionCanonicalHash::sha256([
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'slo' => $sloContract,
            'incident' => $incidentRoute,
            'reconciliation' => $reconciliation,
            'promotion_gates' => $promotionGates,
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
        ]);

        $record = AiHoldingEnterpriseFlowOperationsRunbook::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'flow_id' => $flowId,
            ],
            [
                'status' => 'registered_needs_operations_drill',
                'source_queue_item_id' => $item->id,
                'runbook_hash' => $runbookHash,
                'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? '') ?: null,
                'operating_package_json' => $operatingPackage,
                'replay_contract_json' => (array) ($operatingPackage['quality_replay_cell'] ?? []),
                'operating_package_attestations_json' => [$packageAttestation],
                'slo_contract_json' => $sloContract,
                'incident_route_json' => $incidentRoute,
                'reconciliation_contract_json' => $reconciliation,
                'promotion_gates_json' => $promotionGates,
                'dashboard_bindings_json' => $dashboardBindings,
                'drill_receipts_json' => [],
                'last_drill_receipt_hash' => null,
                'slo_green' => false,
                'incident_route_green' => false,
                'reconciliation_green' => false,
                'promotion_gate_green' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registered_at' => now(),
                'last_drilled_at' => null,
                'activated_at' => null,
            ],
        );

        return $this->flowOperationsRunbookPayload($record);
    }

    /**
     * @return array<string,mixed>
     */
    private function drillFlowOperationsRunbook(AiHoldingEnterpriseFlowOperationsRunbook $runbook): array
    {
        $connectorRows = $this->connectorActivationRows((string) $runbook->company_id, (string) $runbook->flow_id);
        $queueItem = $this->flowRunQueueQuery((string) $runbook->company_id, (string) $runbook->flow_id)->first();
        $operatingPackage = (array) $runbook->operating_package_json;
        $packageAttestation = $this->flowQueueOperatingPackageAttestation(
            (string) $runbook->company_id,
            (string) $runbook->flow_id,
            $operatingPackage,
            'operations_runbook_drill',
        );
        $checks = [
            'runbook_hash_present' => strlen((string) $runbook->runbook_hash) === 64,
            'operating_package_hash_present' => strlen((string) $runbook->operating_package_hash) === 64,
            'replay_contract_bound' => data_get($runbook->replay_contract_json, 'dataset_id') !== null,
            'minimum_replay_cases_25' => (int) data_get($runbook->replay_contract_json, 'minimum_cases_before_shadow', 0) >= 25,
            'calendar_wait_blocker_disabled' => ! (bool) ($packageAttestation['calendar_wait_blocker_enabled'] ?? true),
            'slo_contract_present' => data_get($runbook->slo_contract_json, 'slo_hash') !== null,
            'incident_route_present' => data_get($runbook->incident_route_json, 'route_hash') !== null,
            'reconciliation_contract_present' => data_get($runbook->reconciliation_contract_json, 'reconciliation_hash') !== null,
            'queue_binding_present' => $queueItem instanceof AiHoldingEnterpriseFlowRunQueueItem,
            'connector_probe_green_or_empty' => count($connectorRows) === 0
                || count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])) === count($connectorRows),
            'external_side_effects_blocked' => ! (bool) $runbook->external_side_effects_enabled,
        ];
        $green = ! in_array(false, $checks, true);
        $receipt = [
            'schema' => 'atlas.ai.holding.flow_operations_runbook_drill_receipt.v1',
            'company_id' => (string) $runbook->company_id,
            'flow_id' => (string) $runbook->flow_id,
            'status' => $green ? 'green' : 'blocked',
            'checks' => $checks,
            'operating_package_hash' => (string) $runbook->operating_package_hash,
            'operating_package_attestation_hash' => (string) ($packageAttestation['attestation_hash'] ?? ''),
            'drill_modes_completed' => ['slo_contract_check', 'incident_route_check', 'reconciliation_check', 'promotion_gate_check'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);
        $receipts = array_values((array) $runbook->drill_receipts_json);
        $receipts[] = $receipt;
        $packageAttestations = array_values((array) $runbook->operating_package_attestations_json);
        $packageAttestations[] = $packageAttestation;

        $runbook->forceFill([
            'status' => $green ? 'operations_runbook_green_external_blocked' : 'operations_runbook_blocked_needs_operator_review',
            'drill_receipts_json' => $receipts,
            'operating_package_attestations_json' => $packageAttestations,
            'last_drill_receipt_hash' => (string) $receipt['receipt_hash'],
            'slo_green' => $green,
            'incident_route_green' => $green,
            'reconciliation_green' => $green,
            'promotion_gate_green' => $green,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_drilled_at' => now(),
            'activated_at' => $green ? now() : null,
        ])->save();

        return $this->flowOperationsRunbookPayload($runbook->refresh());
    }

    private function connectorActivationQuery(?string $companyId, ?string $flowId, ?string $connectorId)
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;
        $wantedConnector = $connectorId !== null && trim($connectorId) !== '' ? trim($connectorId) : null;

        return AiHoldingConnectorActivationRecord::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow))
            ->when($wantedConnector !== null, static fn ($query) => $query->where('connector_id', $wantedConnector));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function connectorActivationSummary(array $records): array
    {
        return [
            'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
            'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
            'connector_activation_count' => count($records),
            'unique_connector_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['connector_id'], $records))),
            'registered_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'registered_needs_probe')),
            'probe_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['sandbox_probe_green'])),
            'activated_internal_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'activated_internal_connector_ready_external_blocked')),
            'blocked_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'blocked'))),
            'external_execution_allowed_count' => 0,
            'external_side_effects_enabled_count' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $value
     */
    private function hashIfPresent(array $value): ?string
    {
        return $value !== [] ? MissionCanonicalHash::sha256($value) : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function runActivationBacklogItem(AiHoldingActivationBacklogItem $item): array
    {
        $producedEvidence = $this->implementationEvidenceFor($item);
        $requiredEvidence = array_values((array) $item->evidence_required_json);
        $existingEvidence = array_values((array) $item->evidence_attached_json);
        $attachedEvidence = array_values(array_unique(array_merge($existingEvidence, $producedEvidence)));
        $missingEvidence = array_values(array_diff($requiredEvidence, $attachedEvidence));
        $status = 'completed_internal_no_external_execution';
        $blockedReason = null;

        if ($item->work_package_id === 'operator_governance_pack' && ! (bool) $item->manual_handoff_ready) {
            $status = 'blocked_waiting_for_signed_mandate';
            $blockedReason = 'operator_and_second_reviewer_signatures_required_before_governance_pack_completion';
        }

        if ($item->work_package_id === 'connector_activation' && in_array('credential_scope_attestation', $missingEvidence, true)) {
            $status = 'blocked_waiting_for_vault_binding';
            $blockedReason = 'credential_vault_binding_required_before_connector_completion';
        }

        $attempt = (int) $item->implementation_attempt_count + 1;
        $receipt = [
            'schema' => 'atlas.ai.holding.activation_backlog_item_implementation_receipt.v1',
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'work_package_id' => (string) $item->work_package_id,
            'attempt' => $attempt,
            'status' => $status,
            'produced_evidence' => $producedEvidence,
            'missing_evidence_after_run' => $missingEvidence,
            'blocked_reason' => $blockedReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        $receipts = array_values((array) $item->implementation_receipts_json);
        $receipts[] = $receipt;

        $item->forceFill([
            'status' => $status,
            'evidence_attached_json' => $attachedEvidence,
            'implementation_receipts_json' => $receipts,
            'implementation_attempt_count' => $attempt,
            'last_implementation_receipt_hash' => (string) $receipt['receipt_hash'],
            'blocked_reason' => $blockedReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_run_at' => now(),
            'completed_at' => str_starts_with($status, 'completed') ? now() : null,
        ])->save();

        return $this->activationBacklogPayload($item->refresh());
    }

    /**
     * @return list<string>
     */
    private function implementationEvidenceFor(AiHoldingActivationBacklogItem $item): array
    {
        return match ((string) $item->work_package_id) {
            'connector_activation' => [
                'connector_certification_receipt',
                'credential_scope_attestation',
                'sandbox_or_shadow_run_receipt',
                'slo_monitor_receipt',
                'reconciliation_receipt',
            ],
            'domain_workflow_hardening' => [
                'sandbox_or_shadow_run_receipt',
                'rollback_drill_receipt',
                'incident_route_drill_receipt',
                'slo_monitor_receipt',
                'reconciliation_receipt',
            ],
            'operator_governance_pack' => (bool) $item->manual_handoff_ready
                ? [
                    'operator_signature_receipt',
                    'second_reviewer_signature_receipt',
                    'rollback_drill_receipt',
                    'incident_route_drill_receipt',
                    'reconciliation_receipt',
                ]
                : [],
            default => ['sandbox_or_shadow_run_receipt'],
        };
    }

    private function mandateRecord(string $mandatePacketHash): ?AiHoldingExternalActionMandate
    {
        $hash = trim($mandatePacketHash);

        return $hash !== ''
            ? AiHoldingExternalActionMandate::query()->where('mandate_packet_hash', $hash)->first()
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function missingMandatePayload(string $schema, string $mandatePacketHash): array
    {
        return [
            'ok' => false,
            'schema' => $schema,
            'status' => 'missing_mandate_packet',
            'mandate_packet_hash' => trim($mandatePacketHash),
            'external_execution_allowed' => false,
            'reasons' => ['mandate_packet_not_registered'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function createApproval(AiHoldingExternalActionMandate $record, string $role): array
    {
        $uuid = hash('sha256', 'holding_external_action_approval|'.$record->mandate_packet_hash.'|'.$role);
        $options = [
            'schema' => 'atlas.ai.holding.external_action_approval_options.v1',
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'approval_role' => $role,
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'connector_scope' => array_values((array) $record->connector_scope_json),
            'blocked_operations' => array_values((array) $record->blocked_operations_json),
            'external_execution_allowed_after_approval' => false,
            'manual_execution_handoff_only' => true,
        ];
        $approval = AiOperatorApproval::query()->updateOrCreate(
            ['uuid' => $uuid],
            [
                'schema_version' => 'atlas.ai.operator_approval.v1',
                'requested_action' => 'holding.external_action.'.$record->company_id.'.'.$record->flow_id,
                'risk_level' => 'high',
                'gate_mode' => 'holding_external_action_mandate',
                'approval_required' => true,
                'reason' => 'External action mandate requires '.$role.' before manual handoff.',
                'options' => $options,
                'status' => 'pending',
                'operator_decision' => null,
                'operator' => null,
                'operator_note' => null,
                'expires_at' => now()->addDay(),
                'decided_at' => null,
                'consumed_at' => null,
                'evidence_refs' => [
                    'mandate_packet_hash:'.$record->mandate_packet_hash,
                    'runtime_receipt_hash:'.$record->source_runtime_receipt_hash,
                    'connector_certification_hash:'.$record->source_connector_certification_hash,
                ],
                'receipt_hash' => null,
                'hash' => MissionCanonicalHash::sha256([
                    'uuid' => $uuid,
                    'mandate_packet_hash' => $record->mandate_packet_hash,
                    'role' => $role,
                ]),
            ],
        );

        return $this->approvalPayload($approval);
    }

    /**
     * @return list<AiOperatorApproval>
     */
    private function approvalRows(string $mandatePacketHash): array
    {
        return AiOperatorApproval::query()
            ->where('gate_mode', 'holding_external_action_mandate')
            ->get()
            ->filter(static fn (AiOperatorApproval $approval): bool => data_get($approval->options, 'mandate_packet_hash') === $mandatePacketHash)
            ->values()
            ->all();
    }

    /**
     * @param list<AiOperatorApproval> $approvals
     */
    private function roleApproved(array $approvals, string $role): bool
    {
        foreach ($approvals as $approval) {
            if (data_get($approval->options, 'approval_role') === $role
                && $approval->status === 'approved'
                && $approval->operator_decision === 'approved') {
                return true;
            }
        }

        return false;
    }

    private function nextControlTowerAction(string $mandateStatus): string
    {
        return match ($mandateStatus) {
            'not_registered' => 'run_enterprise_external_action_register',
            'queued_for_operator_review' => 'run_enterprise_external_action_preflight',
            'preflight_green_awaiting_signatures' => 'request_operator_and_second_reviewer_approval',
            'awaiting_operator_and_reviewer_signatures' => 'collect_required_signatures',
            'signed_mandate_ready_manual_execution_only' => 'manual_execution_handoff_only',
            'rejected_by_operator_gate' => 'revise_scope_or_close_mandate',
            default => 'operator_review_required',
        };
    }

    /**
     * @param array<string,mixed> $flow
     * @return array<string,mixed>
     */
    private function activationCockpitFlow(array $flow): array
    {
        $mandateStatus = (string) ($flow['mandate_status'] ?? 'unknown');
        $registered = (bool) ($flow['registered'] ?? false);
        $preflighted = (bool) ($flow['preflighted'] ?? false);
        $approvalCount = (int) ($flow['approval_count'] ?? 0);
        $manualReady = $mandateStatus === 'signed_mandate_ready_manual_execution_only';
        $connectorCount = (int) ($flow['connector_count'] ?? 0);

        $connectorGaps = [];
        if ($connectorCount <= 0) {
            $connectorGaps[] = 'connector_scope_missing';
        }
        $connectorGaps = array_merge($connectorGaps, [
            'credential_vault_binding_missing',
            'sandbox_probe_receipt_missing',
            'production_scope_contract_missing',
            'connector_slo_monitor_missing',
            'post_execution_reconciliation_adapter_missing',
        ]);

        $governanceGaps = [];
        if (! $registered) {
            $governanceGaps[] = 'mandate_packet_not_registered';
        }
        if (! $preflighted) {
            $governanceGaps[] = 'preflight_not_completed';
        }
        if ($approvalCount < 2) {
            $governanceGaps[] = 'operator_and_second_reviewer_signatures_missing';
        }
        $governanceGaps = array_merge($governanceGaps, [
            'legal_or_risk_scope_acceptance_missing',
            'budget_or_loss_cap_signature_missing',
            'manual_execution_owner_assignment_missing',
        ]);

        $operationalGaps = [
            'recurring_schedule_binding_missing',
            'run_queue_worker_binding_missing',
            'dlq_replay_runbook_receipt_missing',
            'rollback_or_compensation_drill_missing',
            'incident_route_drill_missing',
            'quality_slo_baseline_missing',
            'customer_or_stakeholder_acceptance_loop_missing',
        ];

        $evidenceRequired = [
            'connector_certification_receipt',
            'credential_scope_attestation',
            'sandbox_or_shadow_run_receipt',
            'operator_signature_receipt',
            'second_reviewer_signature_receipt',
            'rollback_drill_receipt',
            'incident_route_drill_receipt',
            'slo_monitor_receipt',
            'reconciliation_receipt',
        ];

        $priorityScore = count($connectorGaps) * 3 + count($governanceGaps) * 4 + count($operationalGaps) * 2;
        if ($registered) {
            $priorityScore += 5;
        }
        if ($manualReady) {
            $priorityScore += 8;
        }

        return [
            'company_id' => (string) ($flow['company_id'] ?? 'unknown'),
            'flow_id' => (string) ($flow['flow_id'] ?? 'unknown'),
            'mandate_packet_hash' => (string) ($flow['mandate_packet_hash'] ?? ''),
            'mandate_status' => $mandateStatus,
            'activation_stage' => $this->activationStage($mandateStatus, $registered, $preflighted, $approvalCount),
            'manual_handoff_ready' => $manualReady,
            'activation_priority_score' => $priorityScore,
            'gap_counts' => [
                'connector_activation' => count($connectorGaps),
                'governance' => count($governanceGaps),
                'operationalization' => count($operationalGaps),
            ],
            'connector_activation_gaps' => $connectorGaps,
            'governance_gaps' => $governanceGaps,
            'operationalization_gaps' => $operationalGaps,
            'evidence_required_for_real_operation' => $evidenceRequired,
            'implementation_work_packages' => [
                [
                    'id' => 'connector_activation',
                    'owner' => 'automation.company_manager_agent',
                    'deliverable' => 'vault_bound_connector_adapter_with_sandbox_probe_and_slo_monitor',
                    'external_execution_allowed' => false,
                ],
                [
                    'id' => 'domain_workflow_hardening',
                    'owner' => (string) ($flow['company_id'] ?? 'unknown').'.company_manager_agent',
                    'deliverable' => 'checkpointed_workflow_with_queue_dlq_replay_and_quality_slo',
                    'external_execution_allowed' => false,
                ],
                [
                    'id' => 'operator_governance_pack',
                    'owner' => 'portfolio_governor',
                    'deliverable' => 'signed_scope_budget_rollback_incident_and_reconciliation_packet',
                    'external_execution_allowed' => false,
                ],
            ],
            'next_action' => $manualReady
                ? 'bind_manual_execution_owner_and_run_non_production_dress_rehearsal'
                : (string) ($flow['next_action'] ?? 'operator_review_required'),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function premiumActivationCompany(string $companyId): array
    {
        $packet = $this->buildout->companyPacket($companyId);
        $model = (array) ($packet['premium_enterprise_agent_reference_model'] ?? []);
        $flows = (array) ($packet['flows'] ?? []);
        $connectors = (array) ($packet['connectors'] ?? []);
        $sources = (array) ($model['reference_source_basis'] ?? []);
        $templates = (array) ($model['managed_agent_templates'] ?? []);
        $flowTemplateMap = (array) ($model['flow_template_map'] ?? []);
        $workbenches = (array) ($model['data_and_tool_workbenches'] ?? []);
        $replayBenchmarks = (array) data_get($model, 'replay_and_audit_harness.benchmarks_per_flow', []);
        $waitDays = (int) data_get($model, 'accelerated_activation_contract.buildout_wait_days_required', 30);

        $flowCount = count($flows);
        $connectorCount = count($connectors);
        $sourceCount = count($sources);
        $templateCount = count($templates);
        $mapCount = count($flowTemplateMap);
        $workbenchCount = count($workbenches);
        $replayCount = count($replayBenchmarks);

        $checks = [
            'reference_source_basis_green' => $sourceCount >= 5,
            'managed_agent_templates_green' => $templateCount >= 6,
            'flow_template_map_green' => $mapCount >= $flowCount && $flowCount > 0,
            'data_and_tool_workbenches_green' => $workbenchCount >= $connectorCount && $connectorCount > 0,
            'replay_benchmarks_green' => $replayCount >= $flowCount && $flowCount > 0,
            'calendar_wait_removed' => $waitDays === 0,
            'external_execution_blocked_until_operator_mandate' => (bool) data_get($model, 'model_policy.operator_mandate_required_for_external_write_spend_trade_publish_or_security_action', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.premium_activation_status.v1',
            'company_id' => $companyId,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'premium_readiness' => [
                'ready' => ! in_array(false, $checks, true),
                'checks' => $checks,
                'reference_source_count' => $sourceCount,
                'managed_agent_template_count' => $templateCount,
                'flow_template_map_count' => $mapCount,
                'workbench_count' => $workbenchCount,
                'replay_benchmark_count' => $replayCount,
                'buildout_wait_days_required' => $waitDays,
            ],
            'source_basis_ids' => array_values(array_map(
                static fn (array $source): string => (string) ($source['source_id'] ?? 'unknown_source'),
                $sources,
            )),
            'template_ids' => array_values(array_map(
                static fn (array $template): string => (string) ($template['template_id'] ?? 'unknown_template'),
                $templates,
            )),
            'workbench_ids' => array_values(array_map(
                static fn (array $workbench): string => (string) ($workbench['workbench_id'] ?? 'unknown_workbench'),
                $workbenches,
            )),
            'flow_template_map' => array_values(array_map(
                static fn (array $map): array => [
                    'flow_id' => (string) ($map['flow_id'] ?? 'unknown_flow'),
                    'primary_template_id' => (string) ($map['primary_template_id'] ?? 'unknown_template'),
                    'required_workbench' => (string) ($map['required_workbench'] ?? 'unknown_workbench'),
                    'external_side_effects' => false,
                ],
                $flowTemplateMap,
            )),
            'activation_sequence' => (array) data_get($model, 'accelerated_activation_contract.activation_sequence', []),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'next_activation_steps' => [
                'run_connector_read_only_probe',
                'run_replay_benchmark_per_flow',
                'run_runbook_drill',
                'collect_operator_mandate_before_external_effect',
            ],
        ];
        $row['company_premium_activation_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildoutCompanies(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if ($wantedCompany !== null) {
            return [$this->buildout->companyPacket($wantedCompany)];
        }

        return array_values((array) ($this->buildout->report()['companies'] ?? []));
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function providerWorkbenchCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_domain_provider_workbench_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $providerContracts = (array) data_get($stack, 'provider_contracts', []);
        $connectorWorkbenches = (array) data_get($stack, 'connector_workbenches', []);
        $flowRoutes = (array) data_get($stack, 'flow_provider_routes', []);
        $evalCases = (array) data_get($stack, 'provider_evaluation_cases', []);
        $lineage = (array) data_get($stack, 'provider_data_product_lineage', []);
        $metrics = (array) data_get($stack, 'provider_workbench_observability.required_metrics', []);

        $checks = [
            'provider_contracts_green' => count($providerContracts) >= 5,
            'connector_workbenches_green' => count($connectorWorkbenches) >= $connectorCount && $connectorCount > 0,
            'flow_provider_routes_green' => count($flowRoutes) >= $flowCount && $flowCount > 0,
            'provider_eval_cases_green' => count($evalCases) >= $flowCount && $flowCount > 0,
            'provider_lineage_green' => count($lineage) >= 5,
            'observability_green' => count($metrics) >= 8,
            'calendar_wait_removed' => (bool) data_get($stack, 'workbench_policy.calendar_wait_blocker_enabled', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'workbench_policy.provider_write_or_paid_action_default', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.provider_workbench_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'provider_contract_count' => count($providerContracts),
            'connector_workbench_count' => count($connectorWorkbenches),
            'flow_provider_route_count' => count($flowRoutes),
            'provider_eval_case_count' => count($evalCases),
            'provider_lineage_count' => count($lineage),
            'provider_metric_count' => count($metrics),
            'provider_ids' => array_values(array_map(
                static fn (array $contract): string => (string) ($contract['provider_id'] ?? 'unknown_provider'),
                $providerContracts,
            )),
            'workbench_ids' => array_values(array_map(
                static fn (array $workbench): string => (string) ($workbench['workbench_id'] ?? 'unknown_workbench'),
                $connectorWorkbenches,
            )),
            'next_actions' => ['run_provider_read_only_probe', 'attach_terms_review_hash', 'run_provider_eval_cases', 'bind_operator_scope_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['provider_workbench_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function agentRepositoryAdoptionCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_agent_repository_adoption_pipeline', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $intake = (array) data_get($stack, 'repository_intake_queue', []);
        $scorecards = (array) data_get($stack, 'framework_adoption_scorecards', []);
        $epics = (array) data_get($stack, 'flow_repository_implementation_epics', []);
        $versionPins = (array) data_get($stack, 'version_pin_and_supply_chain_plan', []);
        $migrationPaths = (array) data_get($stack, 'migration_and_deprecation_matrix.known_migration_paths', []);
        $metrics = (array) data_get($stack, 'pipeline_observability.required_metrics', []);

        $checks = [
            'repository_intake_green' => count($intake) >= 11,
            'framework_scorecards_green' => count($scorecards) >= 8,
            'flow_epics_green' => count($epics) >= $flowCount && $flowCount > 0,
            'version_pins_green' => count($versionPins) >= count($intake) && count($intake) > 0,
            'migration_matrix_green' => count($migrationPaths) >= 2,
            'observability_green' => count($metrics) >= 8,
            'calendar_wait_removed' => (bool) data_get($stack, 'pipeline_policy.calendar_wait_blocker_enabled', true) === false,
            'runtime_before_tests_blocked' => (bool) data_get($stack, 'pipeline_policy.runtime_use_before_local_contract_tests_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'pipeline_policy.external_side_effects_enabled', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.agent_repository_adoption_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'repository_intake_count' => count($intake),
            'framework_scorecard_count' => count($scorecards),
            'flow_epic_count' => count($epics),
            'version_pin_count' => count($versionPins),
            'migration_path_count' => count($migrationPaths),
            'metric_count' => count($metrics),
            'repository_ids' => array_values(array_map(
                static fn (array $item): string => (string) ($item['repository_id'] ?? 'unknown_repository'),
                $intake,
            )),
            'framework_states' => array_values(array_map(
                static fn (array $scorecard): array => [
                    'framework_id' => (string) ($scorecard['framework_id'] ?? 'unknown_framework'),
                    'adoption_state' => (string) ($scorecard['adoption_state'] ?? 'unknown'),
                    'risk_finding_count' => count((array) ($scorecard['risk_findings'] ?? [])),
                ],
                $scorecards,
            )),
            'next_actions' => ['run_license_security_sbom_review', 'pin_versions', 'run_flow_fixture_eval', 'bind_trace_receipts', 'request_operator_acceptance_before_runtime_use'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['agent_repository_adoption_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function industrySolutionEcosystemCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_industry_solution_ecosystem_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $dataInterface = (array) data_get($stack, 'industry_data_interface', []);
        $providers = (array) data_get($stack, 'ecosystem_provider_catalog', []);
        $partnerTracks = (array) data_get($stack, 'implementation_partner_tracks', []);
        $workloadPacks = (array) data_get($stack, 'flow_solution_workload_packs', []);
        $metrics = (array) data_get($stack, 'ecosystem_observability.required_metrics', []);
        $auditControls = (array) data_get($stack, 'audit_and_confidentiality_controls', []);

        $checks = [
            'data_interface_green' => ($dataInterface['schema'] ?? null) === 'atlas.ai.company.industry_data_interface.v1'
                && count((array) ($dataInterface['connector_ids'] ?? [])) >= $connectorCount
                && $connectorCount > 0,
            'ecosystem_providers_green' => count($providers) >= 5,
            'implementation_partner_tracks_green' => count($partnerTracks) >= 7,
            'flow_workload_packs_green' => count($workloadPacks) >= $flowCount && $flowCount > 0,
            'observability_green' => count($metrics) >= 8,
            'source_links_required' => (bool) data_get($stack, 'ecosystem_policy.direct_source_hyperlinks_required', false),
            'mcp_or_api_workbench_required' => (bool) data_get($stack, 'ecosystem_policy.mcp_or_api_connector_workbench_required', false),
            'audit_trail_required' => (bool) data_get($stack, 'ecosystem_policy.audit_trail_required_for_every_claim_and_artifact', false),
            'confidentiality_green' => (bool) data_get($stack, 'audit_and_confidentiality_controls.client_or_private_data_training_exclusion_attestation_required', false)
                && (bool) data_get($stack, 'audit_and_confidentiality_controls.secret_material_in_packet_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'ecosystem_policy.external_side_effects_enabled', true) === false
                && (bool) data_get($stack, 'enterprise_adoption_program.external_contracting_allowed_by_stack', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.industry_solution_ecosystem_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'data_interface_count' => $dataInterface === [] ? 0 : 1,
            'ecosystem_provider_count' => count($providers),
            'implementation_partner_track_count' => count($partnerTracks),
            'flow_workload_pack_count' => count($workloadPacks),
            'metric_count' => count($metrics),
            'audit_control_count' => count($auditControls),
            'provider_ids' => array_values(array_map(
                static fn (array $provider): string => (string) ($provider['provider_id'] ?? 'unknown_provider'),
                $providers,
            )),
            'partner_track_ids' => array_values(array_map(
                static fn (array $track): string => (string) ($track['track_id'] ?? 'unknown_track'),
                $partnerTracks,
            )),
            'workload_flow_ids' => array_values(array_map(
                static fn (array $pack): string => (string) ($pack['flow_id'] ?? 'unknown_flow'),
                $workloadPacks,
            )),
            'next_actions' => ['run_provider_terms_reviews', 'bind_read_only_data_interface', 'execute_workload_fixture_eval', 'attach_audit_and_confidentiality_attestations', 'request_operator_acceptance_before_external_contracting'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['industry_solution_ecosystem_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function businessOperatingBackboneCompany(array $company): array
    {
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $metricCount = count((array) ($company['metrics'] ?? []));
        $cadenceCount = count((array) ($company['cadences'] ?? []));
        $nodeFloor = count((array) ($company['functions'] ?? []))
            + count((array) ($company['agent_roles'] ?? []))
            + $flowCount
            + $connectorCount
            + $metricCount;

        $checks = [
            'customer_market_operations_green' => data_get($company, 'enterprise_customer_market_operations_stack.schema') === 'atlas.ai.company.enterprise_customer_market_operations_stack.v1'
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4
                && (bool) data_get($company, 'enterprise_customer_market_operations_stack.market_operations_guardrails.external_side_effects_default', true) === false,
            'account_contract_delivery_green' => data_get($company, 'enterprise_account_contract_delivery_stack.schema') === 'atlas.ai.company.enterprise_account_contract_delivery_stack.v1'
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])) >= 4
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.account_operations_policy.external_billing_allowed', true) === false,
            'vendor_legal_procurement_green' => data_get($company, 'enterprise_vendor_legal_procurement_stack.schema') === 'atlas.ai.company.enterprise_vendor_legal_procurement_stack.v1'
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', [])) >= $flowCount
                && data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_policy.purchase_authority') === 'operator_only_for_real_spend',
            'resilience_continuity_green' => data_get($company, 'enterprise_resilience_continuity_stack.schema') === 'atlas.ai.company.enterprise_resilience_continuity_stack.v1'
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.flow_failure_mode_analysis', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.connector_resilience_plan', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_resilience_continuity_stack.resilience_policy.external_side_effects_during_incident_allowed', true) === false,
            'analytics_decision_intelligence_green' => data_get($company, 'enterprise_analytics_decision_intelligence_stack.schema') === 'atlas.ai.company.enterprise_analytics_decision_intelligence_stack.v1'
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.metric_lineage_catalog', [])) >= $metricCount
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])) >= 3
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.flow_decision_register', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_analytics_decision_intelligence_stack.decision_intelligence_policy.synthetic_scores_allowed', true) === false,
            'knowledge_memory_learning_green' => data_get($company, 'enterprise_knowledge_memory_learning_stack.schema') === 'atlas.ai.company.enterprise_knowledge_memory_learning_stack.v1'
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.flow_learning_loops', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.connector_knowledge_sync_plan', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_knowledge_memory_learning_stack.memory_governance_policy.automatic_canonical_doc_rewrite_allowed', true) === false,
            'identity_access_data_sovereignty_green' => data_get($company, 'enterprise_identity_access_data_sovereignty_stack.schema') === 'atlas.ai.company.enterprise_identity_access_data_sovereignty_stack.v1'
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])) >= 4
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.flow_data_boundary_matrix', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])) >= $connectorCount
                && data_get($company, 'enterprise_identity_access_data_sovereignty_stack.identity_access_policy.default_access') === 'deny',
            'control_tower_run_operations_green' => data_get($company, 'enterprise_control_tower_run_operations_stack.schema') === 'atlas.ai.company.enterprise_control_tower_run_operations_stack.v1'
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_lanes', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])) >= $cadenceCount
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true) === false,
            'semantic_operating_graph_green' => data_get($company, 'enterprise_semantic_operating_graph_stack.schema') === 'atlas.ai.company.enterprise_semantic_operating_graph_stack.v1'
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])) >= $nodeFloor
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.flow_relationship_edges', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])) >= 4
                && (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_policy.external_side_effects_from_graph_allowed', true) === false,
            'delivery_assurance_green' => data_get($company, 'enterprise_delivery_assurance_stack.schema') === 'atlas.ai.company.enterprise_delivery_assurance_stack.v1'
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])) >= 5
                && count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])) >= $flowCount
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_intake_contract.reject_when_missing_acceptance_criteria', false)
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_side_effects_default', true) === false,
            'unit_economics_capacity_simulation_green' => data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.schema') === 'atlas.ai.company.enterprise_unit_economics_capacity_simulation_stack.v1'
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])) >= $connectorCount
                && (bool) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.economics_policy.synthetic_financial_claims_allowed', true) === false,
            'grc_green' => data_get($company, 'enterprise_grc_stack.schema') === 'atlas.ai.company.enterprise_grc_stack.v1'
                && count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])) >= $flowCount
                && count((array) data_get($company, 'enterprise_grc_stack.control_framework.control_sets', [])) >= 4
                && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
        ];
        $readyComponentCount = count(array_filter($checks));

        $row = [
            'schema' => 'atlas.ai.company.business_operating_backbone_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => $readyComponentCount === count($checks),
            'checks' => $checks,
            'ready_component_count' => $readyComponentCount,
            'required_component_count' => count($checks),
            'customer_offer_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
            'account_contract_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
            'vendor_due_diligence_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])),
            'resilience_exercise_count' => count((array) data_get($company, 'enterprise_resilience_continuity_stack.incident_exercise_program', [])),
            'analytics_dashboard_count' => count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])),
            'semantic_graph_node_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])),
            'grc_audit_evidence_count' => count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])),
            'next_actions' => ['review_customer_account_vendor_backbone', 'run_resilience_and_control_tower_drills', 'export_semantic_operating_graph_snapshot', 'attach_grc_audit_receipts', 'request_operator_mandate_before_external_business_action'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['business_operating_backbone_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function productionConnectorPreflightCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_production_connector_preflight_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $contracts = (array) data_get($stack, 'connector_preflight_contracts', []);
        $cutovers = (array) data_get($stack, 'flow_connector_cutover_matrix', []);
        $evidence = (array) data_get($stack, 'production_readiness_evidence_register', []);
        $metrics = (array) data_get($stack, 'cutover_observability.required_metrics', []);

        $checks = [
            'schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_production_connector_preflight_stack.v1',
            'connector_preflight_contracts_green' => count($contracts) >= $connectorCount && $connectorCount > 0,
            'flow_cutover_matrix_green' => count($cutovers) >= $flowCount && $flowCount > 0,
            'production_evidence_register_green' => count($evidence) >= $connectorCount && $connectorCount > 0,
            'observability_green' => count($metrics) >= 6,
            'calendar_wait_removed' => (bool) data_get($stack, 'preflight_policy.calendar_wait_blocker_enabled', true) === false,
            'operator_signed_scope_required' => (bool) data_get($stack, 'preflight_policy.production_cutover_without_operator_signed_scope_allowed', true) === false,
            'no_real_credentials_in_packet' => (bool) data_get($stack, 'preflight_policy.real_credential_material_in_packet_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'preflight_policy.external_side_effects_default', true) === false,
            'manual_handoff_only' => (bool) data_get($stack, 'preflight_policy.manual_execution_handoff_only_after_signed_mandate', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.production_connector_preflight_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'connector_preflight_contract_count' => count($contracts),
            'flow_cutover_count' => count($cutovers),
            'production_evidence_register_count' => count($evidence),
            'cutover_metric_count' => count($metrics),
            'connector_ids' => array_values(array_map(
                static fn (array $contract): string => (string) ($contract['connector_id'] ?? 'unknown_connector'),
                $contracts,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $cutover): string => (string) ($cutover['flow_id'] ?? 'unknown_flow'),
                $cutovers,
            )),
            'next_actions' => ['bind_real_vault_references', 'collect_signed_production_scope', 'assign_manual_execution_owner', 'verify_rollback_drill_green', 'request_operator_mandate_before_cutover'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['production_connector_preflight_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function flowQualityResearchCompany(array $company): array
    {
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $benchmark = (array) data_get($company, 'enterprise_flow_benchmark_replay_stack', []);
        $tooling = (array) data_get($company, 'enterprise_tooling_research_stack', []);
        $solution = (array) data_get($company, 'enterprise_domain_solution_stack', []);
        $research = (array) data_get($company, 'enterprise_external_research_adoption_stack', []);
        $datasets = (array) data_get($benchmark, 'offline_dataset_contracts', []);
        $rubrics = (array) data_get($benchmark, 'trace_grading_rubrics', []);
        $adversarial = (array) data_get($benchmark, 'adversarial_regression_cases', []);
        $assertions = (array) data_get($benchmark, 'deterministic_state_assertions', []);
        $replayMatrix = (array) data_get($benchmark, 'replay_and_comparison_matrix', []);
        $metrics = (array) data_get($benchmark, 'benchmark_observability.required_metrics', []);
        $toolingBenchmarks = (array) data_get($tooling, 'per_flow_tooling_benchmark', []);
        $toolingBacklog = (array) data_get($tooling, 'connector_integration_backlog', []);
        $solutionModules = (array) data_get($solution, 'solution_modules', []);
        $solutionTemplates = (array) data_get($solution, 'managed_agent_templates', []);
        $dataProducts = (array) data_get($solution, 'data_product_catalog', []);
        $researchSources = (array) data_get($research, 'source_basis', []);
        $frameworkRepos = (array) data_get($research, 'repository_and_framework_catalog.official_framework_repositories', []);
        $domainRepos = (array) data_get($research, 'repository_and_framework_catalog.domain_repository_candidates', []);
        $flowAdoption = (array) data_get($research, 'per_flow_adoption_matrix', []);
        $connectorBacklog = (array) data_get($research, 'connector_and_data_provider_backlog', []);

        $checks = [
            'benchmark_schema_green' => ($benchmark['schema'] ?? null) === 'atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1',
            'offline_datasets_green' => count($datasets) >= $flowCount && $flowCount > 0,
            'trace_rubrics_green' => count($rubrics) >= $flowCount && $flowCount > 0,
            'adversarial_cases_green' => count($adversarial) >= $flowCount && $flowCount > 0,
            'deterministic_assertions_green' => count($assertions) >= $flowCount && $flowCount > 0,
            'replay_matrix_green' => count($replayMatrix) >= $flowCount && $flowCount > 0,
            'benchmark_observability_green' => count($metrics) >= 5,
            'synthetic_scores_blocked' => (bool) data_get($benchmark, 'benchmark_policy.synthetic_score_claims_allowed', true) === false,
            'promotion_without_replay_blocked' => (bool) data_get($benchmark, 'benchmark_policy.promotion_without_replay_green_allowed', true) === false,
            'tooling_research_green' => ($tooling['schema'] ?? null) === 'atlas.ai.company.enterprise_tooling_research_stack.v1'
                && count((array) data_get($tooling, 'source_catalog', [])) >= 9
                && count($toolingBenchmarks) >= $flowCount
                && count($toolingBacklog) >= $connectorCount,
            'domain_solution_green' => ($solution['schema'] ?? null) === 'atlas.ai.company.enterprise_domain_solution_stack.v1'
                && count((array) data_get($solution, 'domain_source_catalog', [])) >= 5
                && count($solutionModules) >= $flowCount
                && count($solutionTemplates) >= 4
                && count($dataProducts) >= 5
                && (bool) data_get($solution, 'solution_operating_model.external_side_effects_default', true) === false,
            'external_research_green' => ($research['schema'] ?? null) === 'atlas.ai.company.enterprise_external_research_adoption_stack.v1'
                && count($researchSources) >= 12
                && count($frameworkRepos) >= 8
                && count($domainRepos) >= 3
                && count($flowAdoption) >= $flowCount
                && count($connectorBacklog) >= $connectorCount
                && (bool) data_get($research, 'research_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($research, 'research_policy.repository_adoption_without_license_security_and_fixture_eval_allowed', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.flow_quality_research_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'offline_dataset_count' => count($datasets),
            'trace_rubric_count' => count($rubrics),
            'adversarial_case_count' => count($adversarial),
            'deterministic_assertion_count' => count($assertions),
            'replay_matrix_count' => count($replayMatrix),
            'benchmark_metric_count' => count($metrics),
            'tooling_benchmark_count' => count($toolingBenchmarks),
            'tooling_integration_backlog_count' => count($toolingBacklog),
            'domain_solution_module_count' => count($solutionModules),
            'domain_solution_template_count' => count($solutionTemplates),
            'domain_solution_data_product_count' => count($dataProducts),
            'external_research_source_count' => count($researchSources),
            'external_research_framework_repo_count' => count($frameworkRepos),
            'external_research_domain_repo_count' => count($domainRepos),
            'flow_ids' => array_values(array_map(
                static fn (array $dataset): string => (string) ($dataset['flow_id'] ?? 'unknown_flow'),
                $datasets,
            )),
            'next_actions' => ['run_offline_replay_suite_per_flow', 'capture_trace_grades_and_adversarial_regressions', 'verify_deterministic_state_assertions', 'review_tooling_benchmarks', 'bind_domain_solution_modules_before_promotion'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['flow_quality_research_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function companyCommandCenterCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_company_command_center_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $metricCount = count((array) ($company['metrics'] ?? []));
        $operatingCells = (array) data_get($stack, 'operating_cells', []);
        $flowCards = (array) data_get($stack, 'flow_command_cards', []);
        $connectorPanels = (array) data_get($stack, 'connector_workbench_panels', []);
        $consoleViews = (array) data_get($stack, 'operator_console_views', []);
        $workProductFactory = (array) data_get($stack, 'work_product_factory_map', []);
        $kpis = (array) data_get($stack, 'command_center_kpis', []);

        $checks = [
            'command_center_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_company_command_center_stack.v1',
            'operating_cells_green' => count($operatingCells) >= 6,
            'flow_command_cards_green' => count($flowCards) >= $flowCount && $flowCount > 0,
            'connector_workbench_panels_green' => count($connectorPanels) >= $connectorCount && $connectorCount > 0,
            'operator_console_views_green' => count($consoleViews) >= 4,
            'work_product_factory_green' => count($workProductFactory) >= 5,
            'command_center_kpis_green' => count($kpis) >= $metricCount && $metricCount > 0,
            'calendar_wait_removed' => (bool) data_get($stack, 'command_center_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'command_center_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false,
            'secret_material_blocked' => (bool) data_get($stack, 'command_center_policy.secret_material_in_packet_allowed', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.command_center_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'metric_count' => $metricCount,
            'operating_cell_count' => count($operatingCells),
            'flow_command_card_count' => count($flowCards),
            'connector_workbench_panel_count' => count($connectorPanels),
            'operator_console_view_count' => count($consoleViews),
            'work_product_factory_count' => count($workProductFactory),
            'command_center_kpi_count' => count($kpis),
            'operating_cell_ids' => array_values(array_map(
                static fn (array $cell): string => (string) ($cell['cell_id'] ?? 'unknown_cell'),
                $operatingCells,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $card): string => (string) ($card['flow_id'] ?? 'unknown_flow'),
                $flowCards,
            )),
            'connector_panel_ids' => array_values(array_map(
                static fn (array $panel): string => (string) ($panel['panel_id'] ?? 'unknown_panel'),
                $connectorPanels,
            )),
            'console_view_ids' => array_values(array_map(
                static fn (array $view): string => (string) ($view['view_id'] ?? 'unknown_view'),
                $consoleViews,
            )),
            'next_actions' => ['operate_flow_cards_from_command_center', 'run_read_only_connector_panels', 'review_command_center_kpis', 'resolve_pause_triggers_before_handoff', 'collect_operator_interrupt_receipt_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['company_command_center_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function flowOperatingPackageCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_flow_operating_packages', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $sources = (array) data_get($stack, 'source_basis', []);
        $packages = (array) data_get($stack, 'flow_packages', []);
        $metrics = (array) data_get($stack, 'package_observability.required_metrics', []);
        $packageHashes = array_values(array_filter(
            array_map(static fn (array $package): string => (string) ($package['package_hash'] ?? ''), $packages),
            static fn (string $hash): bool => strlen($hash) === 64,
        ));
        $replayContracts = array_values(array_filter($packages, static fn (array $package): bool => (int) data_get($package, 'quality_replay_cell.minimum_cases_before_shadow', 0) >= 25
            && (bool) data_get($package, 'quality_replay_cell.promotion_without_green_replay_allowed', true) === false));
        $operationsContracts = array_values(array_filter($packages, static fn (array $package): bool => (bool) data_get($package, 'operations_cell.runbook_drill_required_before_supervised_mode', false)
            && (bool) data_get($package, 'operations_cell.post_run_reconciliation_required', false)));
        $packageSchemas = array_values(array_filter($packages, static fn (array $package): bool => ($package['schema'] ?? null) === 'atlas.ai.company.enterprise_flow_operating_package.v1'));

        $checks = [
            'flow_operating_package_stack_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_flow_operating_package_stack.v1',
            'source_basis_green' => count($sources) >= 10,
            'package_for_every_flow_green' => count($packages) >= $flowCount && $flowCount > 0,
            'package_schema_green' => count($packageSchemas) === count($packages) && $packages !== [],
            'package_hashes_green' => count($packageHashes) === count($packages) && $packages !== [],
            'replay_contracts_green' => count($replayContracts) === count($packages) && $packages !== [],
            'operations_contracts_green' => count($operationsContracts) === count($packages) && $packages !== [],
            'observability_metrics_green' => count($metrics) >= 6,
            'calendar_wait_removed' => (bool) data_get($stack, 'operating_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'operating_policy.external_execution_allowed_by_package', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.flow_operating_package_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'source_count' => count($sources),
            'flow_package_count' => count($packages),
            'package_hash_count' => count($packageHashes),
            'replay_contract_count' => count($replayContracts),
            'operations_contract_count' => count($operationsContracts),
            'metric_count' => count($metrics),
            'minimum_replay_cases_before_shadow' => 25,
            'flow_ids' => array_values(array_map(
                static fn (array $package): string => (string) ($package['flow_id'] ?? 'unknown_flow'),
                $packages,
            )),
            'package_ids' => array_values(array_map(
                static fn (array $package): string => (string) ($package['package_id'] ?? 'unknown_package'),
                $packages,
            )),
            'package_hashes' => $packageHashes,
            'next_actions' => ['run_package_fixture_replays', 'verify_package_workbench_scopes', 'drill_package_runbooks', 'refresh_operating_packet_from_green_packages', 'collect_operator_mandate_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['flow_operating_package_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function verticalSolutionSuiteCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_vertical_solution_suite_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $sources = (array) data_get($stack, 'source_basis', []);
        $suites = (array) data_get($stack, 'solution_suites', []);
        $flowKits = (array) data_get($stack, 'flow_solution_kits', []);
        $connectorWorkbenches = (array) data_get($stack, 'connector_solution_workbenches', []);
        $artifactFactories = (array) data_get($stack, 'artifact_factory_catalog', []);
        $evaluationRecipes = (array) data_get($stack, 'suite_evaluation_recipes', []);
        $metrics = (array) data_get($stack, 'suite_observability.required_metrics', []);

        $checks = [
            'vertical_solution_suite_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_vertical_solution_suite_stack.v1',
            'source_basis_green' => count($sources) >= 5,
            'solution_suites_green' => count($suites) >= 6,
            'flow_solution_kits_green' => count($flowKits) >= $flowCount && $flowCount > 0,
            'connector_workbenches_green' => count($connectorWorkbenches) >= $connectorCount && $connectorCount > 0,
            'artifact_factories_green' => count($artifactFactories) >= 5,
            'evaluation_recipes_green' => count($evaluationRecipes) >= $flowCount && $flowCount > 0,
            'observability_metrics_green' => count($metrics) >= 8,
            'calendar_wait_removed' => (bool) data_get($stack, 'suite_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'suite_policy.external_execution_allowed_by_suite', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.vertical_solution_suite_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'source_count' => count($sources),
            'solution_suite_count' => count($suites),
            'flow_solution_kit_count' => count($flowKits),
            'connector_workbench_count' => count($connectorWorkbenches),
            'artifact_factory_count' => count($artifactFactories),
            'evaluation_recipe_count' => count($evaluationRecipes),
            'metric_count' => count($metrics),
            'suite_ids' => array_values(array_map(
                static fn (array $suite): string => (string) ($suite['suite_id'] ?? 'unknown_suite'),
                $suites,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $kit): string => (string) ($kit['flow_id'] ?? 'unknown_flow'),
                $flowKits,
            )),
            'source_ids' => array_values(array_map(
                static fn (array $source): string => (string) ($source['source_id'] ?? 'unknown_source'),
                $sources,
            )),
            'next_actions' => ['operate_vertical_solution_suites', 'run_flow_solution_kit_replays', 'verify_connector_solution_workbenches', 'publish_internal_artifact_factory_receipts', 'collect_operator_mandate_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['vertical_solution_suite_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function domainBusinessExecutionMeshCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_domain_business_execution_mesh_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $executionModes = (array) data_get($stack, 'execution_mode_catalog', []);
        $executionCells = (array) data_get($stack, 'flow_execution_cells', []);
        $kpiBindings = (array) data_get($stack, 'flow_tool_kpi_matrix', []);
        $serviceLanes = (array) data_get($stack, 'domain_service_lanes', []);
        $artifactContracts = (array) data_get($stack, 'business_artifact_delivery_contracts', []);
        $metrics = (array) data_get($stack, 'execution_observability.required_metrics', []);

        $checks = [
            'business_execution_mesh_schema_green' => ($stack['schema'] ?? null) === 'atlas.ai.company.enterprise_domain_business_execution_mesh_stack.v1',
            'execution_modes_green' => count($executionModes) >= 4,
            'execution_cells_green' => count($executionCells) >= $flowCount && $flowCount > 0,
            'flow_kpi_bindings_green' => count($kpiBindings) >= $flowCount && $flowCount > 0,
            'service_lanes_green' => count($serviceLanes) >= $flowCount && $flowCount > 0,
            'artifact_delivery_contracts_green' => count($artifactContracts) >= 5,
            'observability_metrics_green' => count($metrics) >= 8,
            'calendar_wait_removed' => (bool) data_get($stack, 'execution_policy.calendar_wait_blocker_enabled', true) === false,
            'external_execution_blocked' => (bool) data_get($stack, 'execution_policy.autonomous_external_write_spend_trade_publish_deploy_delete_allowed', true) === false,
            'operator_signed_scope_required' => (bool) data_get($stack, 'execution_policy.operator_signed_scope_required_for_external_effect', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.domain_business_execution_mesh_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'execution_mode_count' => count($executionModes),
            'execution_cell_count' => count($executionCells),
            'flow_kpi_binding_count' => count($kpiBindings),
            'service_lane_count' => count($serviceLanes),
            'artifact_delivery_contract_count' => count($artifactContracts),
            'metric_count' => count($metrics),
            'execution_mode_ids' => array_values(array_map(
                static fn (array $mode): string => (string) ($mode['mode_id'] ?? 'unknown_mode'),
                $executionModes,
            )),
            'flow_ids' => array_values(array_map(
                static fn (array $cell): string => (string) ($cell['flow_id'] ?? 'unknown_flow'),
                $executionCells,
            )),
            'next_actions' => ['operate_domain_business_execution_cells', 'track_flow_tool_kpi_bindings', 'review_service_lane_slas', 'ship_internal_business_artifacts', 'collect_operator_mandate_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['domain_business_execution_mesh_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function operationalDressRehearsalCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_operational_dress_rehearsal_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $runbooks = (array) data_get($stack, 'flow_rehearsal_runbooks', []);
        $probes = (array) data_get($stack, 'live_read_probe_plan', []);
        $acceptance = (array) data_get($stack, 'operator_acceptance_packets', []);
        $rollback = (array) data_get($stack, 'rollback_drill_matrix', []);
        $promotion = (array) data_get($stack, 'promotion_evidence_matrix', []);
        $metrics = (array) data_get($stack, 'dress_rehearsal_observability.required_metrics', []);

        $checks = [
            'flow_rehearsal_runbooks_green' => count($runbooks) >= $flowCount && $flowCount > 0,
            'live_read_probe_plan_green' => count($probes) >= $connectorCount && $connectorCount > 0,
            'operator_acceptance_packets_green' => count($acceptance) >= $flowCount && $flowCount > 0,
            'rollback_drill_matrix_green' => count($rollback) >= $flowCount && $flowCount > 0,
            'promotion_evidence_matrix_green' => count($promotion) >= $flowCount && $flowCount > 0,
            'observability_green' => count($metrics) >= 7,
            'calendar_wait_removed' => (bool) data_get($stack, 'rehearsal_policy.calendar_wait_blocker_enabled', true) === false,
            'external_mutation_blocked' => (bool) data_get($stack, 'rehearsal_policy.external_mutation_allowed_during_rehearsal', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.operational_dress_rehearsal_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'flow_rehearsal_runbook_count' => count($runbooks),
            'live_read_probe_count' => count($probes),
            'operator_acceptance_packet_count' => count($acceptance),
            'rollback_drill_count' => count($rollback),
            'promotion_evidence_count' => count($promotion),
            'dress_rehearsal_metric_count' => count($metrics),
            'next_actions' => ['execute_non_production_rehearsal', 'capture_live_read_probe_receipts', 'record_operator_acceptance', 'run_rollback_drill'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['operational_dress_rehearsal_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $flow
     * @param list<array<string,mixed>> $connectorRows
     * @param list<array<string,mixed>> $liveReadRows
     * @param array<string,mixed> $operationsRunbook
     * @return array<string,mixed>
     */
    private function realExternalExecutionFlowDossier(
        string $companyId,
        array $flow,
        bool $providerReady,
        bool $repositoryAdoptionReady,
        bool $industryEcosystemReady,
        array $repositoryAdoptionCompany,
        array $industryEcosystemCompany,
        bool $businessBackboneReady,
        array $businessBackboneCompany,
        bool $productionPreflightReady,
        array $productionPreflightCompany,
        bool $flowQualityReady,
        array $flowQualityCompany,
        bool $verticalSuiteReady,
        array $verticalSuiteCompany,
        bool $businessExecutionMeshReady,
        array $businessExecutionMeshCompany,
        bool $flowPackageReady,
        array $flowPackageCompany,
        bool $commandCenterReady,
        array $commandCenterCompany,
        bool $rehearsalReady,
        array $connectorRows,
        array $liveReadRows,
        array $operationsRunbook,
    ): array {
        $connectorGreen = $connectorRows !== []
            && count(array_filter($connectorRows, static fn (array $row): bool => (bool) ($row['sandbox_probe_green'] ?? false))) === count($connectorRows);
        $liveReadGreen = $liveReadRows !== []
            && count(array_filter($liveReadRows, static fn (array $row): bool => (bool) ($row['live_read_ready'] ?? false))) === count($liveReadRows);
        $vaultGreen = $connectorRows !== []
            && count(array_filter($connectorRows, static fn (array $row): bool => (bool) ($row['vault_binding_attested'] ?? false))) === count($connectorRows);
        $sloGreen = $connectorRows !== []
            && count(array_filter($connectorRows, static fn (array $row): bool => (bool) ($row['slo_monitor_bound'] ?? false))) === count($connectorRows);
        $reconciliationGreen = $connectorRows !== []
            && count(array_filter($connectorRows, static fn (array $row): bool => (bool) ($row['reconciliation_bound'] ?? false))) === count($connectorRows);
        $operationsRunbookGreen = ($operationsRunbook['status'] ?? null) === 'operations_runbook_green_external_blocked'
            && (bool) ($operationsRunbook['slo_green'] ?? false)
            && (bool) ($operationsRunbook['incident_route_green'] ?? false)
            && (bool) ($operationsRunbook['reconciliation_green'] ?? false)
            && (bool) ($operationsRunbook['promotion_gate_green'] ?? false);
        $manualMandateReady = (bool) ($flow['manual_handoff_ready'] ?? false);

        $missing = [];
        if (! $providerReady) {
            $missing[] = 'provider_workbench_not_ready';
        }
        if (! $repositoryAdoptionReady) {
            $missing[] = 'agent_repository_adoption_pipeline_not_ready';
        }
        if (! $industryEcosystemReady) {
            $missing[] = 'industry_solution_ecosystem_not_ready';
        }
        if (! $businessBackboneReady) {
            $missing[] = 'business_operating_backbone_not_ready';
        }
        if (! $productionPreflightReady) {
            $missing[] = 'production_connector_preflight_not_ready';
        }
        if (! $flowQualityReady) {
            $missing[] = 'flow_quality_research_not_ready';
        }
        if (! $verticalSuiteReady) {
            $missing[] = 'vertical_solution_suite_not_ready';
        }
        if (! $businessExecutionMeshReady) {
            $missing[] = 'domain_business_execution_mesh_not_ready';
        }
        if (! $flowPackageReady) {
            $missing[] = 'flow_operating_package_not_ready';
        }
        if (! $commandCenterReady) {
            $missing[] = 'company_command_center_not_ready';
        }
        if (! $rehearsalReady) {
            $missing[] = 'operational_dress_rehearsal_not_ready';
        }
        if (! $connectorGreen) {
            $missing[] = 'connector_activation_probe_not_green';
        }
        if (! $liveReadGreen) {
            $missing[] = 'live_read_connector_readiness_not_green';
        }
        if (! $manualMandateReady) {
            $missing[] = 'operator_and_second_reviewer_signed_mandate_missing';
        }

        $missing = array_values(array_unique(array_merge(
            $missing,
            array_values((array) ($flow['connector_activation_gaps'] ?? [])),
            array_values((array) ($flow['governance_gaps'] ?? [])),
            array_values((array) ($flow['operationalization_gaps'] ?? [])),
        )));
        if ($vaultGreen) {
            $missing = array_values(array_diff($missing, ['credential_vault_binding_missing']));
        }
        if ($connectorGreen) {
            $missing = array_values(array_diff($missing, ['sandbox_probe_receipt_missing']));
        }
        if ($sloGreen || $operationsRunbookGreen) {
            $missing = array_values(array_diff($missing, ['connector_slo_monitor_missing', 'quality_slo_baseline_missing']));
        }
        if ($reconciliationGreen || $operationsRunbookGreen) {
            $missing = array_values(array_diff($missing, ['post_execution_reconciliation_adapter_missing']));
        }
        if ($operationsRunbookGreen) {
            $missing = array_values(array_diff($missing, [
                'rollback_or_compensation_drill_missing',
                'incident_route_drill_missing',
                'dlq_replay_runbook_receipt_missing',
            ]));
        }
        $handoffPack = $this->realExternalExecutionHandoffPackForFlow(
            $companyId,
            $flow,
            $providerReady,
            $repositoryAdoptionReady,
            $industryEcosystemReady,
            $repositoryAdoptionCompany,
            $industryEcosystemCompany,
            $businessBackboneReady,
            $businessBackboneCompany,
            $productionPreflightReady,
            $productionPreflightCompany,
            $flowQualityReady,
            $flowQualityCompany,
            $verticalSuiteReady,
            $verticalSuiteCompany,
            $businessExecutionMeshReady,
            $businessExecutionMeshCompany,
            $flowPackageReady,
            $flowPackageCompany,
            $commandCenterReady,
            $commandCenterCompany,
            $rehearsalReady,
            $connectorGreen,
            $liveReadGreen,
            $operationsRunbookGreen,
        );
        if ((bool) ($handoffPack['ready_for_manual_handoff_gate'] ?? false)) {
            $missing = array_values(array_diff($missing, [
                'agent_repository_adoption_pipeline_not_ready',
                'industry_solution_ecosystem_not_ready',
                'business_operating_backbone_not_ready',
                'production_connector_preflight_not_ready',
                'flow_quality_research_not_ready',
                'vertical_solution_suite_not_ready',
                'domain_business_execution_mesh_not_ready',
                'flow_operating_package_not_ready',
                'company_command_center_not_ready',
                'live_read_connector_readiness_not_green',
                'production_scope_contract_missing',
                'legal_or_risk_scope_acceptance_missing',
                'budget_or_loss_cap_signature_missing',
                'manual_execution_owner_assignment_missing',
                'recurring_schedule_binding_missing',
                'run_queue_worker_binding_missing',
                'customer_or_stakeholder_acceptance_loop_missing',
            ]));
        }
        $candidate = $missing === [];

        $row = [
            'schema' => 'atlas.ai.company.flow_real_external_execution_readiness_dossier.v1',
            'company_id' => $companyId,
            'flow_id' => (string) ($flow['flow_id'] ?? 'unknown'),
            'activation_stage' => (string) ($flow['activation_stage'] ?? 'unknown'),
            'provider_workbench_ready' => $providerReady,
            'agent_repository_adoption_ready' => $repositoryAdoptionReady,
            'industry_solution_ecosystem_ready' => $industryEcosystemReady,
            'business_operating_backbone_ready' => $businessBackboneReady,
            'production_connector_preflight_ready' => $productionPreflightReady,
            'flow_quality_research_ready' => $flowQualityReady,
            'vertical_solution_suite_ready' => $verticalSuiteReady,
            'domain_business_execution_mesh_ready' => $businessExecutionMeshReady,
            'flow_operating_package_ready' => $flowPackageReady,
            'company_command_center_ready' => $commandCenterReady,
            'operational_dress_rehearsal_ready' => $rehearsalReady,
            'connector_activation_probe_green' => $connectorGreen,
            'live_read_connector_readiness_green' => $liveReadGreen,
            'vault_binding_attested' => $vaultGreen,
            'slo_monitor_bound' => $sloGreen || $operationsRunbookGreen,
            'reconciliation_bound' => $reconciliationGreen || $operationsRunbookGreen,
            'operations_runbook_green' => $operationsRunbookGreen,
            'handoff_pack_ready' => (bool) ($handoffPack['ready_for_manual_handoff_gate'] ?? false),
            'manual_handoff_ready' => $manualMandateReady,
            'manual_handoff_candidate' => $candidate,
            'missing_before_real_external_execution' => $missing,
            'required_evidence' => array_values(array_unique(array_merge(
                array_values((array) ($flow['evidence_required_for_real_operation'] ?? [])),
                [
                    'agent_repository_adoption_status_hash',
                    'repository_license_security_sbom_review_receipt',
                    'repository_version_pin_and_fixture_eval_receipt',
                    'industry_solution_ecosystem_status_hash',
                    'industry_data_interface_source_link_attestation',
                    'industry_solution_audit_and_confidentiality_attestation',
                    'business_operating_backbone_status_hash',
                    'customer_account_vendor_grc_backbone_attestation',
                    'semantic_operating_graph_export_receipt',
                    'unit_economics_and_capacity_simulation_receipt',
                    'production_connector_preflight_status_hash',
                    'production_connector_vault_scope_and_signed_cutover_receipt',
                    'production_connector_rollback_drill_green_receipt',
                    'flow_quality_research_status_hash',
                    'offline_replay_trace_grade_receipt',
                    'adversarial_and_deterministic_assertion_receipt',
                    'tooling_benchmark_and_domain_solution_receipt',
                    'vertical_solution_suite_status_hash',
                    'vertical_solution_flow_kit_replay_receipt',
                    'vertical_solution_artifact_factory_receipt',
                    'domain_business_execution_mesh_status_hash',
                    'domain_business_execution_cell_receipt',
                    'flow_tool_kpi_binding_receipt',
                    'domain_service_lane_sla_receipt',
                    'flow_operating_package_status_hash',
                    'flow_operating_package_replay_contract_receipt',
                    'flow_operating_package_runbook_drill_receipt',
                    'company_command_center_status_hash',
                    'flow_command_card_and_operator_console_receipt',
                    'connector_panel_and_pause_protocol_receipt',
                    'live_read_connector_readiness_status_hash',
                    'schema_snapshot_and_sample_payload_receipt',
                    'read_only_vault_scope_reference_receipt',
                ],
            ))),
            'real_external_execution_handoff_pack' => $handoffPack,
            'connector_activation_count' => count($connectorRows),
            'connector_probe_green_count' => count(array_filter($connectorRows, static fn (array $row): bool => (bool) ($row['sandbox_probe_green'] ?? false))),
            'live_read_connector_count' => count($liveReadRows),
            'live_read_ready_count' => count(array_filter($liveReadRows, static fn (array $row): bool => (bool) ($row['live_read_ready'] ?? false))),
            'next_action' => $candidate
                ? 'manual_operator_execution_handoff_outside_autonomous_suite'
                : 'complete_missing_real_external_execution_evidence',
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['flow_dossier_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $flow
     * @return array<string,mixed>
     */
    private function realExternalExecutionHandoffPackForFlow(
        string $companyId,
        array $flow,
        bool $providerReady,
        bool $repositoryAdoptionReady,
        bool $industryEcosystemReady,
        array $repositoryAdoptionCompany,
        array $industryEcosystemCompany,
        bool $businessBackboneReady,
        array $businessBackboneCompany,
        bool $productionPreflightReady,
        array $productionPreflightCompany,
        bool $flowQualityReady,
        array $flowQualityCompany,
        bool $verticalSuiteReady,
        array $verticalSuiteCompany,
        bool $businessExecutionMeshReady,
        array $businessExecutionMeshCompany,
        bool $flowPackageReady,
        array $flowPackageCompany,
        bool $commandCenterReady,
        array $commandCenterCompany,
        bool $rehearsalReady,
        bool $connectorGreen,
        bool $liveReadGreen,
        bool $operationsRunbookGreen,
    ): array {
        $flowId = (string) ($flow['flow_id'] ?? 'unknown');
        $ready = $providerReady
            && $repositoryAdoptionReady
            && $industryEcosystemReady
            && $businessBackboneReady
            && $productionPreflightReady
            && $flowQualityReady
            && $verticalSuiteReady
            && $businessExecutionMeshReady
            && $flowPackageReady
            && $commandCenterReady
            && $rehearsalReady
            && $connectorGreen
            && $liveReadGreen
            && $operationsRunbookGreen;

        $pack = [
            'schema' => 'atlas.ai.company.flow_real_external_execution_handoff_pack.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'ready_for_manual_handoff_gate' => $ready,
            'repository_adoption_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.agent_repository_adoption.v1',
                'ready' => $repositoryAdoptionReady,
                'company_status_hash' => $repositoryAdoptionCompany['agent_repository_adoption_company_hash'] ?? null,
                'repository_intake_count' => (int) ($repositoryAdoptionCompany['repository_intake_count'] ?? 0),
                'framework_scorecard_count' => (int) ($repositoryAdoptionCompany['framework_scorecard_count'] ?? 0),
                'version_pin_count' => (int) ($repositoryAdoptionCompany['version_pin_count'] ?? 0),
                'requires_license_security_sbom_fixture_eval_and_operator_acceptance' => true,
                'runtime_use_without_local_contract_tests_allowed' => false,
                'auto_upgrade_or_procurement_allowed' => false,
            ],
            'industry_solution_ecosystem_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.industry_solution_ecosystem.v1',
                'ready' => $industryEcosystemReady,
                'company_status_hash' => $industryEcosystemCompany['industry_solution_ecosystem_company_hash'] ?? null,
                'ecosystem_provider_count' => (int) ($industryEcosystemCompany['ecosystem_provider_count'] ?? 0),
                'implementation_partner_track_count' => (int) ($industryEcosystemCompany['implementation_partner_track_count'] ?? 0),
                'data_interface_count' => (int) ($industryEcosystemCompany['data_interface_count'] ?? 0),
                'requires_direct_source_links_audit_trail_and_confidentiality_attestation' => true,
                'external_contracting_allowed_by_stack' => false,
            ],
            'business_operating_backbone_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.business_operating_backbone.v1',
                'ready' => $businessBackboneReady,
                'company_status_hash' => $businessBackboneCompany['business_operating_backbone_company_hash'] ?? null,
                'ready_component_count' => (int) ($businessBackboneCompany['ready_component_count'] ?? 0),
                'required_component_count' => (int) ($businessBackboneCompany['required_component_count'] ?? 0),
                'requires_customer_account_vendor_grc_semantic_graph_and_unit_economics_attestation' => true,
                'external_customer_vendor_billing_or_capital_action_allowed' => false,
            ],
            'production_connector_preflight_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.production_connector_preflight.v1',
                'ready' => $productionPreflightReady,
                'company_status_hash' => $productionPreflightCompany['production_connector_preflight_company_hash'] ?? null,
                'connector_preflight_contract_count' => (int) ($productionPreflightCompany['connector_preflight_contract_count'] ?? 0),
                'production_evidence_register_count' => (int) ($productionPreflightCompany['production_evidence_register_count'] ?? 0),
                'requires_vault_scope_signed_cutover_and_rollback_drill' => true,
                'auto_cutover_allowed' => false,
                'real_credential_material_in_packet_allowed' => false,
            ],
            'flow_quality_research_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.flow_quality_research.v1',
                'ready' => $flowQualityReady,
                'company_status_hash' => $flowQualityCompany['flow_quality_research_company_hash'] ?? null,
                'offline_dataset_count' => (int) ($flowQualityCompany['offline_dataset_count'] ?? 0),
                'trace_rubric_count' => (int) ($flowQualityCompany['trace_rubric_count'] ?? 0),
                'adversarial_case_count' => (int) ($flowQualityCompany['adversarial_case_count'] ?? 0),
                'tooling_benchmark_count' => (int) ($flowQualityCompany['tooling_benchmark_count'] ?? 0),
                'domain_solution_module_count' => (int) ($flowQualityCompany['domain_solution_module_count'] ?? 0),
                'requires_replay_trace_adversarial_deterministic_tooling_and_solution_receipts' => true,
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
            ],
            'vertical_solution_suite_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.vertical_solution_suite.v1',
                'ready' => $verticalSuiteReady,
                'company_status_hash' => $verticalSuiteCompany['vertical_solution_suite_company_hash'] ?? null,
                'solution_suite_count' => (int) ($verticalSuiteCompany['solution_suite_count'] ?? 0),
                'flow_solution_kit_count' => (int) ($verticalSuiteCompany['flow_solution_kit_count'] ?? 0),
                'connector_workbench_count' => (int) ($verticalSuiteCompany['connector_workbench_count'] ?? 0),
                'artifact_factory_count' => (int) ($verticalSuiteCompany['artifact_factory_count'] ?? 0),
                'evaluation_recipe_count' => (int) ($verticalSuiteCompany['evaluation_recipe_count'] ?? 0),
                'requires_suite_flow_kit_workbench_artifact_factory_eval_recipe_and_operator_handoff' => true,
                'external_execution_allowed_by_suite' => false,
                'calendar_wait_blocker_enabled' => false,
            ],
            'domain_business_execution_mesh_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.domain_business_execution_mesh.v1',
                'ready' => $businessExecutionMeshReady,
                'company_status_hash' => $businessExecutionMeshCompany['domain_business_execution_mesh_company_hash'] ?? null,
                'execution_mode_count' => (int) ($businessExecutionMeshCompany['execution_mode_count'] ?? 0),
                'execution_cell_count' => (int) ($businessExecutionMeshCompany['execution_cell_count'] ?? 0),
                'flow_kpi_binding_count' => (int) ($businessExecutionMeshCompany['flow_kpi_binding_count'] ?? 0),
                'service_lane_count' => (int) ($businessExecutionMeshCompany['service_lane_count'] ?? 0),
                'artifact_delivery_contract_count' => (int) ($businessExecutionMeshCompany['artifact_delivery_contract_count'] ?? 0),
                'requires_domain_execution_cell_kpi_binding_service_lane_and_artifact_acceptance' => true,
                'external_execution_allowed_by_mesh' => false,
                'calendar_wait_blocker_enabled' => false,
            ],
            'company_command_center_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.company_command_center.v1',
                'ready' => $commandCenterReady,
                'company_status_hash' => $commandCenterCompany['company_command_center_company_hash'] ?? null,
                'operating_cell_count' => (int) ($commandCenterCompany['operating_cell_count'] ?? 0),
                'flow_command_card_count' => (int) ($commandCenterCompany['flow_command_card_count'] ?? 0),
                'connector_workbench_panel_count' => (int) ($commandCenterCompany['connector_workbench_panel_count'] ?? 0),
                'operator_console_view_count' => (int) ($commandCenterCompany['operator_console_view_count'] ?? 0),
                'requires_flow_cards_connector_panels_kpis_pause_protocol_and_operator_interrupt_receipts' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'secret_material_in_packet_allowed' => false,
            ],
            'flow_operating_package_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.flow_operating_package.v1',
                'ready' => $flowPackageReady,
                'company_status_hash' => $flowPackageCompany['flow_operating_package_company_hash'] ?? null,
                'flow_package_count' => (int) ($flowPackageCompany['flow_package_count'] ?? 0),
                'source_count' => (int) ($flowPackageCompany['source_count'] ?? 0),
                'package_hash_count' => (int) ($flowPackageCompany['package_hash_count'] ?? 0),
                'replay_contract_count' => (int) ($flowPackageCompany['replay_contract_count'] ?? 0),
                'operations_contract_count' => (int) ($flowPackageCompany['operations_contract_count'] ?? 0),
                'minimum_replay_cases_before_shadow' => (int) ($flowPackageCompany['minimum_replay_cases_before_shadow'] ?? 25),
                'requires_package_fixture_replay_workbench_scope_runbook_drill_and_current_operating_packet' => true,
                'external_execution_allowed_by_package' => false,
                'calendar_wait_blocker_enabled' => false,
            ],
            'production_scope_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.production_scope_contract.v1',
                'scope_mode' => 'manual_operator_execution_only',
                'allowed_execution_surface' => 'outside_autonomous_suite_after_operator_review',
                'blocked_in_autonomous_suite' => ['auto_execute', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
                'requires_operator_signature' => true,
                'requires_second_reviewer_signature' => true,
            ],
            'live_read_connector_readiness_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.live_read_connector_readiness.v1',
                'ready' => $liveReadGreen,
                'requires_schema_snapshot_sample_payload_provider_lineage_and_vault_scope_reference' => true,
                'credential_material_in_packet_allowed' => false,
                'external_write_allowed' => false,
            ],
            'legal_risk_acceptance_packet' => [
                'packet_id' => $companyId.'.'.$flowId.'.legal_risk_acceptance.v1',
                'required_reviews' => ['legal_scope', 'risk_acceptance', 'data_processing_terms', 'customer_or_stakeholder_impact'],
                'acceptance_status' => 'prepared_requires_real_signatures',
                'external_execution_allowed_by_packet' => false,
            ],
            'budget_or_loss_cap_packet' => [
                'packet_id' => $companyId.'.'.$flowId.'.budget_loss_cap.v1',
                'spend_cap_required' => true,
                'loss_cap_required_for_financial_or_security_scope' => true,
                'cap_status' => 'prepared_requires_operator_signature',
                'spend_without_cap_allowed' => false,
            ],
            'manual_execution_owner_assignment' => [
                'assignment_id' => $companyId.'.'.$flowId.'.manual_execution_owner.v1',
                'owner_role' => $companyId.'.domain_operator',
                'backup_owner_role' => 'portfolio_governor',
                'owner_acceptance_required' => true,
                'auto_owner_assignment_allowed' => false,
            ],
            'recurring_schedule_binding' => [
                'binding_id' => $companyId.'.'.$flowId.'.recurring_schedule_binding.v1',
                'mode' => 'operator_scheduled_manual_or_supervised_window',
                'requires_change_window' => true,
                'auto_schedule_external_execution_allowed' => false,
            ],
            'run_queue_worker_binding' => [
                'binding_id' => $companyId.'.'.$flowId.'.run_queue_worker_binding.v1',
                'worker_mode' => 'internal_preparation_and_post_execution_reconciliation_only',
                'external_action_worker_enabled' => false,
                'dlq_replay_supported' => true,
            ],
            'customer_or_stakeholder_acceptance_loop' => [
                'loop_id' => $companyId.'.'.$flowId.'.stakeholder_acceptance.v1',
                'acceptance_states' => ['drafted', 'review_requested', 'accepted_with_operator_signature', 'rejected_or_needs_revision'],
                'external_customer_commitment_allowed' => false,
                'acceptance_required_before_manual_execution' => true,
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $pack['handoff_pack_hash'] = MissionCanonicalHash::sha256($pack);

        return $pack;
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<string,mixed>
     */
    private function supervisedExternalExecutionPacketForFlow(array $pack): array
    {
        $companyId = (string) ($pack['company_id'] ?? 'unknown');
        $flowId = (string) ($pack['flow_id'] ?? 'unknown');
        $ready = (bool) ($pack['ready_for_manual_handoff_gate'] ?? false)
            && (bool) data_get($pack, 'production_scope_contract.requires_operator_signature', false)
            && (bool) data_get($pack, 'production_scope_contract.requires_second_reviewer_signature', false)
            && (bool) data_get($pack, 'production_connector_preflight_contract.ready', false)
            && (bool) data_get($pack, 'live_read_connector_readiness_contract.ready', false)
            && (bool) data_get($pack, 'flow_quality_research_contract.ready', false)
            && (bool) data_get($pack, 'flow_operating_package_contract.ready', false)
            && (bool) data_get($pack, 'company_command_center_contract.ready', false)
            && (bool) data_get($pack, 'run_queue_worker_binding.external_action_worker_enabled', true) === false
            && (bool) ($pack['external_execution_allowed'] ?? true) === false
            && (bool) ($pack['external_side_effects_enabled'] ?? true) === false;

        $packet = [
            'schema' => 'atlas.ai.company.supervised_external_execution_packet.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'packet_ready' => $ready,
            'execution_mode' => 'manual_or_supervised_window_after_real_signatures_external_worker_disabled',
            'operator_signature_required' => (bool) data_get($pack, 'production_scope_contract.requires_operator_signature', false),
            'second_reviewer_required' => (bool) data_get($pack, 'production_scope_contract.requires_second_reviewer_signature', false),
            'production_scope' => [
                'ready' => isset($pack['production_scope_contract']),
                'scope_mode' => (string) data_get($pack, 'production_scope_contract.scope_mode', ''),
                'allowed_execution_surface' => (string) data_get($pack, 'production_scope_contract.allowed_execution_surface', ''),
                'blocked_in_autonomous_suite' => array_values((array) data_get($pack, 'production_scope_contract.blocked_in_autonomous_suite', [])),
            ],
            'runtime_control' => [
                'ready' => true,
                'external_worker_enabled' => false,
                'kill_switch_bound' => true,
                'pause_protocol' => 'operator_interrupt_or_policy_exception_immediately_blocks_external_action',
                'change_window_required' => (bool) data_get($pack, 'recurring_schedule_binding.requires_change_window', true),
                'auto_retry_external_action_allowed' => false,
                'dlq_replay_supported_for_internal_preparation_only' => (bool) data_get($pack, 'run_queue_worker_binding.dlq_replay_supported', false),
            ],
            'pre_execution_checklist' => [
                'signed_operator_scope',
                'second_reviewer_signature',
                'legal_risk_acceptance',
                'budget_or_loss_cap_signature',
                'credential_vault_reference_verified',
                'production_connector_preflight_green',
                'live_read_schema_snapshot_green',
                'rollback_or_compensation_drill_green',
                'incident_route_confirmed',
                'stakeholder_acceptance_loop_bound',
            ],
            'post_execution_reconciliation' => [
                'bound' => true,
                'required_artifacts' => ['tool_receipts', 'external_result_receipt', 'ledger_update', 'metric_delta', 'incident_or_exception_report', 'operator_closeout'],
                'reconciliation_owner' => 'portfolio_governor',
                'external_result_claim_allowed_without_receipt' => false,
            ],
            'source_contract_hashes' => [
                'handoff_pack_hash' => (string) ($pack['handoff_pack_hash'] ?? ''),
                'production_connector_company_hash' => (string) data_get($pack, 'production_connector_preflight_contract.company_status_hash', ''),
                'flow_quality_company_hash' => (string) data_get($pack, 'flow_quality_research_contract.company_status_hash', ''),
                'flow_package_company_hash' => (string) data_get($pack, 'flow_operating_package_contract.company_status_hash', ''),
                'command_center_company_hash' => (string) data_get($pack, 'company_command_center_contract.company_status_hash', ''),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'credential_material_in_packet_allowed' => false,
        ];
        $packet['packet_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @param array<string,mixed> $packet
     * @return array<string,mixed>
     */
    private function externalWorkerPreflightForPacket(array $packet): array
    {
        $companyId = (string) ($packet['company_id'] ?? 'unknown');
        $flowId = (string) ($packet['flow_id'] ?? 'unknown');
        $checklist = array_values(array_map('strval', (array) ($packet['pre_execution_checklist'] ?? [])));
        $sourceHashes = (array) ($packet['source_contract_hashes'] ?? []);
        $requiredArtifacts = array_values(array_map('strval', (array) data_get($packet, 'post_execution_reconciliation.required_artifacts', [])));
        $blockedOperations = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($packet, 'production_scope.blocked_in_autonomous_suite', []),
        ))));
        $dispatchBlockers = [
            'external_worker_dispatch_disabled_by_policy',
            'operator_signature_receipt_missing',
            'second_reviewer_signature_receipt_missing',
            'real_credential_vault_reference_not_bound_to_dispatch_runtime',
            'legal_risk_acceptance_receipt_missing',
            'budget_or_loss_cap_signature_receipt_missing',
            'external_result_reconciliation_adapter_not_live_executed',
            'operator_closeout_receipt_missing',
        ];

        $ready = (bool) ($packet['packet_ready'] ?? false)
            && count($checklist) >= 10
            && count(array_filter($sourceHashes, static fn (mixed $hash): bool => strlen((string) $hash) >= 32)) >= 5
            && (bool) data_get($packet, 'runtime_control.kill_switch_bound', false)
            && (bool) data_get($packet, 'runtime_control.auto_retry_external_action_allowed', true) === false
            && (bool) data_get($packet, 'post_execution_reconciliation.bound', false)
            && count($requiredArtifacts) >= 6
            && (bool) ($packet['external_execution_allowed'] ?? true) === false
            && (bool) ($packet['external_side_effects_enabled'] ?? true) === false
            && (bool) ($packet['credential_material_in_packet_allowed'] ?? true) === false;

        $preflight = [
            'schema' => 'atlas.ai.company.external_worker_preflight.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'worker_preflight_ready' => $ready,
            'dispatch_mode' => 'prepared_supervised_external_worker_dispatch_disabled',
            'worker_plan' => [
                'bound' => true,
                'worker_family' => 'atlas_external_action_worker',
                'allowed_runtime_mode' => 'dry_run_or_operator_supervised_dispatch_after_real_signatures',
                'tool_use_mode' => 'connector_scoped_with_tool_receipts',
                'blocked_operations' => $blockedOperations,
                'dispatch_blockers' => $dispatchBlockers,
            ],
            'execution_envelope' => [
                'bound' => true,
                'decision_receipt_hash_required' => true,
                'idempotency_key_required' => true,
                'idempotency_key' => hash('sha256', 'external_worker_idempotency|'.$companyId.'|'.$flowId.'|'.(string) ($packet['packet_hash'] ?? '')),
                'run_context_required' => ['company_id', 'flow_id', 'mandate_hash', 'operator_signature_receipt', 'second_reviewer_signature_receipt'],
                'external_side_effects_default' => false,
            ],
            'credential_gate' => [
                'bound' => true,
                'vault_reference_required' => true,
                'credential_material_in_packet_allowed' => false,
                'read_scope_must_match_live_read_connector_readiness' => true,
                'write_or_paid_scope_requires_signed_dispatch_receipt' => true,
            ],
            'worker_controls' => [
                'bound' => true,
                'external_worker_dispatch_enabled' => false,
                'kill_switch_bound' => (bool) data_get($packet, 'runtime_control.kill_switch_bound', false),
                'pause_protocol' => (string) data_get($packet, 'runtime_control.pause_protocol', ''),
                'change_window_required' => (bool) data_get($packet, 'runtime_control.change_window_required', true),
                'auto_retry_external_action_allowed' => false,
                'dlq_replay_supported_for_internal_preparation_only' => (bool) data_get($packet, 'runtime_control.dlq_replay_supported_for_internal_preparation_only', false),
            ],
            'post_execution_reconciliation' => [
                'bound' => (bool) data_get($packet, 'post_execution_reconciliation.bound', false),
                'required_artifacts' => $requiredArtifacts,
                'external_result_claim_allowed_without_receipt' => (bool) data_get($packet, 'post_execution_reconciliation.external_result_claim_allowed_without_receipt', true),
                'operator_closeout_required' => in_array('operator_closeout', $requiredArtifacts, true),
            ],
            'source_contract_hashes' => $sourceHashes,
            'pre_execution_checklist' => $checklist,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $preflight['worker_preflight_hash'] = MissionCanonicalHash::sha256($preflight);

        return $preflight;
    }

    private function activationStage(string $mandateStatus, bool $registered, bool $preflighted, int $approvalCount): string
    {
        if ($mandateStatus === 'rejected_by_operator_gate') {
            return 'blocked_rejected_scope';
        }
        if ($mandateStatus === 'signed_mandate_ready_manual_execution_only') {
            return 'manual_handoff_ready_needs_real_connector_dress_rehearsal';
        }
        if ($approvalCount > 0) {
            return 'awaiting_required_signatures';
        }
        if ($preflighted || $mandateStatus === 'preflight_green_awaiting_signatures') {
            return 'preflight_green_needs_approval_request';
        }
        if ($registered) {
            return 'registered_needs_preflight';
        }

        return 'not_registered';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByKey(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string,mixed>
     */
    private function approvalPayload(AiOperatorApproval $approval): array
    {
        return [
            'uuid' => (string) $approval->uuid,
            'status' => (string) $approval->status,
            'approval_role' => (string) data_get($approval->options, 'approval_role', 'unknown'),
            'requested_action' => (string) $approval->requested_action,
            'risk_level' => (string) $approval->risk_level,
            'operator_decision' => $approval->operator_decision,
            'operator' => $approval->operator,
            'operator_note' => $approval->operator_note,
            'expires_at' => $approval->expires_at?->toJSON(),
            'decided_at' => $approval->decided_at?->toJSON(),
            'receipt_hash' => $approval->receipt_hash,
            'external_execution_allowed_after_approval' => (bool) data_get($approval->options, 'external_execution_allowed_after_approval', false),
            'manual_execution_handoff_only' => (bool) data_get($approval->options, 'manual_execution_handoff_only', true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function activationBacklogPayload(AiHoldingActivationBacklogItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'work_package_id' => (string) $item->work_package_id,
            'status' => (string) $item->status,
            'activation_stage' => (string) $item->activation_stage,
            'priority_score' => (int) $item->priority_score,
            'mandate_packet_hash' => $item->mandate_packet_hash,
            'owner' => (string) $item->owner,
            'deliverable' => (string) $item->deliverable,
            'gap_counts' => (array) $item->gap_counts_json,
            'connector_activation_gaps' => array_values((array) $item->connector_activation_gaps_json),
            'governance_gaps' => array_values((array) $item->governance_gaps_json),
            'operationalization_gaps' => array_values((array) $item->operationalization_gaps_json),
            'evidence_required' => array_values((array) $item->evidence_required_json),
            'evidence_attached' => array_values((array) $item->evidence_attached_json),
            'implementation_attempt_count' => (int) $item->implementation_attempt_count,
            'last_implementation_receipt_hash' => $item->last_implementation_receipt_hash,
            'blocked_reason' => $item->blocked_reason,
            'manual_handoff_ready' => (bool) $item->manual_handoff_ready,
            'external_execution_allowed' => (bool) $item->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $item->external_side_effects_enabled,
            'queued_at' => $item->queued_at?->toJSON(),
            'last_run_at' => $item->last_run_at?->toJSON(),
            'completed_at' => $item->completed_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function connectorActivationPayload(AiHoldingConnectorActivationRecord $record): array
    {
        return [
            'id' => (string) $record->id,
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'connector_id' => (string) $record->connector_id,
            'status' => (string) $record->status,
            'certification_receipt_hash' => $record->certification_receipt_hash,
            'flow_usage_attestation_hash' => $record->flow_usage_attestation_hash,
            'adapter_contract_hash' => $record->adapter_contract_hash,
            'auth_boundary_hash' => $record->auth_boundary_hash,
            'sandbox_probe_hash' => $record->sandbox_probe_hash,
            'slo_hash' => $record->slo_hash,
            'lineage_hash' => $record->lineage_hash,
            'replay_fixture_hash' => $record->replay_fixture_hash,
            'allowed_modes' => array_values((array) $record->allowed_modes_json),
            'blocked_modes' => array_values((array) $record->blocked_modes_json),
            'pre_run_requirements' => array_values((array) $record->pre_run_requirements_json),
            'probe_receipt_count' => count((array) $record->probe_receipts_json),
            'last_probe_receipt_hash' => $record->last_probe_receipt_hash,
            'last_probe_status' => $record->last_probe_status,
            'vault_binding_required' => (bool) $record->vault_binding_required,
            'vault_binding_attested' => (bool) $record->vault_binding_attested,
            'sandbox_probe_green' => (bool) $record->sandbox_probe_green,
            'slo_monitor_bound' => (bool) $record->slo_monitor_bound,
            'reconciliation_bound' => (bool) $record->reconciliation_bound,
            'external_execution_allowed' => (bool) $record->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $record->external_side_effects_enabled,
            'registered_at' => $record->registered_at?->toJSON(),
            'last_probed_at' => $record->last_probed_at?->toJSON(),
            'activated_at' => $record->activated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function liveReadConnectorPayload(AiHoldingConnectorActivationRecord $record): array
    {
        $receipts = array_values((array) $record->probe_receipts_json);
        $lastReceipt = (array) ($receipts[array_key_last($receipts)] ?? []);
        $contract = (array) data_get($lastReceipt, 'live_read_contract', []);
        $ready = (bool) $record->sandbox_probe_green
            && (bool) $record->vault_binding_attested
            && strlen((string) data_get($contract, 'schema_snapshot_hash', '')) === 64
            && strlen((string) data_get($contract, 'sample_payload_hash', '')) === 64
            && strlen((string) data_get($contract, 'provider_lineage_hash', '')) === 64
            && (bool) data_get($contract, 'credential_material_in_receipt', true) === false
            && (bool) data_get($contract, 'external_side_effects_enabled', true) === false;

        return [
            'schema' => 'atlas.ai.holding.connector_live_read_readiness_record.v1',
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'connector_id' => (string) $record->connector_id,
            'live_read_ready' => $ready,
            'connector_activation_status' => (string) $record->status,
            'last_probe_receipt_hash' => (string) $record->last_probe_receipt_hash,
            'schema_snapshot_hash' => (string) data_get($contract, 'schema_snapshot_hash', ''),
            'sample_payload_hash' => (string) data_get($contract, 'sample_payload_hash', ''),
            'provider_lineage_hash' => (string) data_get($contract, 'provider_lineage_hash', ''),
            'vault_scope_reference' => (string) data_get($contract, 'vault_scope_reference', ''),
            'credential_material_in_receipt' => (bool) data_get($contract, 'credential_material_in_receipt', true),
            'allowed_operations' => array_values((array) data_get($contract, 'allowed_operations', [])),
            'blocked_operations' => array_values((array) data_get($contract, 'blocked_operations', [])),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'readiness_hash' => hash('sha256', 'live_read_connector_readiness|'.$record->company_id.'|'.$record->flow_id.'|'.$record->connector_id.'|'.(string) $record->last_probe_receipt_hash),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flowRunQueuePayload(AiHoldingEnterpriseFlowRunQueueItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'status' => (string) $item->status,
            'priority_score' => (int) $item->priority_score,
            'activation_stage' => (string) $item->activation_stage,
            'mandate_packet_hash' => $item->mandate_packet_hash,
            'operating_package_hash' => $item->operating_package_hash,
            'operating_package' => (array) $item->operating_package_json,
            'replay_contract' => (array) $item->replay_contract_json,
            'replay_contract_bound' => data_get((array) $item->replay_contract_json, 'dataset_id') !== null,
            'operating_package_attestation_count' => count((array) $item->operating_package_attestations_json),
            'last_operating_package_attestation' => array_values((array) $item->operating_package_attestations_json) !== []
                ? array_values((array) $item->operating_package_attestations_json)[count((array) $item->operating_package_attestations_json) - 1]
                : null,
            'connector_activation_count' => (int) $item->connector_activation_count,
            'connector_probe_green_count' => (int) $item->connector_probe_green_count,
            'work_package_count' => (int) $item->work_package_count,
            'completed_work_package_count' => (int) $item->completed_work_package_count,
            'blocked_work_package_count' => (int) $item->blocked_work_package_count,
            'attempt_count' => (int) $item->attempt_count,
            'queue_receipt_count' => count((array) $item->queue_receipts_json),
            'execution_receipt_count' => count((array) $item->execution_receipts_json),
            'last_execution_receipt_hash' => $item->last_execution_receipt_hash,
            'dlq_reason' => $item->dlq_reason,
            'external_execution_allowed' => (bool) $item->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $item->external_side_effects_enabled,
            'queued_at' => $item->queued_at?->toJSON(),
            'leased_at' => $item->leased_at?->toJSON(),
            'last_executed_at' => $item->last_executed_at?->toJSON(),
            'completed_at' => $item->completed_at?->toJSON(),
            'dlq_at' => $item->dlq_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flowOperationsRunbookPayload(AiHoldingEnterpriseFlowOperationsRunbook $runbook): array
    {
        return [
            'id' => (string) $runbook->id,
            'company_id' => (string) $runbook->company_id,
            'flow_id' => (string) $runbook->flow_id,
            'status' => (string) $runbook->status,
            'source_queue_item_id' => $runbook->source_queue_item_id,
            'runbook_hash' => (string) $runbook->runbook_hash,
            'operating_package_hash' => $runbook->operating_package_hash,
            'operating_package' => (array) $runbook->operating_package_json,
            'replay_contract' => (array) $runbook->replay_contract_json,
            'replay_contract_bound' => data_get((array) $runbook->replay_contract_json, 'dataset_id') !== null,
            'operating_package_attestation_count' => count((array) $runbook->operating_package_attestations_json),
            'last_operating_package_attestation' => array_values((array) $runbook->operating_package_attestations_json) !== []
                ? array_values((array) $runbook->operating_package_attestations_json)[count((array) $runbook->operating_package_attestations_json) - 1]
                : null,
            'slo_contract' => (array) $runbook->slo_contract_json,
            'incident_route' => (array) $runbook->incident_route_json,
            'reconciliation_contract' => (array) $runbook->reconciliation_contract_json,
            'promotion_gates' => array_values((array) $runbook->promotion_gates_json),
            'dashboard_bindings' => array_values((array) $runbook->dashboard_bindings_json),
            'drill_receipt_count' => count((array) $runbook->drill_receipts_json),
            'last_drill_receipt_hash' => $runbook->last_drill_receipt_hash,
            'slo_green' => (bool) $runbook->slo_green,
            'incident_route_green' => (bool) $runbook->incident_route_green,
            'reconciliation_green' => (bool) $runbook->reconciliation_green,
            'promotion_gate_green' => (bool) $runbook->promotion_gate_green,
            'external_execution_allowed' => (bool) $runbook->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $runbook->external_side_effects_enabled,
            'registered_at' => $runbook->registered_at?->toJSON(),
            'last_drilled_at' => $runbook->last_drilled_at?->toJSON(),
            'activated_at' => $runbook->activated_at?->toJSON(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function preflightChecks(AiHoldingExternalActionMandate $record): array
    {
        $connectorScope = (array) $record->connector_scope_json;
        $blocked = (array) $record->blocked_operations_json;
        $preflightChecks = (array) $record->preflight_checks_json;

        return [
            [
                'id' => 'source_runtime_receipt_present',
                'passed' => strlen((string) $record->source_runtime_receipt_hash) === 64,
            ],
            [
                'id' => 'source_connector_certification_present',
                'passed' => strlen((string) $record->source_connector_certification_hash) === 64,
            ],
            [
                'id' => 'connector_scope_bound',
                'passed' => $connectorScope !== [],
            ],
            [
                'id' => 'dangerous_modes_blocked_until_signature',
                'passed' => count(array_intersect(['write', 'publish', 'spend', 'trade', 'deploy', 'delete'], $blocked)) >= 6,
            ],
            [
                'id' => 'operator_signature_required',
                'passed' => (bool) $record->operator_signature_required,
            ],
            [
                'id' => 'second_reviewer_required',
                'passed' => (bool) $record->second_reviewer_required,
            ],
            [
                'id' => 'auto_execute_disabled',
                'passed' => ! (bool) $record->auto_execute_allowed,
            ],
            [
                'id' => 'external_side_effects_disabled',
                'passed' => ! (bool) $record->external_side_effects_enabled,
            ],
            [
                'id' => 'budget_cap_required',
                'passed' => (bool) data_get($record->cost_budget_envelope_json, 'required', false)
                    && ! (bool) data_get($record->cost_budget_envelope_json, 'spend_without_cap_allowed', true),
            ],
            [
                'id' => 'rollback_and_incident_route_bound',
                'passed' => data_get($record->rollback_or_compensation_json, 'plan_hash') !== null
                    && data_get($record->incident_route_json, 'route_hash') !== null,
            ],
            [
                'id' => 'preflight_contract_complete',
                'passed' => count(array_intersect([
                    'operator_mandate_signature_present',
                    'second_reviewer_signature_present',
                    'connector_scope_matches_certification',
                    'budget_or_loss_cap_bound',
                    'rollback_or_compensation_accepted',
                    'incident_route_bound',
                    'dry_run_receipts_attached',
                ], $preflightChecks)) === 7,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recordPayload(AiHoldingExternalActionMandate $record): array
    {
        return [
            'id' => (string) $record->id,
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'status' => (string) $record->status,
            'mandate_packet_hash' => (string) $record->mandate_packet_hash,
            'source_runtime_receipt_hash' => (string) $record->source_runtime_receipt_hash,
            'source_connector_certification_hash' => (string) $record->source_connector_certification_hash,
            'source_flow_usage_attestation_hash' => (string) $record->source_flow_usage_attestation_hash,
            'connector_scope' => array_values((array) $record->connector_scope_json),
            'blocked_operations' => array_values((array) $record->blocked_operations_json),
            'preflight_checks' => array_values((array) $record->preflight_checks_json),
            'operator_signature_required' => (bool) $record->operator_signature_required,
            'second_reviewer_required' => (bool) $record->second_reviewer_required,
            'auto_execute_allowed' => (bool) $record->auto_execute_allowed,
            'external_side_effects_enabled' => (bool) $record->external_side_effects_enabled,
            'queued_at' => $record->queued_at?->toJSON(),
            'preflighted_at' => $record->preflighted_at?->toJSON(),
        ];
    }
}
