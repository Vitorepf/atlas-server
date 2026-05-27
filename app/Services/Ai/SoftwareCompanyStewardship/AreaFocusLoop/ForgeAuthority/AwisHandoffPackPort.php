<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority;

/**
 * AP-789 port for the REAL AWIS workspace handoff pack
 * ({@see \App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceHandoffPackService}).
 *
 * Runtime handoff readiness MUST come from the real service. Fakes/TestDoubles
 * implementing this port are confined to tests and never cross into runtime,
 * canonical docs or claim_policy.
 */
interface AwisHandoffPackPort
{
    /**
     * @param  array<int,string>  $threadIds
     * @return array<string,mixed>
     */
    public function build(?string $workspace = null, string $task = '', string $consumer = 'atlas_dev', array $threadIds = []): array;
}
