<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiOperatorApproval;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Support\AtlasEnvelope;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class CompanyCockpitSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function controlTower(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $suite = $this->hub->fixtureSuite->externalActionMandateSuite($wantedCompany);
        $expectedPackets = $this->hub->approval->packets($suite, null);
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
                    ? $this->hub->approval->approvalRows($record->mandate_packet_hash)
                    : [];
                $mandateStatus = $record instanceof AiHoldingExternalActionMandate
                    ? $this->hub->approval->approvalStatus($record->mandate_packet_hash)['mandate_status']
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
                    'approvals' => array_map(fn (AiOperatorApproval $approval): array => $this->hub->approval->approvalPayload($approval), $approvals),
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
                'mandate_status_counts' => $this->hub->countsByKey($flowRows, 'mandate_status'),
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

        return AtlasEnvelope::seal([
            'ok' => (bool) ($suite['ok'] ?? false),
            'schema' => ExternalActionMandateRegistryService::CONTROL_TOWER_SCHEMA,
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
        ], 'control_tower_hash');
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
                'activation_stage_counts' => $this->hub->countsByKey($flowRows, 'activation_stage'),
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

        return AtlasEnvelope::seal([
            'ok' => (bool) ($tower['ok'] ?? false),
            'schema' => ExternalActionMandateRegistryService::ACTIVATION_COCKPIT_SCHEMA,
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
        ], 'activation_cockpit_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function premiumActivationStatus(?string $companyId = null): array
    {
        $suite = $this->hub->fixtureSuite->externalActionMandateSuite($companyId);
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

        return AtlasEnvelope::seal([
            'ok' => (bool) ($suite['ok'] ?? false) && $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => ExternalActionMandateRegistryService::PREMIUM_ACTIVATION_STATUS_SCHEMA,
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
        ], 'premium_activation_status_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function providerWorkbenchStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->providerWorkbenchCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));

        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::PROVIDER_WORKBENCH_STATUS_SCHEMA,
            'provider_workbenches_ready_external_execution_blocked',
            'provider_workbenches_attention_required',
            [
                'provider_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_contract_count'], $companyRows)),
                'connector_workbench_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_workbench_count'], $companyRows)),
                'flow_provider_route_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_provider_route_count'], $companyRows)),
                'provider_eval_case_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_eval_case_count'], $companyRows)),
                'provider_lineage_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_lineage_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'provider_write_or_paid_action_default' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
            ],
            'provider_workbench_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function agentRepositoryAdoptionStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->agentRepositoryAdoptionCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));

        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::AGENT_REPOSITORY_ADOPTION_STATUS_SCHEMA,
            'agent_repository_adoption_ready_external_execution_blocked',
            'agent_repository_adoption_attention_required',
            [
                'repository_intake_count' => array_sum(array_map(static fn (array $company): int => (int) $company['repository_intake_count'], $companyRows)),
                'framework_scorecard_count' => array_sum(array_map(static fn (array $company): int => (int) $company['framework_scorecard_count'], $companyRows)),
                'flow_repository_adoption_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_repository_adoption_matrix_count'], $companyRows)),
                'flow_epic_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_epic_count'], $companyRows)),
                'tool_permission_manifest_count' => array_sum(array_map(static fn (array $company): int => (int) $company['tool_permission_manifest_count'], $companyRows)),
                'eval_replay_recipe_count' => array_sum(array_map(static fn (array $company): int => (int) $company['eval_replay_recipe_count'], $companyRows)),
                'version_pin_count' => array_sum(array_map(static fn (array $company): int => (int) $company['version_pin_count'], $companyRows)),
                'migration_path_count' => array_sum(array_map(static fn (array $company): int => (int) $company['migration_path_count'], $companyRows)),
                'license_security_review_count' => array_sum(array_map(static fn (array $company): int => (int) $company['license_security_review_count'], $companyRows)),
                'runtime_boundary_review_count' => array_sum(array_map(static fn (array $company): int => (int) $company['runtime_boundary_review_count'], $companyRows)),
                'operator_acceptance_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['operator_acceptance_contract_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'runtime_use_before_local_contract_tests_allowed' => false,
                'adoption_requires_license_security_sbom_fixture_eval_and_operator_acceptance' => true,
                'operator_mandate_required_for_external_write_or_procurement' => true,
                'blocked_operations' => ['runtime_use_without_tests', 'auto_upgrade', 'auto_procurement', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
            'agent_repository_adoption_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function agentRepositoryOperatingCatalogStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->agentRepositoryOperatingCatalogCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));

        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::AGENT_REPOSITORY_OPERATING_CATALOG_STATUS_SCHEMA,
            'agent_repository_operating_catalog_ready_external_execution_blocked',
            'agent_repository_operating_catalog_attention_required',
            [
                'source_basis_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_basis_count'], $companyRows)),
                'framework_profile_count' => array_sum(array_map(static fn (array $company): int => (int) $company['framework_profile_count'], $companyRows)),
                'framework_runtime_boundary_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['framework_runtime_boundary_contract_count'], $companyRows)),
                'framework_pattern_binding_count' => array_sum(array_map(static fn (array $company): int => (int) $company['framework_pattern_binding_count'], $companyRows)),
                'mcp_security_profile_count' => array_sum(array_map(static fn (array $company): int => (int) $company['mcp_security_profile_count'], $companyRows)),
                'flow_runtime_map_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_runtime_map_count'], $companyRows)),
                'supply_chain_artifact_count' => array_sum(array_map(static fn (array $company): int => (int) $company['supply_chain_artifact_count'], $companyRows)),
                'observability_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_metric_count'], $companyRows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'reference_repositories_are_not_runtime_authority' => true,
                'mcp_reference_servers_require_security_hardening_before_live_use' => true,
                'runtime_use_requires_version_pin_contract_tests_trace_export_and_receipts' => true,
                'operator_mandate_required_for_external_write_or_procurement' => true,
                'blocked_operations' => ['runtime_use_without_version_pin', 'runtime_use_without_contract_tests', 'unhardened_mcp_server_live_use', 'auto_upgrade', 'auto_procurement', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
            'agent_repository_operating_catalog_status_hash',
        );
    }

    public function nextControlTowerAction(string $mandateStatus): string
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
    public function activationCockpitFlow(array $flow): array
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
    public function premiumActivationCompany(string $companyId): array
    {
        $packet = $this->hub->buildout->companyPacket($companyId);
        $model = (array) ($packet['premium_enterprise_agent_reference_model'] ?? []);
        $flows = (array) ($packet['flows'] ?? []);
        $connectors = (array) ($packet['connectors'] ?? []);
        $sources = (array) ($model['reference_source_basis'] ?? []);
        $templates = (array) ($model['managed_agent_templates'] ?? []);
        $architectureBasis = (array) ($model['enterprise_agentic_architecture_basis'] ?? []);
        $templateRuntimeContracts = (array) ($model['template_runtime_contracts'] ?? []);
        $flowTemplateMap = (array) ($model['flow_template_map'] ?? []);
        $managedAgentWorkflows = (array) ($model['flow_managed_agent_workflows'] ?? []);
        $workbenches = (array) ($model['data_and_tool_workbenches'] ?? []);
        $mcpServerPlan = (array) ($model['connector_mcp_server_plan'] ?? []);
        $replayBenchmarks = (array) data_get($model, 'replay_and_audit_harness.benchmarks_per_flow', []);
        $waitDays = (int) data_get($model, 'accelerated_activation_contract.buildout_wait_days_required', 30);

        $flowCount = count($flows);
        $connectorCount = count($connectors);
        $sourceCount = count($sources);
        $templateCount = count($templates);
        $templateRuntimeContractCount = count($templateRuntimeContracts);
        $mapCount = count($flowTemplateMap);
        $managedWorkflowCount = count($managedAgentWorkflows);
        $workbenchCount = count($workbenches);
        $mcpServerPlanCount = count($mcpServerPlan);
        $replayCount = count($replayBenchmarks);

        $checks = [
            'reference_source_basis_green' => $sourceCount >= 5,
            'enterprise_agentic_architecture_basis_green' => count((array) ($architectureBasis['agent_runtime_patterns'] ?? [])) >= 6
                && count((array) ($architectureBasis['required_runtime_properties'] ?? [])) >= 9,
            'managed_agent_templates_green' => $templateCount >= 10,
            'template_runtime_contracts_green' => $templateRuntimeContractCount >= $templateCount && $templateCount > 0,
            'flow_template_map_green' => $mapCount >= $flowCount && $flowCount > 0,
            'flow_managed_agent_workflows_green' => $managedWorkflowCount >= $flowCount && $flowCount > 0,
            'data_and_tool_workbenches_green' => $workbenchCount >= $connectorCount && $connectorCount > 0,
            'connector_mcp_server_plan_green' => $mcpServerPlanCount >= $connectorCount && $connectorCount > 0,
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
                'agentic_runtime_pattern_count' => count((array) ($architectureBasis['agent_runtime_patterns'] ?? [])),
                'required_runtime_property_count' => count((array) ($architectureBasis['required_runtime_properties'] ?? [])),
                'managed_agent_template_count' => $templateCount,
                'template_runtime_contract_count' => $templateRuntimeContractCount,
                'flow_template_map_count' => $mapCount,
                'flow_managed_agent_workflow_count' => $managedWorkflowCount,
                'workbench_count' => $workbenchCount,
                'connector_mcp_server_plan_count' => $mcpServerPlanCount,
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
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function providerWorkbenchCompany(array $company): array
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
    public function agentRepositoryAdoptionCompany(array $company): array
    {
        $stack = (array) data_get($company, 'enterprise_agent_repository_adoption_pipeline', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $intake = (array) data_get($stack, 'repository_intake_queue', []);
        $scorecards = (array) data_get($stack, 'framework_adoption_scorecards', []);
        $adoptionMatrix = (array) data_get($stack, 'flow_repository_adoption_matrix', []);
        $epics = (array) data_get($stack, 'flow_repository_implementation_epics', []);
        $permissionManifests = (array) data_get($stack, 'flow_tool_permission_manifests', []);
        $evalReplayRecipes = (array) data_get($stack, 'flow_eval_replay_recipes', []);
        $versionPins = (array) data_get($stack, 'version_pin_and_supply_chain_plan', []);
        $migrationPaths = (array) data_get($stack, 'migration_and_deprecation_matrix.known_migration_paths', []);
        $metrics = (array) data_get($stack, 'pipeline_observability.required_metrics', []);
        $licenseSecurityReviews = count(array_filter($intake, static fn (array $item): bool => (bool) data_get($item, 'review_contract.license_security_review_required', false)));
        $runtimeBoundaryReviews = count(array_filter($intake, static fn (array $item): bool => (bool) data_get($item, 'review_contract.runtime_boundary_review_required', false)));
        $operatorAcceptanceContracts = count(array_filter($adoptionMatrix, static fn (array $item): bool => (string) ($item['operator_acceptance_contract_ref'] ?? '') !== ''));

        $checks = [
            'repository_intake_green' => count($intake) >= 11,
            'framework_scorecards_green' => count($scorecards) >= 8,
            'flow_repository_adoption_matrix_green' => count($adoptionMatrix) >= $flowCount && $flowCount > 0,
            'flow_epics_green' => count($epics) >= $flowCount && $flowCount > 0,
            'tool_permission_manifests_green' => count($permissionManifests) >= $flowCount && $flowCount > 0,
            'eval_replay_recipes_green' => count($evalReplayRecipes) >= $flowCount && $flowCount > 0,
            'license_security_reviews_green' => $licenseSecurityReviews >= count($intake) && count($intake) > 0,
            'runtime_boundary_reviews_green' => $runtimeBoundaryReviews >= count($intake) && count($intake) > 0,
            'operator_acceptance_contracts_green' => $operatorAcceptanceContracts >= $flowCount && $flowCount > 0,
            'version_pins_green' => count($versionPins) >= count($intake) && count($intake) > 0,
            'migration_matrix_green' => count($migrationPaths) >= 2,
            'observability_green' => count($metrics) >= 8,
            'per_flow_repository_matrix_required' => (bool) data_get($stack, 'pipeline_policy.per_flow_repository_adoption_matrix_required', false),
            'per_flow_tool_permission_manifest_required' => (bool) data_get($stack, 'pipeline_policy.per_flow_tool_permission_manifest_required', false),
            'per_flow_eval_replay_recipe_required' => (bool) data_get($stack, 'pipeline_policy.per_flow_eval_replay_recipe_required', false),
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
            'flow_repository_adoption_matrix_count' => count($adoptionMatrix),
            'flow_epic_count' => count($epics),
            'tool_permission_manifest_count' => count($permissionManifests),
            'eval_replay_recipe_count' => count($evalReplayRecipes),
            'version_pin_count' => count($versionPins),
            'migration_path_count' => count($migrationPaths),
            'metric_count' => count($metrics),
            'license_security_review_count' => $licenseSecurityReviews,
            'runtime_boundary_review_count' => $runtimeBoundaryReviews,
            'operator_acceptance_contract_count' => $operatorAcceptanceContracts,
            'flow_repository_adoption_flow_ids' => array_values(array_map(
                static fn (array $item): string => (string) ($item['flow_id'] ?? 'unknown_flow'),
                $adoptionMatrix,
            )),
            'tool_permission_manifest_ids' => array_values(array_map(
                static fn (array $item): string => (string) ($item['manifest_id'] ?? 'unknown_manifest'),
                $permissionManifests,
            )),
            'eval_replay_recipe_ids' => array_values(array_map(
                static fn (array $item): string => (string) ($item['recipe_id'] ?? 'unknown_recipe'),
                $evalReplayRecipes,
            )),
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
    public function agentRepositoryOperatingCatalogCompany(array $company): array
    {
        $catalog = (array) data_get($company, 'enterprise_agent_repository_operating_catalog', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $sources = (array) data_get($catalog, 'source_basis', []);
        $frameworkProfiles = (array) data_get($catalog, 'framework_operating_profiles', []);
        $mcpProfiles = (array) data_get($catalog, 'mcp_connector_security_profiles', []);
        $flowRuntimeMap = (array) data_get($catalog, 'flow_runtime_adoption_map', []);
        $supplyChainArtifacts = (array) data_get($catalog, 'supply_chain_and_eval_controls.required_artifacts', []);
        $metrics = (array) data_get($catalog, 'operating_catalog_observability.required_metrics', []);
        $runtimeBoundaryContracts = count(array_filter($frameworkProfiles, static fn (array $profile): bool => (bool) data_get($profile, 'runtime_boundary_contract.adapter_required', false)
            && (bool) data_get($profile, 'runtime_boundary_contract.local_contract_tests_required', false)
            && (bool) data_get($profile, 'runtime_boundary_contract.trace_receipt_export_required', false)));
        $patternBindingCount = count(array_filter($frameworkProfiles, static fn (array $profile): bool => count(array_filter((array) ($profile['pattern_bindings'] ?? []))) >= 4));

        $checks = [
            'catalog_schema_green' => (string) ($catalog['schema'] ?? '') === 'atlas.ai.company.enterprise_agent_repository_operating_catalog.v1',
            'source_basis_green' => count($sources) >= 4,
            'framework_profiles_green' => count($frameworkProfiles) >= 8,
            'framework_runtime_boundary_contracts_green' => $runtimeBoundaryContracts >= count($frameworkProfiles) && count($frameworkProfiles) > 0,
            'framework_pattern_bindings_green' => $patternBindingCount >= count($frameworkProfiles) && count($frameworkProfiles) > 0,
            'mcp_connector_security_profiles_green' => count($mcpProfiles) >= $connectorCount && $connectorCount > 0,
            'flow_runtime_map_green' => count($flowRuntimeMap) >= $flowCount && $flowCount > 0,
            'supply_chain_controls_green' => count($supplyChainArtifacts) >= 8,
            'observability_green' => count($metrics) >= 7,
            'calendar_wait_removed' => (bool) data_get($catalog, 'catalog_policy.calendar_wait_blocker_enabled', true) === false,
            'mcp_hardening_required' => (bool) data_get($catalog, 'catalog_policy.mcp_reference_servers_require_security_hardening_before_live_use', false),
            'runtime_requires_receipts_and_contract_tests' => (bool) data_get($catalog, 'catalog_policy.runtime_use_requires_version_pin_contract_tests_trace_export_and_receipts', false),
            'external_writes_blocked' => (bool) data_get($catalog, 'catalog_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.agent_repository_operating_catalog_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'source_basis_count' => count($sources),
            'framework_profile_count' => count($frameworkProfiles),
            'framework_runtime_boundary_contract_count' => $runtimeBoundaryContracts,
            'framework_pattern_binding_count' => $patternBindingCount,
            'mcp_security_profile_count' => count($mcpProfiles),
            'flow_runtime_map_count' => count($flowRuntimeMap),
            'supply_chain_artifact_count' => count($supplyChainArtifacts),
            'observability_metric_count' => count($metrics),
            'framework_ids' => array_values(array_map(
                static fn (array $profile): string => (string) ($profile['framework_id'] ?? 'unknown_framework'),
                $frameworkProfiles,
            )),
            'mcp_connector_ids' => array_values(array_map(
                static fn (array $profile): string => (string) ($profile['connector_id'] ?? 'unknown_connector'),
                $mcpProfiles,
            )),
            'next_actions' => ['pin_versions', 'run_contract_tests', 'harden_mcp_servers', 'bind_trace_and_receipt_export', 'request_operator_scope_before_external_effect'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['agent_repository_operating_catalog_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    public function activationStage(string $mandateStatus, bool $registered, bool $preflighted, int $approvalCount): string
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
}
