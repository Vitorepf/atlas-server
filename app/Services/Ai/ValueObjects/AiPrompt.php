<?php

namespace App\Services\Ai\ValueObjects;

class AiPrompt
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $agentSlug,
        public readonly string $intent,
        public readonly array $skillVersions,
        public readonly array $contextRefs,
        public readonly ?string $model = null,
        public readonly array $taskRequest = [],
        public readonly array $contextPack = [],
        public readonly array $executionPlan = [],
        public readonly array $activatedSkills = [],
        public readonly array $skillCatalog = [],
        public readonly array $openBrainInjection = [],
    ) {}
}
