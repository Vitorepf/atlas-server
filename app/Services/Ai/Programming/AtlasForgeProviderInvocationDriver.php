<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Forge Provider Invocation Driver contract.
 *
 * Every governed real provider driver (claude_cli, codex_cli, gemini_cli) and
 * the safe local executor (atlas-local) implements this contract. The router
 * uses it to ask drivers about their runtime status, plan an invocation
 * without touching the network, and invoke when all gates are green.
 *
 * Schemas produced:
 *   - atlas.forge.provider_driver_config_status.v1
 *   - atlas.forge.provider_driver_plan.v1
 *   - atlas.forge.provider_driver_result.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
 */
interface AtlasForgeProviderInvocationDriver
{
    /**
     * Provider id this driver handles (e.g. `claude_cli`).
     */
    public function provider(): string;

    /**
     * Whether the driver claims it can handle the given provider+model tuple.
     */
    public function supports(string $provider, ?string $model): bool;

    /**
     * Report runtime configuration status. NEVER calls an external provider.
     *
     * @return array<string,mixed>  atlas.forge.provider_driver_config_status.v1
     */
    public function configured(): array;

    /**
     * Plan a provider invocation. NEVER calls an external provider.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>  atlas.forge.provider_driver_plan.v1
     */
    public function plan(array $request): array;

    /**
     * Execute a provider invocation under the safe process runner. Only this
     * method is allowed to actually reach an external provider, and only when
     * the caller has confirmed all gates.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>  atlas.forge.provider_driver_result.v1
     */
    public function invoke(array $request): array;
}
