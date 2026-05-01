<?php

namespace App\Services\Ai\Skills;

use App\Services\Ai\Runtime\AiToolRuntime;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;

class SkillDiscoveryService
{
    private const SKIP_DIRS = ['node_modules', '.git', 'vendor', '.cache', '.idea'];
    private const MAX_DIRS = 2000;

    /** @var array<int,array<string,mixed>> */
    private array $diagnostics = [];

    public function __construct(
        private readonly SkillManifestParser $parser,
    ) {}

    /**
     * @return array<int,SkillManifest>
     */
    public function discoverAll(string $workspace, ?bool $allowWorkspaceSkills = null): array
    {
        $this->diagnostics = [];
        $workspace = $this->resolveWorkspace($workspace);
        $allowWorkspaceSkills ??= $this->isWorkspaceTrusted($workspace);
        $roots = $this->roots($workspace, $allowWorkspaceSkills);
        $byName = [];

        foreach ($roots as $root) {
            foreach ($this->scanRoot($root['path'], $root['tier']) as $manifest) {
                if (isset($byName[$manifest->name])) {
                    $this->recordDuplicateSkill($manifest, $byName[$manifest->name]);

                    continue;
                }

                if ($manifest->quarantined) {
                    $this->diagnostics[] = [
                        'level' => 'error',
                        'name' => $manifest->name,
                        'path' => $manifest->path,
                        'source_tier' => $manifest->sourceTier,
                        'issues' => $manifest->securityIssues,
                    ];

                    continue;
                }

                $filtered = $this->filterAvailable($manifest);
                if ($filtered) {
                    $byName[$manifest->name] = $filtered;
                }
            }
        }

        ksort($byName);

        return array_values($byName);
    }

    public function workspaceHasLocalSkills(string $workspace): bool
    {
        $workspace = $this->resolveWorkspace($workspace);

        return File::isDirectory($workspace.'/.atlas/skills')
            || File::isDirectory($workspace.'/.agents/skills');
    }

    public function isWorkspaceTrusted(string $workspace): bool
    {
        $workspace = $this->resolveWorkspace($workspace);
        $trusted = $this->trustedProjects();

        return collect($trusted)
            ->contains(fn (array $item): bool => ($item['path'] ?? null) === $workspace);
    }

    public function trustWorkspace(string $workspace): void
    {
        $workspace = $this->resolveWorkspace($workspace);
        $trusted = $this->trustedProjects();
        if (collect($trusted)->contains(fn (array $item): bool => ($item['path'] ?? null) === $workspace)) {
            return;
        }

        $trusted[] = [
            'path' => $workspace,
            'approved_at' => now()->toJSON(),
        ];

        File::ensureDirectoryExists(dirname($this->trustedProjectsPath()));
        File::put($this->trustedProjectsPath(), json_encode($trusted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return array{status:string,count:int,warnings:array,errors:array}
     */
    public function health(string $workspace): array
    {
        $skills = $this->discoverAll($workspace);
        $warnings = [];
        $errors = [];

        foreach ($skills as $skill) {
            foreach ($skill->warnings as $warning) {
                $warnings[] = ['skill' => $skill->name, 'warning' => $warning, 'path' => $skill->path];
            }
        }

        foreach ($this->diagnostics as $diagnostic) {
            if (($diagnostic['level'] ?? null) === 'error') {
                $errors[] = $diagnostic;
            } else {
                $warnings[] = $diagnostic;
            }
        }

        return [
            'status' => $errors !== [] ? 'failed' : ($warnings !== [] ? 'needs_review' : 'passed'),
            'count' => count($skills),
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int,array{path:string,tier:string}>
     */
    private function roots(string $workspace, bool $allowWorkspaceSkills): array
    {
        $home = $this->homePath();
        $vault = (string) config('atlas.semantic_memory.vault_path');

        return array_values(array_filter([
            ['path' => base_path('skills'), 'tier' => 'builtin'],
            ['path' => rtrim($vault, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'_skills', 'tier' => 'vault'],
            ['path' => $home.'/.atlas/skills', 'tier' => 'user_atlas'],
            ['path' => $home.'/.agents/skills', 'tier' => 'user_agents'],
            $allowWorkspaceSkills ? ['path' => $workspace.'/.atlas/skills', 'tier' => 'workspace_atlas'] : null,
            $allowWorkspaceSkills ? ['path' => $workspace.'/.agents/skills', 'tier' => 'workspace_agents'] : null,
        ]));
    }

    private function recordDuplicateSkill(SkillManifest $ignored, SkillManifest $kept): void
    {
        if (! str_starts_with($ignored->sourceTier, 'workspace_')) {
            return;
        }

        $this->diagnostics[] = [
            'level' => 'warning',
            'name' => $ignored->name,
            'path' => $ignored->path,
            'source_tier' => $ignored->sourceTier,
            'kept_source_tier' => $kept->sourceTier,
            'kept_path' => $kept->path,
            'message' => 'workspace_skill_conflicts_with_protected_skill',
        ];
    }

    /**
     * @return array<int,SkillManifest>
     */
    private function scanRoot(string $root, string $tier): array
    {
        if (! File::isDirectory($root)) {
            return [];
        }

        $manifests = [];
        $dirsSeen = 0;
        $stack = [[$root, 0]];

        while ($stack !== []) {
            [$directory, $depth] = array_pop($stack);
            $dirsSeen++;
            if ($dirsSeen > self::MAX_DIRS || $depth > 4) {
                $this->diagnostics[] = [
                    'level' => 'warning',
                    'path' => $root,
                    'message' => 'Skill discovery cap reached.',
                ];
                break;
            }

            $basename = basename($directory);
            if (in_array($basename, self::SKIP_DIRS, true)) {
                continue;
            }

            $skillPath = $directory.DIRECTORY_SEPARATOR.'SKILL.md';
            if (File::isFile($skillPath)) {
                try {
                    $manifests[] = $this->parser->parse($skillPath, $tier);
                } catch (\Throwable $exception) {
                    $this->diagnostics[] = [
                        'level' => 'error',
                        'path' => $skillPath,
                        'source_tier' => $tier,
                        'message' => $exception->getMessage(),
                    ];
                }

                continue;
            }

            foreach (File::directories($directory) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $manifests;
    }

    private function filterAvailable(SkillManifest $manifest): ?SkillManifest
    {
        $platform = $this->platform();
        if (! in_array($platform, $manifest->platforms(), true)) {
            return null;
        }

        $availableTools = AiToolRuntime::availableTools();
        $missingRequired = array_values(array_diff($manifest->requiresTools(), $availableTools));
        if ($missingRequired !== []) {
            return null;
        }

        $fallbackFor = $manifest->fallbackForTools();
        if ($fallbackFor !== [] && array_diff($fallbackFor, $availableTools) !== $fallbackFor) {
            return null;
        }

        $warnings = $manifest->warnings;
        foreach ($this->compatibilityWarnings($manifest) as $warning) {
            $warnings[] = $warning;
        }

        if ($warnings === $manifest->warnings) {
            return $manifest;
        }

        return new SkillManifest(
            name: $manifest->name,
            description: $manifest->description,
            license: $manifest->license,
            compatibility: $manifest->compatibility,
            metadata: $manifest->metadata,
            allowedTools: $manifest->allowedTools,
            body: $manifest->body,
            path: $manifest->path,
            directory: $manifest->directory,
            sourceTier: $manifest->sourceTier,
            contentHash: $manifest->contentHash,
            warnings: $warnings,
            quarantined: $manifest->quarantined,
            securityIssues: $manifest->securityIssues,
        );
    }

    /**
     * @return array<int,string>
     */
    private function compatibilityWarnings(SkillManifest $manifest): array
    {
        $compatibility = Str::lower((string) $manifest->compatibility);
        if ($compatibility === '') {
            return [];
        }

        $warnings = [];
        $finder = new ExecutableFinder();
        foreach (['git', 'docker', 'jq'] as $binary) {
            if (str_contains($compatibility, "requires {$binary}") && ! $finder->find($binary)) {
                $warnings[] = "compatibility_missing_binary: {$binary}";
            }
        }

        return $warnings;
    }

    /**
     * @return array<int,array{path:string,approved_at:string}>
     */
    private function trustedProjects(): array
    {
        $path = $this->trustedProjectsPath();
        if (! File::isFile($path)) {
            return [];
        }

        $decoded = json_decode(AtlasSecurity::redactString(File::get($path)), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function trustedProjectsPath(): string
    {
        return $this->homePath().'/.atlas/trusted-projects.json';
    }

    private function homePath(): string
    {
        return rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: dirname(base_path())), DIRECTORY_SEPARATOR);
    }

    private function resolveWorkspace(string $workspace): string
    {
        return realpath($workspace) ?: $workspace;
    }

    private function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => Str::of(PHP_OS_FAMILY)->lower()->value(),
        };
    }
}
