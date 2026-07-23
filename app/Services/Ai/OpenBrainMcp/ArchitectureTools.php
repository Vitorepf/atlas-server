<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureReadinessService;
use App\Services\Ai\Kernel\Architecture\AtlasDocumentationSplitPlanService;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;

/**
 * GOD-DEBULK D3: architecture / onboarding tool family relocated verbatim from
 * AtlasOpenBrainMcpService (domain catalog, architecture validate/operations/
 * readiness, runtime boundary, session bootstrap, feature placement, docs split
 * plan). Every handler is read-only (`writes => false`); bodies are byte-identical
 * to the pre-split service (only dispatched-handler visibility private->public).
 * The façade dispatch delegates here; the AP-129/132/133/177 + validate + domain
 * catalog scanner source-pins were relocated to this file under GOD-DEBULK D3 with
 * their str_contains strength unchanged.
 */
class ArchitectureTools
{
    public function __construct(
        private readonly AtlasAiDomainCatalogService $domainCatalog,
        private readonly AtlasAiArchitectureValidationService $architectureValidation,
        private readonly AtlasArchitectureReadinessService $architectureReadiness,
        private readonly AtlasArchitectureOperationsCatalog $architectureOperations,
        private readonly AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary,
        private readonly AtlasSessionBootstrapService $sessionBootstrap,
        private readonly AtlasFeaturePlacementService $featurePlacement,
        private readonly AtlasGovernanceGateService $governanceGate,
        private readonly AtlasDocumentationSplitPlanService $documentationSplitPlan,
        private readonly KernelReplayReportInput $replayInput,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function domainCatalog(array $arguments): array
    {
        $maturity = $this->string($arguments['maturity'] ?? null);
        if ($maturity !== null && ! in_array($maturity, ['implemented', 'scaffold', 'planned'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_domain_catalog',
                'error' => 'invalid_maturity',
                'allowed_maturity' => ['implemented', 'scaffold', 'planned'],
            ];
        }

        $onboardingStatus = $this->string($arguments['onboarding_status'] ?? null);
        if ($onboardingStatus !== null && ! in_array($onboardingStatus, ['ready', 'executable_incomplete', 'scaffold'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_domain_catalog',
                'error' => 'invalid_onboarding_status',
                'allowed_onboarding_status' => ['ready', 'executable_incomplete', 'scaffold'],
            ];
        }

        $catalog = $this->domainCatalog->inspect([
            'domain' => $this->string($arguments['domain'] ?? null),
            'flow' => $this->string($arguments['flow'] ?? null),
            'maturity' => $maturity,
            'onboarding_status' => $onboardingStatus,
        ]);

        return [
            'ok' => ($catalog['status'] ?? null) === 'ok',
            'tool' => 'atlas_domain_catalog',
            ...$catalog,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function architectureValidate(array $arguments): array
    {
        $detail = $this->string($arguments['detail'] ?? null) ?: 'summary';
        if (! in_array($detail, ['summary', 'full'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_architecture_validate',
                'error' => 'invalid_detail',
                'allowed_detail' => ['summary', 'full'],
            ];
        }

        $payload = $this->architectureValidation->payload();
        $summary = [
            'status' => $payload['status'],
            'schema_version' => $payload['schema_version'],
            'validated_at' => $payload['validated_at'],
            'kernel' => [
                'valid' => data_get($payload, 'kernel.valid'),
                'static_scan' => [
                    'valid' => data_get($payload, 'kernel.static_scan.valid'),
                    'summary' => data_get($payload, 'kernel.static_scan.summary', []),
                ],
            ],
            'capabilities' => [
                'valid' => data_get($payload, 'capabilities.valid'),
                'count' => data_get($payload, 'capabilities.count'),
                'surface_count' => data_get($payload, 'capabilities.surface_count'),
            ],
            'domains' => [
                'valid' => data_get($payload, 'domains.valid'),
                'domain_count' => data_get($payload, 'domains.domain_count'),
                'flow_count' => data_get($payload, 'domains.flow_count'),
            ],
            'orchestrators' => [
                'valid' => data_get($payload, 'orchestrators.valid'),
                'count' => data_get($payload, 'orchestrators.count'),
            ],
            'onboarding' => $payload['onboarding'],
        ];

        return [
            'ok' => $payload['status'] === 'ok',
            'tool' => 'atlas_architecture_validate',
            'detail' => $detail,
            'architecture_validation' => $detail === 'full' ? $payload : $summary,
            'writes' => false,
        ];

    }

    /**
     * @return array<string,mixed>
     */
    public function architectureOperations(array $arguments): array
    {
        return [
            'ok' => true,
            'tool' => 'atlas_architecture_operations',
            'architecture_operations' => $this->architectureOperations->summary($this->onlyScalarFilters($arguments, ['id', 'kind', 'section', 'surface', 'owner_layer'])),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function architectureReadiness(array $arguments): array
    {
        $payload = $this->architectureReadiness->snapshot($this->onlyScalarFilters($arguments, ['workspace', 'owner']));

        return [
            'ok' => ($payload['status'] ?? null) === 'ready',
            'tool' => 'atlas_architecture_readiness',
            'architecture_readiness' => $payload,
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runtimeBoundary(): array
    {
        $payload = $this->runtimeBoundary->report();

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_runtime_boundary',
            'runtime_boundary' => $payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function sessionBootstrap(array $arguments): array
    {
        $payload = $this->sessionBootstrap->bootstrap(
            $this->string($arguments['task'] ?? null) ?? '',
            ['workspace' => $this->string($arguments['workspace'] ?? null) ?? base_path()],
        );
        $strictBlocked = $this->governanceGate->strictBlocked($payload, ($arguments['strict'] ?? false) === true);

        return [
            'ok' => ($payload['status'] ?? null) === 'ok' && ! $strictBlocked,
            'tool' => 'atlas_session_bootstrap',
            'error' => $strictBlocked ? $this->governanceGate->mcpError('atlas_session_bootstrap') : null,
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function featurePlacement(array $arguments): array
    {
        $feature = $this->string($arguments['feature'] ?? null);
        if ($feature === null) {
            return [
                'ok' => false,
                'tool' => 'atlas_feature_placement',
                'error' => 'feature_required',
                'writes' => false,
            ];
        }

        $payload = $this->featurePlacement->place($feature, $this->onlyScalarFilters((array) ($arguments['hints'] ?? []), ['domain', 'surface', 'runtime', 'flow']));
        $strictBlocked = $this->governanceGate->strictBlocked($payload, ($arguments['strict'] ?? false) === true);

        return [
            'ok' => ($payload['status'] ?? null) === 'ok' && ! $strictBlocked,
            'tool' => 'atlas_feature_placement',
            'error' => $strictBlocked ? $this->governanceGate->mcpError('atlas_feature_placement') : null,
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function docsSplitPlan(array $arguments): array
    {
        $payload = $this->documentationSplitPlan->plan($this->onlyScalarFilters($arguments, ['owner', 'severity', 'status']));

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_docs_split_plan',
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<int,string>  $allowed
     * @return array<string,string>
     */
    private function onlyScalarFilters(array $arguments, array $allowed): array
    {
        return $this->replayInput->scalarFilters($arguments, $allowed);
    }

    // ponytail: string copied verbatim from the façade (which keeps its own pinned
    // copy) — matches the existing per-Tools-class primitive convention.

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
