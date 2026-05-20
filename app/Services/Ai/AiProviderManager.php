<?php

namespace App\Services\Ai;

use InvalidArgumentException;

class AiProviderManager
{
    public function __construct(
        private readonly ClaudeCliProvider $claude,
        private readonly CodexCliProvider $codex,
        private readonly GeminiCliProvider $gemini,
        private readonly JarvisMlxProvider $jarvis,
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {}

    public function get(?string $provider = null): AiProvider
    {
        $provider = $provider ?: $this->runtimeSettings->defaultProvider();

        return match ($provider) {
            'claude_cli' => $this->claude,
            'codex_cli' => $this->codex,
            'gemini_cli' => $this->gemini,
            'jarvis_mlx' => $this->jarvis,
            default => throw new InvalidArgumentException("Unsupported AI provider [{$provider}]."),
        };
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return ['claude_cli', 'codex_cli', 'gemini_cli', 'jarvis_mlx'];
    }
}
