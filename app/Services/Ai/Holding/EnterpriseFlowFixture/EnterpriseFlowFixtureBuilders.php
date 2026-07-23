<?php

namespace App\Services\Ai\Holding\EnterpriseFlowFixture;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * run() section builders extracted from EnterpriseFlowFixtureActionRuntimeService (GOD-DEBULK split).
 * Self-contained: each method is a pure transform of its arguments (curated per-vertical business
 * knowledge kept verbatim); no dependency on the facade or persistence layer.
 */
class EnterpriseFlowFixtureBuilders
{
    /**
     * @param  array<string,mixed>  $company
     * @param  array<string,mixed>  $flowSpec
     * @param  array<string,mixed>  $businessExecutionCell
     * @param  array<string,mixed>  $businessKpiBinding
     * @param  array<string,mixed>  $businessServiceLane
     * @param  array<string,mixed>  $businessArtifactContract
     * @param  array<string,mixed>  $deliverySla
     * @param  array<string,mixed>  $flowCostCenter
     * @param  array<string,mixed>  $flowUnitEconomics
     * @param  array<string,mixed>  $capacitySimulation
     * @param  array<string,mixed>  $accountOnboardingPlan
     * @param  array<string,mixed>  $accountServiceReview
     * @param  array<string,mixed>  $customerJourney
     * @return array<string,mixed>
     */
    public function enterpriseBusinessOperatingPacket(
        array $company,
        string $companyId,
        string $flowId,
        string $artifactType,
        array $flowSpec,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $businessArtifactContract,
        array $deliverySla,
        array $flowCostCenter,
        array $flowUnitEconomics,
        array $capacitySimulation,
        array $accountOnboardingPlan,
        array $accountServiceReview,
        array $customerJourney,
    ): array {
        $kpiRefs = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                (array) data_get($businessKpiBinding, 'kpi_refs', []),
                array_slice((array) ($company['metrics'] ?? []), 0, 5),
            ),
        ))));
        $serviceCatalog = array_values((array) data_get($company, 'commercial_operating_stack.service_catalog', []));
        $pricingLadder = array_values((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', []));
        $matchedPricing = $this->pricingForWorkProduct($pricingLadder, $artifactType);

        $packet = [
            'schema' => 'atlas.ai.company.enterprise_business_operating_packet.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'status' => 'business_operating_packet_ready_external_commitments_blocked',
            'business_model' => [
                'service_line' => (string) data_get($serviceCatalog, '0.service_id', $companyId.'_enterprise_service'),
                'artifact_type' => $artifactType,
                'declared_output' => (string) ($flowSpec['output'] ?? $artifactType),
                'delivery_contract_hash' => (string) data_get($businessArtifactContract, 'delivery_contract_hash', ''),
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
            ],
            'kpi_contract' => [
                'kpi_refs' => $kpiRefs,
                'minimum_kpi_count' => 3,
                'acceptance_metric' => 'operator_accepted_work_product_with_source_lineage',
                'external_value_claim_allowed' => false,
            ],
            'delivery_lane' => [
                'service_lane_id' => (string) data_get($businessServiceLane, 'lane_id', ''),
                'queue' => (string) data_get($businessServiceLane, 'queue', $companyId.'.'.$flowId.'.execution_queue'),
                'sla_id' => (string) data_get($deliverySla, 'sla_id', data_get($deliverySla, 'flow_id', '')),
                'sla_class' => (string) data_get($deliverySla, 'sla_class', 'internal_packet_sla'),
                'review_roles' => array_values((array) data_get($businessServiceLane, 'review_roles', [])),
                'customer_visible_delivery_allowed' => false,
            ],
            'economics' => [
                'cost_center_id' => (string) data_get($flowCostCenter, 'cost_center_id', data_get($flowCostCenter, 'cost_center', '')),
                'unit_economics_id' => (string) data_get($flowUnitEconomics, 'unit_id', data_get($flowUnitEconomics, 'flow_id', '')),
                'capacity_simulation_id' => (string) data_get($capacitySimulation, 'simulation_id', ''),
                'pricing_ref' => (string) data_get($matchedPricing, 'pricing_id', data_get($matchedPricing, 'work_product', $artifactType)),
                'margin_or_roi_claim_requires_external_evidence' => true,
                'real_capital_action_allowed' => false,
            ],
            'account_operations' => [
                'customer_journey_id' => (string) data_get($customerJourney, 'journey_id', data_get($customerJourney, 'flow_id', '')),
                'onboarding_plan_id' => (string) data_get($accountOnboardingPlan, 'success_plan_id', data_get($accountOnboardingPlan, 'plan_id', '')),
                'service_review_calendar_id' => (string) data_get($accountServiceReview, 'calendar_id', data_get($accountServiceReview, 'calendar_hash', '')),
                'revenue_collection_allowed' => false,
                'renewal_or_upsell_commitment_allowed' => false,
            ],
            'operating_controls' => [
                'source_lineage_required' => true,
                'tool_receipt_required' => true,
                'operator_acceptance_required' => true,
                'second_review_required_for_external_commitment' => true,
                'rollback_or_compensation_plan_required' => true,
                'blocked_operations' => ['external_write', 'paid_spend', 'live_trade', 'public_publish', 'customer_commitment', 'vendor_procurement', 'secret_export'],
            ],
            'readiness' => [
                'business_execution_cell_bound' => $businessExecutionCell !== [],
                'kpi_contract_bound' => count($kpiRefs) >= 3,
                'delivery_lane_bound' => $businessServiceLane !== [] && $deliverySla !== [],
                'economics_bound' => $flowCostCenter !== [] && $flowUnitEconomics !== [] && $capacitySimulation !== [],
                'account_operations_bound' => $customerJourney !== [] && $accountOnboardingPlan !== [] && $accountServiceReview !== [],
                'external_side_effects_enabled' => false,
            ],
            'external_side_effects' => false,
        ];
        $packet['business_operating_packet_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @param  list<array<string,mixed>>  $pricingLadder
     * @return array<string,mixed>
     */
    private function pricingForWorkProduct(array $pricingLadder, string $artifactType): array
    {
        foreach ($pricingLadder as $pricing) {
            if (($pricing['work_product'] ?? null) === $artifactType) {
                return (array) $pricing;
            }
        }

        return (array) ($pricingLadder[0] ?? []);
    }

    /**
     * @param  array<string,mixed>  $company
     * @param  array<string,mixed>  $flowSpec
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $operatingPackage
     * @param  array<string,mixed>  $domainSolutionPlaybook
     * @param  array<string,mixed>  $businessExecutionCell
     * @param  array<string,mixed>  $businessKpiBinding
     * @param  array<string,mixed>  $businessServiceLane
     * @param  array<string,mixed>  $deliverySla
     * @param  array<string,mixed>  $flowUnitEconomics
     * @param  array<string,mixed>  $semanticFlowEdge
     * @param  list<mixed>  $runtimePhases
     * @return array<string,mixed>
     */
    public function enterpriseFlowOperationalDossier(
        array $company,
        string $companyId,
        string $flowId,
        array $flowSpec,
        array $contract,
        array $operatingPackage,
        array $domainSolutionPlaybook,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $deliverySla,
        array $flowUnitEconomics,
        array $semanticFlowEdge,
        string $artifactType,
        array $runtimePhases,
    ): array {
        $sourceRefs = array_values(array_map('strval', (array) data_get($domainSolutionPlaybook, 'source_pack.source_refs', [])));
        $connectorRefs = array_values(array_map('strval', (array) data_get($domainSolutionPlaybook, 'tooling_contract.connector_refs', [])));
        $evidenceSpine = [
            'flow_spec:'.(string) ($flowSpec['flow_id'] ?? $flowId),
            'action_contract:'.(string) ($contract['action'] ?? $flowId),
            'operating_package:'.(string) ($operatingPackage['package_hash'] ?? ''),
            'domain_solution_playbook:'.(string) ($domainSolutionPlaybook['playbook_hash'] ?? ''),
            'business_execution_cell:'.(string) ($businessExecutionCell['cell_hash'] ?? ''),
            'business_kpi_binding:'.(string) ($businessKpiBinding['binding_hash'] ?? ''),
            'business_service_lane:'.(string) ($businessServiceLane['lane_hash'] ?? ''),
            'delivery_sla:'.(string) ($deliverySla['sla_hash'] ?? ''),
            'unit_economics:'.(string) ($flowUnitEconomics['unit_economics_hash'] ?? ''),
            'semantic_flow_edge:'.(string) ($semanticFlowEdge['edge_hash'] ?? ''),
        ];

        foreach ($sourceRefs as $sourceRef) {
            $evidenceSpine[] = 'domain_source:'.$sourceRef;
        }
        foreach ($connectorRefs as $connectorRef) {
            $evidenceSpine[] = 'connector_scope:'.$connectorRef;
        }

        $dossier = [
            'schema' => 'atlas.ai.company.enterprise_flow_operational_dossier.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'status' => 'internal_operational_dossier_ready_external_blocked',
            'owner_agent' => (string) data_get($operatingPackage, 'owner_agent', (string) ($flowSpec['owner_agent'] ?? 'company_operator_agent')),
            'source_pack' => [
                'source_refs' => $sourceRefs,
                'direct_hyperlinks_required' => (bool) data_get($domainSolutionPlaybook, 'source_pack.direct_hyperlinks_required', false),
                'claim_traceability_required' => (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.claim_traceability_required', false),
                'cross_source_verification_required' => (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.cross_source_verification_required', false),
            ],
            'execution_blueprint' => [
                'runtime_phases' => array_values(array_map('strval', $runtimePhases)),
                'execution_nodes' => array_values(array_map('strval', (array) data_get($domainSolutionPlaybook, 'execution_path.nodes', []))),
                'durable_state_required' => (bool) data_get($domainSolutionPlaybook, 'execution_path.durable_state_required', false),
                'checkpoint_after_every_node' => (bool) data_get($operatingPackage, 'runtime_cell.checkpoint_after_every_node', true),
                'idempotency_required' => true,
            ],
            'control_plane' => [
                'required_controls' => [
                    'policy_gate_before_external_action',
                    'operator_checkpoint_required',
                    'second_reviewer_for_external_action',
                    'source_lineage_required',
                    'tool_receipt_required',
                    'rollback_or_compensation_plan_required',
                    'budget_and_scope_bound_before_external_use',
                    'redaction_review_for_sensitive_data',
                ],
                'review_queue' => (string) data_get($operatingPackage, 'operating_cell.review_queue', $companyId.'_operator_review_queue'),
                'policy_findings_allowed' => 0,
                'external_mutation_allowed' => false,
                'external_delivery_allowed' => false,
            ],
            'business_binding' => [
                'execution_cell_id' => (string) ($businessExecutionCell['cell_id'] ?? ''),
                'service_lane_id' => (string) ($businessServiceLane['lane_id'] ?? ''),
                'kpi_refs' => array_values((array) data_get($businessKpiBinding, 'kpi_refs', [])),
                'sla_id' => (string) ($deliverySla['sla_id'] ?? $deliverySla['flow_id'] ?? ''),
                'unit_economics_mode' => (string) data_get($flowUnitEconomics, 'mode', 'internal_unit_economics_proxy'),
            ],
            'evidence_spine' => array_values(array_unique(array_filter($evidenceSpine))),
            'decision_packet' => [
                'decision_use' => 'promote_flow_to_shadow_or_supervised_internal_operation',
                'operator_checkpoint_required' => true,
                'required_before_external_promotion' => ['signed_scope', 'credential_probe_green', 'budget_limit', 'rollback_plan', 'second_review'],
                'external_delivery_allowed' => false,
                'real_world_autonomy_claim_allowed' => false,
            ],
            'promotion_path' => [
                ['stage' => 'contract_bound', 'required_evidence' => ['operating_package', 'domain_solution_playbook', 'policy_profile'], 'external_side_effects' => false],
                ['stage' => 'fixture_green', 'required_evidence' => ['canonical_fixture', 'assertion_suite', 'failure_injection'], 'external_side_effects' => false],
                ['stage' => 'shadow_ready', 'required_evidence' => ['replay_dataset', 'tool_receipts', 'critic_review'], 'external_side_effects' => false],
                ['stage' => 'supervised_internal', 'required_evidence' => ['operator_checkpoint', 'runbook_drill', 'incident_route'], 'external_side_effects' => false],
            ],
            'readiness_scorecard' => [
                'source_pack_score' => count($sourceRefs) > 0 ? 1.0 : 0.0,
                'execution_blueprint_score' => count($runtimePhases) > 0 ? 1.0 : 0.0,
                'control_plane_score' => 1.0,
                'business_binding_score' => $businessExecutionCell !== [] && $businessKpiBinding !== [] && $businessServiceLane !== [] ? 1.0 : 0.0,
                'operational_readiness_score' => 1.0,
            ],
            'external_side_effects' => false,
        ];
        $dossier['dossier_hash'] = MissionCanonicalHash::sha256($dossier);

        return $dossier;
    }

    /**
     * @param  array<string,mixed>  $flowSpec
     * @param  array<string,mixed>  $operatingPackage
     * @param  list<mixed>  $runtimePhases
     * @param  list<array<string,mixed>>  $connectorWorkbenches
     * @return array<string,mixed>
     */
    public function managedAgentExecution(
        string $companyId,
        string $flowId,
        array $flowSpec,
        array $operatingPackage,
        array $runtimePhases,
        array $connectorWorkbenches,
        array $flowToolkitAssignment,
        array $agentRepositoryEpic,
        array $stateSchema,
        array $eventPlan,
        array $checkpoint,
    ): array {
        $ownerAgent = (string) ($operatingPackage['owner_agent'] ?? $flowSpec['owner_agent'] ?? 'company_operator_agent');
        $supportAgents = array_values(array_map('strval', (array) data_get($operatingPackage, 'operating_cell.support_agents', [])));
        $nodes = array_values((array) data_get($operatingPackage, 'runtime_cell.runtime_nodes', $runtimePhases));
        $toolReceipts = array_values(array_map(
            static fn (array $workbench): array => [
                'connector_id' => (string) ($workbench['connector_id'] ?? 'unknown'),
                'workbench_id' => (string) ($workbench['workbench_id'] ?? 'unknown'),
                'access_mode' => (string) ($workbench['access_mode'] ?? 'internal_fixture'),
                'receipt_hash' => hash('sha256', 'tool_receipt|'.$companyId.'|'.$flowId.'|'.(string) ($workbench['connector_id'] ?? 'unknown')),
                'external_side_effects' => false,
            ],
            $connectorWorkbenches,
        ));
        $subagentRoster = array_values(array_map(
            static fn (string $agent, int $index): array => [
                'agent_id' => $agent,
                'role' => $index === 0 ? 'lead_domain_executor' : 'specialist_subagent',
                'responsibility' => match ($index % 5) {
                    0 => 'domain_execution_and_final_packet_assembly',
                    1 => 'source_lineage_and_connector_context',
                    2 => 'methodology_or_quality_critic',
                    3 => 'policy_risk_and_external_action_guardrail',
                    default => 'handoff_reconciliation_and_learning_update',
                },
                'can_call_external_tool' => false,
                'requires_trace_event' => true,
            ],
            array_values(array_unique(array_merge([$ownerAgent], $supportAgents))),
            array_keys(array_values(array_unique(array_merge([$ownerAgent], $supportAgents)))),
        ));
        $connectorExecutionPlane = array_values(array_map(
            static fn (array $workbench): array => [
                'connector_id' => (string) ($workbench['connector_id'] ?? 'unknown'),
                'workbench_id' => (string) ($workbench['workbench_id'] ?? 'unknown'),
                'mode' => (string) ($workbench['access_mode'] ?? 'internal_fixture'),
                'allowed_operations' => ['read_fixture', 'read_only_probe', 'export_receipt'],
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export'],
                'receipt_required' => true,
                'external_side_effects_enabled' => false,
            ],
            $connectorWorkbenches,
        ));
        $requiredEvents = array_values(array_map('strval', (array) ($eventPlan['required_events'] ?? [])));
        $stateObjects = array_values(array_map('strval', (array) ($stateSchema['state_objects'] ?? $nodes)));
        $requiredSkills = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                (array) data_get($flowToolkitAssignment, 'required_skill_sequence', []),
                ['source_lineage', 'tool_receipt_export', 'critic_review', 'policy_gate', 'operator_handoff'],
            ),
        ))));

        $packet = [
            'schema' => 'atlas.ai.company.enterprise_managed_agent_execution.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'owner_agent' => $ownerAgent,
            'support_agents' => $supportAgents,
            'agent_operating_system' => [
                'schema' => 'atlas.ai.company.enterprise_agent_operating_system_packet.v1',
                'pattern_refs' => [
                    'skills_connectors_subagents',
                    'tools_handoffs_guardrails_tracing_sessions',
                    'durable_state_human_interrupts',
                    'role_task_flow_orchestration',
                ],
                'primary_framework_ref' => (string) ($agentRepositoryEpic['primary_framework_ref'] ?? 'atlas_native_agent_runtime'),
                'assigned_toolkit' => (string) ($flowToolkitAssignment['assigned_toolkit'] ?? 'atlas_enterprise_toolkit'),
                'skill_pack' => [
                    'required_skills' => $requiredSkills,
                    'minimum_skill_count' => 5,
                    'skill_inputs_are_schema_bound' => true,
                    'skill_outputs_require_receipt_hash' => true,
                ],
                'subagent_roster' => $subagentRoster,
                'connector_execution_plane' => $connectorExecutionPlane,
                'session_memory' => [
                    'durable_state_required' => (bool) ($stateSchema['state_hash_required'] ?? true),
                    'state_objects' => $stateObjects,
                    'resume_token_required' => true,
                    'idempotency_key_required' => true,
                    'raw_secret_or_sensitive_payload_memory_allowed' => false,
                ],
                'guardrail_stack' => [
                    'input_schema_validation' => true,
                    'source_lineage_required' => true,
                    'policy_gate_before_tool_use' => in_array('policy_checked', $requiredEvents, true),
                    'external_side_effect_guardrail' => true,
                    'output_claim_review' => true,
                    'human_checkpoint_required_for_external_action' => (bool) ($checkpoint['auto_approval_allowed'] ?? true) === false,
                ],
                'handoff_graph' => [
                    'required_events' => $requiredEvents,
                    'handoff_targets' => array_values(array_map('strval', (array) ($flowSpec['handoffs'] ?? []))),
                    'handoff_packet_requires_source_lineage' => true,
                    'handoff_packet_requires_tool_receipts' => true,
                    'external_handoff_is_packet_only' => true,
                ],
                'evaluation_harness' => [
                    'fixture_replay_required' => true,
                    'adversarial_cases_required' => true,
                    'policy_findings_allowed' => 0,
                    'minimum_source_faithfulness_score' => 0.95,
                    'minimum_domain_correctness_score' => 0.9,
                    'promotion_without_green_eval_allowed' => false,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
            'runtime_nodes_completed' => $nodes,
            'checkpoint_after_every_node' => (bool) data_get($operatingPackage, 'runtime_cell.checkpoint_after_every_node', true),
            'idempotency_key' => hash('sha256', 'idempotency|'.$companyId.'|'.$flowId),
            'state_hash' => hash('sha256', 'state|'.$companyId.'|'.$flowId.'|'.implode('|', array_map('strval', $nodes))),
            'tool_receipts' => $toolReceipts,
            'policy_events' => ['policy_checked', 'external_side_effects_blocked', 'operator_checkpoint_required'],
            'handoff_events' => array_values((array) data_get($flowSpec, 'handoffs', [])),
            'external_side_effects' => false,
        ];
        $packet['agent_operating_system']['agent_os_hash'] = MissionCanonicalHash::sha256($packet['agent_operating_system']);

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $company
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $operatingPackage
     * @param  array<string,mixed>  $operationalDossier
     * @param  array<string,mixed>  $promotionEvidence
     * @param  array<string,mixed>  $flowConnectorCutover
     * @param  array<string,mixed>  $externalResearchFlowMatrix
     * @param  array<string,mixed>  $businessExecutionCell
     * @param  array<string,mixed>  $businessKpiBinding
     * @param  array<string,mixed>  $flowUnitEconomics
     * @param  array<string,mixed>  $deliverySla
     * @return array<string,mixed>
     */
    public function enterpriseAutonomyPromotionPacket(
        array $company,
        string $companyId,
        string $flowId,
        array $contract,
        array $operatingPackage,
        array $operationalDossier,
        array $promotionEvidence,
        array $flowConnectorCutover,
        array $externalResearchFlowMatrix,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $flowUnitEconomics,
        array $deliverySla,
    ): array {
        $promotionGates = (array) data_get($operatingPackage, 'promotion_gates', []);
        $connectorScope = array_values(array_map('strval', (array) ($flowConnectorCutover['connector_scope'] ?? [])));
        $kpiRefs = array_values(array_map('strval', (array) ($businessKpiBinding['kpi_refs'] ?? [])));
        $runtimePhases = array_values(array_map('strval', (array) ($contract['runtime_phases'] ?? [])));
        $handlerContract = $contract['handler_contract'] ?? '';
        $handlerContractRef = is_array($handlerContract)
            ? MissionCanonicalHash::sha256($handlerContract)
            : (string) $handlerContract;

        $evidenceSpine = array_values(array_filter([
            'flow_contract:'.(string) ($contract['flow_id'] ?? $flowId),
            'handler_contract:'.$handlerContractRef,
            'operating_package:'.(string) ($operatingPackage['package_id'] ?? ''),
            'operational_dossier:'.(string) ($operationalDossier['dossier_hash'] ?? ''),
            'decision_packet:'.(string) data_get($operationalDossier, 'decision_packet.decision_id', 'operator_review_required'),
            'promotion_path:'.count((array) ($operationalDossier['promotion_path'] ?? [])),
            'replay_dataset:'.(string) data_get($operatingPackage, 'quality_replay_cell.dataset_id', ''),
            'minimum_shadow_cases:'.(string) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', '0'),
            'external_research_matrix:'.(string) ($externalResearchFlowMatrix['matrix_hash'] ?? ''),
            'business_execution_cell:'.(string) ($businessExecutionCell['cell_id'] ?? ''),
            'kpi_binding:'.(string) ($businessKpiBinding['binding_hash'] ?? ''),
            'unit_economics:'.(string) ($flowUnitEconomics['unit_economics_hash'] ?? $flowUnitEconomics['unit_id'] ?? ''),
            'delivery_sla:'.(string) ($deliverySla['sla_id'] ?? $deliverySla['flow_id'] ?? ''),
            'connector_cutover:'.(string) ($flowConnectorCutover['cutover_hash'] ?? $flowConnectorCutover['flow_id'] ?? ''),
            'rollback_drill:'.(string) ($promotionEvidence['rollback_drill_ref'] ?? 'rollback_drill_required'),
            'acceptance_packet:operator_and_domain_owner_required',
        ]));

        $limitedAutonomyBlockers = [
            'operator_and_second_reviewer_signed_mandate_missing',
            'credential_vault_binding_per_connector_not_attached_to_runtime',
            'external_worker_dispatch_disabled',
            'budget_cap_signature_missing',
            'loss_cap_signature_missing',
            'legal_or_risk_scope_acceptance_missing',
            'post_execution_reconciliation_adapter_not_proven_live',
            'customer_or_counterparty_acceptance_loop_not_live',
            'production_incident_owner_not_signed_for_autonomous_mode',
        ];

        $packet = [
            'schema' => 'atlas.ai.company.enterprise_autonomy_promotion_packet.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'current_allowed_level' => 'A3_supervised_external_packet_ready',
            'next_promotion_target' => 'A4_limited_external_autonomy_after_signed_scope_live_credentials_worker_and_reconciliation',
            'autonomy_ladder' => [
                [
                    'level' => 'A0_fixture',
                    'description' => 'schema_bound_fixture_and_failure_cases_only',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A1_internal_shadow',
                    'description' => 'internal_replay_shadow_with_realistic_artifacts_and_no_external_mutation',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A2_supervised_internal',
                    'description' => 'operator_reviewed_internal_run_with_receipts_rollbacks_and_kpi_proxy',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A3_supervised_external_packet',
                    'description' => 'external_execution_packet_ready_for_manual_or_supervised_handoff',
                    'external_side_effects_allowed' => false,
                ],
                [
                    'level' => 'A4_limited_external_autonomy',
                    'description' => 'narrow_external_autonomy_after_signed_scope_live_credentials_budget_loss_caps_and_reconciliation',
                    'external_side_effects_allowed' => false,
                    'blocked_until' => $limitedAutonomyBlockers,
                ],
            ],
            'stage_readiness' => [
                'fixture_internal' => [
                    'ready' => true,
                    'required_evidence' => ['canonical_fixture', 'expected_trace', 'assertion_suite', 'failure_injection'],
                    'external_side_effects' => false,
                ],
                'shadow_internal' => [
                    'ready' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0) >= 25,
                    'required_evidence' => array_values((array) ($promotionGates['shadow_ready'] ?? [])),
                    'external_side_effects' => false,
                ],
                'supervised_internal' => [
                    'ready' => (bool) data_get($operationalDossier, 'decision_packet.operator_checkpoint_required', false)
                        && (bool) data_get($operationalDossier, 'decision_packet.external_delivery_allowed', true) === false
                        && count((array) ($operationalDossier['promotion_path'] ?? [])) >= 4,
                    'required_evidence' => array_values((array) ($promotionGates['supervised_ready'] ?? [])),
                    'runtime_phases' => $runtimePhases,
                    'external_side_effects' => false,
                ],
                'supervised_external_packet' => [
                    'ready' => $flowConnectorCutover !== []
                        && count($connectorScope) > 0
                        && (bool) ($flowConnectorCutover['auto_execute_allowed'] ?? true) === false
                        && (bool) ($flowConnectorCutover['external_side_effects_enabled'] ?? true) === false,
                    'connector_scope' => $connectorScope,
                    'required_evidence' => [
                        'production_connector_preflight_contract',
                        'vault_scope_attestation_placeholder',
                        'sandbox_probe_receipt',
                        'operator_acceptance_packet',
                        'rollback_drill',
                        'manual_handoff_owner',
                    ],
                    'external_side_effects' => false,
                ],
                'limited_external_autonomy' => [
                    'ready' => false,
                    'blockers' => $limitedAutonomyBlockers,
                    'external_side_effects' => false,
                ],
            ],
            'promotion_evidence_spine' => $evidenceSpine,
            'autonomy_budget_and_loss_cap' => [
                'kpi_refs' => $kpiRefs,
                'unit_economics_id' => (string) ($flowUnitEconomics['unit_id'] ?? $flowUnitEconomics['flow_id'] ?? ''),
                'budget_cap_signature_required' => true,
                'loss_cap_signature_required' => true,
                'real_capital_action_allowed_without_signature' => false,
                'trade_spend_publish_deploy_delete_allowed_without_signature' => false,
            ],
            'rollback_and_reconciliation' => [
                'rollback_drill_required' => true,
                'rollback_drill_ref' => (string) ($promotionEvidence['rollback_drill_ref'] ?? 'rollback_drill_required'),
                'post_execution_reconciliation_required' => true,
                'compensation_plan_required_for_external_action' => true,
                'incident_owner_required_before_autonomous_mode' => true,
            ],
            'promotion_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'external_autonomous_execution_allowed' => false,
                'operator_mandate_required_for_external_autonomy' => true,
                'second_reviewer_required_for_external_autonomy' => true,
                'live_credentials_in_packet_allowed' => false,
                'autonomy_claim_without_external_evidence_allowed' => false,
                'offensive_security_or_unbounded_financial_action_allowed' => false,
            ],
        ];
        $packet['autonomy_promotion_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $company
     * @param  array<string,mixed>  $flowSpec
     * @param  array<string,mixed>  $operatingPackage
     * @param  list<mixed>  $requiredSections
     * @return array<string,mixed>
     */
    public function enterpriseArtifact(
        array $company,
        string $companyId,
        string $flowId,
        string $artifactType,
        array $requiredSections,
        array $flowSpec,
        array $operatingPackage,
        array $verticalSolutionKit,
        array $verticalConnectorWorkbenches,
        array $artifactFactory,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $businessArtifactContract,
    ): array {
        $packageId = (string) data_get($operatingPackage, 'package_id', '');
        $packageHash = (string) data_get($operatingPackage, 'package_hash', '');
        $verticalKitId = (string) data_get($verticalSolutionKit, 'kit_id', '');
        $verticalKitHash = (string) data_get($verticalSolutionKit, 'kit_hash', '');
        $artifactFactoryId = (string) data_get($artifactFactory, 'factory_id', '');
        $artifactFactoryHash = (string) data_get($artifactFactory, 'factory_hash', '');
        $businessExecutionCellId = (string) data_get($businessExecutionCell, 'cell_id', '');
        $businessExecutionCellHash = (string) data_get($businessExecutionCell, 'cell_hash', '');
        $businessServiceLaneId = (string) data_get($businessServiceLane, 'lane_id', '');
        $businessServiceLaneHash = (string) data_get($businessServiceLane, 'lane_hash', '');
        $businessArtifactContractHash = (string) data_get($businessArtifactContract, 'delivery_contract_hash', '');
        $ownerAgent = (string) ($operatingPackage['owner_agent'] ?? $flowSpec['owner_agent'] ?? 'company_operator_agent');
        $connectorWorkbenches = array_values((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', []));
        $connectorIds = array_values(array_map(
            static fn (array $workbench): string => (string) ($workbench['connector_id'] ?? 'unknown'),
            $connectorWorkbenches,
        ));
        $datasetId = (string) data_get($operatingPackage, 'quality_replay_cell.dataset_id', '');
        $minimumScores = (array) data_get($operatingPackage, 'quality_replay_cell.minimum_scores', []);
        $promotionGates = (array) data_get($operatingPackage, 'promotion_gates', []);
        $incidentRoute = array_values((array) data_get($operatingPackage, 'operations_cell.incident_route', []));
        $requiredSections = array_values(array_map('strval', $requiredSections));
        $domainExecutionBrief = $this->domainExecutionBrief(
            $company,
            $companyId,
            $flowId,
            $artifactType,
            $flowSpec,
            $verticalSolutionKit,
            $verticalConnectorWorkbenches,
        );

        $sourceLineage = [
            'flow_spec_ref' => (string) ($flowSpec['flow_id'] ?? $flowId),
            'operating_package_id' => $packageId,
            'operating_package_hash' => $packageHash,
            'vertical_solution_kit_id' => $verticalKitId,
            'vertical_solution_kit_hash' => $verticalKitHash,
            'vertical_suite_refs' => array_values((array) data_get($verticalSolutionKit, 'suite_refs', [])),
            'vertical_artifact_factory_id' => $artifactFactoryId,
            'vertical_artifact_factory_hash' => $artifactFactoryHash,
            'business_execution_cell_id' => $businessExecutionCellId,
            'business_execution_cell_hash' => $businessExecutionCellHash,
            'business_execution_mode' => (string) data_get($businessExecutionCell, 'execution_mode', ''),
            'business_kpi_refs' => array_values((array) data_get($businessKpiBinding, 'kpi_refs', [])),
            'business_kpi_binding_hash' => (string) data_get($businessKpiBinding, 'binding_hash', ''),
            'business_service_lane_id' => $businessServiceLaneId,
            'business_service_lane_hash' => $businessServiceLaneHash,
            'business_artifact_delivery_contract_hash' => $businessArtifactContractHash,
            'owner_agent' => $ownerAgent,
            'support_agents' => array_values((array) data_get($operatingPackage, 'operating_cell.support_agents', [])),
            'dataset_id' => $datasetId,
            'connector_ids' => $connectorIds,
            'vertical_connector_workbench_ids' => array_values(array_map(
                static fn (array $workbench): string => (string) ($workbench['workbench_id'] ?? 'unknown_workbench'),
                $verticalConnectorWorkbenches,
            )),
            'tool_receipt_refs' => array_values(array_map(
                static fn (string $connectorId): string => 'tool_receipt:'.$connectorId,
                $connectorIds,
            )),
            'data_boundary' => (string) data_get(
                $operatingPackage,
                'tool_and_data_cell.data_boundary',
                'company_scoped_redacted_context_with_source_lineage',
            ),
        ];

        $qualitySignals = [
            'schema_bound' => true,
            'required_sections_present' => true,
            'source_lineage_present' => true,
            'tool_receipt_coverage' => count($connectorIds) > 0 ? 1.0 : 0.0,
            'vertical_solution_kit_present' => $verticalSolutionKit !== [],
            'artifact_factory_present' => $artifactFactory !== [],
            'vertical_connector_workbench_coverage' => count($connectorIds) > 0 ? round(count($verticalConnectorWorkbenches) / count($connectorIds), 4) : 0.0,
            'business_execution_cell_present' => $businessExecutionCell !== [],
            'business_kpi_binding_present' => $businessKpiBinding !== [],
            'business_service_lane_present' => $businessServiceLane !== [],
            'business_artifact_contract_present' => $businessArtifactContract !== [],
            'domain_execution_brief_present' => true,
            'minimum_offline_eval_score' => (float) ($minimumScores['offline_eval'] ?? 0.9),
            'minimum_source_faithfulness_score' => (float) ($minimumScores['source_faithfulness'] ?? 0.94),
            'policy_compliance_floor' => (float) ($minimumScores['policy_compliance'] ?? 1.0),
            'external_side_effects' => false,
        ];

        $riskRegister = [
            [
                'risk_id' => $companyId.'.'.$flowId.'.source_drift',
                'severity' => 'medium',
                'control' => 'source_lineage_and_replay_dataset_must_be_refreshed_before_external_use',
                'owner' => $ownerAgent,
            ],
            [
                'risk_id' => $companyId.'.'.$flowId.'.external_mutation',
                'severity' => 'high',
                'control' => 'operator_signed_mandate_required_before_write_publish_spend_trade_deploy_delete_or_security_action',
                'owner' => 'policy_gate_agent',
            ],
            [
                'risk_id' => $companyId.'.'.$flowId.'.connector_scope_expansion',
                'severity' => 'medium',
                'control' => 'connector_scope_must_match_certified_workbench_and_emit_tool_receipt',
                'owner' => 'operations_coordinator_agent',
            ],
        ];

        $decisionPacket = [
            'decision_use' => 'operator_review_shadow_candidate_or_internal_supervised_run',
            'recommended_mode' => 'internal_shadow_or_supervised_after_operator_review',
            'external_delivery_allowed' => false,
            'requires_operator_checkpoint' => true,
            'rollback_or_manual_fallback_required' => (bool) data_get(
                $operatingPackage,
                'operations_cell.rollback_or_compensation_plan_required',
                true,
            ),
            'incident_route' => $incidentRoute,
        ];
        $operationalOutcomeLedger = $this->operationalOutcomeLedger(
            $companyId,
            $flowId,
            $artifactType,
            $businessExecutionCell,
            $businessKpiBinding,
            $businessServiceLane,
            $businessArtifactContract,
            $domainExecutionBrief,
        );

        $nextActions = [
            [
                'action_id' => 'operator_review',
                'owner' => 'operator',
                'evidence_required' => ['artifact_sections_present', 'source_lineage_present', 'risk_register_reviewed'],
                'external_side_effects' => false,
            ],
            [
                'action_id' => 'shadow_run',
                'owner' => $ownerAgent,
                'evidence_required' => array_values((array) ($promotionGates['shadow_ready'] ?? [])),
                'external_side_effects' => false,
            ],
            [
                'action_id' => 'supervised_internal_run',
                'owner' => 'portfolio_governor',
                'evidence_required' => array_values((array) ($promotionGates['supervised_ready'] ?? [])),
                'external_side_effects' => false,
            ],
        ];

        $sections = [];
        foreach ($requiredSections as $section) {
            $sectionId = (string) $section;
            $sectionTitle = ucwords(str_replace('_', ' ', $sectionId));
            $sections[$sectionId] = [
                'title' => $sectionTitle,
                'status' => 'present',
                'content' => $this->artifactSectionContent(
                    $sectionId,
                    $companyId,
                    $flowId,
                    $artifactType,
                    $ownerAgent,
                    $connectorIds,
                    $datasetId,
                    $promotionGates,
                ),
                'source_refs' => [
                    (string) ($flowSpec['flow_id'] ?? $flowId),
                    $packageId,
                ],
                'source_lineage' => $sourceLineage,
                'evidence_refs' => [
                    'operating_package:'.$packageId,
                    'vertical_solution_kit:'.$verticalKitId,
                    'vertical_artifact_factory:'.$artifactFactoryId,
                    'business_execution_cell:'.$businessExecutionCellId,
                    'business_service_lane:'.$businessServiceLaneId,
                    'business_artifact_contract:'.(string) data_get($businessArtifactContract, 'work_product', ''),
                    'replay_dataset:'.$datasetId,
                    'flow_spec:'.$flowId,
                    'domain_execution_brief:'.(string) $domainExecutionBrief['brief_hash'],
                ],
                'risk_notes' => array_values(array_map(
                    static fn (array $risk): string => (string) ($risk['risk_id'] ?? ''),
                    $riskRegister,
                )),
                'decision_use' => (string) $decisionPacket['decision_use'],
                'quality_signals' => $qualitySignals,
                'section_hash' => hash('sha256', 'artifact_section|'.$companyId.'|'.$flowId.'|'.$sectionId.'|'.$packageHash),
            ];
        }

        $artifact = [
            'schema' => 'atlas.ai.company.enterprise_flow_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'status' => 'draft_ready_for_operator_review',
            'required_section_count' => count($requiredSections),
            'sections' => $sections,
            'source_lineage' => $sourceLineage,
            'domain_execution_brief' => $domainExecutionBrief,
            'operational_outcome_ledger' => $operationalOutcomeLedger,
            'quality_signals' => $qualitySignals,
            'risk_register' => $riskRegister,
            'decision_packet' => $decisionPacket,
            'operator_review_packet' => [
                'schema' => 'atlas.ai.company.enterprise_flow_operator_review_packet.v1',
                'review_queue' => (string) data_get($operatingPackage, 'operating_cell.review_queue', $companyId.'_operator_review_queue'),
                'required_review_fields' => ['decision', 'risk_acceptance', 'source_lineage_check', 'rollback_plan', 'receipt_hash'],
                'second_reviewer_required_for_external_action' => true,
                'auto_approval_allowed' => false,
                'external_delivery_allowed' => false,
            ],
            'next_actions' => $nextActions,
            'recommendation' => 'operator_review_then_shadow_or_supervised_internal_run_with_no_external_side_effects',
            'external_delivery_allowed' => false,
        ];
        $artifact['artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @param  array<string,mixed>  $businessExecutionCell
     * @param  array<string,mixed>  $businessKpiBinding
     * @param  array<string,mixed>  $businessServiceLane
     * @param  array<string,mixed>  $businessArtifactContract
     * @param  array<string,mixed>  $domainExecutionBrief
     * @return array<string,mixed>
     */
    private function operationalOutcomeLedger(
        string $companyId,
        string $flowId,
        string $artifactType,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $businessArtifactContract,
        array $domainExecutionBrief,
    ): array {
        $kpis = array_values(array_map('strval', (array) data_get($businessKpiBinding, 'kpi_refs', [])));
        $ledger = [
            'schema' => 'atlas.ai.company.enterprise_operational_outcome_ledger.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'status' => 'internal_outcome_measured_external_claim_blocked',
            'execution_cell_id' => (string) data_get($businessExecutionCell, 'cell_id', ''),
            'service_lane_id' => (string) data_get($businessServiceLane, 'lane_id', ''),
            'artifact_contract_hash' => (string) data_get($businessArtifactContract, 'delivery_contract_hash', ''),
            'measured_kpis' => array_values(array_map(
                static fn (string $kpi, int $index): array => [
                    'kpi_id' => $kpi,
                    'measurement_mode' => 'internal_runtime_proxy_until_external_evidence',
                    'baseline' => 0.0,
                    'observed' => 1.0,
                    'target' => 1.0,
                    'confidence' => round(0.9 + min($index, 4) * 0.01, 2),
                    'external_evidence_required_for_real_world_claim' => true,
                ],
                $kpis,
                array_keys($kpis),
            )),
            'value_proxy' => [
                'mode' => 'operator_accepted_work_product_proxy',
                'value_unit' => 'internal_operational_readiness_and_accepted_artifact_proxy',
                'proxy_score' => 1.0,
                'accepted_work_product_present' => true,
                'decision_packet_present' => true,
                'source_lineage_present' => true,
                'policy_gate_green' => true,
                'external_value_claim_allowed' => false,
            ],
            'outcome_acceptance_contract' => [
                'acceptance_status' => 'internal_acceptance_packet_ready_requires_operator_for_external_claim',
                'acceptance_metric' => 'operator_accepted_work_product_with_source_lineage',
                'required_evidence' => ['accepted_work_product', 'decision_packet', 'source_lineage', 'policy_gate', 'risk_review'],
                'operator_acceptance_required_for_external_claim' => true,
                'auto_accept_allowed' => false,
            ],
            'risk_adjusted_scorecard' => [
                'risk_status' => 'controlled_internal_proxy_external_claim_blocked',
                'risk_score' => 0.1,
                'delivery_risk_review_required' => true,
                'policy_exception_count' => 0,
                'external_commitment_risk_blocked' => true,
            ],
            'next_cycle' => [
                'cycle_state' => 'ready_for_operator_review_shadow_or_supervised_internal_run',
                'promotion_candidate_mode' => 'internal_shadow_or_supervised_only',
                'next_actions' => ['operator_review', 'shadow_run', 'supervised_internal_run'],
                'blocked_external_actions' => ['publish', 'spend', 'trade', 'deploy', 'delete', 'external_customer_commitment', 'external_revenue_claim'],
            ],
            'acceptance_evidence' => [
                'domain_execution_brief_hash' => (string) ($domainExecutionBrief['brief_hash'] ?? ''),
                'business_cell_hash' => (string) data_get($businessExecutionCell, 'cell_hash', ''),
                'kpi_binding_hash' => (string) data_get($businessKpiBinding, 'binding_hash', ''),
                'service_lane_hash' => (string) data_get($businessServiceLane, 'lane_hash', ''),
            ],
            'evidence_refs' => [
                'domain_execution_brief:'.(string) ($domainExecutionBrief['brief_hash'] ?? ''),
                'business_cell:'.(string) data_get($businessExecutionCell, 'cell_hash', ''),
                'kpi_binding:'.(string) data_get($businessKpiBinding, 'binding_hash', ''),
                'service_lane:'.(string) data_get($businessServiceLane, 'lane_hash', ''),
                'artifact_contract:'.(string) data_get($businessArtifactContract, 'delivery_contract_hash', ''),
            ],
            'external_side_effects' => false,
        ];
        $ledger['outcome_ledger_hash'] = MissionCanonicalHash::sha256($ledger);

        return $ledger;
    }

    /**
     * @param  array<string,mixed>  $company
     * @param  array<string,mixed>  $flowSpec
     * @param  array<string,mixed>  $verticalSolutionKit
     * @param  list<array<string,mixed>>  $verticalConnectorWorkbenches
     * @return array<string,mixed>
     */
    private function domainExecutionBrief(
        array $company,
        string $companyId,
        string $flowId,
        string $artifactType,
        array $flowSpec,
        array $verticalSolutionKit,
        array $verticalConnectorWorkbenches,
    ): array {
        $domainPlay = $this->domainPlaybookForCompany($companyId);
        $metrics = array_values(array_map('strval', (array) ($company['metrics'] ?? [])));
        $workProducts = array_values(array_map('strval', (array) ($company['work_products'] ?? [])));
        $connectorIds = array_values(array_map(
            static fn (array $workbench): string => (string) ($workbench['connector_id'] ?? 'unknown_connector'),
            $verticalConnectorWorkbenches,
        ));

        $brief = [
            'schema' => 'atlas.ai.company.enterprise_domain_execution_brief.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'artifact_type' => $artifactType,
            'enterprise_play' => (string) $domainPlay['enterprise_play'],
            'domain_decision_lenses' => array_values((array) $domainPlay['decision_lenses']),
            'specialized_workflow' => array_values((array) $domainPlay['workflow']),
            'required_domain_checks' => array_values((array) $domainPlay['checks']),
            'operator_questions' => array_values((array) $domainPlay['operator_questions']),
            'flow_specific_outputs' => [
                'declared_output' => (string) ($flowSpec['output'] ?? $artifactType),
                'vertical_work_product' => (string) data_get($verticalSolutionKit, 'work_product', $artifactType),
                'suite_refs' => array_values((array) data_get($verticalSolutionKit, 'suite_refs', [])),
                'connector_workbenches' => $connectorIds,
                'primary_metrics' => array_slice($metrics, 0, 5),
                'adjacent_work_products' => array_slice($workProducts, 0, 5),
            ],
            'acceptance_model' => [
                'must_be_source_lineaged' => true,
                'must_bind_vertical_solution_kit' => true,
                'must_bind_artifact_factory' => true,
                'must_emit_tool_receipts' => true,
                'must_pass_domain_specific_checks' => true,
                'external_side_effects_allowed' => false,
            ],
            'handoff_contract' => [
                'audience' => 'operator_or_company_command_center',
                'decision_mode' => 'internal_review_shadow_or_supervised_execution',
                'external_action_requires_signed_scope_budget_rollback_and_second_review' => true,
            ],
        ];
        $brief['brief_hash'] = MissionCanonicalHash::sha256($brief);

        return $brief;
    }

    /**
     * @return array{enterprise_play:string,decision_lenses:list<string>,workflow:list<string>,checks:list<string>,operator_questions:list<string>}
     */
    private function domainPlaybookForCompany(string $companyId): array
    {
        return match ($companyId) {
            'software' => [
                'enterprise_play' => 'repo_grounded_delivery_with_patch_review_repair_and_release_evidence',
                'decision_lenses' => ['architecture_fit', 'dependency_blast_radius', 'test_signal', 'security_regression', 'rollback_path'],
                'workflow' => ['load_repo_context', 'map_symbols_and_tests', 'plan_patch', 'apply_minimal_change', 'run_targeted_tests', 'prepare_release_packet'],
                'checks' => ['owner_boundary_respected', 'tests_bound_to_change', 'security_review_complete', 'no_unrelated_refactor', 'release_receipt_ready'],
                'operator_questions' => ['Is this patch inside the requested scope?', 'Which tests prove the behavior?', 'What rollback path exists?'],
            ],
            'research' => [
                'enterprise_play' => 'primary_source_research_with_claim_attribution_contradiction_review_and_update_cadence',
                'decision_lenses' => ['source_authority', 'recency', 'contradiction', 'methodology_quality', 'decision_relevance'],
                'workflow' => ['define_claims', 'collect_primary_sources', 'rank_source_quality', 'map_contradictions', 'synthesize_findings', 'export_citation_packet'],
                'checks' => ['primary_sources_present', 'quotes_within_policy', 'contradictions_disclosed', 'staleness_flagged', 'citation_graph_bound'],
                'operator_questions' => ['Which claims are directly sourced?', 'What changed recently?', 'Where is evidence weak?'],
            ],
            'strategy' => [
                'enterprise_play' => 'venture_strategy_operating_room_with_market_map_experiment_allocator_and_board_dossier',
                'decision_lenses' => ['market_timing', 'competitive_moat', 'distribution_edge', 'capital_efficiency', 'option_value'],
                'workflow' => ['frame_thesis', 'map_market_segments', 'score_rivals', 'design_experiments', 'rank_capital_options', 'draft_board_decision'],
                'checks' => ['assumptions_explicit', 'experiment_success_metric_bound', 'rival_response_modeled', 'capital_commitment_blocked', 'board_packet_ready'],
                'operator_questions' => ['Which assumption kills the thesis?', 'What is the cheapest learning loop?', 'What decision is needed now?'],
            ],
            'finance' => [
                'enterprise_play' => 'financial_services_analyst_terminal_with_research_modeling_compliance_and_investment_committee_controls',
                'decision_lenses' => ['filing_evidence', 'valuation_sensitivity', 'portfolio_exposure', 'compliance_obligation', 'trade_execution_block'],
                'workflow' => ['bind_market_and_filing_sources', 'normalize_kpis', 'model_assumptions_and_scenarios', 'review_risk_and_compliance', 'draft_ic_memo', 'block_trade_until_signed_mandate'],
                'checks' => ['filing_or_market_source_attached', 'assumptions_auditable', 'sensitivity_table_present', 'compliance_gap_reviewed', 'no_live_trade_or_advice_execution'],
                'operator_questions' => ['Which source changed the thesis?', 'What assumptions drive valuation?', 'What approval is required before any execution?'],
            ],
            'marketing' => [
                'enterprise_play' => 'growth_operating_system_with_icp_positioning_campaign_factory_and_experiment_measurement',
                'decision_lenses' => ['icp_fit', 'message_evidence', 'channel_economics', 'brand_risk', 'conversion_learning'],
                'workflow' => ['define_icp', 'map_funnel_baseline', 'draft_positioning', 'produce_campaign_variants', 'plan_experiment', 'prepare_send_or_publish_approval'],
                'checks' => ['claims_supported', 'brand_voice_reviewed', 'audience_scope_clear', 'paid_spend_blocked', 'external_send_or_publish_blocked'],
                'operator_questions' => ['Who is the exact audience?', 'Which proof supports the claim?', 'What metric decides the experiment?'],
            ],
            'cyber' => [
                'enterprise_play' => 'authorized_security_operations_with_scope_roe_evidence_and_remediation_program_controls',
                'decision_lenses' => ['authorized_scope', 'asset_criticality', 'exploitability', 'business_impact', 'remediation_sla'],
                'workflow' => ['validate_scope_and_roe', 'collect_read_only_evidence', 'triage_findings', 'map_controls', 'draft_remediation_plan', 'block_offensive_action_without_mandate'],
                'checks' => ['rules_of_engagement_present', 'no_unauthorized_mutation', 'severity_rationale_bound', 'evidence_reproducible', 'remediation_owner_assigned'],
                'operator_questions' => ['Is the target explicitly authorized?', 'What evidence proves severity?', 'Who owns remediation?'],
            ],
            'automation' => [
                'enterprise_play' => 'automation_factory_with_tool_discovery_connector_probe_replay_and_safe_rollout',
                'decision_lenses' => ['roi', 'reliability', 'permission_scope', 'replayability', 'rollbackability'],
                'workflow' => ['identify_workflow', 'select_tool_or_connector', 'build_dry_run_fixture', 'probe_read_only', 'simulate_failure', 'prepare_rollout_packet'],
                'checks' => ['dry_run_available', 'idempotency_defined', 'secret_scope_bound', 'rollback_plan_present', 'external_write_blocked'],
                'operator_questions' => ['What manual step is eliminated?', 'How is failure detected?', 'Can it be rolled back safely?'],
            ],
            'personal_development' => [
                'enterprise_play' => 'private_learning_and_focus_operating_system_with_memory_boundaries_and_review_cadence',
                'decision_lenses' => ['goal_alignment', 'energy_cost', 'privacy_boundary', 'practice_feedback', 'habit_friction'],
                'workflow' => ['review_goals', 'map_constraints', 'design_practice_loop', 'schedule_review', 'capture_reflection', 'protect_private_memory'],
                'checks' => ['sensitive_data_not_externalized', 'goal_metric_defined', 'review_cadence_bound', 'habit_protocol_clear', 'operator_acceptance_required'],
                'operator_questions' => ['What outcome matters this week?', 'What friction blocks consistency?', 'What should remain private?'],
            ],
            default => [
                'enterprise_play' => 'operations_control_tower_with_slo_runbooks_incident_review_and_capacity_planning',
                'decision_lenses' => ['slo_risk', 'incident_frequency', 'capacity', 'runbook_quality', 'handoff_latency'],
                'workflow' => ['ingest_operational_signal', 'classify_priority', 'run_diagnostic_playbook', 'assign_owner', 'prepare_reconciliation', 'update_learning_loop'],
                'checks' => ['slo_bound', 'runbook_present', 'owner_assigned', 'rollback_or_compensation_defined', 'post_run_reconciliation_required'],
                'operator_questions' => ['What SLO is at risk?', 'What owner can act?', 'What learning updates the runbook?'],
            ],
        };
    }

    /**
     * @param  list<string>  $connectorIds
     * @param  array<string,mixed>  $promotionGates
     */
    private function artifactSectionContent(
        string $sectionId,
        string $companyId,
        string $flowId,
        string $artifactType,
        string $ownerAgent,
        array $connectorIds,
        string $datasetId,
        array $promotionGates,
    ): string {
        $connectorList = $connectorIds === [] ? 'no_external_connector' : implode(',', $connectorIds);

        return match ($sectionId) {
            'executive_summary' => $companyId.'.'.$flowId.' produced '.$artifactType.' for operator review using '.$ownerAgent.' with external side effects blocked.',
            'source_lineage' => 'Sources are bound to replay dataset '.$datasetId.', connector workbenches '.$connectorList.', and company operating package receipts.',
            'analysis' => 'Analysis follows the registered enterprise flow contract, connector scope, replay rubric, policy gate, critic review, and delivery schema.',
            'risks', 'risk_review' => 'Primary risks are source drift, connector permission expansion, and any attempted external mutation before a signed operator mandate.',
            'recommendation' => 'Proceed to operator review and internal shadow or supervised execution only after required evidence remains green.',
            'next_actions' => 'Next gates: shadow='.implode(',', (array) ($promotionGates['shadow_ready'] ?? [])).'; supervised='.implode(',', (array) ($promotionGates['supervised_ready'] ?? [])).'.',
            'receipt_hash' => 'Receipt hash is produced at runtime after artifact, replay, quality gate, and policy packets are assembled.',
            default => $sectionId.' section is present and bound to '.$companyId.'.'.$flowId.' enterprise operating evidence.',
        };
    }
}
