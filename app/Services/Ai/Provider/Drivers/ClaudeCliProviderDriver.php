<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\ClaudeCliProvider;

class ClaudeCliProviderDriver extends AbstractCliProviderDriver
{
    public function providerId(): string
    {
        return 'claude_cli';
    }

    public function supportedModels(): array
    {
        return ['claude_cli_default'];
    }

    public function legacyProviderClass(): string
    {
        return ClaudeCliProvider::class;
    }
}
