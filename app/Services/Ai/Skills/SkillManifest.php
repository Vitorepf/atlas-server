<?php

namespace App\Services\Ai\Skills;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SkillManifest
{
    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<int,string>  $warnings
     * @param  array<int,array{code:string,message:string}>  $securityIssues
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $license,
        public readonly ?string $compatibility,
        public readonly array $metadata,
        public readonly ?string $allowedTools,
        public readonly string $body,
        public readonly string $path,
        public readonly string $directory,
        public readonly string $sourceTier,
        public readonly string $contentHash,
        public readonly array $warnings = [],
        public readonly bool $quarantined = false,
        public readonly array $securityIssues = [],
    ) {}

    public function trustLevel(): string
    {
        $trust = data_get($this->metadata, 'atlas.trust_level', $this->sourceTier === 'builtin' ? 'builtin' : 'community');

        return in_array($trust, ['builtin', 'official', 'community'], true) ? $trust : 'community';
    }

    /**
     * @return array<int,string>
     */
    public function requiresTools(): array
    {
        return $this->stringList(data_get($this->metadata, 'atlas.requires_tools', []));
    }

    /**
     * @return array<int,string>
     */
    public function fallbackForTools(): array
    {
        return $this->stringList(data_get($this->metadata, 'atlas.fallback_for_tools', []));
    }

    /**
     * @return array<int,string>
     */
    public function platforms(): array
    {
        $platforms = $this->stringList(data_get($this->metadata, 'atlas.platforms', ['macos', 'linux']));

        return $platforms === [] ? ['macos', 'linux'] : $platforms;
    }

    /**
     * @return array<int,string>
     */
    public function resourceFiles(): array
    {
        $resources = [];

        foreach (['scripts', 'references', 'assets'] as $folder) {
            $path = $this->directory.DIRECTORY_SEPARATOR.$folder;
            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if (count($resources) >= 80) {
                    break 2;
                }

                $resources[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        sort($resources);

        return $resources;
    }

    /**
     * @return array{name:string,description:string,compatibility:?string,source_tier:string,trust_level:string,path:string,warnings:array}
     */
    public function catalogEntry(): array
    {
        return [
            'name' => $this->name,
            'description' => Str::limit($this->description, 500, '...'),
            'compatibility' => $this->compatibility,
            'source_tier' => $this->sourceTier,
            'trust_level' => $this->trustLevel(),
            'path' => $this->path,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @return array{name:string,sha256:string,source_tier:string,path:string,trust_level:string}
     */
    public function activationMetadata(): array
    {
        return [
            'name' => $this->name,
            'sha256' => $this->contentHash,
            'source_tier' => $this->sourceTier,
            'path' => $this->path,
            'trust_level' => $this->trustLevel(),
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => Str::of((string) $item)->lower()->trim()->value())
            ->values()
            ->all();
    }
}
