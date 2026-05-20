<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\GeminiCliProvider;
use App\Services\Ai\GeminiModelCatalog;

class GeminiCliProviderDriver extends AbstractCliProviderDriver
{
    public function providerId(): string
    {
        return 'gemini_cli';
    }

    public function supportedModels(): array
    {
        return app(GeminiModelCatalog::class)->resolve('auto')['allowed_models'] ?? [];
    }

    public function legacyProviderClass(): string
    {
        return GeminiCliProvider::class;
    }
}
