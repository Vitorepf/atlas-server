<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;

/**
 * Surface boundary between the HTTP run endpoint and the run-path services
 * (provider, scope guard, verification, completion, receipt composition).
 *
 * Default implementation lives in {@see PipelineRunExecutor}. Tests bind a
 * fake here so the controller stays pure and never calls real provider.
 *
 * Core (Pipeline/Gate/Provider/Repair) does NOT depend on this interface;
 * this is HTTP-layer wiring only.
 */
interface RunExecutor
{
    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
    ): RunExecutionResult;
}
