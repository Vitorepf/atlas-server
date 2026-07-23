<?php

declare(strict_types=1);

namespace App\Services\Ai\ExecutionAuthority;

/**
 * AP-789 port for the REAL Forge provider topology probe
 * ({@see \App\Services\Ai\Programming\AtlasForgeProviderTopologyService}).
 *
 * Runtime authority MUST come from the real service. Unit-test Fakes/TestDoubles
 * implementing this port are allowed only inside tests and must never be wired
 * into runtime, canonical docs or claim_policy as authority.
 */
interface ForgeProviderTopologyPort
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function topology(array $options = []): array;
}
