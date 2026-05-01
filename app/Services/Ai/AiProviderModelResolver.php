<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiProviderModelResolver
{
    /**
     * @return array{model:?string,source:string}
     */
    public function resolveWithSource(?string $provider, mixed $explicit = null): array
    {
        $explicitModel = $this->clean($explicit);
        if ($explicitModel !== null) {
            return ['model' => $explicitModel, 'source' => 'explicit'];
        }

        $configuredModel = $this->clean(config("atlas.ai.providers.{$provider}.model"));
        if ($configuredModel !== null) {
            return ['model' => $configuredModel, 'source' => 'configured_model'];
        }

        $identity = $this->clean(config("atlas.ai.providers.{$provider}.model_identity"));
        if ($identity !== null) {
            return ['model' => $identity, 'source' => 'configured_model_identity'];
        }

        return match ($provider) {
            'claude_cli' => ['model' => 'claude_cli_default', 'source' => 'provider_default_identity'],
            'codex_cli' => ['model' => 'codex_cli_default', 'source' => 'provider_default_identity'],
            'claude_codex' => ['model' => 'council_default', 'source' => 'provider_default_identity'],
            default => ['model' => null, 'source' => 'unresolved'],
        };
    }

    public function resolve(?string $provider, mixed $explicit = null): ?string
    {
        return $this->resolveWithSource($provider, $explicit)['model'];
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return Str::limit($value, 120, '');
    }
}
