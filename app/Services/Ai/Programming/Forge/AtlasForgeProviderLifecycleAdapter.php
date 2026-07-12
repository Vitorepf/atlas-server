<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;

/** Default lifecycle adapter over the governed Forge provider router. */
final readonly class AtlasForgeProviderLifecycleAdapter implements ForgeProviderLifecyclePort
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_lifecycle_port.v1';

    public function __construct(
        private AtlasForgeProviderInvocationDriverRouter $router,
    ) {}

    public function start(array $request): array
    {
        $provider = (string) ($request['provider'] ?? 'atlas_kernel');
        if ($provider === 'atlas_kernel') {
            return $this->ready($provider, 'shared_kernel_execution');
        }

        $plan = $this->router->driverPlan($provider, $request);
        $safe = (bool) ($plan['plan_safe'] ?? false);

        return [
            'schema' => self::SCHEMA_VERSION,
            'status' => $safe ? 'ready' : 'blocked',
            'provider' => $provider,
            'external_provider_call' => $this->router->callsExternalProvider($provider),
            'plan' => $plan,
            'reason' => $safe ? null : (string) (($plan['blockers'][0] ?? $plan['driver_blocker'] ?? 'provider_plan_blocked')),
        ];
    }

    public function poll(array $request): array
    {
        return $this->observation('poll', $request);
    }

    public function heartbeat(array $request): array
    {
        return $this->observation('heartbeat', $request);
    }

    public function cancel(array $request): array
    {
        return $this->observation('cancel', $request);
    }

    /** @return array<string,mixed> */
    private function ready(string $provider, string $route): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'provider' => $provider,
            'route' => $route,
            'external_provider_call' => false,
        ];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function observation(string $operation, array $request): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'operation' => $operation,
            'provider' => (string) ($request['provider'] ?? 'atlas_kernel'),
            'cycle_id' => (string) ($request['cycle_id'] ?? ''),
            'fencing_token' => (int) ($request['fencing_token'] ?? 0),
            'external_provider_call' => false,
        ];
    }
}
