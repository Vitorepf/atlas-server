<?php

namespace App\Services\Ai\Kernel\Domain;

use App\Services\Ai\AtlasDomainProfileRegistry;

class AtlasAiDomainCatalogService
{
    public function __construct(
        private readonly AtlasDomainProfileRegistry $profiles,
        private readonly AtlasDomainManifestValidator $validator,
        private readonly AtlasDomainOrchestratorRegistry $orchestrators,
        private readonly AtlasDomainOnboardingScorecard $onboarding,
    ) {}

    /**
     * @param  array{domain?:string|null,flow?:string|null,maturity?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function inspect(array $filters = []): array
    {
        $catalog = $this->profiles->catalog();
        $domainFilter = trim((string) ($filters['domain'] ?? ''));
        $flowFilter = trim((string) ($filters['flow'] ?? ''));
        $maturityFilter = trim((string) ($filters['maturity'] ?? ''));

        $validation = $this->validator->validateCatalog($catalog);
        $orchestratorReport = $this->orchestrators->complianceReport();
        $orchestratorIndex = $this->orchestrators->all()
            ->keyBy(fn (array $definition): string => (string) $definition['id']);

        $flows = collect((array) ($catalog['flows'] ?? []))
            ->filter(fn (array $flow): bool => ($flow['status'] ?? 'active') === 'active')
            ->when($domainFilter !== '', fn ($items) => $items->filter(
                fn (array $flow): bool => (string) ($flow['domain_id'] ?? '') === $domainFilter
            ))
            ->when($flowFilter !== '', fn ($items) => $items->filter(
                fn (array $flow): bool => (string) ($flow['id'] ?? '') === $flowFilter
            ))
            ->values();

        $flowDomainIds = $flows
            ->pluck('domain_id')
            ->filter(fn (mixed $domainId): bool => is_string($domainId) && $domainId !== '')
            ->unique()
            ->values()
            ->all();

        $domains = collect((array) ($catalog['domains'] ?? []))
            ->filter(fn (array $domain): bool => ($domain['status'] ?? 'active') === 'active')
            ->when($domainFilter !== '', fn ($items) => $items->filter(
                fn (array $domain): bool => (string) ($domain['id'] ?? '') === $domainFilter
            ))
            ->when($flowFilter !== '', fn ($items) => $items->filter(
                fn (array $domain): bool => in_array((string) ($domain['id'] ?? ''), $flowDomainIds, true)
            ))
            ->values();

        $orchestratorRows = $orchestratorIndex
            ->values()
            ->when($maturityFilter !== '', fn ($items) => $items->filter(
                fn (array $definition): bool => (string) ($definition['maturity'] ?? '') === $maturityFilter
            ))
            ->values()
            ->map(fn (array $definition): array => $this->orchestratorPayload($definition))
            ->values();

        return [
            'schema_version' => 1,
            'status' => $validation['ok'] && $orchestratorReport['ok'] ? 'ok' : 'failed',
            'source' => (string) ($catalog['source'] ?? 'unknown'),
            'filters' => [
                'domain' => $domainFilter !== '' ? $domainFilter : null,
                'flow' => $flowFilter !== '' ? $flowFilter : null,
                'maturity' => $maturityFilter !== '' ? $maturityFilter : null,
            ],
            'summary' => [
                'domains' => $domains->count(),
                'flows' => $flows->count(),
                'orchestrators' => $orchestratorRows->count(),
                'implemented_orchestrators' => $orchestratorRows->where('maturity', 'implemented')->count(),
                'scaffold_orchestrators' => $orchestratorRows->where('maturity', 'scaffold')->count(),
                'planned_orchestrators' => $orchestratorRows->where('maturity', 'planned')->count(),
                'ready_domains' => $domains
                    ->map(fn (array $domain): array => $this->domainOnboarding($domain, $flows->all(), $orchestratorIndex->all()))
                    ->where('status', 'ready')
                    ->count(),
                'executable_incomplete_domains' => $domains
                    ->map(fn (array $domain): array => $this->domainOnboarding($domain, $flows->all(), $orchestratorIndex->all()))
                    ->where('status', 'executable_incomplete')
                    ->count(),
            ],
            'domains' => $domains->map(fn (array $domain): array => $this->domainPayload($domain, $flows->all(), $orchestratorIndex->all()))->values()->all(),
            'flows' => $flows->map(fn (array $flow): array => $this->flowPayload($flow, $orchestratorIndex->all()))->values()->all(),
            'orchestrators' => $orchestratorRows->all(),
            'validation' => [
                'valid' => $validation['ok'] && $orchestratorReport['ok'],
                'errors' => array_values(array_unique(array_merge($validation['errors'], $orchestratorReport['errors']))),
                'warnings' => array_values(array_unique(array_merge($validation['warnings'], $orchestratorReport['warnings']))),
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $domain
     * @param  array<int,array<string,mixed>>  $flows
     * @param  array<string,array<string,mixed>>  $orchestrators
     * @return array<string,mixed>
     */
    private function domainPayload(array $domain, array $flows, array $orchestrators): array
    {
        $orchestratorId = (string) ($domain['orchestrator'] ?? '');
        $orchestrator = $orchestrators[$orchestratorId] ?? null;

        return [
            'id' => (string) ($domain['id'] ?? ''),
            'label' => (string) ($domain['label'] ?? ''),
            'default_flow' => (string) ($domain['default_flow'] ?? ''),
            'orchestrator' => $orchestratorId,
            'orchestrator_maturity' => (string) ($orchestrator['maturity'] ?? 'unknown'),
            'runtime_family' => (string) ($domain['runtime_family'] ?? ''),
            'autonomy_default' => (string) ($domain['autonomy_default'] ?? ''),
            'background_allowed' => (bool) ($domain['background_allowed'] ?? false),
            'flow_count' => collect($flows)->where('domain_id', (string) ($domain['id'] ?? ''))->count(),
            'onboarding' => $this->domainOnboarding($domain, $flows, $orchestrators),
        ];
    }

    /**
     * @param  array<string,mixed>  $domain
     * @param  array<int,array<string,mixed>>  $flows
     * @param  array<string,array<string,mixed>>  $orchestrators
     * @return array<string,mixed>
     */
    private function domainOnboarding(array $domain, array $flows, array $orchestrators): array
    {
        $orchestratorId = (string) ($domain['orchestrator'] ?? '');
        $domainId = (string) ($domain['id'] ?? '');

        return $this->onboarding->forDomain(
            $domain,
            collect($flows)->where('domain_id', $domainId)->values()->all(),
            $orchestrators[$orchestratorId] ?? null,
        );
    }

    /**
     * @param  array<string,mixed>  $flow
     * @param  array<string,array<string,mixed>>  $orchestrators
     * @return array<string,mixed>
     */
    private function flowPayload(array $flow, array $orchestrators): array
    {
        $orchestratorId = (string) ($flow['orchestrator'] ?? '');
        $orchestrator = $orchestrators[$orchestratorId] ?? null;

        return [
            'id' => (string) ($flow['id'] ?? ''),
            'domain_id' => (string) ($flow['domain_id'] ?? ''),
            'label' => (string) ($flow['label'] ?? ''),
            'runtime' => (string) ($flow['runtime'] ?? ''),
            'orchestrator' => $orchestratorId,
            'orchestrator_maturity' => (string) ($orchestrator['maturity'] ?? 'unknown'),
            'autonomy' => (string) ($flow['autonomy'] ?? ''),
            'background_allowed' => (bool) ($flow['background_allowed'] ?? false),
            'destructive_requires_approval' => (bool) ($flow['requires_human_approval_for_destructive'] ?? true),
            'executor_preference' => (string) data_get($flow, 'execution_policy.executor_preference', ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function orchestratorPayload(array $definition): array
    {
        $class = (string) ($definition['class'] ?? '');
        $implemented = class_exists($class) && is_subclass_of($class, AtlasDomainOrchestrator::class);

        return [
            'id' => (string) ($definition['id'] ?? ''),
            'class' => $class,
            'maturity' => (string) ($definition['maturity'] ?? 'planned'),
            'implemented_contract' => $implemented,
            'domains' => array_values((array) ($definition['domains'] ?? [])),
            'flows' => array_values((array) ($definition['flows'] ?? [])),
        ];
    }
}
