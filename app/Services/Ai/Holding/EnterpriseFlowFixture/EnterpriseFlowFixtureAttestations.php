<?php

namespace App\Services\Ai\Holding\EnterpriseFlowFixture;

/**
 * Per-flow runtime attestation payload slices extracted from
 * EnterpriseFlowFixtureActionRuntimeService::run() (GOD-DEBULK split).
 * Each method is a pure transform of its arguments (curated per-vertical business
 * knowledge kept verbatim); no dependency on the facade or persistence layer.
 */
class EnterpriseFlowFixtureAttestations
{
    /**
     * @return array<string,mixed>
     */
    public function companySystemModelRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $businessProcess,
        array $deliverableQualityContract,
        array $sloSli,
        int $connectorCount,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.company_system_model_runtime_attestation.v1',
            'domain_data_model_bound' => (string) data_get($company, 'domain_data_model.data_model_hash', '') !== ''
                && count((array) data_get($company, 'domain_data_model.entities', [])) >= 4
                && count(array_filter(
                    (array) data_get($company, 'domain_data_model.entities', []),
                    static fn (array $entity): bool => (string) ($entity['primary_key'] ?? '') !== ''
                        && count((array) ($entity['required_fields'] ?? [])) >= 5,
                )) === count((array) data_get($company, 'domain_data_model.entities', [])),
            'data_lineage_bound' => (bool) data_get($company, 'domain_data_model.retention_and_lineage.source_refs_required', false)
                && (bool) data_get($company, 'domain_data_model.retention_and_lineage.state_hash_required', false)
                && count((array) data_get($company, 'domain_data_model.retention_and_lineage.lineage_links', [])) >= 4,
            'business_process_bound' => $businessProcess !== []
                && count((array) data_get($businessProcess, 'swimlanes', [])) >= 4
                && count((array) data_get($businessProcess, 'states', [])) >= 8
                && count((array) data_get($businessProcess, 'controls', [])) >= 5
                && count((array) data_get($businessProcess, 'outputs', [])) >= 3,
            'deliverable_quality_contract_bound' => $deliverableQualityContract !== []
                && count((array) data_get($deliverableQualityContract, 'required_sections', [])) >= 7
                && count((array) data_get($deliverableQualityContract, 'acceptance_criteria', [])) >= 5
                && count((array) data_get($deliverableQualityContract, 'rejection_criteria', [])) >= 4
                && (float) data_get($deliverableQualityContract, 'quality_score_floor', 0.0) >= 0.86,
            'production_pack_bound' => (string) data_get($company, 'go_to_production_pack.production_readiness_hash', '') !== ''
                && count((array) data_get($company, 'go_to_production_pack.environment_model.stages', [])) >= 4
                && count((array) data_get($company, 'go_to_production_pack.environment_model.promotion_requires', [])) >= 4
                && (bool) data_get($company, 'go_to_production_pack.environment_model.external_side_effects_default', true) === false,
            'production_observability_bound' => count((array) data_get($company, 'go_to_production_pack.observability.required_signals', [])) >= 7
                && count((array) data_get($company, 'go_to_production_pack.observability.dashboards', [])) >= 1
                && count((array) data_get($company, 'go_to_production_pack.observability.alert_routes', [])) >= 3,
            'slo_sli_bound' => $sloSli !== []
                && (float) data_get($sloSli, 'target.routine_success_rate', data_get($sloSli, 'targets.routine_success_rate', 0.0)) >= 0.95
                && (float) data_get($sloSli, 'target.minimum_quality_score', data_get($sloSli, 'targets.minimum_quality_score', 0.0)) >= 0.86
                && (int) data_get($sloSli, 'target.policy_findings_allowed', data_get($sloSli, 'targets.policy_findings_allowed', 1)) === 0,
            'incident_response_bound' => count((array) data_get($company, 'go_to_production_pack.incident_response.severity_levels', [])) >= 4
                && count((array) data_get($company, 'go_to_production_pack.incident_response.escalation_chain', [])) >= 3
                && count((array) data_get($company, 'go_to_production_pack.incident_response.required_artifacts', [])) >= 5,
            'capacity_plan_bound' => count((array) data_get($company, 'go_to_production_pack.capacity_plan.named_agents', [])) >= 4
                && (int) data_get($company, 'go_to_production_pack.capacity_plan.parallel_flow_limit', 0) >= 2
                && (bool) data_get($company, 'go_to_production_pack.capacity_plan.human_checkpoint_capacity_required', false),
            'integration_enablement_bound' => count((array) data_get($company, 'go_to_production_pack.integration_enablement_plan', [])) >= $connectorCount
                && count(array_filter(
                    (array) data_get($company, 'go_to_production_pack.integration_enablement_plan', []),
                    static fn (array $plan): bool => (string) ($plan['status'] ?? '') === 'contract_ready'
                        && count((array) (($plan['activation_steps'] ?? null) ?: ($plan['enablement_steps'] ?? []))) >= 5
                        && (bool) ($plan['external_side_effects_enabled'] ?? true) === false,
                )) === count((array) data_get($company, 'go_to_production_pack.integration_enablement_plan', [])),
            'commercial_stack_bound' => (string) data_get($company, 'commercial_operating_stack.commercial_hash', '') !== ''
                && count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])) >= 5
                && count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])) >= 4,
            'commercial_intake_bound' => count((array) data_get($company, 'commercial_operating_stack.work_intake_model.required_intake_fields', [])) >= 6
                && (bool) data_get($company, 'commercial_operating_stack.work_intake_model.reject_when_missing_policy_profile', false),
            'commercial_fulfillment_bound' => count((array) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.stages', [])) >= 7
                && (bool) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.quality_gate_before_delivery', false)
                && (bool) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.handoff_packet_required_for_cross_company_delivery', data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.handoff_packet_required', false))
                && (bool) data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.receipt_required_for_delivery', data_get($company, 'commercial_operating_stack.fulfillment_lifecycle.receipt_required', false)),
            'commercial_external_billing_allowed' => (bool) data_get($company, 'commercial_operating_stack.pricing_and_cost_model.external_billing_enabled', true),
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'company_system_model_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'domain_data_model.data_model_hash', '').'|'.(string) ($businessProcess['process_hash'] ?? '').'|'.(string) ($deliverableQualityContract['contract_hash'] ?? '').'|'.(string) data_get($company, 'go_to_production_pack.production_readiness_hash', '').'|'.(string) data_get($company, 'commercial_operating_stack.commercial_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function internalOperationsBackboneRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $accountOnboardingPlan,
        array $accountServiceReview,
        array $vendorProcurementRouting,
        int $connectorCount,
        array $resilienceFailureMode,
        array $resilienceExercise,
        array $analyticsDecisionRegister,
        array $scenarioForecast,
        array $knowledgeLearningLoop,
        array $playbookChangeControl,
        array $identityDataBoundary,
        array $purposeConsent,
        array $controlTowerLane,
        array $incidentExceptionDesk,
        array $changeWindowRelease,
        array $deliverySla,
        array $grcEvidence,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.internal_operations_backbone_runtime_attestation.v1',
            'account_contract_delivery_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== ''
                && $accountOnboardingPlan !== []
                && count((array) data_get($accountOnboardingPlan, 'milestones', [])) >= 5
                && count((array) data_get($accountOnboardingPlan, 'required_artifacts', [])) >= 5
                && $accountServiceReview !== []
                && count((array) data_get($accountServiceReview, 'required_review_sections', [])) >= 5
                && (bool) data_get($accountServiceReview, 'renewal_action_allowed', true) === false,
            'vendor_legal_procurement_bound' => (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== ''
                && $vendorProcurementRouting !== []
                && count((array) data_get($vendorProcurementRouting, 'procurement_review_required_when', [])) >= 5
                && count((array) data_get($vendorProcurementRouting, 'blocked_actions', [])) >= 4
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= $connectorCount
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= $connectorCount,
            'resilience_continuity_bound' => (string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '') !== ''
                && $resilienceFailureMode !== []
                && count((array) data_get($resilienceFailureMode, 'failure_modes', [])) >= 5
                && count((array) data_get($resilienceFailureMode, 'recovery_evidence_required', [])) >= 4
                && $resilienceExercise !== []
                && count((array) data_get($resilienceExercise, 'success_criteria', [])) >= 3
                && (bool) data_get($company, 'enterprise_resilience_continuity_stack.resilience_policy.external_side_effects_during_incident_allowed', true) === false,
            'analytics_decision_intelligence_bound' => (string) data_get($company, 'enterprise_analytics_decision_intelligence_stack.analytics_hash', '') !== ''
                && $analyticsDecisionRegister !== []
                && count((array) data_get($analyticsDecisionRegister, 'required_decision_fields', [])) >= 6
                && (bool) data_get($analyticsDecisionRegister, 'action_register_required', false)
                && $scenarioForecast !== []
                && count((array) data_get($scenarioForecast, 'scenario_set', [])) >= 4
                && (bool) data_get($company, 'enterprise_analytics_decision_intelligence_stack.decision_intelligence_policy.external_action_from_dashboard_allowed', true) === false,
            'knowledge_memory_learning_bound' => (string) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_memory_hash', '') !== ''
                && $knowledgeLearningLoop !== []
                && count((array) data_get($knowledgeLearningLoop, 'learning_inputs', [])) >= 5
                && count((array) data_get($knowledgeLearningLoop, 'learning_outputs', [])) >= 4
                && $playbookChangeControl !== []
                && count((array) data_get($playbookChangeControl, 'required_diff_sections', [])) >= 6
                && (bool) data_get($playbookChangeControl, 'auto_apply_allowed', true) === false
                && (bool) data_get($company, 'enterprise_knowledge_memory_learning_stack.memory_governance_policy.automatic_canonical_doc_rewrite_allowed', true) === false,
            'identity_access_sovereignty_bound' => (string) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_hash', '') !== ''
                && $identityDataBoundary !== []
                && count((array) data_get($identityDataBoundary, 'egress_controls', [])) >= 4
                && count((array) data_get($identityDataBoundary, 'blocked_data_classes_without_operator', [])) >= 2
                && $purposeConsent !== []
                && count((array) data_get($purposeConsent, 'consent_or_authority_required_when', [])) >= 4
                && (bool) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.identity_access_policy.privilege_escalation_auto_allowed', true) === false,
            'control_tower_run_operations_bound' => (string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '') !== ''
                && $controlTowerLane !== []
                && count((array) data_get($controlTowerLane, 'intake_states', [])) >= 7
                && count((array) data_get($controlTowerLane, 'required_run_artifacts', [])) >= 6
                && $incidentExceptionDesk !== []
                && count((array) data_get($incidentExceptionDesk, 'exception_types', [])) >= 6
                && $changeWindowRelease !== []
                && (bool) data_get($changeWindowRelease, 'rollback_required', false)
                && (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true) === false,
            'delivery_assurance_bound' => (string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '') !== ''
                && $deliverySla !== []
                && (float) data_get($deliverySla, 'quality_target.minimum_score', 0.0) >= 0.86
                && (int) data_get($deliverySla, 'quality_target.policy_findings_allowed', 1) === 0
                && (bool) data_get($deliverySla, 'quality_target.receipt_required', false)
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_side_effects_default', true) === false,
            'grc_control_evidence_bound' => (string) data_get($company, 'enterprise_grc_stack.grc_hash', '') !== ''
                && $grcEvidence !== []
                && count((array) data_get($grcEvidence, 'required_evidence', [])) >= 6
                && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false)
                && (bool) data_get($company, 'enterprise_grc_stack.privacy_and_security.security_review_required_for_new_connector', false),
            'external_customer_vendor_memory_identity_delivery_actions_blocked' => true,
            'external_side_effects_enabled' => false,
            'operator_mandate_required_for_external_action' => true,
            'attestation_hash' => hash('sha256', 'internal_operations_backbone_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '').'|'.(string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '').'|'.(string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '').'|'.(string) data_get($company, 'enterprise_analytics_decision_intelligence_stack.analytics_hash', '').'|'.(string) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_memory_hash', '').'|'.(string) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_hash', '').'|'.(string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '').'|'.(string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '').'|'.(string) data_get($company, 'enterprise_grc_stack.grc_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function activationRunOperationsRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $sourceActivationTracks,
        array $connectorActivationTracks,
        int $connectorCount,
        array $flowActivationMatrix,
        array $controlTowerLane,
        int $flowConnectorCount,
        array $rehearsalLiveReadProbes,
        array $rehearsalRunbook,
        array $operatorAcceptancePacket,
        array $rollbackDrill,
        array $promotionEvidence,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.activation_run_operations_runtime_attestation.v1',
            'integration_activation_plan_bound' => (string) data_get($company, 'enterprise_integration_activation_plan.activation_hash', '') !== ''
                || (string) data_get($company, 'enterprise_integration_activation_plan.schema', '') === 'atlas.ai.company.enterprise_integration_activation_plan.v1',
            'activation_policy_bound' => (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.buildout_blocked_by_observed_history_window', true) === false
                && (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.external_write_blocked_until_operator_mandate', false)
                && (bool) data_get($company, 'enterprise_integration_activation_plan.activation_policy.kill_switch_required', false)
                && count((array) data_get($company, 'enterprise_integration_activation_plan.activation_policy.promotion_sequence', [])) >= 4,
            'source_activation_tracks_bound' => count($sourceActivationTracks) >= 5
                && count(array_filter($sourceActivationTracks, static fn (array $track): bool => count((array) ($track['activation_steps'] ?? [])) >= 6
                    && count((array) ($track['required_evidence'] ?? [])) >= 5
                    && (bool) ($track['external_side_effects_enabled'] ?? true) === false)) === count($sourceActivationTracks),
            'connector_activation_tracks_bound' => count($connectorActivationTracks) >= $connectorCount
                && count(array_filter($connectorActivationTracks, static fn (array $track): bool => count((array) ($track['activation_steps'] ?? [])) >= 6
                    && (bool) data_get($track, 'health_check_contract.must_return_receipt', false)
                    && (bool) data_get($track, 'health_check_contract.must_not_mutate_external_state', false)
                    && count((array) ($track['shadow_mode_ready_when'] ?? [])) >= 4
                    && (bool) ($track['external_side_effects_enabled'] ?? true) === false)) === count($connectorActivationTracks),
            'flow_activation_matrix_bound' => $flowActivationMatrix !== []
                && count((array) data_get($flowActivationMatrix, 'required_activation_evidence', [])) >= 6
                && count((array) data_get($flowActivationMatrix, 'shadow_mode_entry_criteria', [])) >= 3
                && count((array) data_get($flowActivationMatrix, 'supervised_production_entry_criteria', [])) >= 3,
            'run_queue_model_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.priority_classes', [])) >= 5
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.admission_controls', [])) >= 5
                && (string) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.dead_letter_queue', '') !== '',
            'flow_operations_lane_bound' => $controlTowerLane !== []
                && count((array) data_get($controlTowerLane, 'required_run_artifacts', [])) >= 6
                && count((array) data_get($controlTowerLane, 'blocked_until', [])) >= 4,
            'connector_operations_probe_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= $connectorCount
                && count(array_filter(
                    (array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', []),
                    static fn (array $probe): bool => count((array) ($probe['health_checks'] ?? [])) >= 5
                        && (bool) ($probe['auto_remediation_allowed'] ?? true) === false,
                )) === count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])),
            'live_read_probe_plan_bound' => $flowConnectorCount > 0
                && count($rehearsalLiveReadProbes) >= $flowConnectorCount
                && count(array_filter($rehearsalLiveReadProbes, static fn (array $probe): bool => count((array) ($probe['required_before_supervised_production'] ?? [])) >= 6
                    && (bool) ($probe['mutation_allowed'] ?? true) === false)) === count($rehearsalLiveReadProbes),
            'rehearsal_promotion_evidence_bound' => $rehearsalRunbook !== []
                && $operatorAcceptancePacket !== []
                && $rollbackDrill !== []
                && $promotionEvidence !== []
                && count((array) data_get($promotionEvidence, 'must_have_green', [])) >= 6
                && (int) data_get($promotionEvidence, 'calendar_wait_days_required', 30) === 0
                && (bool) data_get($promotionEvidence, 'external_side_effects_enabled', true) === false,
            'observability_bound' => count((array) data_get($company, 'enterprise_integration_activation_plan.activation_observability.required_signals', [])) >= 6
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_observability.required_metrics', [])) >= 6
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])) >= 7,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'calendar_wait_blocker_enabled' => false,
            'operator_mandate_required_for_external_action' => true,
            'attestation_hash' => hash('sha256', 'activation_run_operations_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($flowActivationMatrix['matrix_hash'] ?? '').'|'.(string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '').'|'.(string) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function flowExecutionFoundationRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $flowRunbook,
        array $connectorBackplane,
        int $connectorCount,
        array $executableFlowPacket,
        array $agentToolRouting,
        array $flowArtifactIoContract,
        array $supervisionShadowGate,
        array $connectorRuntimeAdapters,
        array $canonicalFixture,
        array $trajectory,
        array $assertionSuite,
        array $failureCases,
        array $dryRun,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.flow_execution_foundation_runtime_attestation.v1',
            'orchestration_stack_bound' => (string) data_get($company, 'enterprise_flow_orchestration_runbook_stack.orchestration_runbook_hash', '') !== '',
            'flow_runbook_bound' => $flowRunbook !== []
                && count((array) data_get($flowRunbook, 'intake_packet.required_fields', [])) >= 6
                && count((array) data_get($flowRunbook, 'agent_graph.specialists', [])) >= 1
                && (bool) data_get($flowRunbook, 'agent_graph.handoff_packet_required', false)
                && count((array) data_get($flowRunbook, 'checkpoint_lattice.checkpoints', [])) >= 7
                && count((array) data_get($flowRunbook, 'evaluation_and_acceptance.acceptance_artifacts', [])) >= 5
                && (bool) data_get($flowRunbook, 'handoff_and_delivery.external_delivery_requires_operator_approval', false),
            'connector_backplane_bound' => count($connectorBackplane) >= $connectorCount
                && count(array_filter($connectorBackplane, static fn (array $connector): bool => (bool) ($connector['health_probe_required_before_run'] ?? false)
                    && (bool) ($connector['receipt_export_required'] ?? false))) === count($connectorBackplane),
            'runbook_observability_bound' => count((array) data_get($company, 'enterprise_flow_orchestration_runbook_stack.runbook_observability.required_metrics', [])) >= 5,
            'implementation_stack_bound' => (string) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_hash', '') !== '',
            'executable_flow_packet_bound' => $executableFlowPacket !== []
                && (bool) data_get($executableFlowPacket, 'execution_graph.resume_token_required', false)
                && (bool) data_get($executableFlowPacket, 'execution_graph.idempotency_required', false)
                && count((array) data_get($executableFlowPacket, 'execution_graph.human_interrupt_points', [])) >= 3
                && (bool) data_get($executableFlowPacket, 'runtime_state_contract.state_hash_required', false)
                && (bool) data_get($executableFlowPacket, 'runtime_state_contract.replayable_without_external_mutation', false),
            'agent_tool_routing_bound' => $agentToolRouting !== []
                && count((array) data_get($agentToolRouting, 'routing_rules', [])) >= 3
                && count((array) data_get($agentToolRouting, 'blocked_routes', [])) >= 4,
            'artifact_io_contract_bound' => $flowArtifactIoContract !== []
                && count((array) data_get($flowArtifactIoContract, 'required_lineage', [])) >= 6
                && (bool) data_get($flowArtifactIoContract, 'redaction_before_provider_payload', false),
            'supervision_shadow_gate_bound' => $supervisionShadowGate !== []
                && count((array) data_get($supervisionShadowGate, 'contract_stage_requires', [])) >= 3
                && count((array) data_get($supervisionShadowGate, 'shadow_stage_requires', [])) >= 4
                && count((array) data_get($supervisionShadowGate, 'supervised_stage_requires', [])) >= 4
                && count((array) data_get($supervisionShadowGate, 'blocked_until_production_acceptance', [])) >= 3,
            'connector_runtime_adapters_bound' => count($connectorRuntimeAdapters) >= $connectorCount
                && count(array_filter($connectorRuntimeAdapters, static fn (array $adapter): bool => count((array) ($adapter['supported_operations'] ?? [])) >= 4
                    && count((array) ($adapter['blocked_operations'] ?? [])) >= 7
                    && count((array) ($adapter['adapter_requirements'] ?? [])) >= 5)) === count($connectorRuntimeAdapters),
            'runtime_event_outbox_bound' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.runtime_event_and_outbox_contract.outbox_required_for', [])) >= 7
                && count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.runtime_event_and_outbox_contract.replay_key_fields', [])) >= 5,
            'implementation_observability_bound' => count((array) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_observability.required_metrics', [])) >= 6,
            'fixture_simulation_stack_bound' => (string) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_stack_hash', '') !== '',
            'canonical_fixture_bound' => $canonicalFixture !== []
                && count((array) data_get($canonicalFixture, 'input_packet.acceptance_criteria', [])) >= 4
                && count((array) data_get($canonicalFixture, 'expected_output.required_sections', [])) >= 6,
            'expected_trace_bound' => $trajectory !== []
                && count((array) data_get($trajectory, 'expected_nodes', [])) >= 10
                && count((array) data_get($trajectory, 'required_trace_fields', [])) >= 7
                && count((array) data_get($trajectory, 'must_not_include', [])) >= 4,
            'quality_assertion_suite_bound' => $assertionSuite !== []
                && count((array) data_get($assertionSuite, 'assertions', [])) >= 6,
            'failure_injection_bound' => $failureCases !== []
                && count((array) data_get($failureCases, 'cases', [])) >= 5
                && count((array) data_get($failureCases, 'must_not_do', [])) >= 4,
            'dry_run_command_bound' => $dryRun !== []
                && (int) data_get($dryRun, 'expected_exit_code', 1) === 0
                && count((array) data_get($dryRun, 'required_output_keys', [])) >= 3,
            'simulation_promotion_gates_bound' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.contract_ready_requires', [])) >= 3
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.shadow_ready_requires', [])) >= 4
                && count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.supervised_ready_requires', [])) >= 3
                && (bool) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_promotion_gates.autonomy_claim_requires_current_operational_evidence', false),
            'simulation_observability_bound' => count((array) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_observability.required_metrics', [])) >= 6,
            'calendar_wait_blocker_enabled' => false,
            'external_side_effects_enabled' => false,
            'ungoverned_external_side_effects_allowed' => false,
            'operator_checkpoint_required_before_external_action' => true,
            'attestation_hash' => hash('sha256', 'flow_execution_foundation_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_orchestration_runbook_stack.orchestration_runbook_hash', '').'|'.(string) data_get($company, 'enterprise_flow_runtime_implementation_stack.implementation_hash', '').'|'.(string) data_get($company, 'enterprise_flow_fixture_simulation_stack.simulation_stack_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function agentToolchainRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $frameworkSourceCatalog,
        array $agentFrameworkWatchlist,
        array $frameworkScorecards,
        array $flowToolkitAssignment,
        array $agentRepositoryEpic,
        array $versionPinPlan,
        array $eventPlan,
        array $stateSchema,
        array $checkpoint,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.agent_toolchain_runtime_attestation.v1',
            'framework_source_catalog_bound' => count($frameworkSourceCatalog) >= 9,
            'framework_source_catalog_count' => count($frameworkSourceCatalog),
            'repository_watchlist_bound' => count($agentFrameworkWatchlist) >= 8,
            'framework_watchlist_count' => count($agentFrameworkWatchlist),
            'framework_scorecard_bound' => count($frameworkScorecards) >= 8,
            'framework_scorecard_count' => count($frameworkScorecards),
            'flow_toolkit_assignment_bound' => $flowToolkitAssignment !== [],
            'assigned_toolkit' => (string) ($flowToolkitAssignment['assigned_toolkit'] ?? ''),
            'agent_repository_epic_bound' => $agentRepositoryEpic !== [],
            'primary_framework_ref' => (string) ($agentRepositoryEpic['primary_framework_ref'] ?? ''),
            'version_pin_plan_bound' => count($versionPinPlan) >= 8,
            'version_pin_count' => count($versionPinPlan),
            'local_contract_tests_required' => true,
            'guardrails_runtime_bound' => $eventPlan !== [] && in_array('policy_checked', (array) ($eventPlan['required_events'] ?? []), true),
            'handoffs_runtime_bound' => data_get($company, 'cross_company_fabric') !== null
                || in_array('handoff_packet_production', (array) data_get($flowToolkitAssignment, 'required_skill_sequence', []), true)
                || in_array('action_completed_or_blocked', (array) ($eventPlan['required_events'] ?? []), true),
            'tracing_runtime_bound' => $eventPlan !== [] && (string) ($eventPlan['event_schema'] ?? '') !== '',
            'durable_state_runtime_bound' => $stateSchema !== [] && (bool) ($stateSchema['state_hash_required'] ?? false),
            'human_in_loop_runtime_bound' => $checkpoint !== [] && (bool) ($checkpoint['auto_approval_allowed'] ?? true) === false,
            'mcp_connector_workbench_bound' => count((array) data_get($company, 'enterprise_vertical_solution_suite_stack.connector_solution_workbenches', [])) >= count((array) ($company['connectors'] ?? [])),
            'memory_knowledge_bound' => count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5,
            'calendar_wait_blocker_enabled' => false,
            'external_tool_side_effect_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'agent_toolchain_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($agentRepositoryEpic['epic_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function premiumEnterpriseAgentRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $premiumAgenticArchitecture,
        array $premiumTemplates,
        array $premiumTemplateRuntimeContracts,
        array $contract,
        array $premiumFlowTemplateMap,
        array $premiumManagedAgentWorkflow,
        array $premiumWorkbenches,
        array $premiumMcpServerPlan,
        array $premiumReplayBenchmark,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.premium_enterprise_agent_runtime_attestation.v1',
            'premium_model_bound' => (string) data_get($company, 'premium_enterprise_agent_reference_model.premium_model_hash', '') !== '',
            'agentic_architecture_basis_bound' => count((array) ($premiumAgenticArchitecture['agent_runtime_patterns'] ?? [])) >= 6
                && count((array) ($premiumAgenticArchitecture['required_runtime_properties'] ?? [])) >= 9,
            'managed_agent_templates_bound' => count($premiumTemplates) >= 10,
            'template_runtime_contracts_bound' => count($premiumTemplateRuntimeContracts) >= count($premiumTemplates)
                && count(array_filter($premiumTemplateRuntimeContracts, static fn (array $contract): bool => count((array) ($contract['required_capabilities'] ?? [])) >= 7
                    && count((array) ($contract['required_runtime_events'] ?? [])) >= 7
                    && count((array) ($contract['forbidden_without_operator'] ?? [])) >= 8)) === count($premiumTemplateRuntimeContracts),
            'flow_template_map_bound' => $premiumFlowTemplateMap !== []
                && count((array) ($premiumFlowTemplateMap['supporting_template_ids'] ?? [])) >= 3
                && count((array) ($premiumFlowTemplateMap['required_artifacts'] ?? [])) >= 5
                && (bool) ($premiumFlowTemplateMap['external_side_effects'] ?? true) === false,
            'flow_managed_agent_workflow_bound' => $premiumManagedAgentWorkflow !== []
                && count((array) ($premiumManagedAgentWorkflow['workflow_nodes'] ?? [])) >= 10
                && count((array) ($premiumManagedAgentWorkflow['handoff_contracts'] ?? [])) >= 5
                && count((array) ($premiumManagedAgentWorkflow['guardrails'] ?? [])) >= 5
                && (bool) data_get($premiumManagedAgentWorkflow, 'durable_state.state_hash_required', false)
                && (bool) ($premiumManagedAgentWorkflow['trace_export_required'] ?? false),
            'data_tool_workbenches_bound' => count($premiumWorkbenches) >= count((array) ($company['connectors'] ?? []))
                && count(array_filter($premiumWorkbenches, static fn (array $workbench): bool => count((array) ($workbench['required_capabilities'] ?? [])) >= 5
                    && count((array) ($workbench['blocked_capabilities_without_operator'] ?? [])) >= 6)) === count($premiumWorkbenches),
            'connector_mcp_server_plan_bound' => count($premiumMcpServerPlan) >= count((array) ($company['connectors'] ?? []))
                && count(array_filter($premiumMcpServerPlan, static fn (array $plan): bool => count((array) ($plan['required_tools'] ?? [])) >= 5
                    && count((array) ($plan['required_security_reviews'] ?? [])) >= 5
                    && (bool) ($plan['write_tools_enabled'] ?? true) === false)) === count($premiumMcpServerPlan),
            'replay_audit_harness_bound' => $premiumReplayBenchmark !== []
                && (int) ($premiumReplayBenchmark['minimum_case_count_before_shadow'] ?? 0) >= 25
                && count((array) ($premiumReplayBenchmark['required_scores'] ?? [])) >= 7
                && (bool) ($premiumReplayBenchmark['replay_required_before_promotion'] ?? false),
            'calendar_wait_removed' => (int) data_get($company, 'premium_enterprise_agent_reference_model.accelerated_activation_contract.buildout_wait_days_required', 30) === 0,
            'external_side_effects_enabled' => false,
            'operator_mandate_required_for_external_action' => true,
            'attestation_hash' => hash('sha256', 'premium_enterprise_agent_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'premium_enterprise_agent_reference_model.premium_model_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainCompanyExecutionSuiteRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $domainExecutionPacket,
        array $domainRiskControlPacket,
        array $domainDecisionRoomPacket,
        array $domainReplayEvalPack,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.domain_company_execution_suite_runtime_attestation.v1',
            'suite_stack_bound' => (string) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_suite_hash', '') !== '',
            'source_catalog_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.source_catalog', [])) >= 7,
            'domain_operating_model_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.operating_roles', [])) >= 6
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.required_controls', [])) >= 7
                && count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_operating_model.blocked_external_actions', [])) >= 9,
            'connector_execution_workbench_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])) >= count((array) ($company['connectors'] ?? []))
                && count(array_filter(
                    (array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', []),
                    static fn (array $workbench): bool => count((array) ($workbench['supported_modes'] ?? [])) >= 5
                        && count((array) ($workbench['required_receipts'] ?? [])) >= 5
                        && count((array) ($workbench['blocked_operations_without_operator'] ?? [])) >= 9
                        && (bool) ($workbench['external_side_effects_enabled'] ?? true) === false,
                )) === count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])),
            'flow_domain_execution_packet_bound' => $domainExecutionPacket !== []
                && count((array) data_get($domainExecutionPacket, 'execution_stages', [])) >= 8
                && count((array) data_get($domainExecutionPacket, 'required_artifacts', [])) >= 6
                && count((array) data_get($domainExecutionPacket, 'quality_gates', [])) >= 5
                && (bool) data_get($domainExecutionPacket, 'external_execution_allowed', true) === false,
            'flow_domain_risk_control_bound' => $domainRiskControlPacket !== []
                && count((array) data_get($domainRiskControlPacket, 'control_frameworks', [])) >= 5
                && count((array) data_get($domainRiskControlPacket, 'risk_checks', [])) >= 6
                && count((array) data_get($domainRiskControlPacket, 'required_evidence', [])) >= 6
                && (bool) data_get($domainRiskControlPacket, 'external_exception_acceptance_allowed', true) === false,
            'flow_domain_decision_room_bound' => $domainDecisionRoomPacket !== []
                && count((array) data_get($domainDecisionRoomPacket, 'decision_types', [])) >= 4
                && count((array) data_get($domainDecisionRoomPacket, 'required_sections', [])) >= 7
                && (bool) data_get($domainDecisionRoomPacket, 'auto_decision_allowed', true) === false,
            'flow_domain_replay_eval_bound' => $domainReplayEvalPack !== []
                && count((array) data_get($domainReplayEvalPack, 'case_types', [])) >= 7
                && (int) data_get($domainReplayEvalPack, 'minimum_cases', 0) >= 25
                && count((array) data_get($domainReplayEvalPack, 'required_scores', [])) >= 6
                && (bool) data_get($domainReplayEvalPack, 'promotion_requires_green_replay', false),
            'domain_execution_observability_bound' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 6),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.calendar_wait_blocker_enabled', true),
            'external_side_effects_enabled' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.external_side_effects_enabled', true),
            'operator_mandate_required_for_external_action' => (bool) data_get($company, 'enterprise_domain_company_execution_suite_stack.suite_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
            'attestation_hash' => hash('sha256', 'domain_company_execution_suite_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_domain_company_execution_suite_stack.domain_execution_suite_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function flowWorkProductDeliveryRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $flowWorkProductDeliveryBlueprint,
        array $flowWorkProductAcceptance,
        array $flowWorkProductHandoff,
        array $flowWorkProductReplayCheck,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.flow_work_product_delivery_runtime_attestation.v1',
            'delivery_stack_bound' => (string) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_stack_hash', '') !== '',
            'work_product_catalog_bound' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.work_product_catalog', [])) >= count((array) ($company['work_products'] ?? [])),
            'flow_delivery_blueprint_bound' => $flowWorkProductDeliveryBlueprint !== []
                && count((array) data_get($flowWorkProductDeliveryBlueprint, 'input_contract.required_inputs', [])) >= 6
                && count((array) data_get($flowWorkProductDeliveryBlueprint, 'artifact_sections', [])) >= 8
                && count((array) data_get($flowWorkProductDeliveryBlueprint, 'production_grade_requirements', [])) >= 7
                && (bool) data_get($flowWorkProductDeliveryBlueprint, 'external_delivery_allowed', true) === false,
            'flow_acceptance_contract_bound' => $flowWorkProductAcceptance !== []
                && count((array) data_get($flowWorkProductAcceptance, 'acceptance_tests', [])) >= 7
                && count((array) data_get($flowWorkProductAcceptance, 'quality_floor', [])) >= 4
                && count((array) data_get($flowWorkProductAcceptance, 'required_reviewers', [])) >= 3
                && count((array) data_get($flowWorkProductAcceptance, 'failure_modes', [])) >= 6
                && (bool) data_get($flowWorkProductAcceptance, 'auto_accept_allowed', true) === false,
            'flow_handoff_packet_bound' => $flowWorkProductHandoff !== []
                && count((array) data_get($flowWorkProductHandoff, 'required_evidence', [])) >= 7
                && count((array) data_get($flowWorkProductHandoff, 'handoff_targets', [])) >= 3
                && (bool) data_get($flowWorkProductHandoff, 'atlas_external_send_allowed', true) === false,
            'flow_replay_artifact_check_bound' => $flowWorkProductReplayCheck !== []
                && (int) data_get($flowWorkProductReplayCheck, 'minimum_replay_cases', 0) >= 25
                && count((array) data_get($flowWorkProductReplayCheck, 'artifact_regression_checks', [])) >= 6
                && count((array) data_get($flowWorkProductReplayCheck, 'adversarial_cases', [])) >= 5
                && (bool) data_get($flowWorkProductReplayCheck, 'promotion_requires_green_replay', false),
            'delivery_observability_bound' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 6),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.calendar_wait_blocker_enabled', true),
            'external_delivery_allowed' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_delivery_allowed', true),
            'external_side_effects_enabled' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.external_side_effects_enabled', true),
            'operator_acceptance_required_before_external_handoff' => (bool) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_policy.operator_acceptance_required_before_external_handoff', false),
            'attestation_hash' => hash('sha256', 'flow_work_product_delivery_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_work_product_delivery_stack.delivery_stack_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainDataConnectorOperatingRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $domainDataConnectorContract,
        array $domainConnectorFixtureEval,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.domain_data_connector_operating_runtime_attestation.v1',
            'data_connector_stack_bound' => (string) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_stack_hash', '') !== '',
            'source_data_room_catalog_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.source_data_room_catalog', [])) >= 7,
            'domain_data_products_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_products', [])) >= count((array) ($company['work_products'] ?? [])),
            'connector_permission_profiles_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.connector_permission_profiles', [])) >= count((array) ($company['connectors'] ?? [])),
            'flow_data_connector_contract_bound' => $domainDataConnectorContract !== []
                && count((array) data_get($domainDataConnectorContract, 'required_connectors', [])) >= 1
                && count((array) data_get($domainDataConnectorContract, 'required_source_ids', [])) >= 1
                && count((array) data_get($domainDataConnectorContract, 'required_data_products', [])) >= 3
                && count((array) data_get($domainDataConnectorContract, 'data_contract_gates', [])) >= 6
                && (int) data_get($domainDataConnectorContract, 'minimum_fixture_cases', 0) >= 25
                && (bool) data_get($domainDataConnectorContract, 'external_mutation_allowed', true) === false,
            'connector_fixture_eval_suite_bound' => $domainConnectorFixtureEval !== []
                && count((array) data_get($domainConnectorFixtureEval, 'case_mix', [])) >= 8
                && (int) data_get($domainConnectorFixtureEval, 'minimum_case_count', 0) >= 25
                && count((array) data_get($domainConnectorFixtureEval, 'required_scores', [])) >= 6
                && (bool) data_get($domainConnectorFixtureEval, 'promotion_requires_green_eval', false),
            'domain_data_room_operating_model_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_room_operating_model.required_controls', [])) >= 7
                && count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.domain_data_room_operating_model.blocked_external_actions', [])) >= 9,
            'data_connector_observability_bound' => count((array) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 7),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.calendar_wait_blocker_enabled', true),
            'write_tools_enabled' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.write_tools_enabled', true),
            'external_data_mutation_allowed' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.external_data_mutation_allowed', true),
            'secret_export_allowed' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.secret_export_allowed', true),
            'read_only_probe_required_before_live_use' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.read_only_probe_required_before_live_use', false),
            'operator_mandate_required_for_external_action' => (bool) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
            'attestation_hash' => hash('sha256', 'domain_data_connector_operating_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_domain_data_connector_operating_stack.data_connector_stack_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function flowLiveReadConnectorProbeRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $flowLiveReadProbeContract,
        array $flowLiveReadProbeEvidence,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.flow_live_read_connector_probe_runtime_attestation.v1',
            'probe_stack_bound' => (string) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_stack_hash', '') !== '',
            'connector_probe_profiles_bound' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', [])) >= count((array) ($company['connectors'] ?? [])),
            'flow_live_read_probe_contract_bound' => $flowLiveReadProbeContract !== []
                && count((array) data_get($flowLiveReadProbeContract, 'required_pre_probe_evidence', [])) >= 6
                && count((array) data_get($flowLiveReadProbeContract, 'required_probe_outputs', [])) >= 7
                && count((array) data_get($flowLiveReadProbeContract, 'failure_handling', [])) >= 5
                && (int) data_get($flowLiveReadProbeContract, 'minimum_probe_cases', 0) >= 12
                && (bool) data_get($flowLiveReadProbeContract, 'external_mutation_allowed', true) === false,
            'flow_probe_evidence_matrix_bound' => $flowLiveReadProbeEvidence !== []
                && count((array) data_get($flowLiveReadProbeEvidence, 'required_green_evidence', [])) >= 8
                && (bool) data_get($flowLiveReadProbeEvidence, 'promotion_requires_operator_acceptance', false)
                && (bool) data_get($flowLiveReadProbeEvidence, 'external_execution_authority_granted', true) === false,
            'probe_observability_bound' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 7),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.calendar_wait_blocker_enabled', true),
            'live_read_allowed' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.live_read_allowed', false),
            'write_tools_enabled' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.write_tools_enabled', true),
            'external_mutation_allowed' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true),
            'credential_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.credential_material_in_packet_allowed', true),
            'operator_scope_required_before_live_connector_probe' => (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.operator_scope_required_before_live_connector_probe', false),
            'attestation_hash' => hash('sha256', 'flow_live_read_connector_probe_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_stack_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function externalResearchAdoptionRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $externalResearchSourceBasis,
        array $externalResearchOfficialRepositories,
        array $externalResearchDomainRepositories,
        array $externalResearchFlowMatrix,
        array $externalResearchCapabilityMap,
        array $externalResearchConnectorBacklog,
        array $externalResearchProductionGates,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.external_research_adoption_runtime_attestation.v1',
            'research_stack_bound' => (string) data_get($company, 'enterprise_external_research_adoption_stack.research_adoption_hash', '') !== '',
            'source_basis_bound' => count($externalResearchSourceBasis) >= 12,
            'source_basis_count' => count($externalResearchSourceBasis),
            'source_links_required' => count(array_filter(
                $externalResearchSourceBasis,
                static fn (array $source): bool => (bool) ($source['source_links_required'] ?? false),
            )) === count($externalResearchSourceBasis),
            'official_framework_repository_count' => count($externalResearchOfficialRepositories),
            'domain_repository_candidate_count' => count($externalResearchDomainRepositories),
            'repository_catalog_bound' => count($externalResearchOfficialRepositories) >= 8 && count($externalResearchDomainRepositories) >= 3,
            'flow_adoption_matrix_bound' => $externalResearchFlowMatrix !== []
                && count((array) ($externalResearchFlowMatrix['source_refs'] ?? [])) >= 5
                && count((array) ($externalResearchFlowMatrix['repository_refs'] ?? [])) >= 5
                && count((array) ($externalResearchFlowMatrix['domain_repository_refs'] ?? [])) >= 3
                && count((array) ($externalResearchFlowMatrix['adoption_gates'] ?? [])) >= 5
                && (bool) ($externalResearchFlowMatrix['external_side_effects_enabled'] ?? true) === false,
            'flow_source_ref_count' => count((array) ($externalResearchFlowMatrix['source_refs'] ?? [])),
            'flow_repository_ref_count' => count((array) ($externalResearchFlowMatrix['repository_refs'] ?? [])),
            'flow_domain_repository_ref_count' => count((array) ($externalResearchFlowMatrix['domain_repository_refs'] ?? [])),
            'capability_map_bound' => count($externalResearchCapabilityMap) >= 12
                && count(array_filter(
                    $externalResearchCapabilityMap,
                    static fn (array $capability): bool => (bool) ($capability['external_side_effects_enabled'] ?? true) === false
                        && count((array) ($capability['review_artifacts_required'] ?? [])) >= 5,
                )) === count($externalResearchCapabilityMap),
            'capability_map_count' => count($externalResearchCapabilityMap),
            'connector_backlog_bound' => count($externalResearchConnectorBacklog) >= max(1, count((array) ($externalResearchFlowMatrix['connector_candidates'] ?? [])))
                && count(array_filter(
                    $externalResearchConnectorBacklog,
                    static fn (array $connector): bool => (bool) ($connector['external_side_effects_enabled'] ?? true) === false
                        && count((array) ($connector['required_artifacts'] ?? [])) >= 5,
                )) === count($externalResearchConnectorBacklog),
            'connector_backlog_count' => count($externalResearchConnectorBacklog),
            'production_gates_bound' => count((array) ($externalResearchProductionGates['contract_ready'] ?? [])) >= 3
                && count((array) ($externalResearchProductionGates['fixture_ready'] ?? [])) >= 3
                && count((array) ($externalResearchProductionGates['shadow_ready'] ?? [])) >= 3
                && count((array) ($externalResearchProductionGates['supervised_ready'] ?? [])) >= 3
                && count((array) ($externalResearchProductionGates['autonomy_claim_ready'] ?? [])) >= 3,
            'runtime_ingestion_without_source_review_allowed' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.runtime_ingestion_without_source_review_allowed', true),
            'repository_adoption_without_license_security_and_fixture_eval_allowed' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.repository_adoption_without_license_security_and_fixture_eval_allowed', true),
            'external_research_side_effects_default' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.external_side_effects_default', true),
            'operator_mandate_required_for_external_actions' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_external_research_adoption_stack.research_policy.calendar_wait_blocker_enabled', true),
            'attestation_hash' => hash('sha256', 'external_research_adoption_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_external_research_adoption_stack.research_adoption_hash', '').'|'.(string) ($externalResearchFlowMatrix['matrix_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function workforceCapacityRuntimeAttestation(array $company, string $companyId, string $flowId, array $workforceStaffing): array
    {
        return [
            'schema' => 'atlas.ai.company.workforce_capacity_runtime_attestation.v1',
            'workforce_stack_bound' => (string) data_get($company, 'enterprise_workforce_capacity_stack.workforce_hash', '') !== '',
            'org_model_bound' => (string) data_get($company, 'enterprise_workforce_capacity_stack.org_model.coverage_model', '') === 'manager_specialists_independent_review_operator_checkpoint',
            'manager_agent' => (string) data_get($company, 'enterprise_workforce_capacity_stack.org_model.manager_agent', ''),
            'agent_capacity_plan_bound' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.agent_capacity_plan', [])) >= 4,
            'agent_capacity_plan_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.agent_capacity_plan', [])),
            'flow_staffing_bound' => $workforceStaffing !== []
                && (bool) data_get($workforceStaffing, 'operator_checkpoint_required', false)
                && (string) data_get($workforceStaffing, 'minimum_staffing_state', '') === 'primary_backup_reviewer_defined',
            'primary_agent' => (string) data_get($workforceStaffing, 'primary_agent', ''),
            'backup_agent' => (string) data_get($workforceStaffing, 'backup_agent', ''),
            'reviewer' => (string) data_get($workforceStaffing, 'reviewer', ''),
            'training_enablement_bound' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.training_and_enablement', [])) >= 4,
            'training_plan_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.training_and_enablement', [])),
            'succession_continuity_bound' => (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.single_agent_bottleneck_allowed', true) === false
                && (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.manual_operator_fallback_required', false)
                && (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.backup_assignment_required_for_every_flow', false),
            'capacity_observability_bound' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.capacity_observability.required_metrics', [])) >= 5,
            'capacity_observability_metric_count' => count((array) data_get($company, 'enterprise_workforce_capacity_stack.capacity_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => false,
            'single_agent_bottleneck_allowed' => (bool) data_get($company, 'enterprise_workforce_capacity_stack.succession_and_continuity.single_agent_bottleneck_allowed', true),
            'external_unreviewed_staffing_change_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'workforce_capacity_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_workforce_capacity_stack.workforce_hash', '').'|'.(string) data_get($workforceStaffing, 'staffing_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function portfolioDependencyRuntimeAttestation(array $company, string $companyId, string $flowId, array $portfolioDependencyRouting): array
    {
        return [
            'schema' => 'atlas.ai.company.portfolio_dependency_runtime_attestation.v1',
            'dependency_stack_bound' => (string) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_hash', '') !== '',
            'portfolio_role_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_role.decision_scope', [])) >= 4
                && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_role.blocked_scope', [])) >= 4,
            'dependency_intake_contract_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_intake_contract.required_fields', [])) >= 7
                && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_intake_contract.reject_when_missing', [])) >= 4,
            'upstream_dependency_map_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.upstream_dependency_map', [])) >= 1,
            'upstream_dependency_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.upstream_dependency_map', [])),
            'integration_dependency_map_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.integration_dependency_map', [])) >= 1,
            'integration_dependency_count' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.integration_dependency_map', [])),
            'flow_dependency_routing_bound' => $portfolioDependencyRouting !== []
                && (bool) data_get($portfolioDependencyRouting, 'requires_dependency_check_before_execution', false)
                && count((array) data_get($portfolioDependencyRouting, 'requires_portfolio_review_when', [])) >= 4,
            'dependency_priority' => (string) data_get($portfolioDependencyRouting, 'default_portfolio_priority', ''),
            'escalation_conflict_model_bound' => (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.conflict_packet_required', false)
                && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.decision_receipt_required', false)
                && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.escalation_and_conflict_model.external_side_effects_blocked_until_resolved', false),
            'portfolio_reporting_contract_bound' => count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_sections', [])) >= 6
                && count((array) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.required_metrics', [])) >= 4
                && (bool) data_get($company, 'enterprise_portfolio_dependency_stack.portfolio_reporting_contract.receipt_hash_required', false),
            'calendar_wait_blocker_enabled' => false,
            'external_spend_publish_write_trade_or_transfer_allowed' => false,
            'cross_company_dependency_without_typed_handoff_allowed' => false,
            'unresolved_conflict_external_side_effect_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'portfolio_dependency_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_portfolio_dependency_stack.dependency_hash', '').'|'.(string) data_get($portfolioDependencyRouting, 'routing_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function autonomyPromotionRuntimeAttestation(array $autonomyPromotionPacket): array
    {
        return [
            'schema' => 'atlas.ai.company.autonomy_promotion_runtime_attestation.v1',
            'autonomy_ladder_bound' => count((array) ($autonomyPromotionPacket['autonomy_ladder'] ?? [])) >= 5,
            'autonomy_level_count' => count((array) ($autonomyPromotionPacket['autonomy_ladder'] ?? [])),
            'fixture_level_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.fixture_internal.ready', false),
            'shadow_level_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.shadow_internal.ready', false),
            'supervised_internal_level_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.supervised_internal.ready', false),
            'supervised_external_packet_bound' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.supervised_external_packet.ready', false),
            'limited_external_autonomy_blocked' => (bool) data_get($autonomyPromotionPacket, 'stage_readiness.limited_external_autonomy.ready', true) === false
                && count((array) data_get($autonomyPromotionPacket, 'stage_readiness.limited_external_autonomy.blockers', [])) >= 8,
            'current_allowed_level' => (string) data_get($autonomyPromotionPacket, 'current_allowed_level', ''),
            'next_promotion_target' => (string) data_get($autonomyPromotionPacket, 'next_promotion_target', ''),
            'evidence_spine_bound' => count((array) data_get($autonomyPromotionPacket, 'promotion_evidence_spine', [])) >= 14,
            'evidence_ref_count' => count((array) data_get($autonomyPromotionPacket, 'promotion_evidence_spine', [])),
            'rollback_reconciliation_bound' => (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.rollback_drill_required', false)
                && (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.post_execution_reconciliation_required', false)
                && (bool) data_get($autonomyPromotionPacket, 'rollback_and_reconciliation.compensation_plan_required_for_external_action', false),
            'budget_loss_cap_bound' => (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.budget_cap_signature_required', false)
                && (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.loss_cap_signature_required', false)
                && (bool) data_get($autonomyPromotionPacket, 'autonomy_budget_and_loss_cap.real_capital_action_allowed_without_signature', true) === false,
            'operator_mandate_required_for_external_autonomy' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.operator_mandate_required_for_external_autonomy', false),
            'second_reviewer_required_for_external_autonomy' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.second_reviewer_required_for_external_autonomy', false),
            'calendar_wait_blocker_enabled' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.calendar_wait_blocker_enabled', true),
            'external_autonomous_execution_allowed' => (bool) data_get($autonomyPromotionPacket, 'promotion_policy.external_autonomous_execution_allowed', true),
            'attestation_hash' => (string) data_get($autonomyPromotionPacket, 'autonomy_promotion_hash', ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDossierRuntimeAttestation(string $companyId, string $flowId, array $operationalDossier): array
    {
        return [
            'schema' => 'atlas.ai.company.enterprise_operational_dossier_runtime_attestation.v1',
            'dossier_bound' => $operationalDossier !== [],
            'evidence_spine_bound' => count((array) ($operationalDossier['evidence_spine'] ?? [])) >= 10,
            'evidence_spine_count' => count((array) ($operationalDossier['evidence_spine'] ?? [])),
            'control_plane_bound' => count((array) data_get($operationalDossier, 'control_plane.required_controls', [])) >= 8,
            'control_count' => count((array) data_get($operationalDossier, 'control_plane.required_controls', [])),
            'decision_packet_bound' => (bool) data_get($operationalDossier, 'decision_packet.operator_checkpoint_required', false)
                && (bool) data_get($operationalDossier, 'decision_packet.external_delivery_allowed', true) === false,
            'promotion_path_bound' => count((array) ($operationalDossier['promotion_path'] ?? [])) >= 4,
            'promotion_stage_count' => count((array) ($operationalDossier['promotion_path'] ?? [])),
            'scorecard_bound' => (float) data_get($operationalDossier, 'readiness_scorecard.operational_readiness_score', 0.0) >= 0.95,
            'operational_readiness_score' => (float) data_get($operationalDossier, 'readiness_scorecard.operational_readiness_score', 0.0),
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_delivery_allowed' => false,
            'real_world_autonomy_claim_allowed' => false,
            'attestation_hash' => hash('sha256', 'operational_dossier_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($operationalDossier['dossier_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function commercialOperationsRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $accountOnboardingPlan,
        array $accountServiceReview,
        array $vendorProcurementRouting,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.commercial_operations_runtime_attestation.v1',
            'customer_market_operations_bound' => (string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '') !== '',
            'offer_packaging_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5,
            'offer_packaging_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
            'customer_journey_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])) >= count((array) ($company['flows'] ?? [])),
            'customer_journey_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', [])),
            'customer_success_scorecard_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4,
            'customer_success_metric_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])),
            'account_contract_delivery_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== '',
            'contract_entitlement_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5,
            'contract_entitlement_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
            'onboarding_success_plan_bound' => $accountOnboardingPlan !== [],
            'onboarding_success_plan_id' => (string) ($accountOnboardingPlan['plan_id'] ?? $accountOnboardingPlan['flow_id'] ?? ''),
            'service_review_renewal_bound' => $accountServiceReview !== [],
            'service_review_renewal_id' => (string) ($accountServiceReview['calendar_id'] ?? $accountServiceReview['flow_id'] ?? ''),
            'account_health_risk_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= count((array) ($company['metrics'] ?? [])),
            'account_health_risk_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])),
            'billing_revenue_model_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.mode', '') !== ''
                && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.external_payment_collection_allowed', true) === false,
            'billing_mode' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.mode', ''),
            'vendor_legal_procurement_bound' => (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== '',
            'vendor_due_diligence_bound' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= count((array) ($company['connectors'] ?? [])),
            'vendor_due_diligence_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])),
            'source_terms_review_bound' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5,
            'source_terms_review_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])),
            'flow_procurement_routing_bound' => $vendorProcurementRouting !== [],
            'flow_procurement_route_id' => (string) ($vendorProcurementRouting['route_id'] ?? $vendorProcurementRouting['flow_id'] ?? ''),
            'vendor_operability_bound' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= count((array) ($company['connectors'] ?? [])),
            'vendor_operability_scorecard_count' => count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])),
            'calendar_wait_blocker_enabled' => false,
            'external_customer_commitment_allowed' => false,
            'external_billing_allowed' => false,
            'external_vendor_procurement_allowed' => false,
            'public_claim_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'commercial_operations_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '').'|'.(string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainProviderWorkbenchRuntimeAttestation(array $company, string $companyId, string $flowId, array $flowProviderRoute, array $providerEvaluationCase): array
    {
        return [
            'schema' => 'atlas.ai.company.domain_provider_workbench_runtime_attestation.v1',
            'provider_contracts_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_contracts', [])) >= 5,
            'provider_contract_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_contracts', [])),
            'connector_workbenches_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])) >= count((array) ($company['connectors'] ?? [])),
            'connector_workbench_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.connector_workbenches', [])),
            'flow_provider_route_bound' => $flowProviderRoute !== [],
            'flow_provider_route_id' => (string) ($flowProviderRoute['route_hash'] ?? $flowProviderRoute['flow_id'] ?? ''),
            'flow_required_connector_count' => count((array) ($flowProviderRoute['required_connectors'] ?? [])),
            'primary_provider_count' => count((array) ($flowProviderRoute['primary_provider_ids'] ?? [])),
            'route_stage_count' => count((array) ($flowProviderRoute['route_stages'] ?? [])),
            'route_receipt_count' => count((array) ($flowProviderRoute['required_receipts'] ?? [])),
            'provider_eval_cases_bound' => $providerEvaluationCase !== []
                && (int) ($providerEvaluationCase['minimum_cases_before_shadow'] ?? 0) >= 15
                && count((array) ($providerEvaluationCase['case_types'] ?? [])) >= 6,
            'provider_eval_case_pack_id' => (string) ($providerEvaluationCase['case_pack_id'] ?? ''),
            'provider_eval_case_type_count' => count((array) ($providerEvaluationCase['case_types'] ?? [])),
            'provider_data_product_lineage_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_data_product_lineage', [])) >= 5,
            'provider_lineage_contract_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_data_product_lineage', [])),
            'provider_workbench_observability_bound' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_observability.required_metrics', [])) >= 6,
            'provider_workbench_metric_count' => count((array) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_observability.required_metrics', [])),
            'source_claims_require_provider_lineage' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.source_claims_require_provider_lineage', false),
            'credential_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.credential_material_in_packet_allowed', true),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.calendar_wait_blocker_enabled', true),
            'provider_write_or_paid_action_default' => (bool) data_get($company, 'enterprise_domain_provider_workbench_stack.workbench_policy.provider_write_or_paid_action_default', true),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'domain_provider_workbench_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_domain_provider_workbench_stack.provider_workbench_hash', '').'|'.(string) ($flowProviderRoute['route_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function flowBenchmarkReplayRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $offlineDatasetContract,
        array $traceGradingRubric,
        array $adversarialRegressionCase,
        array $deterministicStateAssertion,
        array $replayComparisonMatrix,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.flow_benchmark_replay_runtime_attestation.v1',
            'offline_dataset_contract_bound' => $offlineDatasetContract !== []
                && (int) ($offlineDatasetContract['minimum_examples'] ?? 0) >= 10
                && count((array) ($offlineDatasetContract['required_fields'] ?? [])) >= 5,
            'dataset_id' => (string) ($offlineDatasetContract['dataset_id'] ?? ''),
            'minimum_examples' => (int) ($offlineDatasetContract['minimum_examples'] ?? 0),
            'dataset_required_field_count' => count((array) ($offlineDatasetContract['required_fields'] ?? [])),
            'trace_grading_rubric_bound' => $traceGradingRubric !== []
                && count((array) ($traceGradingRubric['graded_trace_components'] ?? [])) >= 6
                && count((array) ($traceGradingRubric['score_keys'] ?? [])) >= 6
                && (float) ($traceGradingRubric['minimum_score'] ?? 0.0) >= 0.86,
            'trace_grading_rubric_id' => (string) ($traceGradingRubric['rubric_id'] ?? ''),
            'trace_component_count' => count((array) ($traceGradingRubric['graded_trace_components'] ?? [])),
            'score_key_count' => count((array) ($traceGradingRubric['score_keys'] ?? [])),
            'adversarial_regression_bound' => $adversarialRegressionCase !== []
                && count((array) ($adversarialRegressionCase['case_types'] ?? [])) >= 6
                && in_array('perform_external_side_effect', (array) ($adversarialRegressionCase['must_not_do'] ?? []), true),
            'adversarial_case_suite' => (string) ($adversarialRegressionCase['case_suite'] ?? ''),
            'adversarial_case_type_count' => count((array) ($adversarialRegressionCase['case_types'] ?? [])),
            'deterministic_state_assertion_bound' => $deterministicStateAssertion !== []
                && count((array) ($deterministicStateAssertion['state_objects'] ?? [])) >= 5
                && count((array) ($deterministicStateAssertion['assertions'] ?? [])) >= 4,
            'state_assertion_suite' => (string) ($deterministicStateAssertion['assertion_suite'] ?? ''),
            'state_object_count' => count((array) ($deterministicStateAssertion['state_objects'] ?? [])),
            'state_assertion_count' => count((array) ($deterministicStateAssertion['assertions'] ?? [])),
            'replay_comparison_matrix_bound' => $replayComparisonMatrix !== []
                && count((array) ($replayComparisonMatrix['replay_modes'] ?? [])) >= 4
                && count((array) ($replayComparisonMatrix['comparison_dimensions'] ?? [])) >= 7
                && count((array) ($replayComparisonMatrix['required_artifacts'] ?? [])) >= 6,
            'replay_mode_count' => count((array) ($replayComparisonMatrix['replay_modes'] ?? [])),
            'comparison_dimension_count' => count((array) ($replayComparisonMatrix['comparison_dimensions'] ?? [])),
            'benchmark_observability_bound' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])) >= 5,
            'benchmark_observability_metric_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])),
            'minimum_offline_eval_score' => (float) data_get($company, 'enterprise_flow_benchmark_replay_stack.promotion_quality_gates.minimum_offline_eval_score', 0.0),
            'policy_findings_allowed' => (int) data_get($company, 'enterprise_flow_benchmark_replay_stack.promotion_quality_gates.policy_findings_allowed', 1),
            'synthetic_score_claims_allowed' => (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.synthetic_score_claims_allowed', true),
            'promotion_without_replay_green_allowed' => (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.promotion_without_replay_green_allowed', true),
            'external_model_or_paid_benchmark_requires_operator_approval' => (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.external_model_or_paid_benchmark_requires_operator_approval', false),
            'external_benchmark_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'flow_benchmark_replay_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_replay_hash', '').'|'.(string) ($replayComparisonMatrix['replay_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorCertificationPreflightRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $flowConnectorUsage,
        int $flowConnectorCount,
        array $flowConnectorCutover,
        array $flowConnectorIds,
        array $connectorAdapterContracts,
        array $connectorAuthBoundaries,
        array $connectorSandboxProbes,
        array $connectorContractTests,
        array $connectorDataLineage,
        array $connectorReplayFixtures,
        array $connectorSloFailureModes,
        array $productionPreflightContracts,
        array $productionReadinessEvidence,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.connector_certification_preflight_runtime_attestation.v1',
            'flow_connector_usage_bound' => $flowConnectorUsage !== [] && $flowConnectorCount > 0,
            'flow_connector_cutover_bound' => $flowConnectorCutover !== []
                && (bool) ($flowConnectorCutover['manual_handoff_packet_required'] ?? false)
                && (bool) ($flowConnectorCutover['auto_execute_allowed'] ?? true) === false
                && (bool) ($flowConnectorCutover['external_side_effects_enabled'] ?? true) === false,
            'flow_connector_count' => $flowConnectorCount,
            'flow_connector_ids' => $flowConnectorIds,
            'adapter_contracts_bound' => $flowConnectorCount > 0
                && count($connectorAdapterContracts) === $flowConnectorCount
                && count(array_filter($connectorAdapterContracts, static fn (array $row): bool => count((array) ($row['required_contract_fields'] ?? [])) >= 6 && (bool) ($row['schema_validation_required'] ?? false))) === $flowConnectorCount,
            'auth_boundaries_bound' => $flowConnectorCount > 0
                && count($connectorAuthBoundaries) === $flowConnectorCount
                && count(array_filter($connectorAuthBoundaries, static fn (array $row): bool => (string) ($row['credential_binding'] ?? '') === 'vault_reference_only' && (bool) ($row['secret_material_in_packet_allowed'] ?? true) === false)) === $flowConnectorCount,
            'sandbox_probes_bound' => $flowConnectorCount > 0
                && count($connectorSandboxProbes) === $flowConnectorCount
                && count(array_filter($connectorSandboxProbes, static fn (array $row): bool => count((array) ($row['probe_modes'] ?? [])) >= 5 && count((array) ($row['success_criteria'] ?? [])) >= 5)) === $flowConnectorCount,
            'consumer_provider_contract_tests_bound' => $flowConnectorCount > 0
                && count($connectorContractTests) === $flowConnectorCount
                && count(array_filter($connectorContractTests, static fn (array $row): bool => count((array) ($row['consumer_assumptions'] ?? [])) >= 4 && count((array) ($row['provider_verification'] ?? [])) >= 4)) === $flowConnectorCount,
            'connector_data_lineage_bound' => $flowConnectorCount > 0
                && count($connectorDataLineage) === $flowConnectorCount
                && count(array_filter($connectorDataLineage, static fn (array $row): bool => count((array) ($row['lineage_required'] ?? [])) >= 5 && (bool) ($row['redaction_required_before_provider_payload'] ?? false))) === $flowConnectorCount,
            'replay_fixture_mock_server_bound' => $flowConnectorCount > 0
                && count($connectorReplayFixtures) === $flowConnectorCount
                && count(array_filter($connectorReplayFixtures, static fn (array $row): bool => count((array) ($row['fixture_requirements'] ?? [])) >= 5 && (bool) ($row['required_for_offline_eval'] ?? false))) === $flowConnectorCount,
            'connector_slo_failure_modes_bound' => $flowConnectorCount > 0
                && count($connectorSloFailureModes) === $flowConnectorCount
                && count(array_filter($connectorSloFailureModes, static fn (array $row): bool => count((array) ($row['failure_modes'] ?? [])) >= 6 && (float) data_get($row, 'slo.schema_match_rate', 0.0) >= 1.0)) === $flowConnectorCount,
            'production_preflight_contracts_bound' => $flowConnectorCount > 0
                && count($productionPreflightContracts) === $flowConnectorCount
                && count(array_filter($productionPreflightContracts, static fn (array $row): bool => (bool) data_get($row, 'credential_vault_binding.credential_material_in_packet_allowed', true) === false && (bool) data_get($row, 'live_data_readiness.live_mutation_allowed', true) === false)) === $flowConnectorCount,
            'production_readiness_evidence_bound' => $flowConnectorCount > 0
                && count($productionReadinessEvidence) === $flowConnectorCount
                && count(array_filter($productionReadinessEvidence, static fn (array $row): bool => count((array) ($row['required_evidence'] ?? [])) >= 8 && (bool) ($row['external_side_effects_enabled'] ?? true) === false)) === $flowConnectorCount,
            'connector_certification_observability_bound' => count((array) data_get($company, 'enterprise_connector_certification_stack.connector_certification_observability.required_metrics', [])) >= 5,
            'connector_certification_metric_count' => count((array) data_get($company, 'enterprise_connector_certification_stack.connector_certification_observability.required_metrics', [])),
            'cutover_observability_bound' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.cutover_observability.required_metrics', [])) >= 6,
            'cutover_observability_metric_count' => count((array) data_get($company, 'enterprise_production_connector_preflight_stack.cutover_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.calendar_wait_blocker_enabled', true),
            'production_cutover_without_operator_signed_scope_allowed' => (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.production_cutover_without_operator_signed_scope_allowed', true),
            'real_credential_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_production_connector_preflight_stack.preflight_policy.real_credential_material_in_packet_allowed', true),
            'write_or_paid_mode_allowed_by_default' => (bool) data_get($company, 'enterprise_connector_certification_stack.certification_policy.write_or_paid_mode_allowed_by_default', true),
            'external_connector_cutover_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'connector_certification_preflight_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_connector_certification_stack.connector_certification_hash', '').'|'.(string) data_get($company, 'enterprise_production_connector_preflight_stack.production_connector_preflight_hash', '').'|'.(string) ($flowConnectorCutover['cutover_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function commandCenterControlTowerRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $controlTowerLane,
        array $incidentExceptionDesk,
        array $changeWindowRelease,
        array $flowCommandCard,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.command_center_control_tower_runtime_attestation.v1',
            'control_tower_bound' => (string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '') !== '',
            'control_tower_lane_bound' => $controlTowerLane !== []
                && count((array) ($controlTowerLane['intake_states'] ?? [])) >= 7
                && count((array) ($controlTowerLane['required_run_artifacts'] ?? [])) >= 6,
            'control_tower_lane_id' => (string) ($controlTowerLane['lane_id'] ?? ''),
            'run_queue_model_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.admission_controls', [])) >= 5
                && (string) data_get($company, 'enterprise_control_tower_run_operations_stack.run_queue_model.dead_letter_queue', '') !== '',
            'cadence_scheduler_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.cadence_scheduler', [])) >= count((array) ($company['cadences'] ?? [])),
            'incident_exception_desk_bound' => $incidentExceptionDesk !== []
                && count((array) ($incidentExceptionDesk['exception_types'] ?? [])) >= 6
                && count((array) ($incidentExceptionDesk['resolution_states'] ?? [])) >= 5,
            'change_window_release_bound' => $changeWindowRelease !== []
                && (bool) ($changeWindowRelease['rollback_required'] ?? false)
                && count((array) ($changeWindowRelease['required_approvals'] ?? [])) >= 3,
            'connector_probe_plan_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.connector_operations_probe_plan', [])) >= count((array) ($company['connectors'] ?? [])),
            'dashboard_operations_map_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.dashboard_operations_map', [])) >= 3,
            'human_interrupt_bound' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.human_interrupt_and_escalation_model.handoff_packet_required', false)
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.human_interrupt_and_escalation_model.interrupt_points', [])) >= 5,
            'run_observability_bound' => count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_observability.required_metrics', [])) >= 6
                && count((array) data_get($company, 'enterprise_control_tower_run_operations_stack.run_observability.trace_fields', [])) >= 8,
            'flow_command_card_bound' => $flowCommandCard !== []
                && count((array) ($flowCommandCard['quality_gates'] ?? [])) >= 5
                && count((array) ($flowCommandCard['required_receipts'] ?? [])) >= 5
                && (bool) ($flowCommandCard['external_execution_allowed'] ?? true) === false,
            'flow_command_card_hash' => (string) ($flowCommandCard['card_hash'] ?? ''),
            'command_center_bound' => (string) data_get($company, 'enterprise_company_command_center_stack.command_center_hash', '') !== '',
            'command_center_cells_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.operating_cells', [])) >= 6,
            'connector_panels_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.connector_workbench_panels', [])) >= count((array) ($company['connectors'] ?? [])),
            'operator_console_views_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.operator_console_views', [])) >= 4,
            'work_product_factory_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.work_product_factory_map', [])) >= 5,
            'command_center_kpis_bound' => count((array) data_get($company, 'enterprise_company_command_center_stack.command_center_kpis', [])) >= count((array) ($company['metrics'] ?? [])),
            'escalation_pause_bound' => (bool) data_get($company, 'enterprise_company_command_center_stack.escalation_and_pause_protocol.auto_resume_external_action_allowed', true) === false
                && count((array) data_get($company, 'enterprise_company_command_center_stack.escalation_and_pause_protocol.pause_triggers', [])) >= 6,
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.calendar_wait_blocker_enabled', true),
            'external_write_spend_trade_publish_deploy_delete_allowed' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.external_write_spend_trade_publish_deploy_delete_allowed', true),
            'secret_material_in_packet_allowed' => (bool) data_get($company, 'enterprise_company_command_center_stack.command_center_policy.secret_material_in_packet_allowed', true),
            'control_tower_external_side_effects_default' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.external_side_effects_default', true),
            'run_without_decision_receipt_allowed' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.run_without_decision_receipt_allowed', true),
            'auto_retry_external_action_allowed' => (bool) data_get($company, 'enterprise_control_tower_run_operations_stack.run_operations_policy.auto_retry_external_action_allowed', true),
            'external_control_tower_action_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'command_center_control_tower_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_control_tower_run_operations_stack.control_tower_hash', '').'|'.(string) data_get($company, 'enterprise_company_command_center_stack.command_center_hash', '').'|'.(string) ($flowCommandCard['card_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function operationalDressRehearsalRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $rehearsalRunbook,
        int $flowConnectorCount,
        array $rehearsalLiveReadProbes,
        array $operatorAcceptancePacket,
        array $rollbackDrill,
        array $promotionEvidence,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.operational_dress_rehearsal_runtime_attestation.v1',
            'dress_rehearsal_stack_bound' => (string) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_hash', '') !== '',
            'rehearsal_runbook_bound' => $rehearsalRunbook !== []
                && count((array) ($rehearsalRunbook['staging_sequence'] ?? [])) >= 7
                && count((array) ($rehearsalRunbook['acceptance_criteria'] ?? [])) >= 6
                && in_array('external_write', (array) ($rehearsalRunbook['blocked_during_rehearsal'] ?? []), true),
            'rehearsal_runbook_hash' => (string) ($rehearsalRunbook['runbook_hash'] ?? ''),
            'flow_connector_count' => $flowConnectorCount,
            'live_read_probe_plan_bound' => $flowConnectorCount > 0
                && count($rehearsalLiveReadProbes) === $flowConnectorCount
                && count(array_filter($rehearsalLiveReadProbes, static fn (array $row): bool => (bool) ($row['mutation_allowed'] ?? true) === false && count((array) ($row['required_before_supervised_production'] ?? [])) >= 6)) === $flowConnectorCount,
            'operator_acceptance_packet_bound' => $operatorAcceptancePacket !== []
                && count((array) ($operatorAcceptancePacket['required_signatures'] ?? [])) >= 2
                && count((array) ($operatorAcceptancePacket['required_artifacts'] ?? [])) >= 6
                && (bool) ($operatorAcceptancePacket['auto_accept_allowed'] ?? true) === false
                && (bool) ($operatorAcceptancePacket['external_execution_enabled_by_packet'] ?? true) === false,
            'operator_acceptance_hash' => (string) ($operatorAcceptancePacket['acceptance_hash'] ?? ''),
            'rollback_drill_bound' => $rollbackDrill !== []
                && count((array) ($rollbackDrill['drill_steps'] ?? [])) >= 5
                && count((array) ($rollbackDrill['success_criteria'] ?? [])) >= 4
                && (bool) ($rollbackDrill['required_before_any_external_mutation'] ?? false),
            'rollback_drill_hash' => (string) ($rollbackDrill['rollback_hash'] ?? ''),
            'promotion_evidence_bound' => $promotionEvidence !== []
                && count((array) ($promotionEvidence['must_have_green'] ?? [])) >= 6
                && (int) ($promotionEvidence['calendar_wait_days_required'] ?? 30) === 0
                && (bool) ($promotionEvidence['external_side_effects_enabled'] ?? true) === false,
            'promotion_evidence_hash' => (string) ($promotionEvidence['promotion_hash'] ?? ''),
            'dress_rehearsal_observability_bound' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])) >= 7
                && count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.alert_on', [])) >= 5,
            'dress_rehearsal_metric_count' => count((array) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.calendar_wait_blocker_enabled', true),
            'external_mutation_allowed_during_rehearsal' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.external_mutation_allowed_during_rehearsal', true),
            'production_cutover_allowed_without_signed_acceptance' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.production_cutover_allowed_without_signed_acceptance', true),
            'operator_and_domain_owner_acceptance_required' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.operator_and_domain_owner_acceptance_required', false),
            'second_reviewer_required_for_sensitive_scope' => (bool) data_get($company, 'enterprise_operational_dress_rehearsal_stack.rehearsal_policy.second_reviewer_required_for_spend_trade_publish_security_or_delete_scope', false),
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'operational_dress_rehearsal_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_operational_dress_rehearsal_stack.dress_rehearsal_hash', '').'|'.(string) ($rehearsalRunbook['runbook_hash'] ?? '').'|'.(string) ($operatorAcceptancePacket['acceptance_hash'] ?? '').'|'.(string) ($rollbackDrill['rollback_hash'] ?? '').'|'.(string) ($promotionEvidence['promotion_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function semanticOperatingGraphRuntimeAttestation(array $company, string $companyId, string $flowId, array $semanticFlowEdge): array
    {
        return [
            'schema' => 'atlas.ai.company.semantic_operating_graph_runtime_attestation.v1',
            'semantic_graph_bound' => (string) data_get($company, 'enterprise_semantic_operating_graph_stack.semantic_graph_hash', '') !== '',
            'node_catalog_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])) >= (
                count((array) ($company['functions'] ?? []))
                + count((array) ($company['agent_roles'] ?? []))
                + count((array) ($company['flows'] ?? []))
                + count((array) ($company['connectors'] ?? []))
                + count((array) ($company['metrics'] ?? []))
                + count((array) ($company['work_products'] ?? []))
            ),
            'node_catalog_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.node_catalog', [])),
            'flow_relationship_edge_bound' => $semanticFlowEdge !== [],
            'flow_relationship_edge_id' => (string) ($semanticFlowEdge['edge_id'] ?? $semanticFlowEdge['flow_id'] ?? ''),
            'edge_type_count' => count((array) ($semanticFlowEdge['edge_types'] ?? [])),
            'operating_views_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])) >= 4,
            'operating_view_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.operating_views', [])),
            'drift_detection_rules_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])) >= 5,
            'drift_detection_rule_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.drift_detection_rules', [])),
            'graph_export_contract_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.required_fields', [])) >= 6
                && (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.operator_visualization_ready', false),
            'graph_export_format_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.formats', [])),
            'graph_observability_bound' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_observability.required_metrics', [])) >= 5,
            'graph_observability_metric_count' => count((array) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_observability.required_metrics', [])),
            'graph_mutation_mode' => (string) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_policy.graph_mutation_mode', ''),
            'stale_or_missing_edge_blocks_autonomy_claim' => (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_policy.stale_or_missing_edge_blocks_autonomy_claim', false),
            'raw_secret_or_sensitive_payload_export_allowed' => (bool) data_get($company, 'enterprise_semantic_operating_graph_stack.graph_export_contract.raw_secret_or_sensitive_payload_export_allowed', true),
            'external_graph_mutation_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'semantic_operating_graph_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_semantic_operating_graph_stack.semantic_graph_hash', '').'|'.(string) ($semanticFlowEdge['edge_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function customerAccountRevenueRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $customerJourney,
        array $accountOnboardingPlan,
        array $accountServiceReview,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.customer_account_revenue_runtime_attestation.v1',
            'customer_market_runtime_bound' => (string) data_get($company, 'enterprise_customer_market_operations_stack.customer_market_hash', '') !== '',
            'customer_segment_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_and_stakeholder_model.stakeholder_segments', [])),
            'positioning_claim_review_required' => (bool) data_get($company, 'enterprise_customer_market_operations_stack.market_positioning_system.claim_review_required_before_external_use', false),
            'offer_packaging_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5,
            'offer_packaging_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
            'journey_lifecycle_bound' => $customerJourney !== [],
            'journey_lifecycle_id' => (string) ($customerJourney['journey_hash'] ?? $customerJourney['flow_id'] ?? ''),
            'customer_success_scorecard_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4,
            'customer_success_metric_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])),
            'commercial_service_catalog_bound' => count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])) >= 5,
            'commercial_service_count' => count((array) data_get($company, 'commercial_operating_stack.service_catalog', [])),
            'business_kpi_bound' => count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])) >= 4,
            'business_kpi_count' => count((array) data_get($company, 'commercial_operating_stack.business_kpis', [])),
            'account_contract_delivery_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '') !== '',
            'account_segment_playbook_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])) >= 4,
            'account_segment_playbook_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_segment_playbooks', [])),
            'contract_entitlement_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5,
            'contract_entitlement_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
            'onboarding_success_plan_bound' => $accountOnboardingPlan !== [],
            'onboarding_success_plan_id' => (string) ($accountOnboardingPlan['success_plan_id'] ?? $accountOnboardingPlan['flow_id'] ?? ''),
            'service_review_renewal_bound' => $accountServiceReview !== [],
            'service_review_renewal_id' => (string) ($accountServiceReview['calendar_hash'] ?? $accountServiceReview['flow_id'] ?? ''),
            'account_health_risk_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= count((array) ($company['metrics'] ?? [])),
            'account_health_risk_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])),
            'billing_revenue_model_bound' => (string) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.mode', '') !== ''
                && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model.external_payment_collection_allowed', true) === false,
            'account_observability_bound' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])) >= 6,
            'account_observability_metric_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_observability.required_metrics', [])),
            'voice_of_customer_loop_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.voice_of_customer_and_feedback_loop.required_links', [])) >= 4,
            'growth_retention_model_bound' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.growth_and_retention_operating_model.allowed_actions', [])) >= 4,
            'calendar_wait_blocker_enabled' => false,
            'revenue_claim_allowed' => false,
            'external_customer_commitment_allowed' => false,
            'external_billing_allowed' => false,
            'external_payment_collection_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'customer_account_revenue_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_account_contract_delivery_stack.account_contract_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function productizedServiceRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $productizedServiceOffer,
        array $productizedDeliveryBlueprint,
        array $productizedIntakeContract,
        array $productizedSlaContract,
        array $productizedProofTemplate,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.productized_service_runtime_attestation.v1',
            'product_stack_bound' => (string) data_get($company, 'enterprise_productized_service_stack.productized_service_hash', '') !== '',
            'domain_product_line_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', [])) >= 5,
            'domain_product_line_count' => count((array) data_get($company, 'enterprise_productized_service_stack.domain_product_lines', [])),
            'service_offer_bound' => $productizedServiceOffer !== [],
            'service_offer_id' => (string) data_get($productizedServiceOffer, 'offer_id', ''),
            'delivery_blueprint_bound' => $productizedDeliveryBlueprint !== []
                && count((array) data_get($productizedDeliveryBlueprint, 'delivery_nodes', [])) >= 9
                && count((array) data_get($productizedDeliveryBlueprint, 'required_evidence', [])) >= 8,
            'intake_contract_bound' => $productizedIntakeContract !== []
                && count((array) data_get($productizedIntakeContract, 'required_fields', [])) >= 8
                && count((array) data_get($productizedIntakeContract, 'qualification_rules', [])) >= 4,
            'sla_success_contract_bound' => $productizedSlaContract !== []
                && (float) data_get($productizedSlaContract, 'quality_floor', 0.0) >= 0.9
                && (float) data_get($productizedSlaContract, 'source_faithfulness_floor', 0.0) >= 0.95
                && count((array) data_get($productizedSlaContract, 'success_metrics', [])) >= 1,
            'pricing_packaging_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', [])) >= 5,
            'pricing_package_count' => count((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', [])),
            'gtm_motion_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.go_to_market_motion_catalog', [])) >= 5,
            'gtm_motion_count' => count((array) data_get($company, 'enterprise_productized_service_stack.go_to_market_motion_catalog', [])),
            'proof_template_bound' => $productizedProofTemplate !== []
                && count((array) data_get($productizedProofTemplate, 'required_sections', [])) >= 7
                && (bool) data_get($productizedProofTemplate, 'external_publication_allowed', true) === false,
            'product_observability_bound' => count((array) data_get($company, 'enterprise_productized_service_stack.product_observability.required_metrics', [])) >= 10,
            'product_observability_metric_count' => count((array) data_get($company, 'enterprise_productized_service_stack.product_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.calendar_wait_blocker_enabled', true),
            'public_gtm_or_customer_commitment_allowed' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.public_gtm_or_customer_commitment_allowed', true),
            'external_billing_allowed' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.external_billing_allowed', true),
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'productized_service_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($productizedServiceOffer, 'offer_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function salesCrmPipelineRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $salesOpportunityRoute,
        array $salesProposalPacket,
        array $salesMutualActionPlan,
        array $salesAccountResearchWorkbench,
        array $salesDealRoomPacket,
        array $salesPipelineForecastReview,
        array $salesMapRiskReview,
        array $salesDeliveryHandoff,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.sales_crm_pipeline_runtime_attestation.v1',
            'sales_stack_bound' => (string) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_crm_pipeline_hash', '') !== '',
            'source_catalog_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.source_catalog', [])) >= 5,
            'crm_object_model_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.objects', [])) >= 8
                && count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.required_links', [])) >= 6
                && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.crm_object_model.raw_external_contact_export_allowed', true) === false,
            'segment_sales_play_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.segment_sales_plays', [])) >= 4,
            'opportunity_route_bound' => $salesOpportunityRoute !== []
                && count((array) data_get($salesOpportunityRoute, 'stage_sequence', [])) >= 7
                && count((array) data_get($salesOpportunityRoute, 'exit_criteria', [])) >= 4
                && (bool) data_get($salesOpportunityRoute, 'external_commitment_allowed', true) === false,
            'opportunity_id' => (string) data_get($salesOpportunityRoute, 'opportunity_id', ''),
            'proposal_scope_bound' => $salesProposalPacket !== []
                && count((array) data_get($salesProposalPacket, 'required_sections', [])) >= 8
                && (bool) data_get($salesProposalPacket, 'signature_allowed', true) === false,
            'proposal_id' => (string) data_get($salesProposalPacket, 'proposal_id', ''),
            'mutual_action_plan_bound' => $salesMutualActionPlan !== []
                && count((array) data_get($salesMutualActionPlan, 'milestones', [])) >= 6
                && (bool) data_get($salesMutualActionPlan, 'external_customer_binding_allowed', true) === false,
            'mutual_action_plan_id' => (string) data_get($salesMutualActionPlan, 'map_id', ''),
            'account_research_workbench_bound' => $salesAccountResearchWorkbench !== []
                && count((array) data_get($salesAccountResearchWorkbench, 'research_inputs', [])) >= 7
                && count((array) data_get($salesAccountResearchWorkbench, 'required_outputs', [])) >= 6
                && (bool) data_get($salesAccountResearchWorkbench, 'external_enrichment_allowed', true) === false,
            'deal_room_packet_bound' => $salesDealRoomPacket !== []
                && count((array) data_get($salesDealRoomPacket, 'required_tabs', [])) >= 8
                && count((array) data_get($salesDealRoomPacket, 'approval_gates', [])) >= 5
                && (bool) data_get($salesDealRoomPacket, 'external_customer_room_allowed', true) === false,
            'pipeline_forecast_review_bound' => $salesPipelineForecastReview !== []
                && count((array) data_get($salesPipelineForecastReview, 'forecast_dimensions', [])) >= 6
                && count((array) data_get($salesPipelineForecastReview, 'required_evidence', [])) >= 5
                && (bool) data_get($salesPipelineForecastReview, 'external_revenue_commitment_allowed', true) === false,
            'map_risk_review_bound' => $salesMapRiskReview !== []
                && count((array) data_get($salesMapRiskReview, 'risk_checks', [])) >= 6
                && count((array) data_get($salesMapRiskReview, 'repair_actions', [])) >= 5
                && (bool) data_get($salesMapRiskReview, 'operator_review_required', false),
            'renewal_expansion_signal_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.renewal_and_expansion_signals', [])) >= count((array) ($company['metrics'] ?? [])),
            'renewal_expansion_signal_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.renewal_and_expansion_signals', [])),
            'sales_delivery_handoff_bound' => $salesDeliveryHandoff !== []
                && count((array) data_get($salesDeliveryHandoff, 'required_artifacts', [])) >= 6
                && count((array) data_get($salesDeliveryHandoff, 'delivery_acceptance_gate', [])) >= 5,
            'handoff_id' => (string) data_get($salesDeliveryHandoff, 'handoff_hash', ''),
            'pipeline_observability_bound' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.pipeline_observability.required_metrics', [])) >= 13,
            'pipeline_observability_metric_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.pipeline_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.calendar_wait_blocker_enabled', true),
            'external_sales_commitment_allowed' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.external_outreach_contract_signature_or_customer_commitment_allowed', true),
            'public_claim_or_paid_campaign_allowed' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.public_claim_or_paid_campaign_allowed', true),
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'sales_crm_pipeline_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_crm_pipeline_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function customerSupportServiceDeskRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $supportLane,
        array $supportTicketSla,
        array $supportKbTemplate,
        array $supportEscalationRunbook,
        array $supportResolutionRca,
        array $supportCaseResolutionWorkbench,
        array $supportHealthEscalationPlaybook,
        array $supportKnowledgeQualityReview,
        array $supportAutomationDeflectionTest,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.customer_support_service_desk_runtime_attestation.v1',
            'support_stack_bound' => (string) data_get($company, 'enterprise_customer_support_service_desk_stack.support_service_desk_hash', '') !== '',
            'source_catalog_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.source_catalog', [])) >= 5,
            'service_desk_object_model_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.objects', [])) >= 9
                && count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.required_links', [])) >= 7
                && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.service_desk_object_model.raw_external_customer_message_export_allowed', true) === false,
            'support_segment_playbook_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_segment_playbooks', [])) >= 4,
            'support_lane_bound' => $supportLane !== []
                && count((array) data_get($supportLane, 'ticket_types', [])) >= 6
                && (bool) data_get($supportLane, 'external_customer_response_allowed', true) === false,
            'support_lane_id' => (string) data_get($supportLane, 'lane_id', ''),
            'ticket_sla_contract_bound' => $supportTicketSla !== []
                && count((array) data_get($supportTicketSla, 'required_fields', [])) >= 8
                && count((array) data_get($supportTicketSla, 'sla_targets', [])) >= 3
                && (bool) data_get($supportTicketSla, 'auto_external_response_allowed', true) === false,
            'ticket_sla_id' => (string) data_get($supportTicketSla, 'sla_hash', ''),
            'knowledge_base_template_bound' => $supportKbTemplate !== []
                && count((array) data_get($supportKbTemplate, 'required_sections', [])) >= 8
                && (bool) data_get($supportKbTemplate, 'public_publish_allowed', true) === false,
            'knowledge_base_template_id' => (string) data_get($supportKbTemplate, 'template_id', ''),
            'escalation_incident_runbook_bound' => $supportEscalationRunbook !== []
                && count((array) data_get($supportEscalationRunbook, 'escalation_levels', [])) >= 4
                && count((array) data_get($supportEscalationRunbook, 'incident_steps', [])) >= 7
                && (bool) data_get($supportEscalationRunbook, 'external_notification_allowed', true) === false,
            'resolution_rca_bound' => $supportResolutionRca !== []
                && count((array) data_get($supportResolutionRca, 'resolution_evidence', [])) >= 6
                && count((array) data_get($supportResolutionRca, 'rca_required_for', [])) >= 5
                && (bool) data_get($supportResolutionRca, 'auto_close_external_ticket_allowed', true) === false,
            'case_resolution_workbench_bound' => $supportCaseResolutionWorkbench !== []
                && count((array) data_get($supportCaseResolutionWorkbench, 'case_inputs', [])) >= 7
                && count((array) data_get($supportCaseResolutionWorkbench, 'resolution_outputs', [])) >= 6
                && (bool) data_get($supportCaseResolutionWorkbench, 'external_customer_response_allowed', true) === false,
            'customer_health_escalation_bound' => $supportHealthEscalationPlaybook !== []
                && count((array) data_get($supportHealthEscalationPlaybook, 'health_signals', [])) >= 6
                && count((array) data_get($supportHealthEscalationPlaybook, 'escalation_actions', [])) >= 5
                && (bool) data_get($supportHealthEscalationPlaybook, 'external_service_commitment_allowed', true) === false,
            'knowledge_quality_review_bound' => $supportKnowledgeQualityReview !== []
                && count((array) data_get($supportKnowledgeQualityReview, 'quality_checks', [])) >= 7
                && (float) data_get($supportKnowledgeQualityReview, 'minimum_quality_score', 0.0) >= 0.9
                && (bool) data_get($supportKnowledgeQualityReview, 'public_publish_allowed', true) === false,
            'automation_deflection_test_bound' => $supportAutomationDeflectionTest !== []
                && count((array) data_get($supportAutomationDeflectionTest, 'test_cases', [])) >= 6
                && count((array) data_get($supportAutomationDeflectionTest, 'success_criteria', [])) >= 5
                && (bool) data_get($supportAutomationDeflectionTest, 'auto_deflect_external_ticket_allowed', true) === false,
            'feedback_learning_loop_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.feedback_to_product_learning_loops', [])) >= count((array) ($company['metrics'] ?? [])),
            'feedback_learning_loop_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.feedback_to_product_learning_loops', [])),
            'support_observability_bound' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_observability.required_metrics', [])) >= 13,
            'support_observability_metric_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.support_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.calendar_wait_blocker_enabled', true),
            'external_customer_message_or_support_commitment_allowed' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.external_customer_message_or_support_commitment_allowed', true),
            'regulated_support_advice_allowed_without_review' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.regulated_support_advice_allowed_without_review', true),
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'customer_support_service_desk_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_customer_support_service_desk_stack.support_service_desk_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function marketingGrowthEngineRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $marketingCampaignBlueprint,
        array $marketingContentFactory,
        array $marketingExperiment,
        array $marketingGrowthIntelligenceWorkbench,
        array $marketingAttributionExperimentModel,
        array $marketingChannelBudgetGuardrail,
        array $marketingPublicClaimEvidencePacket,
        array $marketingChannelPlan,
        array $marketingBrandReview,
        array $marketingCrmHandoff,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.marketing_growth_engine_runtime_attestation.v1',
            'marketing_stack_bound' => (string) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_growth_engine_hash', '') !== '',
            'source_catalog_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.source_catalog', [])) >= 5,
            'growth_operating_model_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_operating_model.operating_roles', [])) >= 6
                && count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.growth_operating_model.required_controls', [])) >= 6,
            'audience_segment_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.audience_segment_map', [])) >= 4,
            'campaign_blueprint_bound' => $marketingCampaignBlueprint !== []
                && count((array) data_get($marketingCampaignBlueprint, 'channels', [])) >= 5
                && (bool) data_get($marketingCampaignBlueprint, 'external_launch_allowed', true) === false,
            'campaign_id' => (string) data_get($marketingCampaignBlueprint, 'campaign_id', ''),
            'content_asset_factory_bound' => $marketingContentFactory !== []
                && count((array) data_get($marketingContentFactory, 'asset_types', [])) >= 6
                && count((array) data_get($marketingContentFactory, 'required_evidence', [])) >= 5
                && (bool) data_get($marketingContentFactory, 'public_publish_allowed', true) === false,
            'content_factory_id' => (string) data_get($marketingContentFactory, 'factory_id', ''),
            'experiment_bound' => $marketingExperiment !== []
                && count((array) data_get($marketingExperiment, 'variants', [])) >= 3
                && count((array) data_get($marketingExperiment, 'success_metrics', [])) >= 4
                && (bool) data_get($marketingExperiment, 'external_traffic_allowed', true) === false,
            'experiment_id' => (string) data_get($marketingExperiment, 'experiment_id', ''),
            'growth_intelligence_workbench_bound' => $marketingGrowthIntelligenceWorkbench !== []
                && count((array) data_get($marketingGrowthIntelligenceWorkbench, 'signals', [])) >= 6
                && count((array) data_get($marketingGrowthIntelligenceWorkbench, 'outputs', [])) >= 6
                && (bool) data_get($marketingGrowthIntelligenceWorkbench, 'external_scrape_or_publish_allowed', true) === false,
            'attribution_experiment_model_bound' => $marketingAttributionExperimentModel !== []
                && count((array) data_get($marketingAttributionExperimentModel, 'touchpoints', [])) >= 6
                && count((array) data_get($marketingAttributionExperimentModel, 'measurement_plan', [])) >= 5
                && (bool) data_get($marketingAttributionExperimentModel, 'external_tracking_pixel_allowed', true) === false,
            'channel_budget_guardrail_bound' => $marketingChannelBudgetGuardrail !== []
                && count((array) data_get($marketingChannelBudgetGuardrail, 'allowed_spend_modes', [])) >= 3
                && count((array) data_get($marketingChannelBudgetGuardrail, 'blocked_spend_actions', [])) >= 5
                && (bool) data_get($marketingChannelBudgetGuardrail, 'external_spend_allowed', true) === false,
            'public_claim_evidence_packet_bound' => $marketingPublicClaimEvidencePacket !== []
                && count((array) data_get($marketingPublicClaimEvidencePacket, 'required_evidence', [])) >= 7
                && count((array) data_get($marketingPublicClaimEvidencePacket, 'blocked_claim_types', [])) >= 5
                && (bool) data_get($marketingPublicClaimEvidencePacket, 'public_claim_allowed', true) === false,
            'channel_distribution_bound' => $marketingChannelPlan !== []
                && count((array) data_get($marketingChannelPlan, 'allowed_internal_channels', [])) >= 5
                && count((array) data_get($marketingChannelPlan, 'blocked_external_channels', [])) >= 5,
            'brand_compliance_review_bound' => $marketingBrandReview !== []
                && count((array) data_get($marketingBrandReview, 'required_checks', [])) >= 6
                && (bool) data_get($marketingBrandReview, 'auto_approve_external_publish_allowed', true) === false,
            'growth_crm_handoff_bound' => $marketingCrmHandoff !== []
                && count((array) data_get($marketingCrmHandoff, 'required_artifacts', [])) >= 6
                && count((array) data_get($marketingCrmHandoff, 'handoff_gate', [])) >= 5
                && (bool) data_get($marketingCrmHandoff, 'external_lead_handoff_allowed', true) === false,
            'marketing_observability_bound' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_observability.required_metrics', [])) >= 14,
            'marketing_observability_metric_count' => count((array) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.calendar_wait_blocker_enabled', true),
            'external_publish_paid_campaign_or_outreach_allowed' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.external_publish_paid_campaign_or_outreach_allowed', true),
            'public_claim_allowed_without_source_and_operator_review' => (bool) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_policy.public_claim_allowed_without_source_and_operator_review', true),
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'marketing_growth_engine_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_marketing_growth_engine_stack.marketing_growth_engine_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function financeTreasuryBillingRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $financeResearchWorkbench,
        array $financeBudgetEnvelope,
        array $financeForecastModel,
        array $financeModelRiskControl,
        array $financeInvestmentCommitteePacket,
        array $financeBillingLedger,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.finance_treasury_billing_runtime_attestation.v1',
            'finance_stack_bound' => (string) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_treasury_billing_hash', '') !== '',
            'source_catalog_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])) >= 5,
            'source_catalog_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])),
            'financial_data_interface_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.provider_connector_classes', [])) >= 7
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.direct_source_link_required', false)
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.cross_source_reconciliation_required', false)
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.source_verification_contract.claim_without_source_link_allowed', true) === false,
            'financial_data_interface_connector_class_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.financial_data_interface.provider_connector_classes', [])),
            'provider_connector_matrix_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])) >= count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', []))
                && count(array_filter((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', []), static fn (array $connector): bool => (bool) ($connector['direct_source_link_required'] ?? false) && (bool) ($connector['write_or_trade_scope_allowed'] ?? true) === false)) >= count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.source_catalog', [])),
            'provider_connector_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.provider_connector_matrix', [])),
            'cfo_operating_model_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.cfo_operating_model.operating_roles', [])) >= 9
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.cfo_operating_model.required_controls', [])) >= 8,
            'financial_research_workbench_bound' => $financeResearchWorkbench !== []
                && count((array) data_get($financeResearchWorkbench, 'source_panes', [])) >= 6
                && count((array) data_get($financeResearchWorkbench, 'verification_steps', [])) >= 5
                && (bool) data_get($financeResearchWorkbench, 'claim_without_source_allowed', true) === false,
            'financial_research_workbench_id' => (string) data_get($financeResearchWorkbench, 'workbench_hash', ''),
            'budget_envelope_bound' => $financeBudgetEnvelope !== []
                && count((array) data_get($financeBudgetEnvelope, 'approval_thresholds', [])) >= 3
                && (bool) data_get($financeBudgetEnvelope, 'external_spend_allowed', true) === false,
            'budget_envelope_id' => (string) data_get($financeBudgetEnvelope, 'budget_hash', ''),
            'forecast_model_bound' => $financeForecastModel !== []
                && count((array) data_get($financeForecastModel, 'drivers', [])) >= 5
                && count((array) data_get($financeForecastModel, 'scenario_set', [])) >= 4,
            'forecast_model_id' => (string) data_get($financeForecastModel, 'forecast_hash', ''),
            'model_risk_control_bound' => $financeModelRiskControl !== []
                && count((array) data_get($financeModelRiskControl, 'model_artifacts', [])) >= 5
                && count((array) data_get($financeModelRiskControl, 'review_gates', [])) >= 5
                && (bool) data_get($financeModelRiskControl, 'audit_trail_required', false)
                && (bool) data_get($financeModelRiskControl, 'investment_recommendation_allowed_without_review', true) === false,
            'model_risk_control_id' => (string) data_get($financeModelRiskControl, 'model_risk_hash', ''),
            'investment_committee_packet_bound' => $financeInvestmentCommitteePacket !== []
                && count((array) data_get($financeInvestmentCommitteePacket, 'packet_sections', [])) >= 7
                && count((array) data_get($financeInvestmentCommitteePacket, 'required_approvals', [])) >= 4
                && (bool) data_get($financeInvestmentCommitteePacket, 'external_pitch_or_trade_allowed', true) === false,
            'investment_committee_packet_id' => (string) data_get($financeInvestmentCommitteePacket, 'packet_hash', ''),
            'pnl_line_item_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])) >= 5,
            'pnl_line_item_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.pnl_line_item_model', [])),
            'billing_ledger_bound' => $financeBillingLedger !== []
                && count((array) data_get($financeBillingLedger, 'ledger_artifacts', [])) >= 5
                && count((array) data_get($financeBillingLedger, 'reconciliation_steps', [])) >= 4
                && (bool) data_get($financeBillingLedger, 'external_invoice_allowed', true) === false
                && (bool) data_get($financeBillingLedger, 'payment_collection_allowed', true) === false,
            'billing_ledger_id' => (string) data_get($financeBillingLedger, 'ledger_hash', ''),
            'treasury_risk_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.capital_actions_blocked', [])) >= 6
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.loss_cap_signature_required', false)
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.real_money_movement_allowed', true) === false,
            'finance_close_audit_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.close_packet_sections', [])) >= 9
                && count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.evidence_required', [])) >= 9
                && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_close_and_audit_pack.audit_trail_required', false),
            'finance_observability_bound' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_observability.required_metrics', [])) >= 14,
            'finance_observability_metric_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.calendar_wait_blocker_enabled', true),
            'source_linked_financial_claim_required' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.source_linked_financial_claim_required', false),
            'model_risk_review_required' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.model_risk_review_required_for_investment_or_capital_recommendation', false),
            'external_financial_action_allowed' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true),
            'real_revenue_cash_or_aum_claim_allowed' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.real_revenue_cash_or_aum_claim_allowed', true),
            'real_money_movement_allowed' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.treasury_risk_controls.real_money_movement_allowed', true),
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'finance_treasury_billing_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_treasury_billing_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function governanceRiskOperationsRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $vendorProcurementRouting,
        array $resilienceFailureMode,
        array $resilienceExercise,
        array $analyticsDecisionRegister,
        array $scenarioForecast,
        array $knowledgeLearningLoop,
        array $playbookChangeControl,
        array $identityDataBoundary,
        array $purposeConsent,
        array $grcEvidence,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.governance_risk_operations_runtime_attestation.v1',
            'vendor_procurement_bound' => (string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '') !== ''
                && $vendorProcurementRouting !== []
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_due_diligence_register', [])) >= count((array) ($company['connectors'] ?? []))
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.source_terms_review_register', [])) >= 5
                && count((array) data_get($company, 'enterprise_vendor_legal_procurement_stack.vendor_operability_scorecard', [])) >= count((array) ($company['connectors'] ?? [])),
            'procurement_route_id' => (string) ($vendorProcurementRouting['route_id'] ?? $vendorProcurementRouting['flow_id'] ?? ''),
            'resilience_continuity_bound' => (string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '') !== ''
                && $resilienceFailureMode !== []
                && $resilienceExercise !== []
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.connector_resilience_plan', [])) >= count((array) ($company['connectors'] ?? []))
                && count((array) data_get($company, 'enterprise_resilience_continuity_stack.resilience_observability.required_metrics', [])) >= 5,
            'resilience_exercise_id' => (string) ($resilienceExercise['exercise_id'] ?? ''),
            'analytics_decision_bound' => (string) data_get($company, 'enterprise_analytics_decision_intelligence_stack.analytics_hash', '') !== ''
                && $analyticsDecisionRegister !== []
                && $scenarioForecast !== []
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.metric_lineage_catalog', [])) >= count((array) ($company['metrics'] ?? []))
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.executive_dashboard_catalog', [])) >= 3
                && count((array) data_get($company, 'enterprise_analytics_decision_intelligence_stack.work_product_analytics_map', [])) >= 5,
            'decision_packet_id' => (string) ($analyticsDecisionRegister['decision_packet'] ?? $analyticsDecisionRegister['flow_id'] ?? ''),
            'knowledge_learning_bound' => (string) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_memory_hash', '') !== ''
                && $knowledgeLearningLoop !== []
                && $playbookChangeControl !== []
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.knowledge_source_registry', [])) >= 5
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.connector_knowledge_sync_plan', [])) >= count((array) ($company['connectors'] ?? []))
                && count((array) data_get($company, 'enterprise_knowledge_memory_learning_stack.learning_observability.required_metrics', [])) >= 5,
            'learning_loop_id' => (string) ($knowledgeLearningLoop['learning_loop_hash'] ?? $knowledgeLearningLoop['flow_id'] ?? ''),
            'identity_sovereignty_bound' => (string) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_hash', '') !== ''
                && $identityDataBoundary !== []
                && $purposeConsent !== []
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.agent_access_matrix', [])) >= 4
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.connector_secret_binding_plan', [])) >= count((array) ($company['connectors'] ?? []))
                && count((array) data_get($company, 'enterprise_identity_access_data_sovereignty_stack.sovereignty_observability.required_metrics', [])) >= 5,
            'data_boundary_id' => (string) ($identityDataBoundary['boundary_hash'] ?? $identityDataBoundary['flow_id'] ?? ''),
            'grc_evidence_bound' => (string) data_get($company, 'enterprise_grc_stack.grc_hash', '') !== ''
                && $grcEvidence !== []
                && count((array) data_get($company, 'enterprise_grc_stack.control_framework.control_sets', [])) >= 6
                && count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])) >= count((array) ($company['connectors'] ?? []))
                && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
            'audit_evidence_id' => (string) ($grcEvidence['audit_hash'] ?? $grcEvidence['flow_id'] ?? ''),
            'calendar_wait_blocker_enabled' => false,
            'vendor_purchase_contract_signature_secret_share_or_write_scope_allowed' => false,
            'incident_external_notification_without_operator_allowed' => false,
            'canonical_memory_write_without_review_allowed' => false,
            'secret_material_or_unscoped_memory_export_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'governance_risk_operations_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_vendor_legal_procurement_stack.procurement_hash', '').'|'.(string) data_get($company, 'enterprise_resilience_continuity_stack.resilience_hash', '').'|'.(string) data_get($company, 'enterprise_grc_stack.grc_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function unitEconomicsCapacityRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $flowCostCenter,
        array $flowUnitEconomics,
        array $capacitySimulation,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.unit_economics_capacity_runtime_attestation.v1',
            'flow_cost_center_bound' => $flowCostCenter !== [],
            'cost_center_id' => (string) ($flowCostCenter['cost_center_id'] ?? $flowCostCenter['flow_id'] ?? ''),
            'flow_unit_economics_bound' => $flowUnitEconomics !== [],
            'unit_economics_id' => (string) ($flowUnitEconomics['unit_id'] ?? $flowUnitEconomics['flow_id'] ?? ''),
            'capacity_simulation_bound' => $capacitySimulation !== [],
            'capacity_simulation_id' => (string) ($capacitySimulation['simulation_id'] ?? $capacitySimulation['flow_id'] ?? ''),
            'pricing_ladder_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', [])) >= 5,
            'pricing_ladder_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.work_product_pricing_ladder', [])),
            'agent_capacity_cost_model_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.agent_capacity_cost_model', [])) >= 4,
            'agent_capacity_cost_model_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.agent_capacity_cost_model', [])),
            'connector_cost_limit_model_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])) >= count((array) ($company['connectors'] ?? [])),
            'connector_cost_limit_model_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.connector_cost_and_limit_model', [])),
            'decision_inputs_bound' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_decision_protocol.required_inputs', [])) >= 5,
            'observability_metric_count' => count((array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.economics_capacity_observability.required_metrics', [])),
            'calendar_wait_blocker_enabled' => false,
            'real_capital_action_allowed' => false,
            'external_revenue_or_savings_claim_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'unit_economics_capacity_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack.economics_capacity_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function deliveryRiskRuntimeAttestation(
        array $company,
        string $companyId,
        string $flowId,
        array $deliverySla,
        array $strategicRivalMap,
        array $grcEvidence,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.delivery_risk_runtime_attestation.v1',
            'delivery_assurance_runtime_bound' => (string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '') !== '',
            'work_product_delivery_contract_count' => count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts', [])),
            'delivery_sla_bound' => $deliverySla !== [],
            'delivery_sla_class' => (string) ($deliverySla['sla_class'] ?? ''),
            'delivery_acceptance_tests_bound' => count((array) data_get($company, 'enterprise_delivery_assurance_stack.work_product_delivery_contracts.0.acceptance_tests', [])) >= 4,
            'delivery_risk_controls_bound' => (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_delivery_requires_operator_approval', false)
                && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.customer_visible_claims_require_source_refs', false),
            'strategic_intelligence_bound' => (string) data_get($company, 'strategic_intelligence_stack.intelligence_hash', '') !== '',
            'rival_alternative_map_bound' => $strategicRivalMap !== [],
            'rival_count' => count((array) ($strategicRivalMap['rivals'] ?? [])),
            'benchmark_evidence_required' => (bool) data_get($company, 'strategic_intelligence_stack.competitive_benchmark_model.synthetic_scores_allowed', true) === false,
            'grc_runtime_bound' => (string) data_get($company, 'enterprise_grc_stack.grc_hash', '') !== '',
            'vendor_tool_risk_count' => count((array) data_get($company, 'enterprise_grc_stack.vendor_and_tool_risk', [])),
            'audit_evidence_bound' => $grcEvidence !== [],
            'audit_required_evidence_count' => count((array) ($grcEvidence['required_evidence'] ?? [])),
            'policy_exception_blocked' => (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
            'redaction_required_for_sensitive_output' => (bool) data_get($company, 'enterprise_grc_stack.data_classification.regulated_or_sensitive_requires_redaction', false),
            'calendar_wait_blocker_enabled' => false,
            'external_delivery_allowed' => false,
            'customer_visible_claim_allowed' => false,
            'policy_exception_auto_approval_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'delivery_risk_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($company, 'enterprise_delivery_assurance_stack.delivery_assurance_hash', '').'|'.(string) data_get($company, 'enterprise_grc_stack.grc_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainBusinessExecutionRuntimeAttestation(
        string $companyId,
        string $flowId,
        array $businessExecutionCell,
        array $businessKpiBinding,
        array $businessServiceLane,
        array $businessArtifactContract,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.domain_business_execution_runtime_attestation.v1',
            'execution_cell_bound' => $businessExecutionCell !== [],
            'execution_cell_id' => (string) ($businessExecutionCell['cell_id'] ?? ''),
            'execution_mode' => (string) ($businessExecutionCell['execution_mode'] ?? ''),
            'kpi_binding_bound' => $businessKpiBinding !== [],
            'kpi_ref_count' => count((array) ($businessKpiBinding['kpi_refs'] ?? [])),
            'service_lane_bound' => $businessServiceLane !== [],
            'service_lane_id' => (string) ($businessServiceLane['lane_id'] ?? ''),
            'artifact_delivery_contract_bound' => $businessArtifactContract !== [],
            'artifact_contract_work_product' => (string) ($businessArtifactContract['work_product'] ?? ''),
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'attestation_hash' => hash('sha256', 'domain_business_execution_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($businessExecutionCell['cell_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainSolutionPlaybookRuntimeAttestation(array $company, string $companyId, string $flowId, array $domainSolutionPlaybook): array
    {
        return [
            'schema' => 'atlas.ai.company.domain_solution_playbook_runtime_attestation.v1',
            'solution_playbook_bound' => $domainSolutionPlaybook !== [],
            'playbook_id' => (string) ($domainSolutionPlaybook['playbook_id'] ?? ''),
            'source_pack_bound' => count((array) data_get($domainSolutionPlaybook, 'source_pack.source_refs', [])) >= 1
                && (bool) data_get($domainSolutionPlaybook, 'source_pack.direct_hyperlinks_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'source_pack.freshness_check_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'source_pack.source_disagreement_register_required', false),
            'source_ref_count' => count((array) data_get($domainSolutionPlaybook, 'source_pack.source_refs', [])),
            'domain_data_plane_bound' => (string) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.data_plane_hash', '') !== ''
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.direct_source_hyperlinks_required', false)
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.cross_source_verification_required', false)
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.claim_to_source_traceability_required', false)
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.external_data_mutation_allowed', true) === false,
            'data_plane_layer_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_data_plane.layers', [])),
            'execution_path_bound' => count((array) data_get($domainSolutionPlaybook, 'execution_path.nodes', [])) >= 8
                && (bool) data_get($domainSolutionPlaybook, 'execution_path.durable_state_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'execution_path.resume_token_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'execution_path.idempotency_key_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'execution_path.operator_interrupt_supported', false),
            'execution_node_count' => count((array) data_get($domainSolutionPlaybook, 'execution_path.nodes', [])),
            'tooling_contract_bound' => (bool) data_get($domainSolutionPlaybook, 'tooling_contract.mcp_or_api_adapter_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.sandbox_or_fixture_mode_required_before_live_read', false)
                && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.write_spend_trade_publish_deploy_delete_blocked_without_signed_scope', false)
                && (bool) data_get($domainSolutionPlaybook, 'tooling_contract.tool_receipt_required', false),
            'tool_connector_ref_count' => count((array) data_get($domainSolutionPlaybook, 'tooling_contract.connector_refs', [])),
            'domain_review_contract_bound' => (string) data_get($domainSolutionPlaybook, 'domain_review_contract.reviewer', '') !== ''
                && (float) data_get($domainSolutionPlaybook, 'domain_review_contract.domain_correctness_score_required', 0.0) >= 0.9
                && (float) data_get($domainSolutionPlaybook, 'domain_review_contract.source_faithfulness_score_required', 0.0) >= 0.95
                && (int) data_get($domainSolutionPlaybook, 'domain_review_contract.policy_findings_allowed', 1) === 0
                && (bool) data_get($company, 'enterprise_domain_solution_stack.domain_expert_review_board.second_reviewer_required_for_external_action', false),
            'review_mode_count' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_expert_review_board.review_modes', [])),
            'benchmark_contract_bound' => (int) data_get($domainSolutionPlaybook, 'benchmark_contract.fixture_cases_required', 0) >= 25
                && (int) data_get($domainSolutionPlaybook, 'benchmark_contract.shadow_replays_required', 0) >= 5
                && (int) data_get($domainSolutionPlaybook, 'benchmark_contract.adversarial_cases_required', 0) >= 5
                && (bool) data_get($domainSolutionPlaybook, 'benchmark_contract.regression_pack_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'benchmark_contract.synthetic_score_claims_allowed', true) === false,
            'handoff_contract_bound' => count((array) data_get($domainSolutionPlaybook, 'handoff_contract.required_evidence', [])) >= 6
                && (bool) data_get($domainSolutionPlaybook, 'handoff_contract.target_acceptance_required', false)
                && (bool) data_get($domainSolutionPlaybook, 'handoff_contract.external_delivery_requires_operator_mandate', false),
            'handoff_required_evidence_count' => count((array) data_get($domainSolutionPlaybook, 'handoff_contract.required_evidence', [])),
            'external_data_mutation_allowed' => false,
            'external_delivery_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'domain_solution_playbook_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($domainSolutionPlaybook['playbook_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainOperatingDepthRuntimeAttestation(string $companyId, string $flowId, array $domainOperatingDepthPacket): array
    {
        return [
            'schema' => 'atlas.ai.company.domain_operating_depth_runtime_attestation.v1',
            'depth_packet_bound' => $domainOperatingDepthPacket !== [],
            'skills_bound' => count((array) data_get($domainOperatingDepthPacket, 'skills', [])) >= 6,
            'skill_count' => count((array) data_get($domainOperatingDepthPacket, 'skills', [])),
            'connector_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'connector_refs', [])) >= 1,
            'connector_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'connector_refs', [])),
            'subagents_bound' => count((array) data_get($domainOperatingDepthPacket, 'subagents', [])) >= 4,
            'subagent_count' => count((array) data_get($domainOperatingDepthPacket, 'subagents', [])),
            'source_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'source_refs', [])) >= 1,
            'source_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'source_refs', [])),
            'enterprise_system_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'enterprise_system_refs', [])) >= 1,
            'enterprise_system_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'enterprise_system_refs', [])),
            'data_product_refs_bound' => count((array) data_get($domainOperatingDepthPacket, 'data_product_refs', [])) >= 1,
            'data_product_ref_count' => count((array) data_get($domainOperatingDepthPacket, 'data_product_refs', [])),
            'quality_contract_bound' => (int) data_get($domainOperatingDepthPacket, 'quality_contract.minimum_fixture_cases', 0) >= 25
                && (int) data_get($domainOperatingDepthPacket, 'quality_contract.minimum_shadow_replays', 0) >= 5
                && (float) data_get($domainOperatingDepthPacket, 'quality_contract.source_faithfulness_floor', 0.0) >= 0.95
                && (float) data_get($domainOperatingDepthPacket, 'quality_contract.domain_correctness_floor', 0.0) >= 0.9
                && (int) data_get($domainOperatingDepthPacket, 'quality_contract.policy_findings_allowed', 1) === 0,
            'operating_controls_bound' => (bool) data_get($domainOperatingDepthPacket, 'operating_controls.tool_receipts_required', false)
                && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.second_reviewer_required_for_external_action', false)
                && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.customer_visible_claims_require_source_refs', false)
                && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.external_write_spend_trade_publish_deploy_delete_allowed', true) === false
                && (bool) data_get($domainOperatingDepthPacket, 'operating_controls.offensive_security_allowed', true) === false,
            'external_write_spend_trade_publish_deploy_delete_allowed' => false,
            'offensive_security_allowed' => false,
            'external_side_effects_enabled' => false,
            'attestation_hash' => hash('sha256', 'domain_operating_depth_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($domainOperatingDepthPacket, 'packet_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainAgentWorkforceRuntimeAttestation(string $companyId, string $flowId, array $domainAgentCrew): array
    {
        return [
            'schema' => 'atlas.ai.company.domain_agent_workforce_runtime_attestation.v1',
            'crew_bound' => $domainAgentCrew !== [],
            'crew_id' => (string) data_get($domainAgentCrew, 'crew_id', ''),
            'skills_bound' => count((array) data_get($domainAgentCrew, 'skills', [])) >= 7,
            'skill_count' => count((array) data_get($domainAgentCrew, 'skills', [])),
            'connector_refs_bound' => count((array) data_get($domainAgentCrew, 'connector_refs', [])) >= 1,
            'connector_ref_count' => count((array) data_get($domainAgentCrew, 'connector_refs', [])),
            'subagents_bound' => count((array) data_get($domainAgentCrew, 'subagents', [])) >= 5,
            'subagent_count' => count((array) data_get($domainAgentCrew, 'subagents', [])),
            'source_refs_bound' => count((array) data_get($domainAgentCrew, 'source_refs', [])) >= 1,
            'source_ref_count' => count((array) data_get($domainAgentCrew, 'source_refs', [])),
            'work_surface_adapters_bound' => count((array) data_get($domainAgentCrew, 'work_surface_adapters', [])) >= 5,
            'work_surface_adapter_count' => count((array) data_get($domainAgentCrew, 'work_surface_adapters', [])),
            'managed_runtime_controls_bound' => (bool) data_get($domainAgentCrew, 'managed_runtime_controls.long_running_session', false)
                && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.resume_token_required', false)
                && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.per_tool_permissions', false)
                && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.credential_vault_ref_only', false)
                && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.full_audit_log', false)
                && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.tool_call_receipts_required', false)
                && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.human_interrupt_before_sensitive_tool', false)
                && (bool) data_get($domainAgentCrew, 'managed_runtime_controls.external_side_effects_enabled', true) === false,
            'work_queue_bound' => count((array) data_get($domainAgentCrew, 'work_queue_contract.states', [])) >= 8
                && (bool) data_get($domainAgentCrew, 'work_queue_contract.dead_letter_required', false)
                && (bool) data_get($domainAgentCrew, 'work_queue_contract.idempotency_key_required', false),
            'acceptance_contract_bound' => (int) data_get($domainAgentCrew, 'acceptance_contract.minimum_fixture_cases', 0) >= 25
                && (int) data_get($domainAgentCrew, 'acceptance_contract.minimum_shadow_replays', 0) >= 5
                && (float) data_get($domainAgentCrew, 'acceptance_contract.source_faithfulness_floor', 0.0) >= 0.95
                && (float) data_get($domainAgentCrew, 'acceptance_contract.domain_correctness_floor', 0.0) >= 0.9
                && (int) data_get($domainAgentCrew, 'acceptance_contract.policy_findings_allowed', 1) === 0
                && (bool) data_get($domainAgentCrew, 'acceptance_contract.operator_acceptance_required', false),
            'external_side_effects_enabled' => false,
            'external_write_spend_trade_publish_deploy_delete_allowed' => false,
            'offensive_security_allowed' => false,
            'attestation_hash' => hash('sha256', 'domain_agent_workforce_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) data_get($domainAgentCrew, 'crew_hash', '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function verticalSolutionRuntimeAttestation(
        string $companyId,
        string $flowId,
        array $verticalSolutionKit,
        array $verticalConnectorWorkbenches,
        array $artifactFactory,
    ): array
    {
        return [
            'schema' => 'atlas.ai.company.vertical_solution_runtime_attestation.v1',
            'kit_present' => $verticalSolutionKit !== [],
            'kit_id' => (string) ($verticalSolutionKit['kit_id'] ?? ''),
            'suite_ref_count' => count((array) ($verticalSolutionKit['suite_refs'] ?? [])),
            'connector_workbench_count' => count($verticalConnectorWorkbenches),
            'artifact_factory_present' => $artifactFactory !== [],
            'artifact_factory_id' => (string) ($artifactFactory['factory_id'] ?? ''),
            'quality_floor' => (float) ($verticalSolutionKit['quality_floor'] ?? 0.0),
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'attestation_hash' => hash('sha256', 'vertical_solution_runtime_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($verticalSolutionKit['kit_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function operatingPackageAttestation(string $companyId, string $flowId, array $operatingPackage): array
    {
        return [
            'schema' => 'atlas.ai.company.enterprise_flow_operating_package_attestation.v1',
            'package_present' => $operatingPackage !== [],
            'package_id' => (string) ($operatingPackage['package_id'] ?? ''),
            'premium_template_id' => (string) ($operatingPackage['premium_template_id'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'workbench_binding_count' => count((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', [])),
            'runbook_drill_required_before_supervised_mode' => (bool) data_get($operatingPackage, 'operations_cell.runbook_drill_required_before_supervised_mode', false),
            'calendar_wait_blocker_enabled' => (int) ($operatingPackage['buildout_wait_days_required'] ?? 30) > 0,
            'external_execution_allowed' => false,
            'attestation_hash' => hash('sha256', 'operating_package_attestation|'.$companyId.'|'.$flowId.'|'.(string) ($operatingPackage['package_hash'] ?? '')),
        ];
    }

}
