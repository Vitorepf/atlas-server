<?php

namespace App\Services\Ai;

class AiPrompt
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $agentSlug,
        public readonly string $intent,
        public readonly array $skillVersions,
        public readonly array $contextRefs,
        public readonly ?string $model = null,
    ) {}
}
