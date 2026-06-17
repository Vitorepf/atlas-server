<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiJob;

class AiPermissionEngine
{
    private readonly AiPermissionEngineSupport $support;
    private readonly AiPermissionDecisionBuilder $decisionBuilder;

    public function __construct(
        ?AiPermissionEngineSupport $support = null,
        ?AiPermissionDecisionBuilder $decisionBuilder = null,
    ) {
        $this->support = $support ?? new AiPermissionEngineSupport();
        $this->decisionBuilder = $decisionBuilder ?? new AiPermissionDecisionBuilder();
    }

    public function authorizeJob(AiJob $job, string $providerKey): AiPermissionDecision
    {
        $resolution = $this->support->resolve($job, $providerKey);

        return $this->decisionBuilder->build($resolution, $providerKey);
    }
}
