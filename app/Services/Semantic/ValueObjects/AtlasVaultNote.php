<?php

namespace App\Services\Semantic\ValueObjects;

final readonly class AtlasVaultNote
{
    /**
     * @param  array<string,mixed>  $frontmatter
     * @param  array<int,array<string,string>>  $links
     * @param  array<string,mixed>  $metadata
     * @param  array<int,string>  $conflicts
     */
    public function __construct(
        public string $path,
        public string $markdown,
        public array $frontmatter,
        public array $links,
        public array $metadata = [],
        public array $conflicts = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'markdown' => $this->markdown,
            'frontmatter' => $this->frontmatter,
            'links' => $this->links,
            'metadata' => $this->metadata,
            'conflicts' => $this->conflicts,
        ];
    }
}
