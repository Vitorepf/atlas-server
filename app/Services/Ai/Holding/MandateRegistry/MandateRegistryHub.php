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
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService;
use App\Services\Ai\Holding\EnterpriseFlowFixtureSuiteService;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;

/**
 * GOD-DEBULK shared spine for the ExternalActionMandateRegistryService split:
 * holds the injected services, the per-instance runtime status cache, the
 * memoized buildout report, cross-family helpers, and typed references to
 * every section (wired by the facade constructor).
 */
class MandateRegistryHub
{
    /**
     * @var array<string,array<string,mixed>>
     */
    public array $runtimeStatusCache = [];

    /**
     * @var array<string,mixed>|null
     */
    private ?array $buildoutReport = null;

    public MandateApprovalSection $approval;
    public CompanyCockpitSection $companyCockpit;
    public ConnectorReadinessSection $connectorReadiness;
    public CompanyOperatingStatusSection $companyOperatingStatus;
    public RealExecutionChainSection $realExecutionChain;
    public LaunchReceiptChainSection $launchReceiptChain;
    public CutoverWorkOrderSection $cutoverWorkOrder;
    public CutoverCloseoutSection $cutoverCloseout;
    public EnterpriseCompletionSection $enterpriseCompletion;
    public EnterpriseWorkProductRuntimeSection $enterpriseWorkProductRuntime;
    public EnterprisePersistenceControlPlaneSection $enterprisePersistenceControlPlane;
    public EnterpriseCapabilityRuntimeSection $enterpriseCapabilityRuntime;
    public EnterpriseToolActivationSection $enterpriseToolActivation;
    public EnterpriseVerticalToolRuntimeSection $enterpriseVerticalToolRuntime;
    public EnterpriseToolExecutionSection $enterpriseToolExecution;
    public EnterpriseOperatingModelSection $enterpriseOperatingModel;
    public EnterpriseOperatingCycleSection $enterpriseOperatingCycle;
    public EnterpriseAgentWorkforceSection $enterpriseAgentWorkforce;
    public EnterpriseProductionEvidenceSection $enterpriseProductionEvidence;
    public ActivationBacklogSection $activationBacklog;
    public FlowRunQueueSection $flowRunQueue;

    public function __construct(
        public readonly EnterpriseFlowFixtureSuiteService $fixtureSuite,
        public readonly AutonomousHoldingEnterpriseBuildoutService $buildout,
        public readonly EnterpriseFlowFixtureActionRuntimeService $flowActionRuntime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildoutReport(): array
    {
        if ($this->buildoutReport === null) {
            $this->buildoutReport = $this->buildout->report();
        }

        return $this->buildoutReport;
    }

    public function companyReadinessStatusPayload(
        array $companyRows,
        string $schema,
        string $readyStatus,
        string $attentionStatus,
        array $summary,
        array $policy,
        string $hashKey,
        array $sourceHashes = [],
    ): array {
        $readyCompanies = count(array_filter($companyRows, static fn (array $company): bool => (bool) $company['ready']));

        $payload = [
            'ok' => $companyRows !== [] && $readyCompanies === count($companyRows),
            'schema' => $schema,
            'status' => $companyRows !== [] && $readyCompanies === count($companyRows)
                ? $readyStatus
                : $attentionStatus,
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'ready_company_count' => $readyCompanies,
                ...$summary,
            ],
            ...($sourceHashes === [] ? [] : ['source_hashes' => $sourceHashes]),
            'policy' => $policy,
            'companies' => $companyRows,
        ];
        $payload[$hashKey] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function runtimeCoverageRowReady(array $row, string $completedFlowCountKey): bool
    {
        $expectedFlowCount = (int) ($row['expected_flow_count'] ?? 0);

        return $expectedFlowCount > 0
            && (int) ($row[$completedFlowCountKey] ?? 0) >= $expectedFlowCount
            && (float) ($row['coverage_rate'] ?? 0.0) >= 1.0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function connectorActivationRows(string $companyId, string $flowId): array
    {
        return AiHoldingConnectorActivationRecord::query()
            ->where('company_id', $companyId)
            ->where('flow_id', $flowId)
            ->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->activationBacklog->connectorActivationPayload($record))
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function flowOperatingPackage(string $companyId, string $flowId): array
    {
        try {
            $company = $this->buildout->companyPacket($companyId);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return $this->findByFlow($company, 'enterprise_flow_operating_packages.flow_packages', $flowId);
    }

    /**
     * @return array<string,mixed>
     */
    public function flowWorkloadAgentTemplate(string $companyId, string $flowId): array
    {
        try {
            $company = $this->buildout->companyPacket($companyId);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return $this->findByFlow($company, 'enterprise_domain_workload_agent_template_stack.workload_agent_templates', $flowId);
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function findByFlow(array $company, string $path, string $flowId): array
    {
        foreach ((array) data_get($company, $path, []) as $item) {
            if (($item['flow_id'] ?? null) === $flowId) {
                return (array) $item;
            }
        }

        return [];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function findByKey(array $items, string $key, string $value): array
    {
        foreach ($items as $item) {
            if (($item[$key] ?? null) === $value) {
                return (array) $item;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $value
     */
    public function hashIfPresent(array $value): ?string
    {
        return $value !== [] ? MissionCanonicalHash::sha256($value) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function buildoutCompanies(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if ($wantedCompany !== null) {
            return [$this->buildout->companyPacket($wantedCompany)];
        }

        return array_values((array) ($this->buildoutReport()['companies'] ?? []));
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,array<string,mixed>>
     */
    public function companyRowsById(array $status): array
    {
        $rows = [];
        foreach ((array) ($status['companies'] ?? []) as $company) {
            $rows[(string) ($company['company_id'] ?? 'unknown')] = (array) $company;
        }

        return $rows;
    }

    /**
     * @param array<string,bool> $gates
     * @param list<string> $gateIds
     */
    public function depthAxisReady(array $gates, array $gateIds): bool
    {
        foreach ($gateIds as $gateId) {
            if (($gates[$gateId] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $companyRuntimeRow
     */
    public function companyRuntimeCoverageReady(array $companyRuntimeRow, string $completedField, int $expectedFlowCount): bool
    {
        return $expectedFlowCount > 0
            && (int) ($companyRuntimeRow['expected_flow_count'] ?? 0) === $expectedFlowCount
            && (int) ($companyRuntimeRow[$completedField] ?? 0) >= $expectedFlowCount
            && (float) ($companyRuntimeRow['coverage_rate'] ?? 0.0) >= 1.0;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    public function countsByKey(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
