<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
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
    /**
     * @param  string|null  $expectedCompactSddHash  the CompactSDD hash pinned
     *                                               in the (HMAC-signed) confirmation_token row at Plan time. When provided,
     *                                               the executor MUST recompute the hash of compact_sdd.json on disk and
     *                                               throw {@see CompactSddUnavailableException::tampered()} on mismatch
     *                                               BEFORE invoking the provider. Null only for legacy paths whose token
     *                                               row pre-dates the hash-pin column; in that case the executor falls back
     *                                               to the disk-only pin chain (mini_programming_spec.compact_sdd_hash).
     */
    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult;
}
