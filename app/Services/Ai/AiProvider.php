<?php

namespace App\Services\Ai;

use App\Models\AiJob;

interface AiProvider
{
    public function key(): string;

    public function run(AiJob $job, string $prompt): AiProviderResult;

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult;

    public function health(): AiProviderHealthCheck;
}
