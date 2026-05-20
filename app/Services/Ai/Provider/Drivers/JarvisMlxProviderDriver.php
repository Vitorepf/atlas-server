<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\JarvisMlxProvider;

class JarvisMlxProviderDriver extends AbstractCliProviderDriver
{
    public function providerId(): string
    {
        return 'jarvis_mlx';
    }

    public function supportedModels(): array
    {
        return ['jarvis_mlx_default', 'llama3_mlx_4bit', 'phi3_mlx_8bit'];
    }

    public function legacyProviderClass(): string
    {
        return JarvisMlxProvider::class;
    }
}
