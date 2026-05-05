<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\CodexCliProvider;

class CodexCliProviderDriver extends AbstractCliProviderDriver
{
    public function providerId(): string
    {
        return 'codex_cli';
    }

    public function supportedModels(): array
    {
        return ['codex_cli_default'];
    }

    public function legacyProviderClass(): string
    {
        return CodexCliProvider::class;
    }
}
