<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use InvalidArgumentException;

/**
 * Read-only request envelope the SonnetClaudeCliAdapter hands to the gateway.
 *
 * Carries the projected prompt plus the locked transport parameters; the
 * gateway must use these literally and never substitute provider/model.
 */
final class ClaudeCliRequest
{
    public function __construct(
        public readonly string $runId,
        public readonly string $workspace,
        public readonly string $provider,
        public readonly string $modelFamily,
        public readonly ProviderPromptProjection $promptProjection,
        public readonly int $timeoutSeconds,
        public readonly bool $fallbackAllowed,
    ) {
        if ($this->provider !== SonnetClaudeCliAdapter::PROVIDER) {
            throw new InvalidArgumentException(
                'ClaudeCliRequest.provider locked to '.SonnetClaudeCliAdapter::PROVIDER
                .', got '.$this->provider
            );
        }
        if ($this->modelFamily !== SonnetClaudeCliAdapter::MODEL_FAMILY) {
            throw new InvalidArgumentException(
                'ClaudeCliRequest.model_family locked to '.SonnetClaudeCliAdapter::MODEL_FAMILY
                .', got '.$this->modelFamily
            );
        }
        if ($this->fallbackAllowed) {
            throw new InvalidArgumentException(
                'ClaudeCliRequest.fallback_allowed must be false on the Atlas Dev fast path.'
            );
        }
        if ($this->timeoutSeconds <= 0) {
            throw new InvalidArgumentException('ClaudeCliRequest.timeout_seconds must be positive.');
        }
    }
}
