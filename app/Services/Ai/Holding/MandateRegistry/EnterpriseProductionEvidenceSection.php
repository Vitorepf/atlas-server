<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class EnterpriseProductionEvidenceSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyProductionReadinessCertificationStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $completion = $this->hub->enterpriseCompletion->enterpriseCompanyCompletionCertificationStatus($wantedCompany);
        $verticalDepth = $this->hub->enterpriseCompletion->enterpriseVerticalOperationalDepthStatus($wantedCompany);
        $activeOperatingSystem = $this->hub->enterpriseOperatingCycle->enterpriseCompanyActiveOperatingSystemStatus($wantedCompany);
        $capabilityCatalog = $this->hub->enterpriseOperatingCycle->enterpriseCompanyCapabilityCatalogStatus($wantedCompany);
        $integrationReadiness = $this->hub->enterpriseOperatingCycle->enterpriseCompanyIntegrationReadinessStatus($wantedCompany);
        $domainOperatingModel = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainOperatingModelCertificationStatus($wantedCompany);
        $agentOperationsPack = $this->hub->enterpriseAgentWorkforce->enterpriseCompanyAgentOperationsPackStatus($wantedCompany);
        $agentWorkforceRuntime = $this->hub->enterpriseAgentWorkforce->enterpriseCompanyAgentWorkforceRuntimeStatus($wantedCompany);
        $domainToolExecution = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainToolExecutionReadinessStatus($wantedCompany);
        $flowToolExecutionLedger = $this->hub->enterpriseToolExecution->enterpriseCompanyFlowToolExecutionLedgerStatus($wantedCompany);
        $flowToolExecutionRuntime = $this->hub->enterpriseToolExecution->enterpriseCompanyFlowToolExecutionRuntimeStatus($wantedCompany);
        $domainAdapterEnvelope = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainAdapterExecutionEnvelopeStatus($wantedCompany);
        $operationalExecutionLoop = $this->hub->enterpriseCompletion->enterpriseCompanyOperationalExecutionLoopStatus($wantedCompany);
        $workProductAcceptanceEvidence = $this->hub->enterpriseCompletion->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($wantedCompany);
        $workProductRuntime = $this->hub->enterpriseWorkProductRuntime->enterpriseCompanyWorkProductRuntimeStatus($wantedCompany);
        $operatingBlueprintRuntime = $this->hub->enterpriseWorkProductRuntime->enterpriseCompanyOperatingBlueprintRuntimeStatus($wantedCompany);
        $businessRuntimePersistence = $this->hub->enterprisePersistenceControlPlane->enterpriseCompanyBusinessRuntimePersistenceStatus($wantedCompany);
        $capabilityRuntimeMesh = $this->hub->enterpriseCapabilityRuntime->enterpriseCompanyCapabilityRuntimeMeshStatus($wantedCompany);
        $supervisedConnectorExecution = $this->hub->enterpriseCapabilityRuntime->enterpriseCompanySupervisedConnectorExecutionStatus($wantedCompany);
        $externalToolActivationWorkOrders = $this->hub->enterpriseToolActivation->enterpriseCompanyExternalToolActivationWorkOrderStatus($wantedCompany);
        $externalToolActivationPackets = $this->hub->enterpriseToolActivation->enterpriseCompanyExternalToolActivationPacketStatus($wantedCompany);
        $verticalToolOperatingRuntime = $this->hub->enterpriseVerticalToolRuntime->enterpriseCompanyVerticalToolOperatingRuntimeStatus($wantedCompany);
        $businessExecutionControlPlane = $this->hub->enterprisePersistenceControlPlane->enterpriseCompanyBusinessExecutionControlPlaneStatus($wantedCompany);
        $qualityComplianceLifecycle = $this->hub->enterpriseOperatingModel->enterpriseCompanyQualityComplianceLifecycleStatus($wantedCompany);
        $holdingOutcomeScorecard = $this->hub->flowActionRuntime->holdingOutcomeScorecardStatus($wantedCompany);
        $portfolioDecisionPacket = $this->hub->flowActionRuntime->portfolioDecisionPacketStatus($wantedCompany);
        $companyBoardOperatingReview = $this->hub->flowActionRuntime->companyBoardOperatingReviewStatus($wantedCompany);
        $operatingCycle = $this->hub->enterpriseOperatingCycle->enterpriseCompanyOperatingCycleStatus($wantedCompany);
        $operatingCadence = $this->hub->enterpriseOperatingCycle->enterpriseCompanyOperatingCadenceStatus($wantedCompany);
        $operatingScorecard = $this->hub->enterpriseOperatingCycle->enterpriseCompanyOperatingScorecardStatus($wantedCompany);
        $customerAccountRevenue = $this->hub->flowActionRuntime->customerAccountRevenueRuntimeStatus($wantedCompany);
        $productizedService = $this->hub->flowActionRuntime->productizedServiceRuntimeStatus($wantedCompany);
        $salesCrmPipeline = $this->hub->flowActionRuntime->salesCrmPipelineRuntimeStatus($wantedCompany);
        $customerSupportServiceDesk = $this->hub->flowActionRuntime->customerSupportServiceDeskRuntimeStatus($wantedCompany);
        $marketingGrowthEngine = $this->hub->flowActionRuntime->marketingGrowthEngineRuntimeStatus($wantedCompany);
        $financeTreasuryBilling = $this->hub->flowActionRuntime->financeTreasuryBillingRuntimeStatus($wantedCompany);
        $governanceRiskOperations = $this->hub->flowActionRuntime->governanceRiskOperationsRuntimeStatus($wantedCompany);
        $unitEconomicsCapacity = $this->hub->flowActionRuntime->unitEconomicsCapacityRuntimeStatus($wantedCompany);
        $businessOperatingPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $flowLiveReadConnectorProbe = $this->hub->flowActionRuntime->flowLiveReadConnectorProbeRuntimeStatus($wantedCompany);

        $completionByCompany = $this->hub->companyRowsById($completion);
        $verticalDepthByCompany = $this->hub->companyRowsById($verticalDepth);
        $activeOperatingSystemByCompany = $this->hub->companyRowsById($activeOperatingSystem);
        $catalogByCompany = $this->hub->companyRowsById($capabilityCatalog);
        $integrationByCompany = $this->hub->companyRowsById($integrationReadiness);
        $domainOperatingModelByCompany = $this->hub->companyRowsById($domainOperatingModel);
        $agentOperationsPackByCompany = $this->hub->companyRowsById($agentOperationsPack);
        $agentWorkforceRuntimeByCompany = $this->hub->companyRowsById($agentWorkforceRuntime);
        $domainToolExecutionByCompany = $this->hub->companyRowsById($domainToolExecution);
        $flowToolExecutionLedgerByCompany = $this->hub->companyRowsById($flowToolExecutionLedger);
        $flowToolExecutionRuntimeByCompany = $this->hub->companyRowsById($flowToolExecutionRuntime);
        $domainAdapterEnvelopeByCompany = $this->hub->companyRowsById($domainAdapterEnvelope);
        $operationalExecutionLoopByCompany = $this->hub->companyRowsById($operationalExecutionLoop);
        $workProductAcceptanceEvidenceByCompany = $this->hub->companyRowsById($workProductAcceptanceEvidence);
        $workProductRuntimeByCompany = $this->hub->companyRowsById($workProductRuntime);
        $operatingBlueprintRuntimeByCompany = $this->hub->companyRowsById($operatingBlueprintRuntime);
        $businessRuntimePersistenceByCompany = $this->hub->companyRowsById($businessRuntimePersistence);
        $capabilityRuntimeMeshByCompany = $this->hub->companyRowsById($capabilityRuntimeMesh);
        $supervisedConnectorExecutionByCompany = $this->hub->companyRowsById($supervisedConnectorExecution);
        $externalToolActivationWorkOrdersByCompany = $this->hub->companyRowsById($externalToolActivationWorkOrders);
        $externalToolActivationPacketsByCompany = $this->hub->companyRowsById($externalToolActivationPackets);
        $verticalToolOperatingRuntimeByCompany = $this->hub->companyRowsById($verticalToolOperatingRuntime);
        $businessExecutionControlPlaneByCompany = $this->hub->companyRowsById($businessExecutionControlPlane);
        $qualityComplianceLifecycleByCompany = $this->hub->companyRowsById($qualityComplianceLifecycle);
        $holdingOutcomeScorecardByCompany = $this->hub->companyRowsById($holdingOutcomeScorecard);
        $portfolioDecisionPacketByCompany = $this->hub->companyRowsById($portfolioDecisionPacket);
        $companyBoardOperatingReviewByCompany = $this->hub->companyRowsById($companyBoardOperatingReview);
        $operatingCycleByCompany = $this->hub->companyRowsById($operatingCycle);
        $operatingCadenceByCompany = $this->hub->companyRowsById($operatingCadence);
        $operatingScorecardByCompany = $this->hub->companyRowsById($operatingScorecard);
        $customerAccountRevenueByCompany = $this->hub->companyRowsById($customerAccountRevenue);
        $productizedServiceByCompany = $this->hub->companyRowsById($productizedService);
        $salesCrmPipelineByCompany = $this->hub->companyRowsById($salesCrmPipeline);
        $customerSupportServiceDeskByCompany = $this->hub->companyRowsById($customerSupportServiceDesk);
        $marketingGrowthEngineByCompany = $this->hub->companyRowsById($marketingGrowthEngine);
        $financeTreasuryBillingByCompany = $this->hub->companyRowsById($financeTreasuryBilling);
        $governanceRiskOperationsByCompany = $this->hub->companyRowsById($governanceRiskOperations);
        $unitEconomicsCapacityByCompany = $this->hub->companyRowsById($unitEconomicsCapacity);
        $businessOperatingPacketByCompany = $this->hub->companyRowsById($businessOperatingPacket);
        $flowLiveReadConnectorProbeByCompany = $this->hub->companyRowsById($flowLiveReadConnectorProbe);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $completionRow = (array) ($completionByCompany[$id] ?? []);
            $verticalDepthRow = (array) ($verticalDepthByCompany[$id] ?? []);
            $activeOperatingSystemRow = (array) ($activeOperatingSystemByCompany[$id] ?? []);
            $catalogRow = (array) ($catalogByCompany[$id] ?? []);
            $integrationRow = (array) ($integrationByCompany[$id] ?? []);
            $domainOperatingModelRow = (array) ($domainOperatingModelByCompany[$id] ?? []);
            $agentOperationsPackRow = (array) ($agentOperationsPackByCompany[$id] ?? []);
            $agentWorkforceRuntimeRow = (array) ($agentWorkforceRuntimeByCompany[$id] ?? []);
            $domainToolExecutionRow = (array) ($domainToolExecutionByCompany[$id] ?? []);
            $flowToolExecutionLedgerRow = (array) ($flowToolExecutionLedgerByCompany[$id] ?? []);
            $flowToolExecutionRuntimeRow = (array) ($flowToolExecutionRuntimeByCompany[$id] ?? []);
            $domainAdapterEnvelopeRow = (array) ($domainAdapterEnvelopeByCompany[$id] ?? []);
            $operationalExecutionLoopRow = (array) ($operationalExecutionLoopByCompany[$id] ?? []);
            $workProductAcceptanceEvidenceRow = (array) ($workProductAcceptanceEvidenceByCompany[$id] ?? []);
            $workProductRuntimeRow = (array) ($workProductRuntimeByCompany[$id] ?? []);
            $operatingBlueprintRuntimeRow = (array) ($operatingBlueprintRuntimeByCompany[$id] ?? []);
            $businessRuntimePersistenceRow = (array) ($businessRuntimePersistenceByCompany[$id] ?? []);
            $capabilityRuntimeMeshRow = (array) ($capabilityRuntimeMeshByCompany[$id] ?? []);
            $supervisedConnectorExecutionRow = (array) ($supervisedConnectorExecutionByCompany[$id] ?? []);
            $externalToolActivationWorkOrdersRow = (array) ($externalToolActivationWorkOrdersByCompany[$id] ?? []);
            $externalToolActivationPacketsRow = (array) ($externalToolActivationPacketsByCompany[$id] ?? []);
            $verticalToolOperatingRuntimeRow = (array) ($verticalToolOperatingRuntimeByCompany[$id] ?? []);
            $businessExecutionControlPlaneRow = (array) ($businessExecutionControlPlaneByCompany[$id] ?? []);
            $qualityComplianceLifecycleRow = (array) ($qualityComplianceLifecycleByCompany[$id] ?? []);
            $holdingOutcomeScorecardRow = (array) ($holdingOutcomeScorecardByCompany[$id] ?? []);
            $portfolioDecisionPacketRow = (array) ($portfolioDecisionPacketByCompany[$id] ?? []);
            $companyBoardOperatingReviewRow = (array) ($companyBoardOperatingReviewByCompany[$id] ?? []);
            $operatingCycleRow = (array) ($operatingCycleByCompany[$id] ?? []);
            $operatingCadenceRow = (array) ($operatingCadenceByCompany[$id] ?? []);
            $operatingScorecardRow = (array) ($operatingScorecardByCompany[$id] ?? []);
            $customerAccountRevenueRow = (array) ($customerAccountRevenueByCompany[$id] ?? []);
            $productizedServiceRow = (array) ($productizedServiceByCompany[$id] ?? []);
            $salesCrmPipelineRow = (array) ($salesCrmPipelineByCompany[$id] ?? []);
            $customerSupportServiceDeskRow = (array) ($customerSupportServiceDeskByCompany[$id] ?? []);
            $marketingGrowthEngineRow = (array) ($marketingGrowthEngineByCompany[$id] ?? []);
            $financeTreasuryBillingRow = (array) ($financeTreasuryBillingByCompany[$id] ?? []);
            $governanceRiskOperationsRow = (array) ($governanceRiskOperationsByCompany[$id] ?? []);
            $unitEconomicsCapacityRow = (array) ($unitEconomicsCapacityByCompany[$id] ?? []);
            $businessOperatingPacketRow = (array) ($businessOperatingPacketByCompany[$id] ?? []);
            $flowLiveReadConnectorProbeRow = (array) ($flowLiveReadConnectorProbeByCompany[$id] ?? []);
            $integrationGates = (array) ($integrationRow['gates'] ?? []);
            $structuralLiveReadConnectorProbeReady = data_get($company, 'enterprise_flow_live_read_connector_probe_stack.schema') === 'atlas.ai.company.enterprise_flow_live_read_connector_probe_stack.v1'
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.connector_probe_profiles', [])) >= count((array) ($company['connectors'] ?? []))
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', [])) >= $expectedFlowCount
                && count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_observability.required_metrics', [])) >= (count((array) ($company['metrics'] ?? [])) + 7)
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.calendar_wait_blocker_enabled', true) === false
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.live_read_allowed', false)
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.write_tools_enabled', true) === false
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.external_mutation_allowed', true) === false
                && (bool) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.probe_policy.credential_material_in_packet_allowed', true) === false
                && (bool) data_get($catalogRow, 'families.flow_live_read_connector_probe_operations.ready', false);
            $runtimeLiveReadConnectorProbeReady = $this->hub->runtimeCoverageRowReady($flowLiveReadConnectorProbeRow, 'completed_flow_live_read_connector_probe_count')
                && (int) ($flowLiveReadConnectorProbeRow['external_mutations_blocked_count'] ?? 0) >= $expectedFlowCount
                && (int) ($flowLiveReadConnectorProbeRow['operator_scope_required_count'] ?? 0) >= $expectedFlowCount
                && (int) data_get($flowLiveReadConnectorProbeRow, 'external_side_effect_count', 1) === 0;
            $structuralProductionRuntimeReady = (bool) ($completionRow['completion_certified'] ?? false);

            $gates = [
                'completion_certified' => (bool) ($completionRow['completion_certified'] ?? false),
                'vertical_operational_depth_ready' => (int) ($verticalDepthRow['ready_gate_count'] ?? 0) > 0
                    && (int) ($verticalDepthRow['ready_gate_count'] ?? 0) === (int) ($verticalDepthRow['required_gate_count'] ?? -1),
                'customer_account_revenue_runtime_ready' => $this->hub->runtimeCoverageRowReady($customerAccountRevenueRow, 'completed_customer_account_revenue_flow_count') || $structuralProductionRuntimeReady,
                'productized_service_runtime_ready' => $this->hub->runtimeCoverageRowReady($productizedServiceRow, 'completed_productized_service_flow_count') || $structuralProductionRuntimeReady,
                'sales_crm_pipeline_runtime_ready' => $this->hub->runtimeCoverageRowReady($salesCrmPipelineRow, 'completed_sales_crm_pipeline_flow_count') || $structuralProductionRuntimeReady,
                'customer_support_service_desk_runtime_ready' => $this->hub->runtimeCoverageRowReady($customerSupportServiceDeskRow, 'completed_customer_support_service_desk_flow_count') || $structuralProductionRuntimeReady,
                'marketing_growth_engine_runtime_ready' => $this->hub->runtimeCoverageRowReady($marketingGrowthEngineRow, 'completed_marketing_growth_engine_flow_count') || $structuralProductionRuntimeReady,
                'finance_treasury_billing_runtime_ready' => $this->hub->runtimeCoverageRowReady($financeTreasuryBillingRow, 'completed_finance_treasury_billing_flow_count') || $structuralProductionRuntimeReady,
                'governance_risk_operations_runtime_ready' => $this->hub->runtimeCoverageRowReady($governanceRiskOperationsRow, 'completed_governance_risk_operations_flow_count') || $structuralProductionRuntimeReady,
                'unit_economics_capacity_runtime_ready' => $this->hub->runtimeCoverageRowReady($unitEconomicsCapacityRow, 'completed_unit_economics_capacity_flow_count') || $structuralProductionRuntimeReady,
                'business_operating_packet_runtime_ready' => $this->hub->runtimeCoverageRowReady($businessOperatingPacketRow, 'completed_business_operating_packet_flow_count') || $structuralProductionRuntimeReady,
                'flow_live_read_connector_probe_runtime_ready' => $runtimeLiveReadConnectorProbeReady || $structuralLiveReadConnectorProbeReady,
                'active_operating_system_ready' => (bool) ($activeOperatingSystemRow['active_operating_system_ready'] ?? false),
                'capability_catalog_ready' => (bool) ($catalogRow['catalog_ready'] ?? false),
                'integration_ready' => (bool) ($integrationRow['integration_ready'] ?? false),
                'domain_operating_model_certified' => (bool) ($domainOperatingModelRow['domain_operating_model_certified'] ?? false)
                    && (int) ($domainOperatingModelRow['ready_gate_count'] ?? 0) === (int) ($domainOperatingModelRow['required_gate_count'] ?? -1),
                'agent_operations_pack_ready' => (bool) ($agentOperationsPackRow['agent_operations_pack_ready'] ?? false)
                    && (int) ($agentOperationsPackRow['ready_flow_agent_operations_pack_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($agentOperationsPackRow['ready_gate_count'] ?? 0) === (int) ($agentOperationsPackRow['required_gate_count'] ?? -1)
                    && ! (bool) ($agentOperationsPackRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($agentOperationsPackRow['external_side_effects_enabled'] ?? true),
                'agent_workforce_runtime_ready' => ((bool) ($agentWorkforceRuntimeRow['agent_workforce_runtime_ready'] ?? false)
                    && (int) ($agentWorkforceRuntimeRow['ready_gate_count'] ?? 0) === (int) ($agentWorkforceRuntimeRow['required_gate_count'] ?? -1)
                    && (int) ($agentWorkforceRuntimeRow['ready_agent_workforce_runtime_count'] ?? 0) === (int) ($agentWorkforceRuntimeRow['expected_agent_workforce_runtime_count'] ?? -1)
                    && (int) ($agentWorkforceRuntimeRow['ready_agent_workforce_runtime_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($agentWorkforceRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($agentWorkforceRuntimeRow['external_side_effects_enabled'] ?? true))
                    || $structuralProductionRuntimeReady,
                'domain_tool_execution_ready' => (bool) ($domainToolExecutionRow['tool_execution_ready'] ?? false)
                    && (int) ($domainToolExecutionRow['ready_gate_count'] ?? 0) === (int) ($domainToolExecutionRow['required_gate_count'] ?? -1)
                    && (int) ($domainToolExecutionRow['ready_flow_tool_execution_count'] ?? 0) === (int) ($domainToolExecutionRow['flow_tool_execution_count'] ?? -1),
                'flow_tool_execution_ledger_ready' => (bool) ($flowToolExecutionLedgerRow['flow_tool_execution_ledger_ready'] ?? false)
                    && (int) ($flowToolExecutionLedgerRow['ready_gate_count'] ?? 0) === (int) ($flowToolExecutionLedgerRow['required_gate_count'] ?? -1)
                    && (int) ($flowToolExecutionLedgerRow['ready_ledger_record_count'] ?? 0) === (int) ($flowToolExecutionLedgerRow['ledger_record_count'] ?? -1)
                    && (int) ($flowToolExecutionLedgerRow['ready_ledger_record_count'] ?? 0) >= $expectedFlowCount,
                'flow_tool_execution_runtime_persisted' => (bool) ($flowToolExecutionRuntimeRow['persisted_runtime_ready'] ?? false)
                    && (int) ($flowToolExecutionRuntimeRow['ready_gate_count'] ?? 0) === (int) ($flowToolExecutionRuntimeRow['required_gate_count'] ?? -1)
                    && (int) ($flowToolExecutionRuntimeRow['ready_persisted_run_count'] ?? 0) === (int) ($flowToolExecutionRuntimeRow['expected_run_count'] ?? -1)
                    && (int) ($flowToolExecutionRuntimeRow['ready_persisted_run_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($flowToolExecutionRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($flowToolExecutionRuntimeRow['external_side_effects_enabled'] ?? true),
                'domain_adapter_execution_envelopes_ready' => (bool) ($domainAdapterEnvelopeRow['adapter_envelope_ready'] ?? false)
                    && (int) ($domainAdapterEnvelopeRow['ready_gate_count'] ?? 0) === (int) ($domainAdapterEnvelopeRow['required_gate_count'] ?? -1)
                    && (int) ($domainAdapterEnvelopeRow['ready_persisted_envelope_count'] ?? 0) === (int) ($domainAdapterEnvelopeRow['expected_envelope_count'] ?? -1)
                    && ! (bool) ($domainAdapterEnvelopeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($domainAdapterEnvelopeRow['external_side_effects_enabled'] ?? true),
                'operational_execution_loop_ready' => (bool) ($operationalExecutionLoopRow['operational_execution_loop_ready'] ?? false)
                    && (int) ($operationalExecutionLoopRow['ready_gate_count'] ?? 0) === (int) ($operationalExecutionLoopRow['required_gate_count'] ?? -1),
                'work_product_acceptance_evidence_ready' => (bool) ($workProductAcceptanceEvidenceRow['work_product_acceptance_evidence_ready'] ?? false)
                    && (int) ($workProductAcceptanceEvidenceRow['ready_gate_count'] ?? 0) === (int) ($workProductAcceptanceEvidenceRow['required_gate_count'] ?? -1),
                'work_product_runtime_persisted' => (bool) ($workProductRuntimeRow['work_product_runtime_ready'] ?? false)
                    && (int) ($workProductRuntimeRow['ready_gate_count'] ?? 0) === (int) ($workProductRuntimeRow['required_gate_count'] ?? -1)
                    && (int) ($workProductRuntimeRow['ready_persisted_work_product_run_count'] ?? 0) === (int) ($workProductRuntimeRow['expected_flow_count'] ?? -1)
                    && (int) ($workProductRuntimeRow['ready_persisted_work_product_run_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($workProductRuntimeRow['external_delivery_allowed'] ?? true)
                    && ! (bool) ($workProductRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($workProductRuntimeRow['external_side_effects_enabled'] ?? true),
                'operating_blueprint_runtime_persisted' => (bool) ($operatingBlueprintRuntimeRow['operating_blueprint_runtime_ready'] ?? false)
                    && (int) ($operatingBlueprintRuntimeRow['ready_gate_count'] ?? 0) === (int) ($operatingBlueprintRuntimeRow['required_gate_count'] ?? -1)
                    && (int) ($operatingBlueprintRuntimeRow['ready_persisted_blueprint_run_count'] ?? 0) === (int) ($operatingBlueprintRuntimeRow['expected_flow_count'] ?? -1)
                    && (int) ($operatingBlueprintRuntimeRow['ready_persisted_blueprint_run_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($operatingBlueprintRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($operatingBlueprintRuntimeRow['external_side_effects_enabled'] ?? true),
                'business_runtime_persistence_ready' => (bool) ($businessRuntimePersistenceRow['business_runtime_persistence_ready'] ?? false)
                    && (int) ($businessRuntimePersistenceRow['ready_gate_count'] ?? 0) === (int) ($businessRuntimePersistenceRow['required_gate_count'] ?? -1)
                    && (int) ($businessRuntimePersistenceRow['ready_persisted_business_runtime_run_count'] ?? 0) === (int) ($businessRuntimePersistenceRow['expected_business_runtime_run_count'] ?? -1)
                    && (int) ($businessRuntimePersistenceRow['ready_persisted_business_runtime_run_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($businessRuntimePersistenceRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($businessRuntimePersistenceRow['external_side_effects_enabled'] ?? true),
                'capability_runtime_mesh_ready' => (bool) ($capabilityRuntimeMeshRow['capability_runtime_mesh_ready'] ?? false)
                    && (int) ($capabilityRuntimeMeshRow['ready_gate_count'] ?? 0) === (int) ($capabilityRuntimeMeshRow['required_gate_count'] ?? -1)
                    && (int) ($capabilityRuntimeMeshRow['ready_capability_runtime_run_count'] ?? 0) === (int) ($capabilityRuntimeMeshRow['expected_capability_runtime_run_count'] ?? -1)
                    && (int) ($capabilityRuntimeMeshRow['ready_capability_runtime_run_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($capabilityRuntimeMeshRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($capabilityRuntimeMeshRow['external_side_effects_enabled'] ?? true),
                'supervised_connector_execution_ready' => (bool) ($supervisedConnectorExecutionRow['supervised_connector_execution_ready'] ?? false)
                    && (int) ($supervisedConnectorExecutionRow['ready_gate_count'] ?? 0) === (int) ($supervisedConnectorExecutionRow['required_gate_count'] ?? -1)
                    && (int) ($supervisedConnectorExecutionRow['ready_supervised_connector_execution_run_count'] ?? 0) === (int) ($supervisedConnectorExecutionRow['expected_supervised_connector_execution_run_count'] ?? -1)
                    && (int) ($supervisedConnectorExecutionRow['ready_supervised_connector_execution_run_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($supervisedConnectorExecutionRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($supervisedConnectorExecutionRow['external_side_effects_enabled'] ?? true),
                'external_tool_activation_work_orders_ready' => (bool) ($externalToolActivationWorkOrdersRow['external_tool_activation_work_orders_ready'] ?? false)
                    && (int) ($externalToolActivationWorkOrdersRow['ready_gate_count'] ?? 0) === (int) ($externalToolActivationWorkOrdersRow['required_gate_count'] ?? -1)
                    && (int) ($externalToolActivationWorkOrdersRow['ready_external_tool_activation_work_order_count'] ?? 0) === (int) ($externalToolActivationWorkOrdersRow['expected_external_tool_activation_work_order_count'] ?? -1)
                    && (int) ($externalToolActivationWorkOrdersRow['ready_external_tool_activation_work_order_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($externalToolActivationWorkOrdersRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($externalToolActivationWorkOrdersRow['external_side_effects_enabled'] ?? true),
                'external_tool_activation_packets_ready' => (bool) ($externalToolActivationPacketsRow['external_tool_activation_packets_ready'] ?? false)
                    && (int) ($externalToolActivationPacketsRow['ready_gate_count'] ?? 0) === (int) ($externalToolActivationPacketsRow['required_gate_count'] ?? -1)
                    && (int) ($externalToolActivationPacketsRow['ready_external_tool_activation_packet_count'] ?? 0) === (int) ($externalToolActivationPacketsRow['expected_external_tool_activation_packet_count'] ?? -1)
                    && (int) ($externalToolActivationPacketsRow['ready_external_tool_activation_packet_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($externalToolActivationPacketsRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($externalToolActivationPacketsRow['external_side_effects_enabled'] ?? true),
                'vertical_tool_operating_runtime_ready' => (bool) ($verticalToolOperatingRuntimeRow['vertical_tool_operating_runtime_ready'] ?? false)
                    && (int) ($verticalToolOperatingRuntimeRow['ready_gate_count'] ?? 0) === (int) ($verticalToolOperatingRuntimeRow['required_gate_count'] ?? -1)
                    && (int) ($verticalToolOperatingRuntimeRow['ready_vertical_tool_runtime_count'] ?? 0) === (int) ($verticalToolOperatingRuntimeRow['expected_vertical_tool_runtime_count'] ?? -1)
                    && (int) ($verticalToolOperatingRuntimeRow['ready_vertical_tool_runtime_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($verticalToolOperatingRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($verticalToolOperatingRuntimeRow['external_side_effects_enabled'] ?? true),
                'business_execution_control_plane_ready' => (bool) ($businessExecutionControlPlaneRow['business_execution_control_plane_ready'] ?? false)
                    && (int) ($businessExecutionControlPlaneRow['ready_gate_count'] ?? 0) === (int) ($businessExecutionControlPlaneRow['required_gate_count'] ?? -1)
                    && (int) ($businessExecutionControlPlaneRow['ready_business_control_plane_count'] ?? 0) === (int) ($businessExecutionControlPlaneRow['expected_business_control_plane_count'] ?? -1)
                    && (int) ($businessExecutionControlPlaneRow['ready_business_control_plane_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($businessExecutionControlPlaneRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($businessExecutionControlPlaneRow['external_side_effects_enabled'] ?? true)
                    && ! (bool) ($businessExecutionControlPlaneRow['real_money_movement_allowed'] ?? true)
                    && ! (bool) ($businessExecutionControlPlaneRow['customer_commitment_allowed'] ?? true),
                'quality_compliance_lifecycle_ready' => (bool) ($qualityComplianceLifecycleRow['quality_compliance_lifecycle_ready'] ?? false)
                    && (int) ($qualityComplianceLifecycleRow['ready_gate_count'] ?? 0) === (int) ($qualityComplianceLifecycleRow['required_gate_count'] ?? -1)
                    && (int) ($qualityComplianceLifecycleRow['ready_flow_quality_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($qualityComplianceLifecycleRow['external_benchmark_claim_allowed'] ?? true)
                    && ! (bool) ($qualityComplianceLifecycleRow['promotion_without_replay_allowed'] ?? true)
                    && ! (bool) ($qualityComplianceLifecycleRow['unsupported_quality_claim_allowed'] ?? true),
                'holding_outcome_scorecard_ready' => (bool) ($holdingOutcomeScorecardRow['ready'] ?? false)
                    && (float) ($holdingOutcomeScorecardRow['score'] ?? 0.0) >= 9.0
                    && (int) ($holdingOutcomeScorecardRow['completed_outcome_flow_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($holdingOutcomeScorecardRow['measured_kpi_count'] ?? 0) >= $expectedFlowCount
                    || $structuralProductionRuntimeReady,
                'portfolio_decision_packet_ready' => ((bool) ($portfolioDecisionPacketRow['ready'] ?? false)
                    && ($portfolioDecisionPacketRow['decision_recommendation'] ?? null) === 'scale_internal_supervised_capacity'
                    && (bool) ($portfolioDecisionPacketRow['real_capital_action_allowed'] ?? true) === false)
                    || $structuralProductionRuntimeReady,
                'company_board_operating_review_ready' => ((bool) ($companyBoardOperatingReviewRow['ready'] ?? false)
                    && (bool) data_get($companyBoardOperatingReviewRow, 'external_commitment_controls.external_customer_commitment_allowed', true) === false
                    && (bool) data_get($companyBoardOperatingReviewRow, 'external_commitment_controls.real_capital_action_allowed', true) === false)
                    || $structuralProductionRuntimeReady,
                'company_operating_cycle_ready' => ((bool) ($operatingCycleRow['operating_cycle_ready'] ?? false)
                    && (int) ($operatingCycleRow['ready_flow_cycle_count'] ?? 0) === (int) ($operatingCycleRow['flow_cycle_count'] ?? -1)
                    && (int) ($operatingCycleRow['ready_runtime_gate_count'] ?? 0) === (int) ($operatingCycleRow['required_runtime_gate_count'] ?? -1))
                    || $structuralProductionRuntimeReady,
                'company_operating_cadence_ready' => ((bool) ($operatingCadenceRow['cadence_ready'] ?? false)
                    && (int) ($operatingCadenceRow['ready_gate_count'] ?? 0) === (int) ($operatingCadenceRow['required_gate_count'] ?? -1)
                    && (int) ($operatingCadenceRow['ready_cadence_count'] ?? 0) === (int) ($operatingCadenceRow['cadence_count'] ?? -1))
                    || $structuralProductionRuntimeReady,
                'company_operating_scorecard_ready' => ((bool) ($operatingScorecardRow['scorecard_ready'] ?? false)
                    && (float) ($operatingScorecardRow['internal_outcome_score'] ?? 0.0) >= 9.0
                    && (bool) data_get($operatingScorecardRow, 'notional_internal_pnl.external_revenue_claim_allowed', true) === false
                    && (bool) data_get($operatingScorecardRow, 'notional_internal_pnl.real_money_movement_allowed', true) === false)
                    || $structuralProductionRuntimeReady,
                'supervised_cutover_chain_complete' => (bool) ($completionRow['supervised_cutover_receipt_chain_complete'] ?? false),
                'manual_handoff_pack_ready' => (bool) ($completionRow['manual_handoff_pack_ready'] ?? false),
                'real_external_execution_dossier_ready' => (bool) ($completionRow['real_external_execution_dossier_ready'] ?? false),
                'external_launch_control_ready' => $expectedFlowCount > 0
                    && (bool) ($integrationGates['external_launch_control_ready'] ?? false),
                'external_receipt_binders_ready' => $expectedFlowCount > 0
                    && (bool) ($integrationGates['external_receipt_binders_ready'] ?? false),
                'external_execution_blocked' => ! (bool) ($completionRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($activeOperatingSystemRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($catalogRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($integrationRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($operatingCycleRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($operatingCadenceRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($operatingScorecardRow['external_execution_allowed'] ?? false)
                    && (int) data_get($completion, 'summary.external_execution_allowed_count', 0) === 0
                    && (int) data_get($integrationReadiness, 'summary.external_execution_allowed_count', 0) === 0
                    && (int) data_get($operatingCycle, 'summary.external_execution_allowed_count', 0) === 0
                    && (int) data_get($operatingCadence, 'summary.external_execution_allowed_count', 0) === 0
                    && (int) data_get($operatingScorecard, 'summary.external_execution_allowed_count', 0) === 0,
            ];

            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_production_readiness_certification_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'production_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'production_readiness_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_enterprise_production_ready_external_launch_blocked'
                    : 'enterprise_production_readiness_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'production_readiness_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'source_hashes' => [
                    'completion_certification_record_hash' => $completionRow['completion_certification_record_hash'] ?? null,
                    'vertical_operational_depth_record_hash' => $verticalDepthRow['vertical_operational_depth_record_hash'] ?? null,
                    'customer_account_revenue_runtime_status_hash' => $customerAccountRevenue['customer_account_revenue_runtime_status_hash'] ?? null,
                    'productized_service_runtime_status_hash' => $productizedService['productized_service_runtime_status_hash'] ?? null,
                    'sales_crm_pipeline_runtime_status_hash' => $salesCrmPipeline['sales_crm_pipeline_runtime_status_hash'] ?? null,
                    'customer_support_service_desk_runtime_status_hash' => $customerSupportServiceDesk['customer_support_service_desk_runtime_status_hash'] ?? null,
                    'marketing_growth_engine_runtime_status_hash' => $marketingGrowthEngine['marketing_growth_engine_runtime_status_hash'] ?? null,
                    'finance_treasury_billing_runtime_status_hash' => $financeTreasuryBilling['finance_treasury_billing_runtime_status_hash'] ?? null,
                    'governance_risk_operations_runtime_status_hash' => $governanceRiskOperations['governance_risk_operations_runtime_status_hash'] ?? null,
                    'unit_economics_capacity_runtime_status_hash' => $unitEconomicsCapacity['unit_economics_capacity_runtime_status_hash'] ?? null,
                    'business_operating_packet_runtime_status_hash' => $businessOperatingPacket['business_operating_packet_runtime_status_hash'] ?? null,
                    'flow_live_read_connector_probe_runtime_status_hash' => $flowLiveReadConnectorProbe['flow_live_read_connector_probe_runtime_status_hash'] ?? null,
                    'active_operating_system_record_hash' => $activeOperatingSystemRow['active_operating_system_record_hash'] ?? null,
                    'company_capability_catalog_record_hash' => $catalogRow['company_capability_catalog_record_hash'] ?? null,
                    'company_integration_readiness_record_hash' => $integrationRow['company_integration_readiness_record_hash'] ?? null,
                    'company_domain_operating_model_certification_record_hash' => $domainOperatingModelRow['company_domain_operating_model_certification_record_hash'] ?? null,
                    'company_agent_operations_pack_record_hash' => $agentOperationsPackRow['company_agent_operations_pack_record_hash'] ?? null,
                    'company_agent_workforce_runtime_status_record_hash' => $agentWorkforceRuntimeRow['company_agent_workforce_runtime_status_record_hash'] ?? null,
                    'company_domain_tool_execution_readiness_record_hash' => $domainToolExecutionRow['company_domain_tool_execution_readiness_record_hash'] ?? null,
                    'company_flow_tool_execution_ledger_record_hash' => $flowToolExecutionLedgerRow['company_flow_tool_execution_ledger_record_hash'] ?? null,
                    'company_flow_tool_execution_runtime_status_record_hash' => $flowToolExecutionRuntimeRow['company_flow_tool_execution_runtime_status_record_hash'] ?? null,
                    'company_domain_adapter_execution_envelope_status_record_hash' => $domainAdapterEnvelopeRow['company_domain_adapter_execution_envelope_status_record_hash'] ?? null,
                    'company_operational_execution_loop_record_hash' => $operationalExecutionLoopRow['company_operational_execution_loop_record_hash'] ?? null,
                    'company_work_product_acceptance_evidence_record_hash' => $workProductAcceptanceEvidenceRow['company_work_product_acceptance_evidence_record_hash'] ?? null,
                    'company_work_product_runtime_status_record_hash' => $workProductRuntimeRow['company_work_product_runtime_status_record_hash'] ?? null,
                    'company_operating_blueprint_runtime_status_record_hash' => $operatingBlueprintRuntimeRow['company_operating_blueprint_runtime_status_record_hash'] ?? null,
                    'company_business_runtime_persistence_status_record_hash' => $businessRuntimePersistenceRow['company_business_runtime_persistence_status_record_hash'] ?? null,
                    'company_capability_runtime_mesh_status_record_hash' => $capabilityRuntimeMeshRow['company_capability_runtime_mesh_status_record_hash'] ?? null,
                    'company_supervised_connector_execution_status_record_hash' => $supervisedConnectorExecutionRow['company_supervised_connector_execution_status_record_hash'] ?? null,
                    'company_external_tool_activation_work_order_status_record_hash' => $externalToolActivationWorkOrdersRow['company_external_tool_activation_work_order_status_record_hash'] ?? null,
                    'company_external_tool_activation_packet_status_record_hash' => $externalToolActivationPacketsRow['company_external_tool_activation_packet_status_record_hash'] ?? null,
                    'company_vertical_tool_operating_runtime_status_record_hash' => $verticalToolOperatingRuntimeRow['company_vertical_tool_operating_runtime_status_record_hash'] ?? null,
                    'company_business_execution_control_plane_status_record_hash' => $businessExecutionControlPlaneRow['company_business_execution_control_plane_status_record_hash'] ?? null,
                    'company_quality_compliance_lifecycle_record_hash' => $qualityComplianceLifecycleRow['company_quality_compliance_lifecycle_record_hash'] ?? null,
                    'holding_outcome_scorecard_record_hash' => $holdingOutcomeScorecardRow['scorecard_hash'] ?? null,
                    'portfolio_decision_packet_record_hash' => $portfolioDecisionPacketRow['decision_packet_hash'] ?? null,
                    'company_board_operating_review_hash' => $companyBoardOperatingReviewRow['board_operating_review_hash'] ?? null,
                    'company_operating_cycle_record_hash' => $operatingCycleRow['company_operating_cycle_record_hash'] ?? null,
                    'company_operating_cadence_record_hash' => $operatingCadenceRow['company_operating_cadence_record_hash'] ?? null,
                    'company_operating_scorecard_record_hash' => $operatingScorecardRow['company_operating_scorecard_record_hash'] ?? null,
                    'portfolio_readiness_record_hash' => data_get($completionRow, 'evidence_hashes.portfolio_readiness_record_hash'),
                    'company_external_launch_control_hash' => $integrationRow['company_external_launch_control_hash'] ?? null,
                    'company_external_receipt_binding_hash' => $integrationRow['company_external_receipt_binding_hash'] ?? null,
                ],
                'live_read_connector_probe_readiness' => [
                    'runtime_coverage_ready' => $runtimeLiveReadConnectorProbeReady,
                    'structural_buildout_ready' => $structuralLiveReadConnectorProbeReady,
                    'completed_probe_flow_count' => (int) ($flowLiveReadConnectorProbeRow['completed_flow_live_read_connector_probe_count'] ?? 0),
                    'external_mutations_blocked_count' => (int) ($flowLiveReadConnectorProbeRow['external_mutations_blocked_count'] ?? 0),
                    'operator_scope_required_count' => (int) ($flowLiveReadConnectorProbeRow['operator_scope_required_count'] ?? 0),
                    'external_side_effect_count' => (int) data_get($flowLiveReadConnectorProbeRow, 'external_side_effect_count', 0),
                    'structural_contract_count' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', [])),
                    'structural_evidence_matrix_count' => count((array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack.flow_probe_evidence_matrix', [])),
                    'external_mutation_allowed' => false,
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_launch_allowed' => false,
            ];
            $row['company_production_readiness_certification_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['production_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_PRODUCTION_READINESS_CERTIFICATION_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_production_readiness_certified_external_launch_still_blocked'
                : 'enterprise_company_production_readiness_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'production_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'average_production_readiness_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['production_readiness_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'quality_compliance_lifecycle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.quality_compliance_lifecycle_ready', false))),
                'agent_operations_pack_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.agent_operations_pack_ready', false))),
                'agent_workforce_runtime_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.agent_workforce_runtime_ready', false))),
                'work_product_runtime_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.work_product_runtime_persisted', false))),
                'operating_blueprint_runtime_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.operating_blueprint_runtime_persisted', false))),
                'business_runtime_persistence_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.business_runtime_persistence_ready', false))),
                'capability_runtime_mesh_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.capability_runtime_mesh_ready', false))),
                'supervised_connector_execution_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.supervised_connector_execution_ready', false))),
                'external_tool_activation_work_orders_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.external_tool_activation_work_orders_ready', false))),
                'external_tool_activation_packets_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.external_tool_activation_packets_ready', false))),
                'vertical_tool_operating_runtime_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.vertical_tool_operating_runtime_ready', false))),
                'business_execution_control_plane_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.business_execution_control_plane_ready', false))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'external_launch_allowed_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_completion_certification_status_hash' => $completion['enterprise_company_completion_certification_status_hash'] ?? null,
                'enterprise_vertical_operational_depth_status_hash' => $verticalDepth['enterprise_vertical_operational_depth_status_hash'] ?? null,
                'customer_account_revenue_runtime_status_hash' => $customerAccountRevenue['customer_account_revenue_runtime_status_hash'] ?? null,
                'productized_service_runtime_status_hash' => $productizedService['productized_service_runtime_status_hash'] ?? null,
                'sales_crm_pipeline_runtime_status_hash' => $salesCrmPipeline['sales_crm_pipeline_runtime_status_hash'] ?? null,
                'customer_support_service_desk_runtime_status_hash' => $customerSupportServiceDesk['customer_support_service_desk_runtime_status_hash'] ?? null,
                'marketing_growth_engine_runtime_status_hash' => $marketingGrowthEngine['marketing_growth_engine_runtime_status_hash'] ?? null,
                'finance_treasury_billing_runtime_status_hash' => $financeTreasuryBilling['finance_treasury_billing_runtime_status_hash'] ?? null,
                'governance_risk_operations_runtime_status_hash' => $governanceRiskOperations['governance_risk_operations_runtime_status_hash'] ?? null,
                'unit_economics_capacity_runtime_status_hash' => $unitEconomicsCapacity['unit_economics_capacity_runtime_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessOperatingPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'flow_live_read_connector_probe_runtime_status_hash' => $flowLiveReadConnectorProbe['flow_live_read_connector_probe_runtime_status_hash'] ?? null,
                'enterprise_company_active_operating_system_status_hash' => $activeOperatingSystem['enterprise_company_active_operating_system_status_hash'] ?? null,
                'enterprise_company_capability_catalog_status_hash' => $capabilityCatalog['enterprise_company_capability_catalog_status_hash'] ?? null,
                'enterprise_company_integration_readiness_status_hash' => $integrationReadiness['enterprise_company_integration_readiness_status_hash'] ?? null,
                'enterprise_company_domain_operating_model_certification_status_hash' => $domainOperatingModel['enterprise_company_domain_operating_model_certification_status_hash'] ?? null,
                'enterprise_company_agent_operations_pack_status_hash' => $agentOperationsPack['enterprise_company_agent_operations_pack_status_hash'] ?? null,
                'enterprise_company_agent_workforce_runtime_status_hash' => $agentWorkforceRuntime['enterprise_company_agent_workforce_runtime_status_hash'] ?? null,
                'enterprise_company_domain_tool_execution_readiness_status_hash' => $domainToolExecution['enterprise_company_domain_tool_execution_readiness_status_hash'] ?? null,
                'enterprise_company_flow_tool_execution_ledger_status_hash' => $flowToolExecutionLedger['enterprise_company_flow_tool_execution_ledger_status_hash'] ?? null,
                'enterprise_company_flow_tool_execution_runtime_status_hash' => $flowToolExecutionRuntime['enterprise_company_flow_tool_execution_runtime_status_hash'] ?? null,
                'enterprise_company_domain_adapter_execution_envelope_status_hash' => $domainAdapterEnvelope['enterprise_company_domain_adapter_execution_envelope_status_hash'] ?? null,
                'enterprise_company_operational_execution_loop_status_hash' => $operationalExecutionLoop['enterprise_company_operational_execution_loop_status_hash'] ?? null,
                'enterprise_company_work_product_acceptance_evidence_status_hash' => $workProductAcceptanceEvidence['enterprise_company_work_product_acceptance_evidence_status_hash'] ?? null,
                'enterprise_company_work_product_runtime_status_hash' => $workProductRuntime['enterprise_company_work_product_runtime_status_hash'] ?? null,
                'enterprise_company_operating_blueprint_runtime_status_hash' => $operatingBlueprintRuntime['enterprise_company_operating_blueprint_runtime_status_hash'] ?? null,
                'enterprise_company_business_runtime_persistence_status_hash' => $businessRuntimePersistence['enterprise_company_business_runtime_persistence_status_hash'] ?? null,
                'enterprise_company_capability_runtime_mesh_status_hash' => $capabilityRuntimeMesh['enterprise_company_capability_runtime_mesh_status_hash'] ?? null,
                'enterprise_company_supervised_connector_execution_status_hash' => $supervisedConnectorExecution['enterprise_company_supervised_connector_execution_status_hash'] ?? null,
                'enterprise_company_external_tool_activation_work_order_status_hash' => $externalToolActivationWorkOrders['enterprise_company_external_tool_activation_work_order_status_hash'] ?? null,
                'enterprise_company_external_tool_activation_packet_status_hash' => $externalToolActivationPackets['enterprise_company_external_tool_activation_packet_status_hash'] ?? null,
                'enterprise_company_vertical_tool_operating_runtime_status_hash' => $verticalToolOperatingRuntime['enterprise_company_vertical_tool_operating_runtime_status_hash'] ?? null,
                'enterprise_company_business_execution_control_plane_status_hash' => $businessExecutionControlPlane['enterprise_company_business_execution_control_plane_status_hash'] ?? null,
                'enterprise_company_quality_compliance_lifecycle_status_hash' => $qualityComplianceLifecycle['enterprise_company_quality_compliance_lifecycle_status_hash'] ?? null,
                'holding_outcome_scorecard_status_hash' => $holdingOutcomeScorecard['holding_outcome_scorecard_status_hash'] ?? null,
                'portfolio_decision_packet_status_hash' => $portfolioDecisionPacket['portfolio_decision_packet_status_hash'] ?? null,
                'company_board_operating_review_status_hash' => $companyBoardOperatingReview['company_board_operating_review_status_hash'] ?? null,
                'enterprise_company_operating_cycle_status_hash' => $operatingCycle['enterprise_company_operating_cycle_status_hash'] ?? null,
                'enterprise_company_operating_cadence_status_hash' => $operatingCadence['enterprise_company_operating_cadence_status_hash'] ?? null,
                'enterprise_company_operating_scorecard_status_hash' => $operatingScorecard['enterprise_company_operating_scorecard_status_hash'] ?? null,
                'external_supervised_cutover_portfolio_readiness_status_hash' => data_get($completion, 'source_hashes.external_supervised_cutover_portfolio_readiness_status_hash'),
                'external_launch_control_status_hash' => data_get($integrationReadiness, 'source_hashes.external_launch_control_status_hash'),
                'external_receipt_binding_status_hash' => data_get($integrationReadiness, 'source_hashes.external_receipt_binding_status_hash'),
            ],
            'companies' => $companies,
            'policy' => [
                'production_readiness_certification_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_launch_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['auto_launch', 'auto_dispatch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export', 'external_customer_commitment', 'external_revenue_claim'],
            ],
        ];
        $payload['enterprise_company_production_readiness_certification_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperatingEvidenceBundleStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $productionReadiness = $this->enterpriseCompanyProductionReadinessCertificationStatus($wantedCompany);
        $capabilityCatalog = $this->hub->enterpriseOperatingCycle->enterpriseCompanyCapabilityCatalogStatus($wantedCompany);
        $integrationReadiness = $this->hub->enterpriseOperatingCycle->enterpriseCompanyIntegrationReadinessStatus($wantedCompany);
        $domainOperatingModel = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainOperatingModelCertificationStatus($wantedCompany);
        $agentOperationsPack = $this->hub->enterpriseAgentWorkforce->enterpriseCompanyAgentOperationsPackStatus($wantedCompany);
        $agentWorkforceRuntime = $this->hub->enterpriseAgentWorkforce->enterpriseCompanyAgentWorkforceRuntimeStatus($wantedCompany);
        $domainToolExecution = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainToolExecutionReadinessStatus($wantedCompany);
        $flowToolExecutionLedger = $this->hub->enterpriseToolExecution->enterpriseCompanyFlowToolExecutionLedgerStatus($wantedCompany);
        $flowToolExecutionRuntime = $this->hub->enterpriseToolExecution->enterpriseCompanyFlowToolExecutionRuntimeStatus($wantedCompany);
        $domainAdapterEnvelope = $this->hub->enterpriseToolExecution->enterpriseCompanyDomainAdapterExecutionEnvelopeStatus($wantedCompany);
        $operationalLoop = $this->hub->enterpriseCompletion->enterpriseCompanyOperationalExecutionLoopStatus($wantedCompany);
        $workProductAcceptance = $this->hub->enterpriseCompletion->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($wantedCompany);
        $workProductRuntime = $this->hub->enterpriseWorkProductRuntime->enterpriseCompanyWorkProductRuntimeStatus($wantedCompany);
        $operatingBlueprintRuntime = $this->hub->enterpriseWorkProductRuntime->enterpriseCompanyOperatingBlueprintRuntimeStatus($wantedCompany);
        $businessRuntimePersistence = $this->hub->enterprisePersistenceControlPlane->enterpriseCompanyBusinessRuntimePersistenceStatus($wantedCompany);
        $capabilityRuntimeMesh = $this->hub->enterpriseCapabilityRuntime->enterpriseCompanyCapabilityRuntimeMeshStatus($wantedCompany);
        $supervisedConnectorExecution = $this->hub->enterpriseCapabilityRuntime->enterpriseCompanySupervisedConnectorExecutionStatus($wantedCompany);
        $externalToolActivationWorkOrders = $this->hub->enterpriseToolActivation->enterpriseCompanyExternalToolActivationWorkOrderStatus($wantedCompany);
        $externalToolActivationPackets = $this->hub->enterpriseToolActivation->enterpriseCompanyExternalToolActivationPacketStatus($wantedCompany);
        $verticalToolOperatingRuntime = $this->hub->enterpriseVerticalToolRuntime->enterpriseCompanyVerticalToolOperatingRuntimeStatus($wantedCompany);
        $businessExecutionControlPlane = $this->hub->enterprisePersistenceControlPlane->enterpriseCompanyBusinessExecutionControlPlaneStatus($wantedCompany);
        $qualityComplianceLifecycle = $this->hub->enterpriseOperatingModel->enterpriseCompanyQualityComplianceLifecycleStatus($wantedCompany);
        $industrySolutionEcosystem = $this->hub->companyOperatingStatus->industrySolutionEcosystemStatus($wantedCompany);
        $agentRepositoryAdoption = $this->hub->companyCockpit->agentRepositoryAdoptionStatus($wantedCompany);
        $agentRepositoryOperatingCatalog = $this->hub->companyCockpit->agentRepositoryOperatingCatalogStatus($wantedCompany);
        $this->hub->flowActionRuntime->clearRuntimeRecordCache($wantedCompany);
        $operationalOutcome = $this->hub->flowActionRuntime->operationalOutcomeRuntimeStatus($wantedCompany);
        $holdingOutcomeScorecard = $this->hub->flowActionRuntime->holdingOutcomeScorecardStatus($wantedCompany);
        $companyBoardOperatingReview = $this->hub->flowActionRuntime->companyBoardOperatingReviewStatus($wantedCompany);
        $operatingCycle = $this->hub->enterpriseOperatingCycle->enterpriseCompanyOperatingCycleStatus($wantedCompany);
        $operatingCadence = $this->hub->enterpriseOperatingCycle->enterpriseCompanyOperatingCadenceStatus($wantedCompany);
        $operatingScorecard = $this->hub->enterpriseOperatingCycle->enterpriseCompanyOperatingScorecardStatus($wantedCompany);
        $completion = $this->hub->enterpriseCompletion->enterpriseCompanyCompletionCertificationStatus($wantedCompany);

        $productionByCompany = $this->hub->companyRowsById($productionReadiness);
        $catalogByCompany = $this->hub->companyRowsById($capabilityCatalog);
        $integrationByCompany = $this->hub->companyRowsById($integrationReadiness);
        $domainModelByCompany = $this->hub->companyRowsById($domainOperatingModel);
        $agentOperationsPackByCompany = $this->hub->companyRowsById($agentOperationsPack);
        $agentWorkforceRuntimeByCompany = $this->hub->companyRowsById($agentWorkforceRuntime);
        $domainToolExecutionByCompany = $this->hub->companyRowsById($domainToolExecution);
        $flowToolExecutionLedgerByCompany = $this->hub->companyRowsById($flowToolExecutionLedger);
        $flowToolExecutionRuntimeByCompany = $this->hub->companyRowsById($flowToolExecutionRuntime);
        $domainAdapterEnvelopeByCompany = $this->hub->companyRowsById($domainAdapterEnvelope);
        $operationalLoopByCompany = $this->hub->companyRowsById($operationalLoop);
        $workProductByCompany = $this->hub->companyRowsById($workProductAcceptance);
        $workProductRuntimeByCompany = $this->hub->companyRowsById($workProductRuntime);
        $operatingBlueprintRuntimeByCompany = $this->hub->companyRowsById($operatingBlueprintRuntime);
        $businessRuntimePersistenceByCompany = $this->hub->companyRowsById($businessRuntimePersistence);
        $capabilityRuntimeMeshByCompany = $this->hub->companyRowsById($capabilityRuntimeMesh);
        $supervisedConnectorExecutionByCompany = $this->hub->companyRowsById($supervisedConnectorExecution);
        $externalToolActivationWorkOrdersByCompany = $this->hub->companyRowsById($externalToolActivationWorkOrders);
        $externalToolActivationPacketsByCompany = $this->hub->companyRowsById($externalToolActivationPackets);
        $verticalToolOperatingRuntimeByCompany = $this->hub->companyRowsById($verticalToolOperatingRuntime);
        $businessExecutionControlPlaneByCompany = $this->hub->companyRowsById($businessExecutionControlPlane);
        $qualityComplianceByCompany = $this->hub->companyRowsById($qualityComplianceLifecycle);
        $industrySolutionEcosystemByCompany = $this->hub->companyRowsById($industrySolutionEcosystem);
        $agentRepositoryAdoptionByCompany = $this->hub->companyRowsById($agentRepositoryAdoption);
        $agentRepositoryOperatingCatalogByCompany = $this->hub->companyRowsById($agentRepositoryOperatingCatalog);
        $operationalOutcomeByCompany = $this->hub->companyRowsById($operationalOutcome);
        $holdingOutcomeScorecardByCompany = $this->hub->companyRowsById($holdingOutcomeScorecard);
        $companyBoardOperatingReviewByCompany = $this->hub->companyRowsById($companyBoardOperatingReview);
        $operatingCycleByCompany = $this->hub->companyRowsById($operatingCycle);
        $operatingCadenceByCompany = $this->hub->companyRowsById($operatingCadence);
        $operatingScorecardByCompany = $this->hub->companyRowsById($operatingScorecard);
        $completionByCompany = $this->hub->companyRowsById($completion);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', count((array) ($company['flows'] ?? [])));
            $productionRow = (array) ($productionByCompany[$id] ?? []);
            $catalogRow = (array) ($catalogByCompany[$id] ?? []);
            $integrationRow = (array) ($integrationByCompany[$id] ?? []);
            $domainModelRow = (array) ($domainModelByCompany[$id] ?? []);
            $agentOperationsPackRow = (array) ($agentOperationsPackByCompany[$id] ?? []);
            $agentWorkforceRuntimeRow = (array) ($agentWorkforceRuntimeByCompany[$id] ?? []);
            $domainToolExecutionRow = (array) ($domainToolExecutionByCompany[$id] ?? []);
            $flowToolExecutionLedgerRow = (array) ($flowToolExecutionLedgerByCompany[$id] ?? []);
            $flowToolExecutionRuntimeRow = (array) ($flowToolExecutionRuntimeByCompany[$id] ?? []);
            $domainAdapterEnvelopeRow = (array) ($domainAdapterEnvelopeByCompany[$id] ?? []);
            $operationalLoopRow = (array) ($operationalLoopByCompany[$id] ?? []);
            $workProductRow = (array) ($workProductByCompany[$id] ?? []);
            $workProductRuntimeRow = (array) ($workProductRuntimeByCompany[$id] ?? []);
            $operatingBlueprintRuntimeRow = (array) ($operatingBlueprintRuntimeByCompany[$id] ?? []);
            $businessRuntimePersistenceRow = (array) ($businessRuntimePersistenceByCompany[$id] ?? []);
            $capabilityRuntimeMeshRow = (array) ($capabilityRuntimeMeshByCompany[$id] ?? []);
            $supervisedConnectorExecutionRow = (array) ($supervisedConnectorExecutionByCompany[$id] ?? []);
            $externalToolActivationWorkOrdersRow = (array) ($externalToolActivationWorkOrdersByCompany[$id] ?? []);
            $externalToolActivationPacketsRow = (array) ($externalToolActivationPacketsByCompany[$id] ?? []);
            $verticalToolOperatingRuntimeRow = (array) ($verticalToolOperatingRuntimeByCompany[$id] ?? []);
            $businessExecutionControlPlaneRow = (array) ($businessExecutionControlPlaneByCompany[$id] ?? []);
            $qualityComplianceRow = (array) ($qualityComplianceByCompany[$id] ?? []);
            $industrySolutionEcosystemRow = (array) ($industrySolutionEcosystemByCompany[$id] ?? []);
            $agentRepositoryAdoptionRow = (array) ($agentRepositoryAdoptionByCompany[$id] ?? []);
            $agentRepositoryOperatingCatalogRow = (array) ($agentRepositoryOperatingCatalogByCompany[$id] ?? []);
            $operationalOutcomeRow = (array) ($operationalOutcomeByCompany[$id] ?? []);
            $holdingOutcomeScorecardRow = (array) ($holdingOutcomeScorecardByCompany[$id] ?? []);
            $companyBoardOperatingReviewRow = (array) ($companyBoardOperatingReviewByCompany[$id] ?? []);
            $operatingCycleRow = (array) ($operatingCycleByCompany[$id] ?? []);
            $operatingCadenceRow = (array) ($operatingCadenceByCompany[$id] ?? []);
            $operatingScorecardRow = (array) ($operatingScorecardByCompany[$id] ?? []);
            $completionRow = (array) ($completionByCompany[$id] ?? []);
            $flowCapabilities = array_values((array) ($catalogRow['flow_capabilities'] ?? []));
            $flowIntegrations = array_values((array) ($integrationRow['flow_integrations'] ?? []));

            $readyFlowCapabilityCount = count(array_filter($flowCapabilities, static fn (array $flow): bool => (bool) ($flow['ready'] ?? false)));
            $readyFlowIntegrationCount = count(array_filter($flowIntegrations, static fn (array $flow): bool => (bool) ($flow['integration_ready'] ?? false)));
            $sourceHashes = [
                'company_production_readiness_certification_record_hash' => $productionRow['company_production_readiness_certification_record_hash'] ?? null,
                'company_capability_catalog_record_hash' => $catalogRow['company_capability_catalog_record_hash'] ?? null,
                'company_integration_readiness_record_hash' => $integrationRow['company_integration_readiness_record_hash'] ?? null,
                'company_domain_operating_model_certification_record_hash' => $domainModelRow['company_domain_operating_model_certification_record_hash'] ?? null,
                'company_agent_operations_pack_record_hash' => $agentOperationsPackRow['company_agent_operations_pack_record_hash'] ?? null,
                'company_agent_workforce_runtime_status_record_hash' => $agentWorkforceRuntimeRow['company_agent_workforce_runtime_status_record_hash'] ?? null,
                'company_domain_tool_execution_readiness_record_hash' => $domainToolExecutionRow['company_domain_tool_execution_readiness_record_hash'] ?? null,
                'company_flow_tool_execution_ledger_record_hash' => $flowToolExecutionLedgerRow['company_flow_tool_execution_ledger_record_hash'] ?? null,
                'company_flow_tool_execution_runtime_status_record_hash' => $flowToolExecutionRuntimeRow['company_flow_tool_execution_runtime_status_record_hash'] ?? null,
                'company_domain_adapter_execution_envelope_status_record_hash' => $domainAdapterEnvelopeRow['company_domain_adapter_execution_envelope_status_record_hash'] ?? null,
                'company_operational_execution_loop_record_hash' => $operationalLoopRow['company_operational_execution_loop_record_hash'] ?? null,
                'company_work_product_acceptance_evidence_record_hash' => $workProductRow['company_work_product_acceptance_evidence_record_hash'] ?? null,
                'company_work_product_runtime_status_record_hash' => $workProductRuntimeRow['company_work_product_runtime_status_record_hash'] ?? null,
                'company_operating_blueprint_runtime_status_record_hash' => $operatingBlueprintRuntimeRow['company_operating_blueprint_runtime_status_record_hash'] ?? null,
                'company_business_runtime_persistence_status_record_hash' => $businessRuntimePersistenceRow['company_business_runtime_persistence_status_record_hash'] ?? null,
                'company_capability_runtime_mesh_status_record_hash' => $capabilityRuntimeMeshRow['company_capability_runtime_mesh_status_record_hash'] ?? null,
                'company_supervised_connector_execution_status_record_hash' => $supervisedConnectorExecutionRow['company_supervised_connector_execution_status_record_hash'] ?? null,
                'company_external_tool_activation_work_order_status_record_hash' => $externalToolActivationWorkOrdersRow['company_external_tool_activation_work_order_status_record_hash'] ?? null,
                'company_external_tool_activation_packet_status_record_hash' => $externalToolActivationPacketsRow['company_external_tool_activation_packet_status_record_hash'] ?? null,
                'company_vertical_tool_operating_runtime_status_record_hash' => $verticalToolOperatingRuntimeRow['company_vertical_tool_operating_runtime_status_record_hash'] ?? null,
                'company_business_execution_control_plane_status_record_hash' => $businessExecutionControlPlaneRow['company_business_execution_control_plane_status_record_hash'] ?? null,
                'company_quality_compliance_lifecycle_record_hash' => $qualityComplianceRow['company_quality_compliance_lifecycle_record_hash'] ?? null,
                'industry_solution_ecosystem_company_hash' => $industrySolutionEcosystemRow['industry_solution_ecosystem_company_hash'] ?? null,
                'agent_repository_adoption_company_hash' => $agentRepositoryAdoptionRow['agent_repository_adoption_company_hash'] ?? null,
                'agent_repository_operating_catalog_company_hash' => $agentRepositoryOperatingCatalogRow['agent_repository_operating_catalog_company_hash'] ?? null,
                'operational_outcome_runtime_record_hash' => $operationalOutcomeRow['operational_outcome_runtime_record_hash'] ?? null,
                'holding_outcome_scorecard_record_hash' => $holdingOutcomeScorecardRow['scorecard_hash'] ?? null,
                'company_board_operating_review_hash' => $companyBoardOperatingReviewRow['board_operating_review_hash'] ?? null,
                'company_operating_cycle_record_hash' => $operatingCycleRow['company_operating_cycle_record_hash'] ?? null,
                'company_operating_cadence_record_hash' => $operatingCadenceRow['company_operating_cadence_record_hash'] ?? null,
                'company_operating_scorecard_record_hash' => $operatingScorecardRow['company_operating_scorecard_record_hash'] ?? null,
                'completion_certification_record_hash' => $completionRow['completion_certification_record_hash'] ?? null,
                'company_external_launch_control_hash' => $integrationRow['company_external_launch_control_hash'] ?? null,
                'company_external_receipt_binding_hash' => $integrationRow['company_external_receipt_binding_hash'] ?? null,
            ];
            $validSourceHashCount = count(array_filter($sourceHashes, static fn (mixed $hash): bool => is_string($hash) && strlen($hash) === 64));

            $gates = [
                'production_readiness_certified' => (bool) ($productionRow['production_ready'] ?? false)
                    && (int) ($productionRow['ready_gate_count'] ?? 0) === (int) ($productionRow['required_gate_count'] ?? -1),
                'capability_catalog_flow_records_complete' => $expectedFlowCount > 0
                    && (bool) ($catalogRow['catalog_ready'] ?? false)
                    && count($flowCapabilities) >= $expectedFlowCount
                    && $readyFlowCapabilityCount === count($flowCapabilities),
                'integration_flow_records_complete' => $expectedFlowCount > 0
                    && (bool) ($integrationRow['integration_ready'] ?? false)
                    && count($flowIntegrations) >= $expectedFlowCount
                    && $readyFlowIntegrationCount === count($flowIntegrations),
                'domain_model_and_operational_loop_bound' => (bool) ($domainModelRow['domain_operating_model_certified'] ?? false)
                    && (bool) ($operationalLoopRow['operational_execution_loop_ready'] ?? false),
                'agent_operations_bundle_bound' => (bool) ($agentOperationsPackRow['agent_operations_pack_ready'] ?? false)
                    && (int) ($agentOperationsPackRow['ready_flow_agent_operations_pack_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($agentOperationsPackRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($agentOperationsPackRow['external_side_effects_enabled'] ?? true),
                'agent_workforce_runtime_bound' => ((bool) ($agentWorkforceRuntimeRow['agent_workforce_runtime_ready'] ?? false)
                    && (int) ($agentWorkforceRuntimeRow['ready_agent_workforce_runtime_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($agentWorkforceRuntimeRow['ready_gate_count'] ?? 0) === (int) ($agentWorkforceRuntimeRow['required_gate_count'] ?? -1)
                    && ! (bool) ($agentWorkforceRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($agentWorkforceRuntimeRow['external_side_effects_enabled'] ?? true))
                    || (bool) ($productionRow['production_ready'] ?? false),
                'domain_tool_execution_bound' => (bool) ($domainToolExecutionRow['tool_execution_ready'] ?? false)
                    && (int) ($domainToolExecutionRow['ready_flow_tool_execution_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($domainToolExecutionRow['ready_gate_count'] ?? 0) === (int) ($domainToolExecutionRow['required_gate_count'] ?? -1),
                'flow_tool_execution_ledger_bound' => (bool) ($flowToolExecutionLedgerRow['flow_tool_execution_ledger_ready'] ?? false)
                    && (int) ($flowToolExecutionLedgerRow['ready_ledger_record_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($flowToolExecutionLedgerRow['ready_gate_count'] ?? 0) === (int) ($flowToolExecutionLedgerRow['required_gate_count'] ?? -1),
                'flow_tool_execution_runtime_persisted_bound' => (bool) ($flowToolExecutionRuntimeRow['persisted_runtime_ready'] ?? false)
                    && (int) ($flowToolExecutionRuntimeRow['ready_persisted_run_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($flowToolExecutionRuntimeRow['ready_gate_count'] ?? 0) === (int) ($flowToolExecutionRuntimeRow['required_gate_count'] ?? -1)
                    && ! (bool) ($flowToolExecutionRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($flowToolExecutionRuntimeRow['external_side_effects_enabled'] ?? true),
                'domain_adapter_execution_envelope_bound' => (bool) ($domainAdapterEnvelopeRow['adapter_envelope_ready'] ?? false)
                    && (int) ($domainAdapterEnvelopeRow['ready_persisted_envelope_count'] ?? 0) === (int) ($domainAdapterEnvelopeRow['expected_envelope_count'] ?? -1)
                    && (int) ($domainAdapterEnvelopeRow['ready_gate_count'] ?? 0) === (int) ($domainAdapterEnvelopeRow['required_gate_count'] ?? -1)
                    && ! (bool) ($domainAdapterEnvelopeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($domainAdapterEnvelopeRow['external_side_effects_enabled'] ?? true),
                'work_product_acceptance_bundle_bound' => (bool) ($workProductRow['work_product_acceptance_evidence_ready'] ?? false)
                    && (int) data_get($workProductRow, 'evidence_counts.acceptance_contract_count', 0) >= $expectedFlowCount
                    && (int) data_get($workProductRow, 'evidence_counts.handoff_packet_count', 0) >= $expectedFlowCount
                    && (int) data_get($workProductRow, 'evidence_counts.replay_artifact_check_count', 0) >= $expectedFlowCount,
                'work_product_runtime_persisted_bound' => (bool) ($workProductRuntimeRow['work_product_runtime_ready'] ?? false)
                    && (int) ($workProductRuntimeRow['ready_persisted_work_product_run_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($workProductRuntimeRow['ready_gate_count'] ?? 0) === (int) ($workProductRuntimeRow['required_gate_count'] ?? -1)
                    && ! (bool) ($workProductRuntimeRow['external_delivery_allowed'] ?? true)
                    && ! (bool) ($workProductRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($workProductRuntimeRow['external_side_effects_enabled'] ?? true),
                'operating_blueprint_runtime_persisted_bound' => (bool) ($operatingBlueprintRuntimeRow['operating_blueprint_runtime_ready'] ?? false)
                    && (int) ($operatingBlueprintRuntimeRow['ready_persisted_blueprint_run_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($operatingBlueprintRuntimeRow['ready_gate_count'] ?? 0) === (int) ($operatingBlueprintRuntimeRow['required_gate_count'] ?? -1)
                    && ! (bool) ($operatingBlueprintRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($operatingBlueprintRuntimeRow['external_side_effects_enabled'] ?? true),
                'business_runtime_persistence_bound' => (bool) ($businessRuntimePersistenceRow['business_runtime_persistence_ready'] ?? false)
                    && (int) ($businessRuntimePersistenceRow['ready_persisted_business_runtime_run_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($businessRuntimePersistenceRow['ready_gate_count'] ?? 0) === (int) ($businessRuntimePersistenceRow['required_gate_count'] ?? -1)
                    && ! (bool) ($businessRuntimePersistenceRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($businessRuntimePersistenceRow['external_side_effects_enabled'] ?? true),
                'capability_runtime_mesh_bound' => (bool) ($capabilityRuntimeMeshRow['capability_runtime_mesh_ready'] ?? false)
                    && (int) ($capabilityRuntimeMeshRow['ready_capability_runtime_run_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($capabilityRuntimeMeshRow['ready_gate_count'] ?? 0) === (int) ($capabilityRuntimeMeshRow['required_gate_count'] ?? -1)
                    && ! (bool) ($capabilityRuntimeMeshRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($capabilityRuntimeMeshRow['external_side_effects_enabled'] ?? true),
                'supervised_connector_execution_bound' => (bool) ($supervisedConnectorExecutionRow['supervised_connector_execution_ready'] ?? false)
                    && (int) ($supervisedConnectorExecutionRow['ready_supervised_connector_execution_run_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($supervisedConnectorExecutionRow['ready_gate_count'] ?? 0) === (int) ($supervisedConnectorExecutionRow['required_gate_count'] ?? -1)
                    && ! (bool) ($supervisedConnectorExecutionRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($supervisedConnectorExecutionRow['external_side_effects_enabled'] ?? true),
                'external_tool_activation_work_orders_bound' => (bool) ($externalToolActivationWorkOrdersRow['external_tool_activation_work_orders_ready'] ?? false)
                    && (int) ($externalToolActivationWorkOrdersRow['ready_external_tool_activation_work_order_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($externalToolActivationWorkOrdersRow['ready_gate_count'] ?? 0) === (int) ($externalToolActivationWorkOrdersRow['required_gate_count'] ?? -1)
                    && ! (bool) ($externalToolActivationWorkOrdersRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($externalToolActivationWorkOrdersRow['external_side_effects_enabled'] ?? true),
                'external_tool_activation_packets_bound' => (bool) ($externalToolActivationPacketsRow['external_tool_activation_packets_ready'] ?? false)
                    && (int) ($externalToolActivationPacketsRow['ready_external_tool_activation_packet_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($externalToolActivationPacketsRow['ready_gate_count'] ?? 0) === (int) ($externalToolActivationPacketsRow['required_gate_count'] ?? -1)
                    && ! (bool) ($externalToolActivationPacketsRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($externalToolActivationPacketsRow['external_side_effects_enabled'] ?? true),
                'vertical_tool_operating_runtime_bound' => (bool) ($verticalToolOperatingRuntimeRow['vertical_tool_operating_runtime_ready'] ?? false)
                    && (int) ($verticalToolOperatingRuntimeRow['ready_vertical_tool_runtime_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($verticalToolOperatingRuntimeRow['ready_gate_count'] ?? 0) === (int) ($verticalToolOperatingRuntimeRow['required_gate_count'] ?? -1)
                    && ! (bool) ($verticalToolOperatingRuntimeRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($verticalToolOperatingRuntimeRow['external_side_effects_enabled'] ?? true),
                'business_execution_control_plane_bound' => (bool) ($businessExecutionControlPlaneRow['business_execution_control_plane_ready'] ?? false)
                    && (int) ($businessExecutionControlPlaneRow['ready_business_control_plane_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($businessExecutionControlPlaneRow['ready_gate_count'] ?? 0) === (int) ($businessExecutionControlPlaneRow['required_gate_count'] ?? -1)
                    && ! (bool) ($businessExecutionControlPlaneRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($businessExecutionControlPlaneRow['external_side_effects_enabled'] ?? true)
                    && ! (bool) ($businessExecutionControlPlaneRow['real_money_movement_allowed'] ?? true)
                    && ! (bool) ($businessExecutionControlPlaneRow['customer_commitment_allowed'] ?? true),
                'quality_compliance_bundle_bound' => (bool) ($qualityComplianceRow['quality_compliance_lifecycle_ready'] ?? false)
                    && (int) ($qualityComplianceRow['ready_flow_quality_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($qualityComplianceRow['replay_matrix_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($qualityComplianceRow['audit_evidence_requirement_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($qualityComplianceRow['external_benchmark_claim_allowed'] ?? true),
                'industry_solution_ecosystem_bound' => (bool) ($industrySolutionEcosystemRow['ready'] ?? false)
                    && (int) ($industrySolutionEcosystemRow['flow_workload_pack_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($industrySolutionEcosystemRow['source_verification_matrix_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($industrySolutionEcosystemRow['compliance_workload_control_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($industrySolutionEcosystemRow['partner_handoff_count'] ?? 0) >= $expectedFlowCount
                    && (bool) data_get($industrySolutionEcosystemRow, 'checks.flow_source_verification_matrix_green', false)
                    && (bool) data_get($industrySolutionEcosystemRow, 'checks.flow_compliance_workload_controls_green', false)
                    && (bool) data_get($industrySolutionEcosystemRow, 'checks.implementation_partner_handoff_green', false)
                    && ! (bool) ($industrySolutionEcosystemRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($industrySolutionEcosystemRow['external_side_effects_enabled'] ?? true),
                'agent_repository_adoption_matrix_bound' => (bool) ($agentRepositoryAdoptionRow['ready'] ?? false)
                    && (int) ($agentRepositoryAdoptionRow['flow_repository_adoption_matrix_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($agentRepositoryAdoptionRow['tool_permission_manifest_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($agentRepositoryAdoptionRow['eval_replay_recipe_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($agentRepositoryAdoptionRow['license_security_review_count'] ?? 0) >= (int) ($agentRepositoryAdoptionRow['repository_intake_count'] ?? 0)
                    && (int) ($agentRepositoryAdoptionRow['runtime_boundary_review_count'] ?? 0) >= (int) ($agentRepositoryAdoptionRow['repository_intake_count'] ?? 0)
                    && (int) ($agentRepositoryAdoptionRow['operator_acceptance_contract_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($agentRepositoryAdoptionRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($agentRepositoryAdoptionRow['external_side_effects_enabled'] ?? true),
                'agent_repository_operating_catalog_bound' => (bool) ($agentRepositoryOperatingCatalogRow['ready'] ?? false)
                    && (int) ($agentRepositoryOperatingCatalogRow['framework_profile_count'] ?? 0) >= 8
                    && (int) ($agentRepositoryOperatingCatalogRow['framework_runtime_boundary_contract_count'] ?? 0) >= (int) ($agentRepositoryOperatingCatalogRow['framework_profile_count'] ?? 0)
                    && (int) ($agentRepositoryOperatingCatalogRow['framework_pattern_binding_count'] ?? 0) >= (int) ($agentRepositoryOperatingCatalogRow['framework_profile_count'] ?? 0)
                    && (int) ($agentRepositoryOperatingCatalogRow['flow_runtime_map_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($agentRepositoryOperatingCatalogRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($agentRepositoryOperatingCatalogRow['external_side_effects_enabled'] ?? true),
                'operational_outcome_runtime_bound' => $this->hub->runtimeCoverageRowReady($operationalOutcomeRow, 'completed_operational_outcome_flow_count')
                    && (int) ($operationalOutcomeRow['operational_outcome_ledger_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($operationalOutcomeRow['value_proxy_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($operationalOutcomeRow['acceptance_contract_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($operationalOutcomeRow['risk_scorecard_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($operationalOutcomeRow['next_cycle_bound_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($operationalOutcomeRow['evidence_ref_count'] ?? 0) >= ($expectedFlowCount * 4),
                'holding_outcome_scorecard_bound' => (bool) ($holdingOutcomeScorecardRow['ready'] ?? false)
                    && (int) ($holdingOutcomeScorecardRow['completed_outcome_flow_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($holdingOutcomeScorecardRow['acceptance_contract_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($holdingOutcomeScorecardRow['risk_scorecard_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($holdingOutcomeScorecardRow['next_cycle_count'] ?? 0) >= $expectedFlowCount
                    && ! (bool) ($holdingOutcomeScorecardRow['external_value_claim_allowed'] ?? true)
                    && ! (bool) ($holdingOutcomeScorecardRow['external_side_effects'] ?? true),
                'company_board_operating_review_bound' => (bool) ($companyBoardOperatingReviewRow['ready'] ?? false)
                    && (int) data_get($companyBoardOperatingReviewRow, 'continuous_improvement_backlog.next_cycle_action_count', 0) >= $expectedFlowCount
                    && (int) data_get($companyBoardOperatingReviewRow, 'continuous_improvement_backlog.risk_remediation_count', 0) >= $expectedFlowCount
                    && (bool) data_get($companyBoardOperatingReviewRow, 'continuous_improvement_backlog.bound_to_outcome_scorecard', false)
                    && ! (bool) data_get($companyBoardOperatingReviewRow, 'external_commitment_controls.external_customer_commitment_allowed', true)
                    && ! (bool) data_get($companyBoardOperatingReviewRow, 'external_commitment_controls.real_capital_action_allowed', true),
                'company_operating_cycle_bound' => (bool) ($operatingCycleRow['operating_cycle_ready'] ?? false)
                    && (int) ($operatingCycleRow['ready_flow_cycle_count'] ?? 0) >= $expectedFlowCount
                    && (int) ($operatingCycleRow['ready_runtime_gate_count'] ?? 0) === (int) ($operatingCycleRow['required_runtime_gate_count'] ?? -1)
                    && ! (bool) ($operatingCycleRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($operatingCycleRow['external_side_effects_enabled'] ?? true),
                'company_operating_cadence_bound' => (bool) ($operatingCadenceRow['cadence_ready'] ?? false)
                    && (int) ($operatingCadenceRow['ready_gate_count'] ?? 0) === (int) ($operatingCadenceRow['required_gate_count'] ?? -1)
                    && (bool) data_get($operatingCadenceRow, 'continuous_improvement_system.monthly_process_improvement_bound', false)
                    && (bool) data_get($operatingCadenceRow, 'continuous_improvement_system.incident_or_missed_cadence_postmortem_bound', false)
                    && ! (bool) ($operatingCadenceRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($operatingCadenceRow['external_side_effects_enabled'] ?? true),
                'company_operating_scorecard_bound' => (bool) ($operatingScorecardRow['scorecard_ready'] ?? false)
                    && (int) data_get($operatingScorecardRow, 'governance_and_improvement.risk_remediation_count', 0) >= $expectedFlowCount
                    && (int) data_get($operatingScorecardRow, 'governance_and_improvement.next_cycle_action_count', 0) >= $expectedFlowCount
                    && (int) data_get($operatingScorecardRow, 'governance_and_improvement.scorecard_source_hash_lineage_count', 0) >= 4
                    && ! (bool) ($operatingScorecardRow['external_execution_allowed'] ?? true)
                    && ! (bool) ($operatingScorecardRow['external_side_effects_enabled'] ?? true),
                'supervised_cutover_evidence_bound' => (bool) ($completionRow['completion_certified'] ?? false)
                    && (bool) ($completionRow['manual_handoff_pack_ready'] ?? false)
                    && (bool) ($completionRow['real_external_execution_dossier_ready'] ?? false)
                    && (bool) ($completionRow['supervised_cutover_receipt_chain_complete'] ?? false),
                'launch_and_receipt_controls_bound' => is_string($sourceHashes['company_external_launch_control_hash'])
                    && strlen($sourceHashes['company_external_launch_control_hash']) === 64
                    && is_string($sourceHashes['company_external_receipt_binding_hash'])
                    && strlen($sourceHashes['company_external_receipt_binding_hash']) === 64,
                'source_lineage_hashes_complete' => $validSourceHashCount === count($sourceHashes),
                'external_effects_blocked' => ! (bool) ($productionRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($productionRow['external_side_effects_enabled'] ?? false)
                    && ! (bool) ($productionRow['external_launch_allowed'] ?? false)
                    && ! (bool) ($catalogRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($integrationRow['external_execution_allowed'] ?? false)
                    && ! (bool) ($workProductRow['external_delivery_allowed'] ?? false),
            ];

            $readyGateCount = count(array_filter($gates));
            $evidenceSections = [
                'production_readiness',
                'capability_catalog_flow_matrix',
                'integration_readiness_flow_matrix',
                'domain_operating_model',
                'agent_operations',
                'agent_workforce_runtime',
                'domain_tool_execution',
                'flow_tool_execution_ledger',
                'flow_tool_execution_runtime',
                'domain_adapter_execution_envelope',
                'operational_execution_loop',
                'work_product_acceptance',
                'work_product_runtime',
                'operating_blueprint_runtime',
                'business_runtime_persistence',
                'capability_runtime_mesh',
                'supervised_connector_execution',
                'external_tool_activation_work_orders',
                'external_tool_activation_packets',
                'vertical_tool_operating_runtime',
                'business_execution_control_plane',
                'quality_compliance',
                'industry_solution_ecosystem',
                'agent_repository_adoption',
                'agent_repository_operating_catalog',
                'operational_outcome_runtime',
                'holding_outcome_scorecard',
                'company_board_operating_review',
                'company_operating_cycle',
                'company_operating_cadence',
                'company_operating_scorecard',
                'supervised_cutover',
                'launch_control',
                'receipt_binding',
            ];
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_operating_evidence_bundle_record.v1',
                'company_id' => $id,
                'bundle_id' => $id.'.operating_evidence_bundle.v1',
                'expected_flow_count' => $expectedFlowCount,
                'operating_evidence_bundle_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'bundle_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_company_operating_evidence_bundle_ready_external_launch_blocked'
                    : 'company_operating_evidence_bundle_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'bundle_score' => count($gates) > 0 ? round($readyGateCount / count($gates), 4) : 0.0,
                'flow_evidence' => [
                    'capability_flow_record_count' => count($flowCapabilities),
                    'ready_capability_flow_record_count' => $readyFlowCapabilityCount,
                    'integration_flow_record_count' => count($flowIntegrations),
                    'ready_integration_flow_record_count' => $readyFlowIntegrationCount,
                    'total_flow_evidence_record_count' => count($flowCapabilities) + count($flowIntegrations),
                ],
                'bundle_sections' => $evidenceSections,
                'source_hashes' => $sourceHashes,
                'source_hash_lineage_count' => $validSourceHashCount,
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_launch_allowed' => false,
            ];
            $row['company_operating_evidence_bundle_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['operating_evidence_bundle_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_EVIDENCE_BUNDLE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_operating_evidence_bundles_ready_external_launch_blocked'
                : 'enterprise_company_operating_evidence_bundles_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'operating_evidence_bundle_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'flow_evidence_record_count' => array_sum(array_map(static fn (array $company): int => (int) data_get($company, 'flow_evidence.total_flow_evidence_record_count', 0), $companies)),
                'source_hash_lineage_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['source_hash_lineage_count'] ?? 0), $companies)),
                'agent_operations_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.agent_operations_bundle_bound', false))),
                'agent_workforce_runtime_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.agent_workforce_runtime_bound', false))),
                'capability_runtime_mesh_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.capability_runtime_mesh_bound', false))),
                'supervised_connector_execution_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.supervised_connector_execution_bound', false))),
                'external_tool_activation_work_orders_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.external_tool_activation_work_orders_bound', false))),
                'external_tool_activation_packets_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.external_tool_activation_packets_bound', false))),
                'vertical_tool_operating_runtime_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.vertical_tool_operating_runtime_bound', false))),
                'business_execution_control_plane_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.business_execution_control_plane_bound', false))),
                'industry_solution_ecosystem_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.industry_solution_ecosystem_bound', false))),
                'agent_repository_adoption_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.agent_repository_adoption_matrix_bound', false))),
                'agent_repository_operating_catalog_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.agent_repository_operating_catalog_bound', false))),
                'operational_outcome_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.operational_outcome_runtime_bound', false))),
                'holding_outcome_scorecard_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.holding_outcome_scorecard_bound', false))),
                'company_board_operating_review_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.company_board_operating_review_bound', false))),
                'company_operating_cycle_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.company_operating_cycle_bound', false))),
                'company_operating_cadence_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.company_operating_cadence_bound', false))),
                'company_operating_scorecard_bundle_ready_company_count' => count(array_filter($companies, static fn (array $company): bool => (bool) data_get($company, 'gates.company_operating_scorecard_bound', false))),
                'average_bundle_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['bundle_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'external_launch_allowed_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_production_readiness_certification_status_hash' => $productionReadiness['enterprise_company_production_readiness_certification_status_hash'] ?? null,
                'enterprise_company_capability_catalog_status_hash' => $capabilityCatalog['enterprise_company_capability_catalog_status_hash'] ?? null,
                'enterprise_company_integration_readiness_status_hash' => $integrationReadiness['enterprise_company_integration_readiness_status_hash'] ?? null,
                'enterprise_company_domain_operating_model_certification_status_hash' => $domainOperatingModel['enterprise_company_domain_operating_model_certification_status_hash'] ?? null,
                'enterprise_company_agent_operations_pack_status_hash' => $agentOperationsPack['enterprise_company_agent_operations_pack_status_hash'] ?? null,
                'enterprise_company_agent_workforce_runtime_status_hash' => $agentWorkforceRuntime['enterprise_company_agent_workforce_runtime_status_hash'] ?? null,
                'enterprise_company_domain_tool_execution_readiness_status_hash' => $domainToolExecution['enterprise_company_domain_tool_execution_readiness_status_hash'] ?? null,
                'enterprise_company_flow_tool_execution_ledger_status_hash' => $flowToolExecutionLedger['enterprise_company_flow_tool_execution_ledger_status_hash'] ?? null,
                'enterprise_company_flow_tool_execution_runtime_status_hash' => $flowToolExecutionRuntime['enterprise_company_flow_tool_execution_runtime_status_hash'] ?? null,
                'enterprise_company_domain_adapter_execution_envelope_status_hash' => $domainAdapterEnvelope['enterprise_company_domain_adapter_execution_envelope_status_hash'] ?? null,
                'enterprise_company_operational_execution_loop_status_hash' => $operationalLoop['enterprise_company_operational_execution_loop_status_hash'] ?? null,
                'enterprise_company_work_product_acceptance_evidence_status_hash' => $workProductAcceptance['enterprise_company_work_product_acceptance_evidence_status_hash'] ?? null,
                'enterprise_company_work_product_runtime_status_hash' => $workProductRuntime['enterprise_company_work_product_runtime_status_hash'] ?? null,
                'enterprise_company_operating_blueprint_runtime_status_hash' => $operatingBlueprintRuntime['enterprise_company_operating_blueprint_runtime_status_hash'] ?? null,
                'enterprise_company_business_runtime_persistence_status_hash' => $businessRuntimePersistence['enterprise_company_business_runtime_persistence_status_hash'] ?? null,
                'enterprise_company_capability_runtime_mesh_status_hash' => $capabilityRuntimeMesh['enterprise_company_capability_runtime_mesh_status_hash'] ?? null,
                'enterprise_company_supervised_connector_execution_status_hash' => $supervisedConnectorExecution['enterprise_company_supervised_connector_execution_status_hash'] ?? null,
                'enterprise_company_external_tool_activation_work_order_status_hash' => $externalToolActivationWorkOrders['enterprise_company_external_tool_activation_work_order_status_hash'] ?? null,
                'enterprise_company_external_tool_activation_packet_status_hash' => $externalToolActivationPackets['enterprise_company_external_tool_activation_packet_status_hash'] ?? null,
                'enterprise_company_vertical_tool_operating_runtime_status_hash' => $verticalToolOperatingRuntime['enterprise_company_vertical_tool_operating_runtime_status_hash'] ?? null,
                'enterprise_company_business_execution_control_plane_status_hash' => $businessExecutionControlPlane['enterprise_company_business_execution_control_plane_status_hash'] ?? null,
                'enterprise_company_quality_compliance_lifecycle_status_hash' => $qualityComplianceLifecycle['enterprise_company_quality_compliance_lifecycle_status_hash'] ?? null,
                'industry_solution_ecosystem_status_hash' => $industrySolutionEcosystem['industry_solution_ecosystem_status_hash'] ?? null,
                'agent_repository_adoption_status_hash' => $agentRepositoryAdoption['agent_repository_adoption_status_hash'] ?? null,
                'agent_repository_operating_catalog_status_hash' => $agentRepositoryOperatingCatalog['agent_repository_operating_catalog_status_hash'] ?? null,
                'operational_outcome_runtime_status_hash' => $operationalOutcome['operational_outcome_runtime_status_hash'] ?? null,
                'holding_outcome_scorecard_status_hash' => $holdingOutcomeScorecard['holding_outcome_scorecard_status_hash'] ?? null,
                'company_board_operating_review_status_hash' => $companyBoardOperatingReview['company_board_operating_review_status_hash'] ?? null,
                'enterprise_company_operating_cycle_status_hash' => $operatingCycle['enterprise_company_operating_cycle_status_hash'] ?? null,
                'enterprise_company_operating_cadence_status_hash' => $operatingCadence['enterprise_company_operating_cadence_status_hash'] ?? null,
                'enterprise_company_operating_scorecard_status_hash' => $operatingScorecard['enterprise_company_operating_scorecard_status_hash'] ?? null,
                'enterprise_company_completion_certification_status_hash' => $completion['enterprise_company_completion_certification_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'operating_evidence_bundle_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_launch_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'required_bundle_sections' => ['production_readiness', 'flow_capability_matrix', 'flow_integration_matrix', 'agent_operations', 'agent_workforce_runtime', 'persisted_tool_runtime', 'domain_adapter_execution_envelope', 'acceptance_evidence', 'work_product_runtime', 'operating_blueprint_runtime', 'business_runtime_persistence', 'capability_runtime_mesh', 'supervised_connector_execution', 'external_tool_activation_work_orders', 'external_tool_activation_packets', 'vertical_tool_operating_runtime', 'business_execution_control_plane', 'quality_compliance', 'industry_solution_ecosystem', 'agent_repository_adoption', 'agent_repository_operating_catalog', 'operational_outcome_runtime', 'holding_outcome_scorecard', 'company_board_operating_review', 'company_operating_cycle', 'company_operating_cadence', 'company_operating_scorecard', 'cutover_evidence', 'launch_control', 'receipt_binding'],
                'blocked_operations' => ['auto_launch', 'auto_dispatch', 'external_write', 'external_publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'skip_operator_acceptance'],
            ],
        ];
        $payload['enterprise_company_operating_evidence_bundle_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
