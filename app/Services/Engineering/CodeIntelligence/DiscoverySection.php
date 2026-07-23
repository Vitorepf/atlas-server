<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringStringListNormalizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

/**
 * GOD-DEBULK FASE C - the file-discovery / workspace-path family extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Bodies are byte-identical; only cross-family
 * `$this->helper(` calls were redirected to injected collaborators.
 */
class DiscoverySection
{

    /**
     * @return array<int,string>
     */
    public function discoverFiles(string $workspace): array
    {
        $roots = [
            'app',
            'src',
            'routes',
            'database/migrations',
            'tests',
            'docs/engineering-knowledge-base',
            'config',
            'lib',
            'components',
            'packages',
            'source',
            'scripts',
        ];

        $reject = static function (SplFileInfo $file): bool {
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file->getPathname());
            foreach ([
                '.git',
                '.next',
                '.turbo',
                '.expo',
                '.gradle',
                '.dart_tool',
                'build',
                'coverage',
                'DerivedData',
                'dist',
                'node_modules',
                'Pods',
                'storage',
                'target',
                'vendor',
            ] as $segment) {
                if (str_contains($path, DIRECTORY_SEPARATOR.$segment.DIRECTORY_SEPARATOR)) {
                    return true;
                }
            }

            return false;
        };

        $collect = fn (array $dirs): array => collect($dirs)
            ->filter(fn (string $root): bool => File::isDirectory($root))
            ->flatMap(fn (string $root): array => File::allFiles($root))
            ->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), EngineeringCodeIntelligenceService::EXTENSIONS, true))
            ->reject($reject)
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();

        $scanRoots = $this->workspaceScanRoots($workspace);
        $files = [];
        foreach ($scanRoots as $index => $scanRoot) {
            $rootFiles = $collect(
                collect($roots)
                    ->map(fn (string $root): string => $scanRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $root))
                    ->all()
            );

            // A nested repo may keep source under non-standard paths (for example
            // apps/desktop/src). Fall back to scanning that repo root only for nested
            // repos, keeping the umbrella root on explicit roots to avoid sweeping every
            // dependency/cache folder in a broad workspace.
            if ($rootFiles === [] && $index > 0 && File::isDirectory($scanRoot)) {
                $rootFiles = $collect([$scanRoot]);
            }

            $files = array_merge($files, $rootFiles);
        }

        // AP-815 W-2: cross-project fallback — a repo laid out differently from atlas-server
        // (no app/src/routes/... roots) still gets indexed by scanning the workspace root
        // directly (deps excluded). atlas-server always matches its roots, so $files is never
        // empty for it and this fallback never alters its behavior.
        if ($files === [] && File::isDirectory($workspace)) {
            $files = $collect([$workspace]);
        }

        return collect($files)->unique()->sort()->values()->all();
    }


    /**
     * @return array<int,string>
     */
    public function workspaceScanRoots(string $workspace): array
    {
        $root = $this->canonicalDirectory($workspace);
        if ($root === null) {
            return [$workspace];
        }

        $configured = $this->configuredWorkspaceScanRoots($root);
        if ($configured !== null) {
            return $configured;
        }

        return collect([$root])
            ->merge($this->nestedGitRepositories($root))
            ->unique()
            ->values()
            ->all();
    }


    /**
     * @return array<int,string>|null
     */
    public function configuredWorkspaceScanRoots(string $workspace): ?array
    {
        try {
            if (! function_exists('app')) {
                return null;
            }

            $profile = app(AtlasCodeWorkspaceProfileService::class)->findByReference($workspace);
            $configured = is_array($profile) ? (array) ($profile['code_index_roots'] ?? []) : [];
            if ($configured === []) {
                return null;
            }

            $base = $this->canonicalDirectory((string) ($profile['workspace_path'] ?? '')) ?? $workspace;
            $roots = [];
            foreach ($configured as $entry) {
                if (! is_string($entry) || trim($entry) === '') {
                    continue;
                }

                $entry = trim($entry);
                $path = $entry === '.'
                    ? $base
                    : $base.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entry);
                $canonical = $this->canonicalDirectory($path);
                if ($canonical !== null) {
                    $roots[] = $canonical;
                }
            }

            return $roots !== [] ? EngineeringStringListNormalizer::uniqueStringCasts($roots) : null;
        } catch (Throwable) {
            return null;
        }
    }


    /**
     * @return array<int,string>
     */
    public function nestedGitRepositories(string $workspace, int $maxDepth = 4): array
    {
        $repos = [];
        $queue = [[$workspace, 0]];
        $skip = [
            '.git' => true,
            '.next' => true,
            '.turbo' => true,
            '.expo' => true,
            '.gradle' => true,
            '.dart_tool' => true,
            'build' => true,
            'coverage' => true,
            'DerivedData' => true,
            'dist' => true,
            'node_modules' => true,
            'Pods' => true,
            'storage' => true,
            'target' => true,
            'vendor' => true,
        ];

        while ($queue !== []) {
            [$dir, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) {
                continue;
            }

            foreach (File::directories($dir) as $child) {
                $name = basename($child);
                if (isset($skip[$name])) {
                    continue;
                }

                if (File::isDirectory($child.DIRECTORY_SEPARATOR.'.git') || File::isFile($child.DIRECTORY_SEPARATOR.'.git')) {
                    $canonical = $this->canonicalDirectory($child);
                    if ($canonical !== null) {
                        $repos[] = $canonical;
                    }

                    continue;
                }

                $queue[] = [$child, $depth + 1];
            }
        }

        sort($repos);

        return EngineeringStringListNormalizer::uniqueStringCasts($repos);
    }


    /**
     * @return array<int,string>
     */
    public function workspaceAnalysisPrefixes(string $workspace): array
    {
        $root = $this->canonicalDirectory($workspace);
        if ($root === null) {
            return [];
        }

        return collect($this->workspaceScanRoots($workspace))
            ->map(fn (string $scanRoot): ?string => $this->canonicalDirectory($scanRoot))
            ->filter(fn (?string $scanRoot): bool => is_string($scanRoot) && $scanRoot !== $root && str_starts_with($scanRoot, $root.DIRECTORY_SEPARATOR))
            ->map(fn (string $scanRoot): string => str_replace(DIRECTORY_SEPARATOR, '/', substr($scanRoot, strlen($root) + 1)))
            ->filter(fn (string $prefix): bool => $prefix !== '')
            ->unique()
            ->sortByDesc(fn (string $prefix): int => strlen($prefix))
            ->values()
            ->all();
    }


    /**
     * Use sub-repo-local paths for parsing/classification while keeping the umbrella path
     * as the persisted identity.
     *
     * @param  array<int,string>  $prefixes
     */
    public function analysisPathForRelativePath(string $relativePath, array $prefixes): string
    {
        foreach ($prefixes as $prefix) {
            if ($relativePath === $prefix) {
                return basename($relativePath);
            }

            if (str_starts_with($relativePath, $prefix.'/')) {
                $analysisPath = substr($relativePath, strlen($prefix) + 1);

                return $analysisPath !== '' ? $analysisPath : basename($relativePath);
            }
        }

        return $relativePath;
    }


    public function analysisPrefixForRelativePath(string $relativePath, string $analysisPath): ?string
    {
        if ($analysisPath === '' || $analysisPath === $relativePath) {
            return null;
        }

        if (str_ends_with($relativePath, '/'.$analysisPath)) {
            $prefix = substr($relativePath, 0, -strlen('/'.$analysisPath));

            return $prefix !== '' ? $prefix : null;
        }

        return null;
    }


    /**
     * @param  array<string,mixed>  $module
     * @return array<string,mixed>
     */
    public function qualifyModuleForAnalysisPath(array $module, string $relativePath, string $analysisPath): array
    {
        $prefix = $this->analysisPrefixForRelativePath($relativePath, $analysisPath);
        if ($prefix === null) {
            return $module;
        }

        $prefixSlug = Str::slug($prefix, '_');
        if ($prefixSlug === '') {
            return $module;
        }

        $rootPath = trim((string) ($module['root_path'] ?? ''), '/');
        $prefixName = (string) Str::of($prefix)
            ->replace(['/', '-', '_'], ' ')
            ->title();

        $module['slug'] = $this->qualifyModuleSlug($prefixSlug, (string) ($module['slug'] ?? 'workspace_misc'));
        $module['name'] = trim($prefixName.' '.(string) ($module['name'] ?? 'Module'));
        $module['root_path'] = trim($prefix.'/'.($rootPath !== '' ? $rootPath : dirname($analysisPath)), '/');
        $module['tags'] = EngineeringStringListNormalizer::uniqueStringCasts(array_merge(
            is_array($module['tags'] ?? null) ? $module['tags'] : [],
            array_filter(explode('/', $prefix)),
        ));

        return $module;
    }


    public function qualifyTargetModuleForAnalysisPath(mixed $moduleSlug, string $relativePath, string $analysisPath): mixed
    {
        if (! is_string($moduleSlug) || $moduleSlug === '' || $moduleSlug === 'external_package') {
            return $moduleSlug;
        }

        $prefix = $this->analysisPrefixForRelativePath($relativePath, $analysisPath);
        if ($prefix === null) {
            return $moduleSlug;
        }

        $prefixSlug = Str::slug($prefix, '_');
        if ($prefixSlug === '' || str_starts_with($moduleSlug, $prefixSlug.'_')) {
            return $moduleSlug;
        }

        return $this->qualifyModuleSlug($prefixSlug, $moduleSlug);
    }


    public function qualifyModuleSlug(string $prefixSlug, string $moduleSlug): string
    {
        return Str::slug($prefixSlug.'_'.$moduleSlug, '_');
    }


    public function canonicalDirectory(string $path): ?string
    {
        if (! File::isDirectory($path)) {
            return null;
        }

        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, DIRECTORY_SEPARATOR);
    }
}
