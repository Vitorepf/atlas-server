<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Models\AiHoldingEnterpriseFlowOperationsRunbook;
use App\Models\AiHoldingEnterpriseFlowRunQueueItem;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Models\AiOperatorApproval;
use App\Models\AtlasToolRun;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class RealExecutionChainSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function realExternalExecutionReadinessDossier(?string $companyId = null): array
    {
        $cockpit = $this->hub->companyCockpit->activationCockpit($companyId);
        $provider = $this->hub->companyCockpit->providerWorkbenchStatus($companyId);
        $repositoryAdoption = $this->hub->companyCockpit->agentRepositoryAdoptionStatus($companyId);
        $repositoryOperatingCatalog = $this->hub->companyCockpit->agentRepositoryOperatingCatalogStatus($companyId);
        $domainToolchain = $this->hub->companyOperatingStatus->domainAgentToolchainCertificationStatus($companyId);
        $industryEcosystem = $this->hub->companyOperatingStatus->industrySolutionEcosystemStatus($companyId);
        $businessBackbone = $this->hub->companyOperatingStatus->businessOperatingBackboneStatus($companyId);
        $productionPreflight = $this->hub->companyOperatingStatus->productionConnectorPreflightStatus($companyId);
        $flowQuality = $this->hub->companyOperatingStatus->flowQualityResearchStatus($companyId);
        $verticalSuites = $this->hub->companyOperatingStatus->verticalSolutionSuiteStatus($companyId);
        $businessExecutionMesh = $this->hub->companyOperatingStatus->domainBusinessExecutionMeshStatus($companyId);
        $flowPackages = $this->hub->companyOperatingStatus->flowOperatingPackageStatus($companyId);
        $commandCenter = $this->hub->companyOperatingStatus->companyCommandCenterStatus($companyId);
        $rehearsal = $this->hub->companyOperatingStatus->operationalDressRehearsalStatus($companyId);
        $connectorStatus = $this->hub->activationBacklog->connectorActivationStatus($companyId);
        $liveRead = $this->hub->activationBacklog->liveReadConnectorReadinessStatus($companyId);
        $operationsRunbooks = $this->hub->flowRunQueue->flowOperationsRunbookStatus($companyId);

        $providerByCompany = [];
        foreach ((array) ($provider['companies'] ?? []) as $company) {
            $providerByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $repositoryAdoptionByCompany = [];
        foreach ((array) ($repositoryAdoption['companies'] ?? []) as $company) {
            $repositoryAdoptionByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $repositoryOperatingCatalogByCompany = [];
        foreach ((array) ($repositoryOperatingCatalog['companies'] ?? []) as $company) {
            $repositoryOperatingCatalogByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }
        $domainToolchainByCompany = [];
        foreach ((array) ($domainToolchain['companies'] ?? []) as $company) {
            $domainToolchainByCompany[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
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
            $repositoryOperatingCatalogCompany = (array) ($repositoryOperatingCatalogByCompany[$companyIdValue] ?? []);
            $domainToolchainCompany = (array) ($domainToolchainByCompany[$companyIdValue] ?? []);
            $industryEcosystemCompany = (array) ($industryEcosystemByCompany[$companyIdValue] ?? []);
            $businessBackboneCompany = (array) ($businessBackboneByCompany[$companyIdValue] ?? []);
            $productionPreflightCompany = (array) ($productionPreflightByCompany[$companyIdValue] ?? []);
            $flowQualityCompany = (array) ($flowQualityByCompany[$companyIdValue] ?? []);
            $verticalSuiteCompany = (array) ($verticalSuitesByCompany[$companyIdValue] ?? []);
            $businessExecutionMeshCompany = (array) ($businessExecutionMeshByCompany[$companyIdValue] ?? []);
            $flowPackageCompany = (array) ($flowPackagesByCompany[$companyIdValue] ?? []);
            $commandCenterCompany = (array) ($commandCenterByCompany[$companyIdValue] ?? []);
            $repositoryAdoptionReady = (bool) ($repositoryAdoptionCompany['ready'] ?? false);
            $repositoryOperatingCatalogReady = (bool) ($repositoryOperatingCatalogCompany['ready'] ?? false);
            $domainToolchainReady = (bool) ($domainToolchainCompany['ready'] ?? false)
                && (int) ($domainToolchainCompany['ready_gate_count'] ?? 0) === (int) ($domainToolchainCompany['required_gate_count'] ?? -1);
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
                    $repositoryOperatingCatalogReady,
                    $domainToolchainReady,
                    $industryEcosystemReady,
                    $repositoryAdoptionCompany,
                    $repositoryOperatingCatalogCompany,
                    $domainToolchainCompany,
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
                'agent_repository_operating_catalog_ready' => $repositoryOperatingCatalogReady,
                'domain_agent_toolchain_certified' => $domainToolchainReady,
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
                && (bool) ($repositoryOperatingCatalog['ok'] ?? false)
                && (bool) ($domainToolchain['ok'] ?? false)
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
            'schema' => ExternalActionMandateRegistryService::REAL_EXTERNAL_EXECUTION_READINESS_DOSSIER_SCHEMA,
            'status' => 'real_external_execution_dossier_ready_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($flowRows),
                'provider_ready_company_count' => (int) data_get($provider, 'summary.ready_company_count', 0),
                'agent_repository_ready_company_count' => (int) data_get($repositoryAdoption, 'summary.ready_company_count', 0),
                'agent_repository_operating_catalog_ready_company_count' => (int) data_get($repositoryOperatingCatalog, 'summary.ready_company_count', 0),
                'domain_agent_toolchain_certified_company_count' => (int) data_get($domainToolchain, 'summary.ready_company_count', 0),
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
                    'agent_repository_operating_catalog_ready',
                    'domain_agent_toolchain_certified',
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
                'agent_repository_operating_catalog_status_hash' => $repositoryOperatingCatalog['agent_repository_operating_catalog_status_hash'] ?? null,
                'domain_agent_toolchain_certification_status_hash' => $domainToolchain['domain_agent_toolchain_certification_status_hash'] ?? null,
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
            'schema' => ExternalActionMandateRegistryService::REAL_EXTERNAL_EXECUTION_HANDOFF_PACK_SCHEMA,
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
                'agent_repository_controls_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) data_get($packet, 'agent_repository_controls.adoption_ready', false)
                    && (bool) data_get($packet, 'agent_repository_controls.operating_catalog_ready', false)
                    && (bool) data_get($packet, 'agent_repository_controls.toolchain_certified', false))),
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
            'schema' => ExternalActionMandateRegistryService::SUPERVISED_EXTERNAL_EXECUTION_PACKET_STATUS_SCHEMA,
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
                'agent_repository_controls_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) data_get($packet, 'agent_repository_controls.adoption_ready', false)
                    && (bool) data_get($packet, 'agent_repository_controls.operating_catalog_ready', false)
                    && (bool) data_get($packet, 'agent_repository_controls.toolchain_certified', false))),
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
                'agent_repository_gate_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'agent_repository_gate.bound', false)
                    && (bool) data_get($preflight, 'agent_repository_gate.adoption_ready', false)
                    && (bool) data_get($preflight, 'agent_repository_gate.operating_catalog_ready', false)
                    && (bool) data_get($preflight, 'agent_repository_gate.toolchain_certified', false))),
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
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_WORKER_PREFLIGHT_STATUS_SCHEMA,
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
                'agent_repository_gate_bound_count' => count(array_filter($preflights, static fn (array $preflight): bool => (bool) data_get($preflight, 'agent_repository_gate.bound', false)
                    && (bool) data_get($preflight, 'agent_repository_gate.adoption_ready', false)
                    && (bool) data_get($preflight, 'agent_repository_gate.operating_catalog_ready', false)
                    && (bool) data_get($preflight, 'agent_repository_gate.toolchain_certified', false))),
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
     * @param array<string,mixed> $flow
     * @param list<array<string,mixed>> $connectorRows
     * @param list<array<string,mixed>> $liveReadRows
     * @param array<string,mixed> $operationsRunbook
     * @return array<string,mixed>
     */
    public function realExternalExecutionFlowDossier(
        string $companyId,
        array $flow,
        bool $providerReady,
        bool $repositoryAdoptionReady,
        bool $repositoryOperatingCatalogReady,
        bool $domainToolchainReady,
        bool $industryEcosystemReady,
        array $repositoryAdoptionCompany,
        array $repositoryOperatingCatalogCompany,
        array $domainToolchainCompany,
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
        if (! $repositoryOperatingCatalogReady) {
            $missing[] = 'agent_repository_operating_catalog_not_ready';
        }
        if (! $domainToolchainReady) {
            $missing[] = 'domain_agent_toolchain_not_certified';
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
            $repositoryOperatingCatalogReady,
            $domainToolchainReady,
            $industryEcosystemReady,
            $repositoryAdoptionCompany,
            $repositoryOperatingCatalogCompany,
            $domainToolchainCompany,
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
                'agent_repository_operating_catalog_not_ready',
                'domain_agent_toolchain_not_certified',
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
            'agent_repository_operating_catalog_ready' => $repositoryOperatingCatalogReady,
            'domain_agent_toolchain_certified' => $domainToolchainReady,
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
                    'agent_repository_operating_catalog_status_hash',
                    'domain_agent_toolchain_certification_status_hash',
                    'repository_license_security_sbom_review_receipt',
                    'repository_tool_permission_manifest_receipt',
                    'repository_eval_replay_recipe_receipt',
                    'domain_toolchain_certification_receipt',
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
    public function realExternalExecutionHandoffPackForFlow(
        string $companyId,
        array $flow,
        bool $providerReady,
        bool $repositoryAdoptionReady,
        bool $repositoryOperatingCatalogReady,
        bool $domainToolchainReady,
        bool $industryEcosystemReady,
        array $repositoryAdoptionCompany,
        array $repositoryOperatingCatalogCompany,
        array $domainToolchainCompany,
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
            && $repositoryOperatingCatalogReady
            && $domainToolchainReady
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
                'flow_repository_adoption_matrix_count' => (int) ($repositoryAdoptionCompany['flow_repository_adoption_matrix_count'] ?? 0),
                'tool_permission_manifest_count' => (int) ($repositoryAdoptionCompany['tool_permission_manifest_count'] ?? 0),
                'eval_replay_recipe_count' => (int) ($repositoryAdoptionCompany['eval_replay_recipe_count'] ?? 0),
                'version_pin_count' => (int) ($repositoryAdoptionCompany['version_pin_count'] ?? 0),
                'requires_license_security_sbom_fixture_eval_and_operator_acceptance' => true,
                'runtime_use_without_local_contract_tests_allowed' => false,
                'auto_upgrade_or_procurement_allowed' => false,
            ],
            'repository_operating_catalog_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.agent_repository_operating_catalog.v1',
                'ready' => $repositoryOperatingCatalogReady,
                'company_status_hash' => $repositoryOperatingCatalogCompany['agent_repository_operating_catalog_company_hash'] ?? null,
                'source_basis_count' => (int) ($repositoryOperatingCatalogCompany['source_basis_count'] ?? 0),
                'framework_profile_count' => (int) ($repositoryOperatingCatalogCompany['framework_profile_count'] ?? 0),
                'framework_runtime_boundary_contract_count' => (int) ($repositoryOperatingCatalogCompany['framework_runtime_boundary_contract_count'] ?? 0),
                'framework_pattern_binding_count' => (int) ($repositoryOperatingCatalogCompany['framework_pattern_binding_count'] ?? 0),
                'mcp_security_profile_count' => (int) ($repositoryOperatingCatalogCompany['mcp_security_profile_count'] ?? 0),
                'flow_runtime_map_count' => (int) ($repositoryOperatingCatalogCompany['flow_runtime_map_count'] ?? 0),
                'requires_mcp_hardening_version_pin_contract_tests_trace_and_receipts' => true,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
            ],
            'domain_agent_toolchain_certification_contract' => [
                'contract_id' => $companyId.'.'.$flowId.'.domain_agent_toolchain_certification.v1',
                'ready' => $domainToolchainReady,
                'company_status_hash' => $domainToolchainCompany['domain_agent_toolchain_certification_record_hash'] ?? null,
                'certified_tool_contract_count' => (int) ($domainToolchainCompany['certified_tool_contract_count'] ?? 0),
                'repository_flow_adoption_matrix_count' => (int) ($domainToolchainCompany['repository_flow_adoption_matrix_count'] ?? 0),
                'repository_tool_permission_manifest_count' => (int) ($domainToolchainCompany['repository_tool_permission_manifest_count'] ?? 0),
                'repository_eval_replay_recipe_count' => (int) ($domainToolchainCompany['repository_eval_replay_recipe_count'] ?? 0),
                'repository_license_security_review_count' => (int) ($domainToolchainCompany['repository_license_security_review_count'] ?? 0),
                'repository_runtime_boundary_review_count' => (int) ($domainToolchainCompany['repository_runtime_boundary_review_count'] ?? 0),
                'requires_toolchain_certification_before_runtime_use' => true,
                'external_tool_side_effects_allowed' => false,
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
    public function supervisedExternalExecutionPacketForFlow(array $pack): array
    {
        $companyId = (string) ($pack['company_id'] ?? 'unknown');
        $flowId = (string) ($pack['flow_id'] ?? 'unknown');
        $ready = (bool) ($pack['ready_for_manual_handoff_gate'] ?? false)
            && (bool) data_get($pack, 'repository_adoption_contract.ready', false)
            && (bool) data_get($pack, 'repository_operating_catalog_contract.ready', false)
            && (bool) data_get($pack, 'domain_agent_toolchain_certification_contract.ready', false)
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
            'agent_repository_controls' => [
                'adoption_ready' => (bool) data_get($pack, 'repository_adoption_contract.ready', false),
                'operating_catalog_ready' => (bool) data_get($pack, 'repository_operating_catalog_contract.ready', false),
                'toolchain_certified' => (bool) data_get($pack, 'domain_agent_toolchain_certification_contract.ready', false),
                'flow_repository_adoption_matrix_count' => (int) data_get($pack, 'repository_adoption_contract.flow_repository_adoption_matrix_count', 0),
                'tool_permission_manifest_count' => (int) data_get($pack, 'repository_adoption_contract.tool_permission_manifest_count', 0),
                'eval_replay_recipe_count' => (int) data_get($pack, 'repository_adoption_contract.eval_replay_recipe_count', 0),
                'framework_runtime_boundary_contract_count' => (int) data_get($pack, 'repository_operating_catalog_contract.framework_runtime_boundary_contract_count', 0),
                'framework_pattern_binding_count' => (int) data_get($pack, 'repository_operating_catalog_contract.framework_pattern_binding_count', 0),
                'certified_tool_contract_count' => (int) data_get($pack, 'domain_agent_toolchain_certification_contract.certified_tool_contract_count', 0),
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'external_tool_side_effects_allowed' => false,
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
                'agent_repository_operating_catalog_green',
                'domain_agent_toolchain_certified',
                'repository_tool_permission_manifest_green',
                'repository_eval_replay_green',
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
                'repository_adoption_company_hash' => (string) data_get($pack, 'repository_adoption_contract.company_status_hash', ''),
                'repository_operating_catalog_company_hash' => (string) data_get($pack, 'repository_operating_catalog_contract.company_status_hash', ''),
                'domain_agent_toolchain_certification_record_hash' => (string) data_get($pack, 'domain_agent_toolchain_certification_contract.company_status_hash', ''),
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
    public function externalWorkerPreflightForPacket(array $packet): array
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
            && count($checklist) >= 14
            && count(array_filter($sourceHashes, static fn (mixed $hash): bool => strlen((string) $hash) >= 32)) >= 8
            && (bool) data_get($packet, 'agent_repository_controls.adoption_ready', false)
            && (bool) data_get($packet, 'agent_repository_controls.operating_catalog_ready', false)
            && (bool) data_get($packet, 'agent_repository_controls.toolchain_certified', false)
            && (int) data_get($packet, 'agent_repository_controls.tool_permission_manifest_count', 0) >= 1
            && (int) data_get($packet, 'agent_repository_controls.eval_replay_recipe_count', 0) >= 1
            && (bool) data_get($packet, 'agent_repository_controls.external_tool_side_effects_allowed', true) === false
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
            'agent_repository_gate' => [
                'bound' => true,
                'adoption_ready' => (bool) data_get($packet, 'agent_repository_controls.adoption_ready', false),
                'operating_catalog_ready' => (bool) data_get($packet, 'agent_repository_controls.operating_catalog_ready', false),
                'toolchain_certified' => (bool) data_get($packet, 'agent_repository_controls.toolchain_certified', false),
                'tool_permission_manifest_count' => (int) data_get($packet, 'agent_repository_controls.tool_permission_manifest_count', 0),
                'eval_replay_recipe_count' => (int) data_get($packet, 'agent_repository_controls.eval_replay_recipe_count', 0),
                'certified_tool_contract_count' => (int) data_get($packet, 'agent_repository_controls.certified_tool_contract_count', 0),
                'external_tool_side_effects_allowed' => false,
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
}
