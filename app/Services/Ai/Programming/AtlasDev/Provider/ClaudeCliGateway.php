<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

/**
 * Narrow gateway contract that SonnetClaudeCliAdapter depends on.
 *
 * The fast path never talks to the legacy AiGatewayService directly. A thin
 * production adapter can implement this interface around ClaudeCliProvider /
 * AiGatewayService, while tests inject a deterministic fake that returns a
 * pre-baked stdout payload without touching the network.
 */
interface ClaudeCliGateway
{
    public function dispatch(ClaudeCliRequest $request): ClaudeCliResponse;
}
