<?php

namespace App\Services\Ai\Skills;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SkillBundleStore
{
    /** @var array<string,SkillManifest> */
    private array $skills = [];

    /** @var array<int,array<string,mixed>> */
    private array $diagnostics = [];

    public function clear(): void
    {
        $this->skills = [];
        $this->diagnostics = [];
    }

    public function register(SkillManifest $manifest): void
    {
        if ($manifest->quarantined) {
            $this->diagnostics[] = [
                'level' => 'error',
                'name' => $manifest->name,
                'path' => $manifest->path,
                'issues' => $manifest->securityIssues,
            ];

            return;
        }

        $this->skills[$manifest->name] = $manifest;
    }

    /**
     * @param  iterable<int,SkillManifest>  $manifests
     */
    public function registerAll(iterable $manifests): void
    {
        foreach ($manifests as $manifest) {
            $this->register($manifest);
        }
    }

    public function find(string $name): ?SkillManifest
    {
        return $this->skills[Str::of($name)->lower()->trim()->value()] ?? null;
    }

    /**
     * @return Collection<int,SkillManifest>
     */
    public function all(): Collection
    {
        return collect($this->skills)->sortKeys()->values();
    }

    /**
     * @return array<int,array{name:string,description:string,compatibility:?string,source_tier:string,trust_level:string,path:string,warnings:array}>
     */
    public function catalog(int $maxChars = 32000): array
    {
        $size = 0;
        $entries = [];

        foreach ($this->all() as $manifest) {
            $entry = $manifest->catalogEntry();
            $entrySize = strlen($entry['name'].$entry['description'].($entry['compatibility'] ?? ''));
            if ($size + $entrySize > $maxChars) {
                break;
            }

            $entries[] = $entry;
            $size += $entrySize;
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
