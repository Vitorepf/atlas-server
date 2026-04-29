<?php

namespace App\Services\Ai;

class AiSkill
{
    public function __construct(
        public readonly string $slug,
        public readonly string $path,
        public readonly string $title,
        public readonly string $body,
        public readonly array $frontmatter,
        public readonly string $contentHash,
    ) {}

    public function version(): string
    {
        return substr($this->contentHash, 0, 12);
    }
}
