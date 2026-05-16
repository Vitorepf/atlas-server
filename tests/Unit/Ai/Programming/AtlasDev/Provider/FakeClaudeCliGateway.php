<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliRequest;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliResponse;

/**
 * Deterministic test double for ClaudeCliGateway.
 *
 * Records each dispatched request and returns a queued response, so tests can
 * exercise SonnetClaudeCliAdapter without spawning a real provider process.
 */
final class FakeClaudeCliGateway implements ClaudeCliGateway
{
    /** @var list<ClaudeCliRequest> */
    public array $requests = [];

    /** @var list<ClaudeCliResponse|\Throwable> */
    private array $responses = [];

    public function queue(ClaudeCliResponse|\Throwable $response): void
    {
        $this->responses[] = $response;
    }

    public function dispatch(ClaudeCliRequest $request): ClaudeCliResponse
    {
        $this->requests[] = $request;
        if ($this->responses === []) {
            throw new \RuntimeException('FakeClaudeCliGateway: no response queued for dispatch.');
        }
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
