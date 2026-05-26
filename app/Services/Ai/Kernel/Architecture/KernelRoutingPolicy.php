<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

/**
 * Gap1.F4 — Kernel routing cutover policy.
 *
 * Single source of truth for the flag `ATLAS_AIWORKER_KERNEL_ROUTED`
 * (config: `atlas_ai.aiworker_kernel_routed`). When the flag is true,
 * AiWorker should route HTTP execution through the canonical Kernel
 * (Mission/Router/Policy) instead of `AtlasProgrammingOrchestrator`
 * direct. When false (default), the legacy path runs unchanged.
 *
 * The policy is a tiny read-only helper so AiWorker and downstream
 * services can consult ONE place. The default is FALSE — operator must
 * emit a Decision Receipt v2 and flip the config before any cutover.
 *
 * Output: `atlas.ai.kernel_routing_policy.v1` decision envelope. Used by
 * the ADR documentation and the architecture-validate command.
 */
final class KernelRoutingPolicy
{
    public const SCHEMA_VERSION = 'atlas.ai.kernel_routing_policy.v1';

    public const ROUTE_KERNEL = 'kernel';

    public const ROUTE_LEGACY = 'legacy';

    public function shouldRouteKernel(): bool
    {
        return (bool) config('atlas_ai.aiworker_kernel_routed', false);
    }

    /**
     * @return array{
     *   schema_version: string,
     *   flag_enabled: bool,
     *   route: string,
     *   flag_source: string,
     *   detail: string,
     *   safe_default: bool
     * }
     */
    public function decision(): array
    {
        $enabled = $this->shouldRouteKernel();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'flag_enabled' => $enabled,
            'route' => $enabled ? self::ROUTE_KERNEL : self::ROUTE_LEGACY,
            'flag_source' => 'config(atlas_ai.aiworker_kernel_routed) <- ATLAS_AIWORKER_KERNEL_ROUTED env',
            'detail' => $enabled
                ? 'HTTP routes through canonical Kernel (Mission/Router/Policy). Orchestrator is fallback.'
                : 'HTTP routes through legacy AtlasProgrammingOrchestrator. Kernel is shadow-only.',
            'safe_default' => $enabled === false,
        ];
    }
}
