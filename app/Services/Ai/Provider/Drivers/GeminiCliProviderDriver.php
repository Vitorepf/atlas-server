<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\GeminiCliProvider;

class GeminiCliProviderDriver extends AbstractCliProviderDriver
{
    public function providerId(): string
    {
        return 'gemini_cli';
    }

    public function supportedModels(): array
    {
        return ['gemini-3.1-pro-preview'];
    }

    public function legacyProviderClass(): string
    {
        return GeminiCliProvider::class;
    }
}
