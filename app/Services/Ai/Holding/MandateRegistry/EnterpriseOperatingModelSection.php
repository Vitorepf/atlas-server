<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class EnterpriseOperatingModelSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyCommercialServiceCatalogStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $customerRevenue = $this->hub->flowActionRuntime->customerAccountRevenueRuntimeStatus($wantedCompany);
        $productizedService = $this->hub->flowActionRuntime->productizedServiceRuntimeStatus($wantedCompany);
        $salesCrm = $this->hub->flowActionRuntime->salesCrmPipelineRuntimeStatus($wantedCompany);
        $supportDesk = $this->hub->flowActionRuntime->customerSupportServiceDeskRuntimeStatus($wantedCompany);
        $marketingGrowth = $this->hub->flowActionRuntime->marketingGrowthEngineRuntimeStatus($wantedCompany);
        $financeTreasury = $this->hub->flowActionRuntime->financeTreasuryBillingRuntimeStatus($wantedCompany);
        $acceptanceEvidence = $this->hub->enterpriseCompletion->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($wantedCompany);
        $customerByCompany = $this->hub->companyRowsById($customerRevenue);
        $productByCompany = $this->hub->companyRowsById($productizedService);
        $salesByCompany = $this->hub->companyRowsById($salesCrm);
        $supportByCompany = $this->hub->companyRowsById($supportDesk);
        $marketingByCompany = $this->hub->companyRowsById($marketingGrowth);
        $financeByCompany = $this->hub->companyRowsById($financeTreasury);
        $acceptanceByCompany = $this->hub->companyRowsById($acceptanceEvidence);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $expectedFlowCount = count($flows);
            $pricingPackages = array_values((array) data_get($company, 'enterprise_productized_service_stack.pricing_packaging_model', []));
            $contractCatalog = array_values((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', []));
            $customerOfferCatalog = array_values((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', []));
            $accountBillingModel = array_values((array) data_get($company, 'enterprise_account_contract_delivery_stack.billing_and_revenue_operations_model', []));

            $runtimeGates = [
                'customer_account_revenue_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($customerByCompany[$id] ?? []), 'completed_customer_account_revenue_flow_count', $expectedFlowCount),
                'productized_service_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($productByCompany[$id] ?? []), 'completed_productized_service_flow_count', $expectedFlowCount),
                'sales_crm_pipeline_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($salesByCompany[$id] ?? []), 'completed_sales_crm_pipeline_flow_count', $expectedFlowCount),
                'customer_support_service_desk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($supportByCompany[$id] ?? []), 'completed_customer_support_service_desk_flow_count', $expectedFlowCount),
                'marketing_growth_engine_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($marketingByCompany[$id] ?? []), 'completed_marketing_growth_engine_flow_count', $expectedFlowCount),
                'finance_treasury_billing_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($financeByCompany[$id] ?? []), 'completed_finance_treasury_billing_flow_count', $expectedFlowCount),
                'work_product_acceptance_evidence' => (bool) data_get($acceptanceByCompany, $id.'.work_product_acceptance_evidence_ready', false),
            ];

            $flowCatalog = [];
            foreach ($flows as $flowId) {
                $offer = $this->hub->findByFlow($company, 'enterprise_productized_service_stack.flow_service_offers', $flowId);
                $primaryWorkProduct = (string) ($offer['primary_work_product'] ?? '');
                $pricingPackage = $primaryWorkProduct !== ''
                    ? $this->hub->findByKey($pricingPackages, 'work_product', $primaryWorkProduct)
                    : [];
                $flowGates = [
                    'service_offer' => $offer !== [] && (bool) ($offer['external_customer_commitment_allowed'] ?? true) === false,
                    'delivery_blueprint' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.service_delivery_blueprints', $flowId) !== [],
                    'intake_contract' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.intake_and_qualification_contracts', $flowId) !== [],
                    'sla_success_contract' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.sla_success_contracts', $flowId) !== [],
                    'pricing_package' => $pricingPackage !== [] && (bool) ($pricingPackage['external_invoice_allowed'] ?? true) === false,
                    'crm_opportunity_route' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', $flowId) !== [],
                    'proposal_scope_packet' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', $flowId) !== [],
                    'support_lane' => $this->hub->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', $flowId) !== [],
                    'support_ticket_sla' => $this->hub->findByFlow($company, 'enterprise_customer_support_service_desk_stack.ticket_triage_and_sla_contracts', $flowId) !== [],
                    'account_onboarding_success_plan' => $this->hub->findByFlow($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', $flowId) !== [],
                    'account_review_renewal_calendar' => $this->hub->findByFlow($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', $flowId) !== [],
                    'delivery_acceptance_contract' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', $flowId) !== [],
                    'delivery_handoff_packet' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', $flowId) !== [],
                ];
                $readyGateCount = count(array_filter($flowGates));
                $flowRow = [
                    'schema' => 'atlas.ai.company.enterprise_commercial_flow_service_catalog_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'primary_work_product' => $primaryWorkProduct,
                    'service_offer_id' => (string) ($offer['offer_id'] ?? ''),
                    'pricing_package_id' => (string) ($pricingPackage['package_id'] ?? ''),
                    'ready' => $readyGateCount === count($flowGates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($flowGates),
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'external_customer_commitment_allowed' => false,
                    'external_invoice_allowed' => false,
                    'external_revenue_claim_allowed' => false,
                ];
                $flowRow['commercial_flow_service_catalog_record_hash'] = MissionCanonicalHash::sha256($flowRow);
                $flowCatalog[] = $flowRow;
            }

            $companyGates = [
                'runtime_gates_ready' => ! in_array(false, $runtimeGates, true)
                    || count(array_filter($flowCatalog, static fn (array $flow): bool => (bool) ($flow['ready'] ?? false))) === $expectedFlowCount,
                'productized_service_stack_schema' => data_get($company, 'enterprise_productized_service_stack.schema') === 'atlas.ai.company.enterprise_productized_service_stack.v1',
                'customer_market_offer_catalog' => count($customerOfferCatalog) >= 5,
                'account_contract_catalog' => count($contractCatalog) >= 5,
                'pricing_packaging_model' => count($pricingPackages) >= max(5, $expectedFlowCount),
                'account_billing_revenue_model' => count($accountBillingModel) >= 4,
                'all_flow_catalog_records_ready' => $expectedFlowCount > 0
                    && count(array_filter($flowCatalog, static fn (array $flow): bool => (bool) ($flow['ready'] ?? false))) === $expectedFlowCount,
                'public_customer_commitment_blocked' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.public_gtm_or_customer_commitment_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.external_outreach_contract_signature_or_customer_commitment_allowed', true) === false,
                'external_billing_blocked' => (bool) data_get($company, 'enterprise_productized_service_stack.product_policy.external_billing_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.account_operations_policy.external_billing_allowed', true) === false,
                'support_external_messaging_blocked' => (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.external_customer_message_or_support_commitment_allowed', true) === false,
                'finance_money_movement_blocked' => (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.real_revenue_cash_or_aum_claim_allowed', true) === false,
            ];
            $readyGateCount = count(array_filter($companyGates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_commercial_service_catalog_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'commercial_flow_count' => count($flowCatalog),
                'ready_commercial_flow_count' => count(array_filter($flowCatalog, static fn (array $flow): bool => (bool) ($flow['ready'] ?? false))),
                'service_offer_count' => count((array) data_get($company, 'enterprise_productized_service_stack.flow_service_offers', [])),
                'pricing_package_count' => count($pricingPackages),
                'intake_contract_count' => count((array) data_get($company, 'enterprise_productized_service_stack.intake_and_qualification_contracts', [])),
                'sla_success_contract_count' => count((array) data_get($company, 'enterprise_productized_service_stack.sla_success_contracts', [])),
                'crm_route_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', [])),
                'proposal_scope_packet_count' => count((array) data_get($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', [])),
                'support_lane_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', [])),
                'support_ticket_sla_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.ticket_triage_and_sla_contracts', [])),
                'account_onboarding_plan_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', [])),
                'account_review_calendar_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', [])),
                'account_contract_count' => count($contractCatalog),
                'customer_offer_count' => count($customerOfferCatalog),
                'ready_runtime_gate_count' => count(array_filter($runtimeGates)),
                'required_runtime_gate_count' => count($runtimeGates),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'commercial_service_catalog_ready' => $readyGateCount === count($companyGates),
                'commercial_service_catalog_score' => count($companyGates) > 0 ? round($readyGateCount / count($companyGates), 4) : 0.0,
                'runtime_gates' => $runtimeGates,
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'flow_catalog' => $flowCatalog,
                'external_customer_commitment_allowed' => false,
                'external_invoice_allowed' => false,
                'external_revenue_claim_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['commercial_service_catalog_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['commercial_service_catalog_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_COMMERCIAL_SERVICE_CATALOG_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_commercial_service_catalog_ready_external_revenue_blocked'
                : 'enterprise_company_commercial_service_catalog_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'commercial_service_catalog_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'commercial_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['commercial_flow_count'] ?? 0), $companies)),
                'ready_commercial_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_commercial_flow_count'] ?? 0), $companies)),
                'service_offer_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['service_offer_count'] ?? 0), $companies)),
                'pricing_package_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['pricing_package_count'] ?? 0), $companies)),
                'intake_contract_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['intake_contract_count'] ?? 0), $companies)),
                'sla_success_contract_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['sla_success_contract_count'] ?? 0), $companies)),
                'crm_route_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['crm_route_count'] ?? 0), $companies)),
                'support_lane_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['support_lane_count'] ?? 0), $companies)),
                'account_contract_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['account_contract_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'average_commercial_service_catalog_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['commercial_service_catalog_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_customer_commitment_allowed_count' => 0,
                'external_invoice_allowed_count' => 0,
                'external_revenue_claim_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'customer_account_revenue_runtime_status_hash' => $customerRevenue['customer_account_revenue_runtime_status_hash'] ?? null,
                'productized_service_runtime_status_hash' => $productizedService['productized_service_runtime_status_hash'] ?? null,
                'sales_crm_pipeline_runtime_status_hash' => $salesCrm['sales_crm_pipeline_runtime_status_hash'] ?? null,
                'customer_support_service_desk_runtime_status_hash' => $supportDesk['customer_support_service_desk_runtime_status_hash'] ?? null,
                'marketing_growth_engine_runtime_status_hash' => $marketingGrowth['marketing_growth_engine_runtime_status_hash'] ?? null,
                'finance_treasury_billing_runtime_status_hash' => $financeTreasury['finance_treasury_billing_runtime_status_hash'] ?? null,
                'enterprise_company_work_product_acceptance_evidence_status_hash' => $acceptanceEvidence['enterprise_company_work_product_acceptance_evidence_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'commercial_service_catalog_is_not_external_revenue_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_customer_commitment_allowed' => false,
                'external_invoice_allowed' => false,
                'external_revenue_claim_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_before_customer_commitment_billing_or_revenue_claim' => true,
                'required_commercial_surfaces' => ['service_offer', 'delivery_blueprint', 'intake_contract', 'sla_success_contract', 'pricing_package', 'crm_route', 'proposal_packet', 'support_lane', 'account_success_plan', 'acceptance_contract', 'handoff_packet'],
                'blocked_operations' => ['public_gtm_commitment', 'external_customer_message', 'invoice_send', 'payment_collection', 'revenue_claim', 'paid_campaign', 'contract_signature', 'real_money_movement', 'trade', 'vendor_purchase', 'deploy', 'delete'],
            ],
        ];
        $payload['enterprise_company_commercial_service_catalog_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyRevenueDeliveryOperatingMeshStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $commercialCatalog = $this->enterpriseCompanyCommercialServiceCatalogStatus($wantedCompany);
        $customerDelivery = $this->enterpriseCompanyCustomerDeliveryLifecycleStatus($wantedCompany);
        $qualityCompliance = $this->enterpriseCompanyQualityComplianceLifecycleStatus($wantedCompany);
        $businessPersistence = $this->hub->enterprisePersistenceControlPlane->enterpriseCompanyBusinessRuntimePersistenceStatus($wantedCompany);

        $commercialByCompany = $this->hub->companyRowsById($commercialCatalog);
        $deliveryByCompany = $this->hub->companyRowsById($customerDelivery);
        $qualityByCompany = $this->hub->companyRowsById($qualityCompliance);
        $businessByCompany = $this->hub->companyRowsById($businessPersistence);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $expectedFlowCount = count($flows);
            $mesh = (array) data_get($company, 'enterprise_company_revenue_delivery_operating_mesh', []);
            $operatingSystems = array_values((array) data_get($mesh, 'operating_systems', []));
            $flowThreads = array_values((array) data_get($mesh, 'flow_commercial_operating_threads', []));
            $connectorMap = array_values((array) data_get($mesh, 'connector_to_commercial_system_map', []));
            $metrics = array_values((array) data_get($mesh, 'company_board_value_scorecard.required_metrics', []));

            $threadsByFlow = [];
            foreach ($flowThreads as $thread) {
                $flowId = (string) ($thread['flow_id'] ?? '');
                if ($flowId !== '') {
                    $threadsByFlow[$flowId] = (array) $thread;
                }
            }

            $flowRecords = [];
            foreach ($flows as $flowId) {
                $thread = (array) ($threadsByFlow[$flowId] ?? []);
                $stageContracts = array_values((array) ($thread['commercial_stage_contracts'] ?? []));
                $flowGates = [
                    'thread_bound' => $thread !== [] && (string) ($thread['schema'] ?? '') === 'atlas.ai.company.flow_revenue_delivery_thread.v1',
                    'stage_contracts_cover_revenue_delivery_chain' => count($stageContracts) >= 8,
                    'handoff_chain_bound' => count((array) ($thread['handoff_chain'] ?? [])) >= 6,
                    'work_products_bound' => count((array) ($thread['work_product_refs'] ?? [])) >= 1,
                    'connectors_bound' => count((array) ($thread['connector_refs'] ?? [])) >= 1,
                    'external_effects_blocked' => count(array_filter($stageContracts, static fn (array $stage): bool => (bool) ($stage['external_effect_allowed'] ?? true))) === 0,
                    'thread_hash_bound' => strlen((string) ($thread['thread_hash'] ?? '')) === 64,
                ];
                $readyGateCount = count(array_filter($flowGates));
                $flowRecord = [
                    'schema' => 'atlas.ai.company.flow_revenue_delivery_mesh_status_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready' => $readyGateCount === count($flowGates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($flowGates),
                    'stage_contract_count' => count($stageContracts),
                    'handoff_count' => count((array) ($thread['handoff_chain'] ?? [])),
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'external_customer_commitment_allowed' => false,
                    'external_billing_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $flowRecord['flow_revenue_delivery_mesh_status_record_hash'] = MissionCanonicalHash::sha256($flowRecord);
                $flowRecords[] = $flowRecord;
            }

            $commercialRow = (array) ($commercialByCompany[$id] ?? []);
            $deliveryRow = (array) ($deliveryByCompany[$id] ?? []);
            $qualityRow = (array) ($qualityByCompany[$id] ?? []);
            $businessRow = (array) ($businessByCompany[$id] ?? []);
            $readyFlowThreadCount = count(array_filter($flowRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $structuralMeshReady = $expectedFlowCount > 0
                && $readyFlowThreadCount === $expectedFlowCount
                && count($operatingSystems) >= 8
                && count($connectorMap) >= count((array) ($company['connectors'] ?? []))
                && (bool) data_get($mesh, 'mesh_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false;
            $companyGates = [
                'mesh_schema_bound' => (string) ($mesh['schema'] ?? '') === 'atlas.ai.company.enterprise_revenue_delivery_operating_mesh.v1',
                'operating_systems_bound' => count($operatingSystems) >= 8,
                'flow_threads_cover_company_flows' => $expectedFlowCount > 0
                    && $readyFlowThreadCount === $expectedFlowCount,
                'connector_commercial_system_map_bound' => count($connectorMap) >= count((array) ($company['connectors'] ?? [])),
                'board_value_scorecard_bound' => count($metrics) >= 8,
                'commercial_catalog_ready' => (bool) ($commercialRow['commercial_service_catalog_ready'] ?? false),
                'customer_delivery_lifecycle_ready' => (bool) ($deliveryRow['customer_delivery_lifecycle_ready'] ?? false),
                'quality_compliance_lifecycle_ready' => (bool) ($qualityRow['quality_compliance_lifecycle_ready'] ?? false),
                'business_runtime_persistence_ready_or_structural_mesh_bound' => (bool) ($businessRow['business_runtime_persistence_ready'] ?? false)
                    || $structuralMeshReady,
                'customer_commitment_blocked' => (bool) data_get($mesh, 'mesh_policy.customer_commitment_allowed_without_operator', true) === false,
                'billing_or_capital_action_blocked' => (bool) data_get($mesh, 'mesh_policy.invoice_payment_or_capital_action_allowed_without_operator', true) === false,
                'external_writes_blocked' => (bool) data_get($mesh, 'mesh_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false,
            ];
            $readyGateCount = count(array_filter($companyGates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_revenue_delivery_operating_mesh_status_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'operating_system_count' => count($operatingSystems),
                'flow_thread_count' => count($flowThreads),
                'ready_flow_thread_count' => $readyFlowThreadCount,
                'connector_map_count' => count($connectorMap),
                'board_value_metric_count' => count($metrics),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'revenue_delivery_operating_mesh_ready' => $readyGateCount === count($companyGates),
                'mesh_score' => count($companyGates) > 0 ? round($readyGateCount / count($companyGates), 4) : 0.0,
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'flow_records' => $flowRecords,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'external_revenue_claim_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['revenue_delivery_operating_mesh_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['revenue_delivery_operating_mesh_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_REVENUE_DELIVERY_OPERATING_MESH_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_revenue_delivery_operating_mesh_ready_external_revenue_blocked'
                : 'enterprise_company_revenue_delivery_operating_mesh_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'revenue_delivery_operating_mesh_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'flow_thread_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_thread_count'] ?? 0), $companies)),
                'ready_flow_thread_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_thread_count'] ?? 0), $companies)),
                'operating_system_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['operating_system_count'] ?? 0), $companies)),
                'connector_map_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['connector_map_count'] ?? 0), $companies)),
                'board_value_metric_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['board_value_metric_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'average_mesh_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['mesh_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_customer_commitment_allowed_count' => 0,
                'external_billing_allowed_count' => 0,
                'external_revenue_claim_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_commercial_service_catalog_status_hash' => $commercialCatalog['enterprise_company_commercial_service_catalog_status_hash'] ?? null,
                'enterprise_company_customer_delivery_lifecycle_status_hash' => $customerDelivery['enterprise_company_customer_delivery_lifecycle_status_hash'] ?? null,
                'enterprise_company_quality_compliance_lifecycle_status_hash' => $qualityCompliance['enterprise_company_quality_compliance_lifecycle_status_hash'] ?? null,
                'enterprise_company_business_runtime_persistence_status_hash' => $businessPersistence['enterprise_company_business_runtime_persistence_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'revenue_delivery_mesh_is_not_external_revenue_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_customer_commitment_allowed' => false,
                'external_billing_allowed' => false,
                'external_revenue_claim_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_before_customer_commitment_billing_or_revenue_claim' => true,
                'required_mesh_surfaces' => ['product_catalog', 'sales_crm', 'delivery_lane', 'support_desk', 'billing_ledger', 'risk_register', 'evidence_room', 'operator_board'],
                'blocked_operations' => ['external_customer_commitment', 'invoice_send', 'payment_collection', 'revenue_claim', 'public_claim', 'paid_campaign', 'contract_signature', 'capital_transfer', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
        ];
        $payload['enterprise_company_revenue_delivery_operating_mesh_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOrgOperatingModelStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $domainAgentWorkforce = $this->hub->flowActionRuntime->domainAgentWorkforceRuntimeStatus($wantedCompany);
        $workforceCapacity = $this->hub->flowActionRuntime->workforceCapacityRuntimeStatus($wantedCompany);
        $financeTreasury = $this->hub->flowActionRuntime->financeTreasuryBillingRuntimeStatus($wantedCompany);
        $governanceRisk = $this->hub->flowActionRuntime->governanceRiskOperationsRuntimeStatus($wantedCompany);
        $unitEconomics = $this->hub->flowActionRuntime->unitEconomicsCapacityRuntimeStatus($wantedCompany);
        $businessPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $deliveryRisk = $this->hub->flowActionRuntime->deliveryRiskRuntimeStatus($wantedCompany);
        $boardReview = $this->hub->flowActionRuntime->companyBoardOperatingReviewStatus($wantedCompany);
        $scorecard = $this->hub->enterpriseOperatingCycle->enterpriseCompanyOperatingScorecardStatus($wantedCompany);
        $commercialCatalog = $this->enterpriseCompanyCommercialServiceCatalogStatus($wantedCompany);

        $domainAgentByCompany = $this->hub->companyRowsById($domainAgentWorkforce);
        $workforceByCompany = $this->hub->companyRowsById($workforceCapacity);
        $financeByCompany = $this->hub->companyRowsById($financeTreasury);
        $governanceByCompany = $this->hub->companyRowsById($governanceRisk);
        $unitByCompany = $this->hub->companyRowsById($unitEconomics);
        $businessByCompany = $this->hub->companyRowsById($businessPacket);
        $deliveryByCompany = $this->hub->companyRowsById($deliveryRisk);
        $boardByCompany = $this->hub->companyRowsById($boardReview);
        $scorecardByCompany = $this->hub->companyRowsById($scorecard);
        $commercialByCompany = $this->hub->companyRowsById($commercialCatalog);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $expectedFlowCount = count($flows);
            $agentRoles = array_values((array) ($company['agent_roles'] ?? []));
            $flowOwnerRoles = array_values(array_unique(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow) ? (string) ($flow['agent_role'] ?? '') : '',
                (array) ($company['flows'] ?? []),
            ))));
            $expectedCapacityRoleCount = max(1, count($flowOwnerRoles));
            $connectors = array_values((array) ($company['connectors'] ?? []));
            $operatingSystem = (array) data_get($company, 'enterprise_operating_system', []);
            $workforceStack = (array) data_get($company, 'enterprise_workforce_capacity_stack', []);
            $vendorStack = (array) data_get($company, 'enterprise_vendor_legal_procurement_stack', []);
            $grcStack = (array) data_get($company, 'enterprise_grc_stack', []);
            $financeStack = (array) data_get($company, 'enterprise_finance_treasury_billing_stack', []);
            $unitStack = (array) data_get($company, 'enterprise_unit_economics_capacity_simulation_stack', []);

            $runtimeGates = [
                'domain_agent_workforce_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($domainAgentByCompany[$id] ?? []), 'completed_domain_agent_workforce_flow_count', $expectedFlowCount),
                'workforce_capacity_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($workforceByCompany[$id] ?? []), 'completed_workforce_capacity_flow_count', $expectedFlowCount),
                'finance_treasury_billing_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($financeByCompany[$id] ?? []), 'completed_finance_treasury_billing_flow_count', $expectedFlowCount),
                'governance_risk_operations_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($governanceByCompany[$id] ?? []), 'completed_governance_risk_operations_flow_count', $expectedFlowCount),
                'unit_economics_capacity_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($unitByCompany[$id] ?? []), 'completed_unit_economics_capacity_flow_count', $expectedFlowCount),
                'business_operating_packet_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($businessByCompany[$id] ?? []), 'completed_business_operating_packet_flow_count', $expectedFlowCount),
                'delivery_risk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($deliveryByCompany[$id] ?? []), 'completed_delivery_risk_flow_count', $expectedFlowCount),
                'board_operating_review' => (bool) data_get($boardByCompany, $id.'.ready', false),
                'operating_scorecard' => (bool) data_get($scorecardByCompany, $id.'.scorecard_ready', false),
                'commercial_service_catalog' => (bool) data_get($commercialByCompany, $id.'.commercial_service_catalog_ready', false),
            ];

            $flowOrgRecords = [];
            foreach ($flows as $flowId) {
                $flowGates = [
                    'staffing_matrix' => $this->hub->findByFlow($company, 'enterprise_workforce_capacity_stack.flow_staffing_matrix', $flowId) !== [],
                    'procurement_routing' => $this->hub->findByFlow($company, 'enterprise_vendor_legal_procurement_stack.flow_procurement_routing', $flowId) !== [],
                    'audit_evidence_requirement' => $this->hub->findByFlow($company, 'enterprise_grc_stack.audit_evidence_requirements', $flowId) !== [],
                    'budget_envelope' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.flow_budget_envelopes', $flowId) !== [],
                    'billing_ledger_control' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', $flowId) !== [],
                    'unit_economics' => $this->hub->findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.flow_unit_economics', $flowId) !== [],
                    'capacity_simulation' => $this->hub->findByFlow($company, 'enterprise_unit_economics_capacity_simulation_stack.capacity_simulation_model', $flowId) !== [],
                    'operating_sla' => $this->hub->findByFlow($company, 'enterprise_operating_system.sla_catalog', $flowId) !== [],
                    'runbook' => $this->hub->findByFlow($company, 'enterprise_operating_system.runbooks', $flowId) !== [],
                    'delivery_risk_sla' => $this->hub->findByFlow($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', $flowId) !== [],
                ];
                $readyGateCount = count(array_filter($flowGates));
                $record = [
                    'schema' => 'atlas.ai.company.enterprise_flow_org_operating_model_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready' => $readyGateCount === count($flowGates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($flowGates),
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'external_hiring_procurement_capital_or_legal_action_allowed' => false,
                ];
                $record['flow_org_operating_model_record_hash'] = MissionCanonicalHash::sha256($record);
                $flowOrgRecords[] = $record;
            }

            $companyGates = [
                'runtime_gates_ready' => ! in_array(false, $runtimeGates, true)
                    || count(array_filter($flowOrgRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))) === $expectedFlowCount,
                'governance_board_ready' => data_get($operatingSystem, 'governance_board.cadence') === 'weekly_operating_board'
                    && data_get($operatingSystem, 'governance_board.decision_rights.external_action') === 'operator_approval_required',
                'workforce_org_model_ready' => data_get($workforceStack, 'schema') === 'atlas.ai.company.enterprise_workforce_capacity_stack.v1'
                    && (string) data_get($workforceStack, 'org_model.manager_agent') !== ''
                    && (string) data_get($workforceStack, 'org_model.independent_reviewer') !== ''
                    && data_get($workforceStack, 'org_model.operator_escalation') === 'operator',
                'agent_capacity_plan_ready' => count((array) data_get($workforceStack, 'agent_capacity_plan', [])) >= $expectedCapacityRoleCount
                    && count(array_filter((array) data_get($workforceStack, 'agent_capacity_plan', []), static fn (array $row): bool => (bool) ($row['requires_backup'] ?? false))) >= $expectedCapacityRoleCount,
                'flow_staffing_matrix_ready' => count((array) data_get($workforceStack, 'flow_staffing_matrix', [])) >= $expectedFlowCount,
                'training_enablement_ready' => count((array) data_get($workforceStack, 'training_and_enablement', [])) >= $expectedCapacityRoleCount
                    && count(array_filter((array) data_get($workforceStack, 'training_and_enablement', []), static fn (array $row): bool => (bool) ($row['certification_required_before_shadow_mode'] ?? false))) >= $expectedCapacityRoleCount,
                'succession_continuity_ready' => (bool) data_get($workforceStack, 'succession_and_continuity.single_agent_bottleneck_allowed', true) === false
                    && (bool) data_get($workforceStack, 'succession_and_continuity.manual_operator_fallback_required', false)
                    && (bool) data_get($workforceStack, 'succession_and_continuity.backup_assignment_required_for_every_flow', false),
                'vendor_legal_procurement_ready' => data_get($vendorStack, 'schema') === 'atlas.ai.company.enterprise_vendor_legal_procurement_stack.v1'
                    && data_get($vendorStack, 'procurement_policy.purchase_authority') === 'operator_only_for_real_spend'
                    && count((array) data_get($vendorStack, 'vendor_due_diligence_register', [])) >= count($connectors)
                    && count((array) data_get($vendorStack, 'flow_procurement_routing', [])) >= $expectedFlowCount,
                'grc_controls_ready' => data_get($grcStack, 'schema') === 'atlas.ai.company.enterprise_grc_stack.v1'
                    && count((array) data_get($grcStack, 'vendor_and_tool_risk', [])) >= count($connectors)
                    && count((array) data_get($grcStack, 'audit_evidence_requirements', [])) >= $expectedFlowCount
                    && count((array) data_get($grcStack, 'control_framework.control_sets', [])) >= 4
                    && (bool) data_get($grcStack, 'control_framework.exceptions_require_operator_review', false),
                'finance_operating_controls_ready' => data_get($financeStack, 'schema') === 'atlas.ai.company.enterprise_finance_treasury_billing_stack.v1'
                    && count((array) data_get($financeStack, 'flow_budget_envelopes', [])) >= $expectedFlowCount
                    && count((array) data_get($financeStack, 'billing_ledger_controls', [])) >= $expectedFlowCount
                    && (bool) data_get($financeStack, 'finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true) === false,
                'unit_economics_capacity_ready' => data_get($unitStack, 'schema') === 'atlas.ai.company.enterprise_unit_economics_capacity_simulation_stack.v1'
                    && count((array) data_get($unitStack, 'flow_unit_economics', [])) >= $expectedFlowCount
                    && count((array) data_get($unitStack, 'capacity_simulation_model', [])) >= $expectedFlowCount
                    && (bool) data_get($unitStack, 'economics_policy.synthetic_financial_claims_allowed', true) === false
                    && (bool) data_get($unitStack, 'economics_policy.external_spend_default', true) === false
                    && (bool) data_get($unitStack, 'economics_policy.real_pricing_or_capital_commitment_requires_operator_approval', false),
                'all_flow_org_records_ready' => $expectedFlowCount > 0
                    && count(array_filter($flowOrgRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))) === $expectedFlowCount,
            ];
            $readyGateCount = count(array_filter($companyGates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_org_operating_model_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'ready_flow_org_record_count' => count(array_filter($flowOrgRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))),
                'flow_org_record_count' => count($flowOrgRecords),
                'agent_role_count' => count($agentRoles),
                'flow_owner_role_count' => $expectedCapacityRoleCount,
                'connector_count' => count($connectors),
                'agent_capacity_plan_count' => count((array) data_get($workforceStack, 'agent_capacity_plan', [])),
                'flow_staffing_count' => count((array) data_get($workforceStack, 'flow_staffing_matrix', [])),
                'training_enablement_count' => count((array) data_get($workforceStack, 'training_and_enablement', [])),
                'vendor_due_diligence_count' => count((array) data_get($vendorStack, 'vendor_due_diligence_register', [])),
                'procurement_routing_count' => count((array) data_get($vendorStack, 'flow_procurement_routing', [])),
                'audit_evidence_requirement_count' => count((array) data_get($grcStack, 'audit_evidence_requirements', [])),
                'control_set_count' => count((array) data_get($grcStack, 'control_framework.control_sets', [])),
                'budget_envelope_count' => count((array) data_get($financeStack, 'flow_budget_envelopes', [])),
                'billing_ledger_control_count' => count((array) data_get($financeStack, 'billing_ledger_controls', [])),
                'capacity_simulation_count' => count((array) data_get($unitStack, 'capacity_simulation_model', [])),
                'ready_runtime_gate_count' => count(array_filter($runtimeGates)),
                'required_runtime_gate_count' => count($runtimeGates),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'org_operating_model_ready' => $readyGateCount === count($companyGates),
                'org_operating_model_score' => count($companyGates) > 0 ? round($readyGateCount / count($companyGates), 4) : 0.0,
                'runtime_gates' => $runtimeGates,
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'flow_org_records' => $flowOrgRecords,
                'external_hiring_allowed' => false,
                'external_procurement_allowed' => false,
                'external_legal_signature_allowed' => false,
                'real_capital_action_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_org_operating_model_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['org_operating_model_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_ORG_OPERATING_MODEL_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_org_operating_models_ready_external_corporate_actions_blocked'
                : 'enterprise_company_org_operating_models_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'org_operating_model_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'ready_flow_org_record_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_org_record_count'] ?? 0), $companies)),
                'flow_org_record_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_org_record_count'] ?? 0), $companies)),
                'agent_capacity_plan_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['agent_capacity_plan_count'] ?? 0), $companies)),
                'flow_staffing_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_staffing_count'] ?? 0), $companies)),
                'training_enablement_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['training_enablement_count'] ?? 0), $companies)),
                'vendor_due_diligence_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['vendor_due_diligence_count'] ?? 0), $companies)),
                'procurement_routing_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['procurement_routing_count'] ?? 0), $companies)),
                'audit_evidence_requirement_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['audit_evidence_requirement_count'] ?? 0), $companies)),
                'budget_envelope_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['budget_envelope_count'] ?? 0), $companies)),
                'billing_ledger_control_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['billing_ledger_control_count'] ?? 0), $companies)),
                'capacity_simulation_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['capacity_simulation_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'average_org_operating_model_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['org_operating_model_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_hiring_allowed_count' => 0,
                'external_procurement_allowed_count' => 0,
                'external_legal_signature_allowed_count' => 0,
                'real_capital_action_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'domain_agent_workforce_runtime_status_hash' => $domainAgentWorkforce['domain_agent_workforce_runtime_status_hash'] ?? null,
                'workforce_capacity_runtime_status_hash' => $workforceCapacity['workforce_capacity_runtime_status_hash'] ?? null,
                'finance_treasury_billing_runtime_status_hash' => $financeTreasury['finance_treasury_billing_runtime_status_hash'] ?? null,
                'governance_risk_operations_runtime_status_hash' => $governanceRisk['governance_risk_operations_runtime_status_hash'] ?? null,
                'unit_economics_capacity_runtime_status_hash' => $unitEconomics['unit_economics_capacity_runtime_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'delivery_risk_runtime_status_hash' => $deliveryRisk['delivery_risk_runtime_status_hash'] ?? null,
                'company_board_operating_review_status_hash' => $boardReview['company_board_operating_review_status_hash'] ?? null,
                'enterprise_company_operating_scorecard_status_hash' => $scorecard['enterprise_company_operating_scorecard_status_hash'] ?? null,
                'enterprise_company_commercial_service_catalog_status_hash' => $commercialCatalog['enterprise_company_commercial_service_catalog_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'org_operating_model_is_not_corporate_action_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_hiring_allowed' => false,
                'external_procurement_allowed' => false,
                'external_legal_signature_allowed' => false,
                'real_capital_action_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_before_hiring_procurement_legal_signature_or_capital_action' => true,
                'required_operating_surfaces' => ['governance_board', 'workforce_org_model', 'capacity_plan', 'staffing_matrix', 'training_enablement', 'succession_continuity', 'vendor_legal_procurement', 'grc_controls', 'finance_controls', 'unit_economics', 'board_review'],
                'blocked_operations' => ['hire_or_terminate', 'sign_contract', 'start_paid_plan', 'share_secret', 'grant_write_scope', 'commit_external_spend', 'capital_transfer', 'trade', 'public_claim', 'deploy', 'delete'],
            ],
        ];
        $payload['enterprise_company_org_operating_model_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyCustomerDeliveryLifecycleStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $customerRevenue = $this->hub->flowActionRuntime->customerAccountRevenueRuntimeStatus($wantedCompany);
        $productizedService = $this->hub->flowActionRuntime->productizedServiceRuntimeStatus($wantedCompany);
        $salesCrm = $this->hub->flowActionRuntime->salesCrmPipelineRuntimeStatus($wantedCompany);
        $supportDesk = $this->hub->flowActionRuntime->customerSupportServiceDeskRuntimeStatus($wantedCompany);
        $marketingGrowth = $this->hub->flowActionRuntime->marketingGrowthEngineRuntimeStatus($wantedCompany);
        $financeTreasury = $this->hub->flowActionRuntime->financeTreasuryBillingRuntimeStatus($wantedCompany);
        $businessPacket = $this->hub->flowActionRuntime->businessOperatingPacketRuntimeStatus($wantedCompany);
        $deliveryRisk = $this->hub->flowActionRuntime->deliveryRiskRuntimeStatus($wantedCompany);
        $operationalOutcome = $this->hub->flowActionRuntime->operationalOutcomeRuntimeStatus($wantedCompany);
        $commercialCatalog = $this->enterpriseCompanyCommercialServiceCatalogStatus($wantedCompany);
        $orgOperatingModel = $this->enterpriseCompanyOrgOperatingModelStatus($wantedCompany);

        $customerByCompany = $this->hub->companyRowsById($customerRevenue);
        $productByCompany = $this->hub->companyRowsById($productizedService);
        $salesByCompany = $this->hub->companyRowsById($salesCrm);
        $supportByCompany = $this->hub->companyRowsById($supportDesk);
        $marketingByCompany = $this->hub->companyRowsById($marketingGrowth);
        $financeByCompany = $this->hub->companyRowsById($financeTreasury);
        $businessByCompany = $this->hub->companyRowsById($businessPacket);
        $deliveryByCompany = $this->hub->companyRowsById($deliveryRisk);
        $outcomeByCompany = $this->hub->companyRowsById($operationalOutcome);
        $commercialByCompany = $this->hub->companyRowsById($commercialCatalog);
        $orgByCompany = $this->hub->companyRowsById($orgOperatingModel);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $expectedFlowCount = count($flows);

            $runtimeGates = [
                'customer_account_revenue_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($customerByCompany[$id] ?? []), 'completed_customer_account_revenue_flow_count', $expectedFlowCount),
                'productized_service_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($productByCompany[$id] ?? []), 'completed_productized_service_flow_count', $expectedFlowCount),
                'sales_crm_pipeline_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($salesByCompany[$id] ?? []), 'completed_sales_crm_pipeline_flow_count', $expectedFlowCount),
                'customer_support_service_desk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($supportByCompany[$id] ?? []), 'completed_customer_support_service_desk_flow_count', $expectedFlowCount),
                'marketing_growth_engine_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($marketingByCompany[$id] ?? []), 'completed_marketing_growth_engine_flow_count', $expectedFlowCount),
                'finance_treasury_billing_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($financeByCompany[$id] ?? []), 'completed_finance_treasury_billing_flow_count', $expectedFlowCount),
                'business_operating_packet_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($businessByCompany[$id] ?? []), 'completed_business_operating_packet_flow_count', $expectedFlowCount),
                'delivery_risk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($deliveryByCompany[$id] ?? []), 'completed_delivery_risk_flow_count', $expectedFlowCount),
                'operational_outcome_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($outcomeByCompany[$id] ?? []), 'completed_operational_outcome_flow_count', $expectedFlowCount),
                'commercial_service_catalog' => (bool) data_get($commercialByCompany, $id.'.commercial_service_catalog_ready', false),
                'org_operating_model' => (bool) data_get($orgByCompany, $id.'.org_operating_model_ready', false),
            ];

            $flowLifecycle = [];
            foreach ($flows as $flowId) {
                $flowGates = [
                    'market_journey_lifecycle' => $this->hub->findByFlow($company, 'enterprise_customer_market_operations_stack.journey_and_lifecycle_map', $flowId) !== [],
                    'service_offer' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.flow_service_offers', $flowId) !== [],
                    'service_intake' => $this->hub->findByFlow($company, 'enterprise_productized_service_stack.intake_and_qualification_contracts', $flowId) !== [],
                    'crm_route' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.flow_opportunity_routes', $flowId) !== [],
                    'proposal_scope_packet' => $this->hub->findByFlow($company, 'enterprise_sales_crm_pipeline_stack.proposal_and_scope_packets', $flowId) !== [],
                    'marketing_campaign' => $this->hub->findByFlow($company, 'enterprise_marketing_growth_engine_stack.flow_campaign_blueprints', $flowId) !== [],
                    'account_onboarding_success_plan' => $this->hub->findByFlow($company, 'enterprise_account_contract_delivery_stack.onboarding_success_plans', $flowId) !== [],
                    'service_review_renewal_calendar' => $this->hub->findByFlow($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', $flowId) !== [],
                    'support_lane' => $this->hub->findByFlow($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', $flowId) !== [],
                    'support_ticket_sla' => $this->hub->findByFlow($company, 'enterprise_customer_support_service_desk_stack.ticket_triage_and_sla_contracts', $flowId) !== [],
                    'billing_ledger_control' => $this->hub->findByFlow($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', $flowId) !== [],
                    'delivery_acceptance_contract' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', $flowId) !== [],
                    'delivery_handoff_packet' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', $flowId) !== [],
                    'delivery_sla' => $this->hub->findByFlow($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', $flowId) !== [],
                    'business_operating_packet' => $this->hub->findByFlow($company, 'enterprise_flow_operating_packages.flow_packages', $flowId) !== [],
                ];
                $readyGateCount = count(array_filter($flowGates));
                $record = [
                    'schema' => 'atlas.ai.company.enterprise_flow_customer_delivery_lifecycle_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready' => $readyGateCount === count($flowGates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($flowGates),
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'external_customer_commitment_allowed' => false,
                    'external_invoice_allowed' => false,
                    'external_support_message_allowed' => false,
                    'external_revenue_claim_allowed' => false,
                ];
                $record['flow_customer_delivery_lifecycle_record_hash'] = MissionCanonicalHash::sha256($record);
                $flowLifecycle[] = $record;
            }

            $companyGates = [
                'runtime_gates_ready' => ! in_array(false, $runtimeGates, true)
                    || count(array_filter($flowLifecycle, static fn (array $record): bool => (bool) ($record['ready'] ?? false))) === $expectedFlowCount,
                'customer_market_stack_ready' => data_get($company, 'enterprise_customer_market_operations_stack.schema') === 'atlas.ai.company.enterprise_customer_market_operations_stack.v1'
                    && count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_customer_market_operations_stack.customer_success_scorecard', [])) >= 4
                    && (bool) data_get($company, 'enterprise_customer_market_operations_stack.market_operations_guardrails.external_side_effects_default', true) === false,
                'account_contract_delivery_ready' => data_get($company, 'enterprise_account_contract_delivery_stack.schema') === 'atlas.ai.company.enterprise_account_contract_delivery_stack.v1'
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])) >= 5
                    && count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])) >= $expectedFlowCount
                    && (bool) data_get($company, 'enterprise_account_contract_delivery_stack.account_operations_policy.external_customer_commitment_allowed', true) === false,
                'delivery_assurance_ready' => data_get($company, 'enterprise_delivery_assurance_stack.schema') === 'atlas.ai.company.enterprise_delivery_assurance_stack.v1'
                    && count((array) data_get($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', [])) >= $expectedFlowCount
                    && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_intake_contract.reject_when_missing_acceptance_criteria', false)
                    && (bool) data_get($company, 'enterprise_delivery_assurance_stack.delivery_risk_controls.external_side_effects_default', true) === false,
                'all_flow_lifecycle_records_ready' => $expectedFlowCount > 0
                    && count(array_filter($flowLifecycle, static fn (array $record): bool => (bool) ($record['ready'] ?? false))) === $expectedFlowCount,
                'external_customer_actions_blocked' => (bool) data_get($company, 'enterprise_sales_crm_pipeline_stack.sales_policy.external_outreach_contract_signature_or_customer_commitment_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_customer_support_service_desk_stack.support_policy.external_customer_message_or_support_commitment_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_finance_treasury_billing_stack.finance_policy.external_invoice_payment_collection_capital_transfer_or_trade_allowed', true) === false,
            ];
            $readyGateCount = count(array_filter($companyGates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_customer_delivery_lifecycle_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'ready_flow_lifecycle_count' => count(array_filter($flowLifecycle, static fn (array $record): bool => (bool) ($record['ready'] ?? false))),
                'flow_lifecycle_count' => count($flowLifecycle),
                'customer_offer_count' => count((array) data_get($company, 'enterprise_customer_market_operations_stack.offer_and_packaging_catalog', [])),
                'account_contract_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.contract_and_entitlement_catalog', [])),
                'account_health_risk_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.account_health_and_risk_register', [])),
                'service_review_renewal_count' => count((array) data_get($company, 'enterprise_account_contract_delivery_stack.service_review_and_renewal_calendar', [])),
                'support_lane_count' => count((array) data_get($company, 'enterprise_customer_support_service_desk_stack.flow_support_lanes', [])),
                'billing_ledger_control_count' => count((array) data_get($company, 'enterprise_finance_treasury_billing_stack.billing_ledger_controls', [])),
                'ready_runtime_gate_count' => count(array_filter($runtimeGates)),
                'required_runtime_gate_count' => count($runtimeGates),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'customer_delivery_lifecycle_ready' => $readyGateCount === count($companyGates),
                'customer_delivery_lifecycle_score' => count($companyGates) > 0 ? round($readyGateCount / count($companyGates), 4) : 0.0,
                'runtime_gates' => $runtimeGates,
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'flow_lifecycle' => $flowLifecycle,
                'external_customer_commitment_allowed' => false,
                'external_invoice_allowed' => false,
                'external_support_message_allowed' => false,
                'external_revenue_claim_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_customer_delivery_lifecycle_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['customer_delivery_lifecycle_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_CUSTOMER_DELIVERY_LIFECYCLE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_customer_delivery_lifecycles_ready_external_customer_actions_blocked'
                : 'enterprise_company_customer_delivery_lifecycles_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'customer_delivery_lifecycle_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'ready_flow_lifecycle_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_lifecycle_count'] ?? 0), $companies)),
                'flow_lifecycle_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_lifecycle_count'] ?? 0), $companies)),
                'customer_offer_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['customer_offer_count'] ?? 0), $companies)),
                'account_contract_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['account_contract_count'] ?? 0), $companies)),
                'account_health_risk_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['account_health_risk_count'] ?? 0), $companies)),
                'service_review_renewal_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['service_review_renewal_count'] ?? 0), $companies)),
                'support_lane_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['support_lane_count'] ?? 0), $companies)),
                'billing_ledger_control_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['billing_ledger_control_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'average_customer_delivery_lifecycle_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['customer_delivery_lifecycle_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_customer_commitment_allowed_count' => 0,
                'external_invoice_allowed_count' => 0,
                'external_support_message_allowed_count' => 0,
                'external_revenue_claim_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'customer_account_revenue_runtime_status_hash' => $customerRevenue['customer_account_revenue_runtime_status_hash'] ?? null,
                'productized_service_runtime_status_hash' => $productizedService['productized_service_runtime_status_hash'] ?? null,
                'sales_crm_pipeline_runtime_status_hash' => $salesCrm['sales_crm_pipeline_runtime_status_hash'] ?? null,
                'customer_support_service_desk_runtime_status_hash' => $supportDesk['customer_support_service_desk_runtime_status_hash'] ?? null,
                'marketing_growth_engine_runtime_status_hash' => $marketingGrowth['marketing_growth_engine_runtime_status_hash'] ?? null,
                'finance_treasury_billing_runtime_status_hash' => $financeTreasury['finance_treasury_billing_runtime_status_hash'] ?? null,
                'business_operating_packet_runtime_status_hash' => $businessPacket['business_operating_packet_runtime_status_hash'] ?? null,
                'delivery_risk_runtime_status_hash' => $deliveryRisk['delivery_risk_runtime_status_hash'] ?? null,
                'operational_outcome_runtime_status_hash' => $operationalOutcome['operational_outcome_runtime_status_hash'] ?? null,
                'enterprise_company_commercial_service_catalog_status_hash' => $commercialCatalog['enterprise_company_commercial_service_catalog_status_hash'] ?? null,
                'enterprise_company_org_operating_model_status_hash' => $orgOperatingModel['enterprise_company_org_operating_model_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'customer_delivery_lifecycle_is_not_external_customer_action_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_customer_commitment_allowed' => false,
                'external_invoice_allowed' => false,
                'external_support_message_allowed' => false,
                'external_revenue_claim_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_before_external_customer_delivery_billing_support_or_revenue_claim' => true,
                'required_lifecycle_surfaces' => ['market_journey', 'service_offer', 'intake', 'crm_route', 'proposal', 'marketing_campaign', 'account_onboarding', 'support_lane', 'billing_control', 'acceptance', 'handoff', 'outcome'],
                'blocked_operations' => ['external_outreach', 'customer_commitment', 'support_message', 'invoice_send', 'payment_collection', 'revenue_claim', 'paid_campaign', 'public_case_study', 'contract_signature', 'real_money_movement'],
            ],
        ];
        $payload['enterprise_company_customer_delivery_lifecycle_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyQualityComplianceLifecycleStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $qualityResearch = $this->hub->companyOperatingStatus->flowQualityResearchStatus($wantedCompany);
        $benchmarkReplay = $this->hub->flowActionRuntime->flowBenchmarkReplayRuntimeStatus($wantedCompany);
        $workProductDelivery = $this->hub->flowActionRuntime->flowWorkProductDeliveryRuntimeStatus($wantedCompany);
        $domainExecutionSuite = $this->hub->flowActionRuntime->domainCompanyExecutionSuiteRuntimeStatus($wantedCompany);
        $connectorPreflight = $this->hub->flowActionRuntime->connectorCertificationPreflightRuntimeStatus($wantedCompany);
        $governanceRisk = $this->hub->flowActionRuntime->governanceRiskOperationsRuntimeStatus($wantedCompany);
        $deliveryRisk = $this->hub->flowActionRuntime->deliveryRiskRuntimeStatus($wantedCompany);
        $operationalOutcome = $this->hub->flowActionRuntime->operationalOutcomeRuntimeStatus($wantedCompany);
        $acceptanceEvidence = $this->hub->enterpriseCompletion->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($wantedCompany);

        $qualityByCompany = $this->hub->companyRowsById($qualityResearch);
        $benchmarkByCompany = $this->hub->companyRowsById($benchmarkReplay);
        $workProductByCompany = $this->hub->companyRowsById($workProductDelivery);
        $domainSuiteByCompany = $this->hub->companyRowsById($domainExecutionSuite);
        $connectorByCompany = $this->hub->companyRowsById($connectorPreflight);
        $governanceByCompany = $this->hub->companyRowsById($governanceRisk);
        $deliveryRiskByCompany = $this->hub->companyRowsById($deliveryRisk);
        $outcomeByCompany = $this->hub->companyRowsById($operationalOutcome);
        $acceptanceByCompany = $this->hub->companyRowsById($acceptanceEvidence);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (mixed $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $expectedFlowCount = count($flows);

            $runtimeGates = [
                'flow_quality_research' => (bool) data_get($qualityByCompany, $id.'.ready', false),
                'benchmark_replay_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($benchmarkByCompany[$id] ?? []), 'completed_benchmark_replay_flow_count', $expectedFlowCount),
                'work_product_delivery_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($workProductByCompany[$id] ?? []), 'completed_flow_work_product_delivery_count', $expectedFlowCount),
                'domain_execution_suite_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($domainSuiteByCompany[$id] ?? []), 'completed_domain_company_execution_suite_flow_count', $expectedFlowCount),
                'connector_preflight_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($connectorByCompany[$id] ?? []), 'completed_connector_certification_preflight_flow_count', $expectedFlowCount),
                'governance_risk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($governanceByCompany[$id] ?? []), 'completed_governance_risk_operations_flow_count', $expectedFlowCount),
                'delivery_risk_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($deliveryRiskByCompany[$id] ?? []), 'completed_delivery_risk_flow_count', $expectedFlowCount),
                'operational_outcome_runtime' => $this->hub->companyRuntimeCoverageReady((array) ($outcomeByCompany[$id] ?? []), 'completed_operational_outcome_flow_count', $expectedFlowCount),
                'work_product_acceptance_evidence' => (bool) data_get($acceptanceByCompany, $id.'.work_product_acceptance_evidence_ready', false),
            ];

            $flowQualityRecords = [];
            foreach ($flows as $flowId) {
                $flowGates = [
                    'offline_dataset_contract' => $this->hub->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.offline_dataset_contracts', $flowId) !== [],
                    'trace_grading_rubric' => $this->hub->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.trace_grading_rubrics', $flowId) !== [],
                    'adversarial_regression_case' => $this->hub->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.adversarial_regression_cases', $flowId) !== [],
                    'deterministic_state_assertion' => $this->hub->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.deterministic_state_assertions', $flowId) !== [],
                    'replay_comparison_matrix' => $this->hub->findByFlow($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', $flowId) !== [],
                    'tooling_benchmark' => $this->hub->findByFlow($company, 'enterprise_tooling_research_stack.per_flow_tooling_benchmark', $flowId) !== [],
                    'domain_execution_risk_control' => $this->hub->findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_risk_control_packets', $flowId) !== [],
                    'domain_replay_eval_pack' => $this->hub->findByFlow($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_replay_and_eval_packs', $flowId) !== [],
                    'work_product_replay_check' => $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', $flowId) !== [],
                    'grc_audit_evidence_requirement' => $this->hub->findByFlow($company, 'enterprise_grc_stack.audit_evidence_requirements', $flowId) !== [],
                    'connector_fixture_eval_suite' => $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.connector_fixture_eval_suites', $flowId) !== [],
                    'delivery_risk_sla' => $this->hub->findByFlow($company, 'enterprise_delivery_assurance_stack.flow_delivery_sla', $flowId) !== [],
                ];
                $readyGateCount = count(array_filter($flowGates));
                $record = [
                    'schema' => 'atlas.ai.company.enterprise_flow_quality_compliance_lifecycle_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'ready' => $readyGateCount === count($flowGates),
                    'ready_gate_count' => $readyGateCount,
                    'required_gate_count' => count($flowGates),
                    'gates' => $flowGates,
                    'missing_gates' => array_values(array_keys(array_filter($flowGates, static fn (bool $ready): bool => ! $ready))),
                    'external_benchmark_claim_allowed' => false,
                    'promotion_without_replay_allowed' => false,
                    'unsupported_quality_claim_allowed' => false,
                ];
                $record['flow_quality_compliance_lifecycle_record_hash'] = MissionCanonicalHash::sha256($record);
                $flowQualityRecords[] = $record;
            }

            $companyGates = [
                'runtime_gates_ready' => ! in_array(false, $runtimeGates, true)
                    || count(array_filter($flowQualityRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))) === $expectedFlowCount,
                'benchmark_policy_ready' => data_get($company, 'enterprise_flow_benchmark_replay_stack.schema') === 'atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1'
                    && (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.synthetic_score_claims_allowed', true) === false
                    && (bool) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_policy.promotion_without_replay_green_allowed', true) === false,
                'domain_expert_review_ready' => count((array) data_get($company, 'enterprise_domain_solution_stack.domain_expert_review_board.review_modes', [])) >= 3,
                'quality_observability_ready' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.benchmark_observability.required_metrics', [])) >= 5,
                'grc_control_framework_ready' => count((array) data_get($company, 'enterprise_grc_stack.control_framework.control_sets', [])) >= 4
                    && (bool) data_get($company, 'enterprise_grc_stack.control_framework.exceptions_require_operator_review', false),
                'all_flow_quality_records_ready' => $expectedFlowCount > 0
                    && count(array_filter($flowQualityRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))) === $expectedFlowCount,
            ];
            $readyGateCount = count(array_filter($companyGates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_quality_compliance_lifecycle_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'ready_flow_quality_count' => count(array_filter($flowQualityRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false))),
                'flow_quality_record_count' => count($flowQualityRecords),
                'offline_dataset_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.offline_dataset_contracts', [])),
                'trace_rubric_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.trace_grading_rubrics', [])),
                'adversarial_case_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.adversarial_regression_cases', [])),
                'deterministic_assertion_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.deterministic_state_assertions', [])),
                'replay_matrix_count' => count((array) data_get($company, 'enterprise_flow_benchmark_replay_stack.replay_and_comparison_matrix', [])),
                'audit_evidence_requirement_count' => count((array) data_get($company, 'enterprise_grc_stack.audit_evidence_requirements', [])),
                'work_product_replay_check_count' => count((array) data_get($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', [])),
                'domain_replay_eval_pack_count' => count((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.flow_domain_replay_and_eval_packs', [])),
                'ready_runtime_gate_count' => count(array_filter($runtimeGates)),
                'required_runtime_gate_count' => count($runtimeGates),
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($companyGates),
                'quality_compliance_lifecycle_ready' => $readyGateCount === count($companyGates),
                'quality_compliance_lifecycle_score' => count($companyGates) > 0 ? round($readyGateCount / count($companyGates), 4) : 0.0,
                'runtime_gates' => $runtimeGates,
                'gates' => $companyGates,
                'missing_gates' => array_values(array_keys(array_filter($companyGates, static fn (bool $ready): bool => ! $ready))),
                'flow_quality_records' => $flowQualityRecords,
                'external_benchmark_claim_allowed' => false,
                'promotion_without_replay_allowed' => false,
                'unsupported_quality_claim_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_quality_compliance_lifecycle_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['quality_compliance_lifecycle_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_QUALITY_COMPLIANCE_LIFECYCLE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_quality_compliance_lifecycles_ready_external_quality_claims_blocked'
                : 'enterprise_company_quality_compliance_lifecycles_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'quality_compliance_lifecycle_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'ready_flow_quality_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_flow_quality_count'] ?? 0), $companies)),
                'flow_quality_record_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['flow_quality_record_count'] ?? 0), $companies)),
                'offline_dataset_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['offline_dataset_count'] ?? 0), $companies)),
                'trace_rubric_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['trace_rubric_count'] ?? 0), $companies)),
                'adversarial_case_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['adversarial_case_count'] ?? 0), $companies)),
                'deterministic_assertion_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['deterministic_assertion_count'] ?? 0), $companies)),
                'replay_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['replay_matrix_count'] ?? 0), $companies)),
                'audit_evidence_requirement_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['audit_evidence_requirement_count'] ?? 0), $companies)),
                'work_product_replay_check_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['work_product_replay_check_count'] ?? 0), $companies)),
                'domain_replay_eval_pack_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['domain_replay_eval_pack_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'average_quality_compliance_lifecycle_score' => $companies !== [] ? round(array_sum(array_map(static fn (array $company): float => (float) ($company['quality_compliance_lifecycle_score'] ?? 0.0), $companies)) / count($companies), 4) : 0.0,
                'external_benchmark_claim_allowed_count' => 0,
                'promotion_without_replay_allowed_count' => 0,
                'unsupported_quality_claim_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'flow_quality_research_status_hash' => $qualityResearch['flow_quality_research_status_hash'] ?? null,
                'flow_benchmark_replay_runtime_status_hash' => $benchmarkReplay['flow_benchmark_replay_runtime_status_hash'] ?? null,
                'flow_work_product_delivery_runtime_status_hash' => $workProductDelivery['flow_work_product_delivery_runtime_status_hash'] ?? null,
                'domain_company_execution_suite_runtime_status_hash' => $domainExecutionSuite['domain_company_execution_suite_runtime_status_hash'] ?? null,
                'connector_certification_preflight_runtime_status_hash' => $connectorPreflight['connector_certification_preflight_runtime_status_hash'] ?? null,
                'governance_risk_operations_runtime_status_hash' => $governanceRisk['governance_risk_operations_runtime_status_hash'] ?? null,
                'delivery_risk_runtime_status_hash' => $deliveryRisk['delivery_risk_runtime_status_hash'] ?? null,
                'operational_outcome_runtime_status_hash' => $operationalOutcome['operational_outcome_runtime_status_hash'] ?? null,
                'enterprise_company_work_product_acceptance_evidence_status_hash' => $acceptanceEvidence['enterprise_company_work_product_acceptance_evidence_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => [
                'quality_compliance_lifecycle_is_not_external_benchmark_claim_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_benchmark_claim_allowed' => false,
                'promotion_without_replay_allowed' => false,
                'unsupported_quality_claim_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_signed_scope_required_before_external_quality_benchmark_or_compliance_claim' => true,
                'required_quality_surfaces' => ['offline_dataset', 'trace_rubric', 'adversarial_regression', 'deterministic_assertion', 'replay_matrix', 'tooling_benchmark', 'domain_risk_control', 'domain_replay_eval', 'work_product_replay', 'grc_audit_evidence'],
                'blocked_operations' => ['external_benchmark_claim', 'promotion_without_green_replay', 'unsupported_quality_claim', 'skip_audit_evidence', 'skip_source_lineage', 'public_compliance_claim', 'deploy', 'delete'],
            ],
        ];
        $payload['enterprise_company_quality_compliance_lifecycle_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
