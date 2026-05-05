<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\AiCouncilCoordinator;

class ClaudeCodexCouncilProviderDriver extends AbstractCliProviderDriver
{
    public function providerId(): string
    {
        return 'claude_codex';
    }

    public function supportedModels(): array
    {
        return ['council_default', 'selected-by-decide'];
    }

    public function legacyProviderClass(): string
    {
        return AiCouncilCoordinator::class;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function enrichPayload(array $payload, array $prompt, array $context): array
    {
        $payload['council_subproviders'] = array_map(
            fn (string $providerId): array => [
                'provider_id' => $providerId,
                'identity_fragment' => $this->identityFragmentForProvider($providerId)->toArray(),
            ],
            ['claude_cli', 'codex_cli'],
        );

        return $payload;
    }
}
