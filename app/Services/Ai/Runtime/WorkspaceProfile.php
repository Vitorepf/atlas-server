<?php

namespace App\Services\Ai\Runtime;

class WorkspaceProfile
{
    /**
     * @param  array<int,string>  $dirtyFiles
     * @param  array<int,string>  $stack
     * @param  array<string,string>  $scripts
     * @param  array<int,string>  $testCommands
     * @param  array<int,string>  $files
     * @param  array<int,string>  $importantFiles
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $workspace,
        public readonly ?string $repoRoot = null,
        public readonly ?string $branch = null,
        public readonly ?string $head = null,
        public readonly array $dirtyFiles = [],
        public readonly array $stack = [],
        public readonly ?string $packageManager = null,
        public readonly array $scripts = [],
        public readonly array $testCommands = [],
        public readonly array $files = [],
        public readonly array $importantFiles = [],
        public readonly string $cacheKey = '',
        public readonly ?string $generatedAt = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace' => $this->workspace,
            'repo_root' => $this->repoRoot,
            'branch' => $this->branch,
            'head' => $this->head,
            'dirty_files' => $this->dirtyFiles,
            'stack' => $this->stack,
            'package_manager' => $this->packageManager,
            'scripts' => $this->scripts,
            'test_commands' => $this->testCommands,
            'files' => $this->files,
            'important_files' => $this->importantFiles,
            'cache_key' => $this->cacheKey,
            'generated_at' => $this->generatedAt,
            'metadata' => $this->metadata,
        ];
    }
}
