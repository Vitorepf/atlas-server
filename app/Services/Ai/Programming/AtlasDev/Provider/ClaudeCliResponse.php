<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

use InvalidArgumentException;

/**
 * Read-only response envelope returned by ClaudeCliGateway::dispatch().
 *
 * Reports literally what the gateway observed at the transport edge. The
 * `actualProvider` / `actualModelFamily` fields are mandatory and used by
 * SonnetClaudeCliAdapter to detect (and reject) silent fallback.
 */
final class ClaudeCliResponse
{
    public function __construct(
        public readonly string $actualProvider,
        public readonly string $actualModelFamily,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly ?int $tokensIn = null,
        public readonly ?int $tokensOut = null,
        public readonly ?float $costEstimateUsd = null,
    ) {
        if ($this->actualProvider === '') {
            throw new InvalidArgumentException('ClaudeCliResponse.actual_provider must not be empty.');
        }
        if ($this->actualModelFamily === '') {
            throw new InvalidArgumentException('ClaudeCliResponse.actual_model_family must not be empty.');
        }
        if ($this->durationMs < 0) {
            throw new InvalidArgumentException('ClaudeCliResponse.duration_ms must be non-negative.');
        }
        if ($this->tokensIn !== null && $this->tokensIn < 0) {
            throw new InvalidArgumentException('ClaudeCliResponse.tokens_in must be null or non-negative.');
        }
        if ($this->tokensOut !== null && $this->tokensOut < 0) {
            throw new InvalidArgumentException('ClaudeCliResponse.tokens_out must be null or non-negative.');
        }
    }
}
