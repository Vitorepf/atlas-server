<?php

namespace App\Services\Ai\Surface;

use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Support\AiStringListNormalizer;

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
        private readonly SurfaceAdapterRegistry $surfaces,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function select(array $input): array
    {
        $selectionInput = $this->selectionInput($input);
        $surfaceId = $this->surfaces->canonicalSurfaceId(
            $this->string($input['surface_id'] ?? $input['surface'] ?? $selectionInput['surface_id'] ?? 'atlas_app') ?: 'atlas_app',
        );
        $uxMode = $this->normalizeMode($this->string($input['mode'] ?? $input['atlas_mode'] ?? $selectionInput['mode'] ?? 'general'));
        $uxTask = $this->normalizeTask($this->string($input['task'] ?? $input['routing_task'] ?? $selectionInput['task'] ?? 'direct'));
        $productDomain = $this->productDomain($input, $selectionInput);
        $requestedFlow = $this->string($input['flow_id'] ?? $input['flow'] ?? $selectionInput['flow_id'] ?? null);
        $requestedDomain = $this->string($input['domain_id'] ?? $selectionInput['domain_id'] ?? null);
        $surfaceHints = $this->surfaceHints($surfaceId);

        $catalog = $this->catalog->inspect();
        $flows = collect((array) ($catalog['flows'] ?? []))->keyBy('id');
        $domains = collect((array) ($catalog['domains'] ?? []))->keyBy('id');

        if (($requestedDomain !== null || $requestedFlow !== null) && $this->rejectsExplicitDomainFlowSelection($surfaceHints)) {
            return $this->unresolvedSelection(
                $surfaceId,
                $uxMode,
                $uxTask,
                $productDomain,
                $requestedDomain,
                $requestedFlow,
                $requestedFlow ?: ($requestedDomain ?: 'unresolved'),
                $catalog,
                $surfaceHints,
                'surface_domain_flow_selection_not_supported',
                "Surface [{$surfaceId}] does not declare support for explicit Atlas AI domain/flow selection.",
            );
        }

        if ($requestedDomain !== null && ! $domains->has($requestedDomain)) {
            return $this->unresolvedSelection(
                $surfaceId,
                $uxMode,
                $uxTask,
                $productDomain,
                $requestedDomain,
                $requestedFlow,
                $requestedFlow ?: $requestedDomain,
                $catalog,
                $surfaceHints,
                'domain_not_found',
                "Domain [{$requestedDomain}] does not exist in the Atlas AI domain catalog.",
            );
        }

        $selectionSource = $requestedFlow ? 'explicit_flow' : ($requestedDomain ? 'explicit_domain' : 'ux_mapping');
        $flowId = $requestedFlow
            ?: $this->flowFromRequestedDomain($requestedDomain, $domains)
            ?: $this->flowFromSurfaceHints($uxTask, $surfaceHints)
            ?: $this->flowFromUx($uxMode, $uxTask);

        $flow = $flows->get($flowId);
        if (! is_array($flow)) {
            if ($requestedFlow === null && $requestedDomain === null && $flowId !== 'general.answer') {
                $fallbackFlow = $flows->get('general.answer');
                if (is_array($fallbackFlow)) {
                    $flowId = 'general.answer';
                    $flow = $fallbackFlow;
                    $selectionSource = 'safe_fallback';
                }
            }

            if (! is_array($flow)) {
                return $this->unresolvedSelection(
                    $surfaceId,
                    $uxMode,
                    $uxTask,
                    $productDomain,
                    $requestedDomain,
                    $requestedFlow,
                    $flowId,
                    $catalog,
                    $surfaceHints,
                    $requestedFlow ? 'flow_not_found' : 'resolved_flow_not_found',
                    $requestedFlow
                        ? "Flow [{$requestedFlow}] does not exist in the Atlas AI domain catalog."
                        : "Resolved flow [{$flowId}] does not exist in the Atlas AI domain catalog.",
                );
            }
        }

        $domain = $domains->get((string) ($flow['domain_id'] ?? ''));
        if (! is_array($domain)) {
            return $this->unresolvedSelection(
                $surfaceId,
                $uxMode,
                $uxTask,
                $productDomain,
                $requestedDomain,
                $requestedFlow,
                $flowId,
                $catalog,
                $surfaceHints,
                'flow_domain_not_found',
                "Flow [{$flowId}] points to a domain that is not present in the Atlas AI domain catalog.",
            );
        }

        if ($requestedDomain !== null && (string) ($flow['domain_id'] ?? '') !== $requestedDomain) {
            return $this->unresolvedSelection(
                $surfaceId,
                $uxMode,
                $uxTask,
                $productDomain,
                $requestedDomain,
                $requestedFlow,
                $flowId,
                $catalog,
                $surfaceHints,
                'domain_flow_mismatch',
                "Flow [{$flowId}] belongs to domain [{$flow['domain_id']}], not requested domain [{$requestedDomain}].",
            );
        }

        $surfaceSupportError = $this->surfaceSupportError((string) $domain['id'], (string) $flow['id'], $surfaceHints);
        if ($surfaceSupportError !== null) {
            return $this->unresolvedSelection(
                $surfaceId,
                $uxMode,
                $uxTask,
                $productDomain,
                $requestedDomain,
                $requestedFlow,
                $flowId,
                $catalog,
                $surfaceHints,
                $surfaceSupportError['code'],
                $surfaceSupportError['message'],
            );
        }

        return [
            'schema_version' => 1,
            'status' => 'ok',
            'surface_id' => $surfaceId,
            'selection_source' => $selectionSource,
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
            'surface_hints' => $surfaceHints,
            'payload_patch' => [
                'domain_id' => (string) $domain['id'],
                'flow_id' => (string) $flow['id'],
                'surface_id' => $surfaceId,
                'catalog_schema_version' => (int) ($catalog['schema_version'] ?? 1),
                'selection_source' => $selectionSource,
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
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function selectionInput(array $input): array
    {
        $selection = $input['domain_catalog_selection'] ?? null;
        if (! is_array($selection)) {
            return [];
        }

        return [
            'surface_id' => $selection['surface_id'] ?? data_get($selection, 'payload_patch.surface_id'),
            'mode' => data_get($selection, 'ux.mode'),
            'task' => data_get($selection, 'ux.task'),
            'product_domain' => data_get($selection, 'ux.product_domain') ?? data_get($selection, 'payload_patch.product_domain'),
            'domain_id' => $selection['domain_id'] ?? data_get($selection, 'domain.id') ?? data_get($selection, 'payload_patch.domain_id'),
            'flow_id' => $selection['flow_id'] ?? data_get($selection, 'flow.id') ?? data_get($selection, 'payload_patch.flow_id'),
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
        array $surfaceHints = [],
        string $errorCode = 'unresolved_flow',
        ?string $errorMessage = null,
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
            'error' => [
                'code' => $errorCode,
                'message' => $errorMessage ?: "Unable to resolve Atlas AI domain flow [{$resolvedFlow}] from the canonical catalog.",
            ],
            'surface_hints' => $surfaceHints,
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

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $selectionInput
     */
    private function productDomain(array $input, array $selectionInput = []): ?string
    {
        $domain = $this->string($input['product_domain'] ?? $input['routing_domain'] ?? $selectionInput['product_domain'] ?? $input['domain'] ?? null);

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
            'plan', 'review', 'dev', 'debug', 'direct', 'forge', 'heavy', 'build' => $task,
            'repair' => 'debug',
            default => 'direct',
        };
    }

    /**
     * @param  array<string,mixed>  $surfaceHints
     */
    private function flowFromSurfaceHints(string $task, array $surfaceHints): ?string
    {
        $taskFlowMap = is_array($surfaceHints['task_flow_map'] ?? null) ? $surfaceHints['task_flow_map'] : [];
        $flowId = $this->string($taskFlowMap[$task] ?? null);
        if ($flowId !== null) {
            return $flowId;
        }

        return ($surfaceHints['prefer_default_flow'] ?? false) === true
            ? $this->string($surfaceHints['default_flow_id'] ?? null)
            : null;
    }

    /**
     * @param  array<string,mixed>  $surfaceHints
     */
    private function rejectsExplicitDomainFlowSelection(array $surfaceHints): bool
    {
        return ($surfaceHints['registered'] ?? false) === true
            && ($surfaceHints['accepts_explicit_domain_flow_selection'] ?? false) !== true;
    }

    /**
     * @param  array<string,mixed>  $surfaceHints
     * @return array{code:string,message:string}|null
     */
    private function surfaceSupportError(string $domainId, string $flowId, array $surfaceHints): ?array
    {
        $supportedDomains = AiStringListNormalizer::trimmedStrings($surfaceHints['supported_domain_ids'] ?? null);
        if ($supportedDomains !== [] && ! in_array($domainId, $supportedDomains, true)) {
            return [
                'code' => 'surface_domain_not_supported',
                'message' => "Surface [{$surfaceHints['surface_id']}] does not declare support for domain [{$domainId}].",
            ];
        }

        $supportedFlows = AiStringListNormalizer::trimmedStrings($surfaceHints['supported_flow_ids'] ?? null);
        if ($supportedFlows !== [] && ! in_array($flowId, $supportedFlows, true)) {
            return [
                'code' => 'surface_flow_not_supported',
                'message' => "Surface [{$surfaceHints['surface_id']}] does not declare support for flow [{$flowId}].",
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceHints(string $surfaceId): array
    {
        try {
            $adapter = $this->surfaces->get($surfaceId);
        } catch (\Throwable) {
            return [
                'surface_id' => $surfaceId,
                'registered' => false,
                'supported_capabilities' => [],
            ];
        }

        return method_exists($adapter, 'supportedDomainFlowHints')
            ? [
                'registered' => true,
                'supported_capabilities' => $adapter->supportedCapabilities(),
                ...$adapter->supportedDomainFlowHints(),
            ]
            : [
                'surface_id' => $surfaceId,
                'registered' => true,
                'supported_capabilities' => $adapter->supportedCapabilities(),
            ];
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
