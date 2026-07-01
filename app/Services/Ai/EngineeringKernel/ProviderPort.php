<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: invoke a provider or local model capability through governed
 * policy, never by runtime-specific prompt hacks.
 *
 * Owns: a single governed entry point for calling out to a provider/local model capability.
 * Must never own: product strategy, business policy, or which policy applies for a given risk
 * level/project/autonomy level — those live in the Policy Plane, never inside the Kernel.
 */
interface ProviderPort
{
    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function invoke(array $request): array;
}
