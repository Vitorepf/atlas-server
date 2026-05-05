<?php

namespace App\Services\Ai\Surface;

use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;

class DomainCatalogSurfaceSelectionService
{
    /**
     * @var array<string,array<string,string>>
     */
    private const UX_FLOW_MAP = [
        'general' => [
            'direct' => 'general.answer',
            'plan' => 'general.answer',
            'review' => 'general.answer',
        ],
        'operational' => [
            'direct' => 'operations.diagnostic',
            'plan' => 'operations.diagnostic',
            'review' => 'operations.diagnostic',
        ],
        'programming' => [
            'direct' => 'programming.dev',
            'plan' => 'programming.dev',
            'review' => 'programming.review',
            'dev' => 'programming.dev',
            'debug' => 'programming.repair',
        ],
    ];

    public function __construct(
        private readonly AtlasAiDomainCatalogService $catalog,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function select(array $input): array
    {
        $surfaceId = $this->string($input['surface_id'] ?? $input['surface'] ?? 'atlas_app') ?: 'atlas_app';
        $uxMode = $this->normalizeMode($this->string($input['mode'] ?? $input['atlas_mode'] ?? 'general'));
        $uxTask = $this->normalizeTask($this->string($input['task'] ?? $input['routing_task'] ?? 'direct'));
        $productDomain = $this->productDomain($input);
        $requestedFlow = $this->string($input['flow_id'] ?? $input['flow'] ?? null);
        $requestedDomain = $this->string($input['domain_id'] ?? null);

        $catalog = $this->catalog->inspect();
        $flows = collect((array) ($catalog['flows'] ?? []))->keyBy('id');
        $domains = collect((array) ($catalog['domains'] ?? []))->keyBy('id');

        $flowId = $requestedFlow
            ?: $this->flowFromRequestedDomain($requestedDomain, $domains)
            ?: $this->flowFromUx($uxMode, $uxTask);

        $flow = $flows->get($flowId);
        if (! is_array($flow)) {
            return $this->unresolvedSelection($surfaceId, $uxMode, $uxTask, $productDomain, $requestedDomain, $requestedFlow, $flowId, $catalog);
        }

        $domain = $domains->get((string) ($flow['domain_id'] ?? ''));
        if (! is_array($domain)) {
            return $this->unresolvedSelection($surfaceId, $uxMode, $uxTask, $productDomain, $requestedDomain, $requestedFlow, $flowId, $catalog);
        }

        return [
            'schema_version' => 1,
            'status' => 'ok',
            'surface_id' => $surfaceId,
            'selection_source' => $requestedFlow ? 'explicit_flow' : ($requestedDomain ? 'explicit_domain' : 'ux_mapping'),
            'operator_override' => $requestedFlow !== null || $requestedDomain !== null,
            'ux' => [
                'mode' => $uxMode,
                'task' => $uxTask,
                'product_domain' => $productDomain,
            ],
            'domain' => [
                'id' => (string) $domain['id'],
                'label' => (string) $domain['label'],
                'default_flow' => (string) $domain['default_flow'],
                'orchestrator_maturity' => (string) $domain['orchestrator_maturity'],
                'runtime_family' => (string) $domain['runtime_family'],
                'autonomy_default' => (string) $domain['autonomy_default'],
                'background_allowed' => (bool) $domain['background_allowed'],
                'onboarding' => (array) ($domain['onboarding'] ?? []),
            ],
            'flow' => [
                'id' => (string) $flow['id'],
                'domain_id' => (string) $flow['domain_id'],
                'label' => (string) $flow['label'],
                'runtime' => (string) $flow['runtime'],
                'orchestrator_maturity' => (string) $flow['orchestrator_maturity'],
                'autonomy' => (string) $flow['autonomy'],
                'background_allowed' => (bool) $flow['background_allowed'],
                'destructive_requires_approval' => (bool) $flow['destructive_requires_approval'],
                'executor_preference' => (string) $flow['executor_preference'],
            ],
            'safety' => [
                'ready' => data_get($domain, 'onboarding.status') === 'ready',
                'onboarding_status' => (string) data_get($domain, 'onboarding.status', 'unknown'),
                'autonomy' => (string) $flow['autonomy'],
                'background_allowed' => (bool) $flow['background_allowed'],
                'destructive_requires_approval' => (bool) $flow['destructive_requires_approval'],
                'surface_must_confirm_destructive' => (bool) $flow['destructive_requires_approval'],
            ],
            'payload_patch' => [
                'domain_id' => (string) $domain['id'],
                'flow_id' => (string) $flow['id'],
                'surface_id' => $surfaceId,
                'catalog_schema_version' => (int) ($catalog['schema_version'] ?? 1),
                'selection_source' => $requestedFlow ? 'explicit_flow' : ($requestedDomain ? 'explicit_domain' : 'ux_mapping'),
                'product_domain' => $productDomain,
            ],
            'catalog' => [
                'source' => (string) ($catalog['source'] ?? 'unknown'),
                'status' => (string) ($catalog['status'] ?? 'unknown'),
                'validation_valid' => (bool) data_get($catalog, 'validation.valid', false),
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string,array<string,mixed>>  $domains
     */
    private function flowFromRequestedDomain(?string $domainId, $domains): ?string
    {
        if ($domainId === null || $domainId === '') {
            return null;
        }

        $domain = $domains->get($domainId);

        return is_array($domain) && is_string($domain['default_flow'] ?? null)
            ? $domain['default_flow']
            : null;
    }

    private function flowFromUx(string $mode, string $task): string
    {
        return self::UX_FLOW_MAP[$mode][$task]
            ?? self::UX_FLOW_MAP[$mode]['direct']
            ?? 'general.answer';
    }

    /**
     * @param  array<string,mixed>  $catalog
     * @return array<string,mixed>
     */
    private function unresolvedSelection(
        string $surfaceId,
        string $uxMode,
        string $uxTask,
        ?string $productDomain,
        ?string $requestedDomain,
        ?string $requestedFlow,
        string $resolvedFlow,
        array $catalog,
    ): array {
        return [
            'schema_version' => 1,
            'status' => 'unresolved',
            'surface_id' => $surfaceId,
            'selection_source' => $requestedFlow ? 'explicit_flow' : ($requestedDomain ? 'explicit_domain' : 'ux_mapping'),
            'operator_override' => $requestedFlow !== null || $requestedDomain !== null,
            'ux' => [
                'mode' => $uxMode,
                'task' => $uxTask,
                'product_domain' => $productDomain,
            ],
            'requested' => [
                'domain_id' => $requestedDomain,
                'flow_id' => $requestedFlow,
                'resolved_flow_id' => $resolvedFlow,
            ],
            'payload_patch' => [
                'domain_id' => null,
                'flow_id' => null,
                'surface_id' => $surfaceId,
                'catalog_schema_version' => (int) ($catalog['schema_version'] ?? 1),
                'selection_source' => 'unresolved',
                'product_domain' => $productDomain,
            ],
            'catalog' => [
                'source' => (string) ($catalog['source'] ?? 'unknown'),
                'status' => (string) ($catalog['status'] ?? 'unknown'),
                'validation_valid' => (bool) data_get($catalog, 'validation.valid', false),
            ],
        ];
    }

    private function productDomain(array $input): ?string
    {
        $domain = $this->string($input['product_domain'] ?? $input['routing_domain'] ?? $input['domain'] ?? null);

        if ($domain === null || in_array($domain, ['auto', 'programming', 'marketing', 'self_improvement', 'general'], true)) {
            return null;
        }

        return $domain;
    }

    private function normalizeMode(?string $mode): string
    {
        return match ($mode) {
            'operational', 'ops' => 'operational',
            'programming', 'programacao', 'programação', 'dev', 'debug' => 'programming',
            default => 'general',
        };
    }

    private function normalizeTask(?string $task): string
    {
        return match ($task) {
            'plan', 'review', 'dev', 'debug', 'direct' => $task,
            'repair' => 'debug',
            default => 'direct',
        };
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
