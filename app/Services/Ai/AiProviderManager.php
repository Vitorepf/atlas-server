<?php

namespace App\Services\Ai;

use InvalidArgumentException;

class AiProviderManager
{
    public function __construct(
        private readonly ClaudeCliProvider $claude,
        private readonly CodexCliProvider $codex,
    ) {}

    public function get(?string $provider = null): AiProvider
    {
        $provider = $provider ?: (string) config('atlas.ai.default_provider', 'claude_cli');

        return match ($provider) {
            'claude_cli' => $this->claude,
            'codex_cli' => $this->codex,
            default => throw new InvalidArgumentException("Unsupported AI provider [{$provider}]."),
        };
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return ['claude_cli', 'codex_cli'];
    }
}
