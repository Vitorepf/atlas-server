<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority;

/**
 * AP-789 port for the REAL AWIS execution gate
 * ({@see \App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService}).
 *
 * Runtime workspace readiness MUST come from the real gate. Fakes/TestDoubles
 * implementing this port are confined to tests and never cross into runtime,
 * canonical docs or claim_policy.
 */
interface AwisExecutionGatePort
{
    /**
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array;
}
