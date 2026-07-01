<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;

/**
 * Second Engineering Kernel adapter: pure delegation to the existing, already-proven
 * AgentExecutionProviderPortService — zero behavior change, zero new normalization rules. Gives
 * Atlas Dev, Atlas Forge and Autonomos one shared ProviderPort mechanism surface for provider
 * invocation facts while each runtime keeps its own flow.
 */
final class AgentExecutionProviderPortAdapter implements ProviderPort
{
    public function __construct(private readonly AgentExecutionProviderPortService $service) {}

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function invoke(array $request): array
    {
        return $this->service->normalize($request);
    }
}
