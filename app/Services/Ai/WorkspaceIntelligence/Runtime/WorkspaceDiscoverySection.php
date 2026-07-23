<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence\Runtime;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceListNormalizer;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\AtlasCode\GitWorkspaceInspector;

/**
 * GOD-DEBULK FASE C extraction of the AWIS workspace discovery engine
 * (git repository discovery, manifest/stack detection, workspace change memory and
 * provider-safe repository inventory) from AtlasWorkspaceIntelligenceRuntimeService.
 *
 * Method bodies are moved VERBATIM. Shared private helpers that stay on the façade
 * (providerSafeStringList, limitedProviderSafeStringList, fileExists, fileGet) are
 * reached through __call, which rebinds into the façade scope.
 */
final class WorkspaceDiscoverySection
{
    private ?AtlasWorkspaceIntelligenceRuntimeService $mother = null;

    public function __construct(
        private readonly GitWorkspaceInspector $gitWorkspace,
        private readonly AtlasWorkspaceIntelligenceListNormalizer $listNormalizer = new AtlasWorkspaceIntelligenceListNormalizer,
    ) {}

    public function setMother(AtlasWorkspaceIntelligenceRuntimeService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    /**
     * @param  array<int,mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException(self::class.' mother not bound for '.$name);
        }

        // ponytail: verbatim-moved methods still call shared private helpers that live
        // on the façade (providerSafeStringList, fileExists, …). Rebind the call into the
        // façade scope so those private methods stay reachable without widening their
        // visibility. Upgrade path: promote the shared helpers to a Support class if this
        // section ever needs to run without a mother bound.
        return (function () use ($name, $arguments) {
            return $this->{$name}(...$arguments);
        })->call($this->mother);
    }

    /**
     * Provider-safe memory of current workspace changes.
     *
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    public function workspaceChangeMemory(?array $profile): array
    {
        $base = [
            'schema_version' => 'atlas.awis.workspace_change_memory.v1',
            'status' => 'blocked',
            'workspace_id' => $profile['slug'] ?? null,
            'is_git' => false,
            'branch' => null,
            'head_sha_short' => null,
            'dirty' => false,
            'repository_count' => 0,
            'repositories' => [],
            'changed_file_count' => 0,
            'changed_files_preview' => [],
            'changed_files_truncated' => false,
            'top_level_areas' => [],
            'critical_areas_touched' => [],
            'blocker_reason' => null,
            'source_policy' => [
                'raw_diff_returned' => false,
                'absolute_workspace_path_returned' => false,
                'provider_prompt_unit' => 'relative_paths_hashes_and_area_names_only',
                'diff_hash_allowed' => true,
            ],
        ];

        if ($profile === null) {
            $base['blocker_reason'] = 'workspace_not_registered';
            $base['change_hash'] = MissionCanonicalHash::sha256($base);

            return $base;
        }

        $path = (string) ($profile['workspace_path'] ?? '');
        if ($path === '' || ! (bool) ($profile['workspace_path_exists'] ?? false)) {
            $base['blocker_reason'] = 'workspace_path_missing_or_inaccessible';
            $base['change_hash'] = MissionCanonicalHash::sha256($base);

            return $base;
        }

        $rootSnapshot = $this->gitWorkspace->captureProviderSafeSnapshot($path);
        $rootHasDirectGitMetadata = @is_dir(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git')
            || @is_file(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git');
        $nestedRepositories = $this->discoverGitRepositories($path);
        $snapshots = [];
        if (($rootSnapshot['success'] ?? false) === true && ($rootHasDirectGitMetadata || $nestedRepositories === [])) {
            $snapshots[] = ['repo_key' => '.', 'snapshot' => $rootSnapshot];
        } else {
            foreach ($nestedRepositories as $repoKey) {
                $snapshots[] = [
                    'repo_key' => $repoKey,
                    'snapshot' => $this->gitWorkspace->captureProviderSafeSnapshot($path.DIRECTORY_SEPARATOR.$repoKey),
                ];
            }
        }

        if ($snapshots === []) {
            $payload = array_merge($base, [
                'status' => 'limited',
                'blocker_reason' => isset($rootSnapshot['blocker_reason']) && is_string($rootSnapshot['blocker_reason'])
                    ? $rootSnapshot['blocker_reason']
                    : 'git_workspace_not_found',
            ]);
            $payload['change_hash'] = MissionCanonicalHash::sha256($payload);

            return $payload;
        }

        $repositorySummaries = [];
        $diffHashes = [];
        $files = [];
        $filesTruncated = false;
        $successfulSnapshots = 0;
        foreach ($snapshots as $repo) {
            $repoKey = (string) ($repo['repo_key'] ?? '');
            $snapshot = (array) ($repo['snapshot'] ?? []);
            if (($snapshot['success'] ?? false) === true) {
                $successfulSnapshots++;
            }
            if (($snapshot['files_changed_truncated'] ?? false) === true) {
                $filesTruncated = true;
            }
            $repoFiles = array_values(array_filter(
                (array) ($snapshot['files_changed'] ?? []),
                static fn (mixed $file): bool => is_string($file) && trim($file) !== '' && ! str_starts_with(trim($file), '/'),
            ));
            foreach ($repoFiles as $file) {
                $files[] = $repoKey === '.'
                    ? $file
                    : trim($repoKey.'/'.$file, '/');
            }
            if (isset($snapshot['diff_hash']) && is_string($snapshot['diff_hash'])) {
                $diffHashes[] = $repoKey.':'.$snapshot['diff_hash'];
            }
            $repositorySummaries[] = [
                'repo_key' => $repoKey,
                'status' => ($snapshot['success'] ?? false) === true ? 'ready' : 'limited',
                'branch' => isset($snapshot['branch']) && is_string($snapshot['branch']) ? $snapshot['branch'] : null,
                'head_sha_short' => isset($snapshot['head_sha']) && is_string($snapshot['head_sha'])
                    ? substr($snapshot['head_sha'], 0, 12)
                    : null,
                'dirty' => $repoFiles !== [],
                'changed_file_count' => count($repoFiles),
                'blocker_reason' => isset($snapshot['blocker_reason']) && is_string($snapshot['blocker_reason'])
                    ? $snapshot['blocker_reason']
                    : null,
            ];
        }

        $files = array_values(array_filter(
            $this->providerSafeStringList($files),
            static fn (string $file): bool => ! str_starts_with($file, '/'),
        ));
        $preview = array_slice($files, 0, 20);
        $topLevelAreas = $this->topLevelAreas($files);

        $criticalAreasTouched = $this->criticalAreasTouched(
            $files,
            array_values((array) ($profile['critical_areas'] ?? [])),
        );

        $payload = array_merge($base, [
            'status' => $successfulSnapshots > 0 ? 'ready' : 'limited',
            'is_git' => $successfulSnapshots > 0,
            'branch' => count($repositorySummaries) === 1 ? $repositorySummaries[0]['branch'] : null,
            'head_sha_short' => count($repositorySummaries) === 1 ? $repositorySummaries[0]['head_sha_short'] : null,
            'dirty' => $files !== [],
            'repository_count' => count($repositorySummaries),
            'repositories' => $repositorySummaries,
            'changed_file_count' => count($files),
            'changed_files_preview' => $preview,
            'changed_files_truncated' => $filesTruncated || count($files) > count($preview),
            'top_level_areas' => $topLevelAreas,
            'critical_areas_touched' => $criticalAreasTouched,
            'blocker_reason' => $successfulSnapshots > 0 ? null : 'git_workspace_not_ready',
            'diff_hash' => $diffHashes !== [] ? MissionCanonicalHash::sha256($diffHashes) : null,
        ]);
        $payload['change_hash'] = MissionCanonicalHash::sha256([
            'workspace_id' => $payload['workspace_id'],
            'is_git' => $payload['is_git'],
            'repositories' => $payload['repositories'],
            'changed_files_preview' => $payload['changed_files_preview'],
            'changed_files_truncated' => $payload['changed_files_truncated'],
            'top_level_areas' => $payload['top_level_areas'],
            'critical_areas_touched' => $payload['critical_areas_touched'],
            'diff_hash' => $payload['diff_hash'],
        ]);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function discoverGitRepositories(string $workspacePath, int $maxDepth = 3): array
    {
        $workspacePath = rtrim($workspacePath, DIRECTORY_SEPARATOR);
        if ($workspacePath === '' || ! @is_dir($workspacePath)) {
            return [];
        }

        $repos = [];
        $queue = [['path' => $workspacePath, 'relative' => '', 'depth' => 0]];
        $skip = ['.git', 'node_modules', 'vendor', 'storage', 'target', 'build', 'dist', '.next', '.turbo'];

        while ($queue !== []) {
            $current = array_shift($queue);
            $path = (string) ($current['path'] ?? '');
            $relative = (string) ($current['relative'] ?? '');
            $depth = (int) ($current['depth'] ?? 0);

            if ($relative !== '' && (@is_dir($path.DIRECTORY_SEPARATOR.'.git') || @is_file($path.DIRECTORY_SEPARATOR.'.git'))) {
                $repos[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                continue;
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            $entries = @scandir($path);
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
                    continue;
                }
                $childPath = $path.DIRECTORY_SEPARATOR.$entry;
                if (! @is_dir($childPath)) {
                    continue;
                }
                $childRelative = $relative === '' ? $entry : $relative.DIRECTORY_SEPARATOR.$entry;
                $queue[] = ['path' => $childPath, 'relative' => $childRelative, 'depth' => $depth + 1];
            }
        }

        sort($repos);

        return $this->limitedProviderSafeStringList($repos, 12);
    }

    /**
     * @param  array<int,string>  $files
     * @return array<int,string>
     */
    private function topLevelAreas(array $files): array
    {
        $areas = array_map(
            static function (string $file): string {
                $normalized = str_replace('\\', '/', $file);
                $first = explode('/', $normalized, 2)[0] ?? '';

                return trim($first);
            },
            $files,
        );
        $areas = $this->providerSafeStringList($areas);
        sort($areas);

        return $areas;
    }

    /**
     * @param  array<int,string>  $files
     * @param  array<int,string>  $criticalAreas
     * @return array<int,string>
     */
    private function criticalAreasTouched(array $files, array $criticalAreas): array
    {
        $touched = [];
        foreach ($criticalAreas as $area) {
            $area = trim(str_replace('\\', '/', $area));
            if ($area === '') {
                continue;
            }
            $prefix = rtrim(explode('*', $area, 2)[0], '/');
            if ($prefix === '') {
                continue;
            }
            foreach ($files as $file) {
                $file = trim(str_replace('\\', '/', $file));
                if ($file === $prefix || str_starts_with($file, $prefix.'/')) {
                    $touched[] = $area;
                    break;
                }
            }
        }

        return $this->listNormalizer->uniqueStrings($touched);
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    public function workspaceRepositoryInventory(?array $profile): array
    {
        $base = [
            'schema_version' => 'atlas.workspace_repository_inventory.v1',
            'status' => 'blocked',
            'workspace_id' => $profile['slug'] ?? null,
            'repository_count' => 0,
            'repositories' => [],
            'stack' => [],
            'command_hints' => [],
            'source_policy' => [
                'raw_manifest_returned' => false,
                'absolute_workspace_path_returned' => false,
                'script_bodies_returned' => false,
                'provider_prompt_unit' => 'repo_key_manifest_names_stack_tags_and_command_hints_only',
            ],
        ];

        if ($profile === null) {
            $base['blocker_reason'] = 'workspace_not_registered';
            $base['inventory_hash'] = MissionCanonicalHash::sha256($base);

            return $base;
        }

        $workspacePath = (string) ($profile['workspace_path'] ?? '');
        if ($workspacePath === '' || ! (bool) ($profile['workspace_path_exists'] ?? false)) {
            $base['blocker_reason'] = 'workspace_path_missing_or_inaccessible';
            $base['inventory_hash'] = MissionCanonicalHash::sha256($base);

            return $base;
        }

        $repositories = [];
        $stack = [];
        $commandHints = [];
        foreach ($this->discoverWorkspaceRepositoryKeys($workspacePath) as $repoKey) {
            $repoPath = $repoKey === '.'
                ? rtrim($workspacePath, DIRECTORY_SEPARATOR)
                : rtrim($workspacePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$repoKey;
            $signals = $this->repositoryManifestSignals($repoPath, $repoKey);
            if ($signals === null) {
                continue;
            }

            $repositories[] = $signals;
            $stack = array_merge($stack, (array) ($signals['stack'] ?? []));
            $commandHints = array_merge($commandHints, (array) ($signals['command_hints'] ?? []));
        }

        $stack = $this->providerSafeStringList($stack);
        sort($stack);
        $commandHints = $this->providerSafeStringList($commandHints);

        $payload = array_merge($base, [
            'status' => $repositories !== [] ? 'ready' : 'limited',
            'repository_count' => count($repositories),
            'repositories' => $repositories,
            'stack' => $stack,
            'command_hints' => $commandHints,
            'blocker_reason' => $repositories === [] ? 'workspace_manifests_not_found' : null,
        ]);
        $payload['inventory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function discoverWorkspaceRepositoryKeys(string $workspacePath): array
    {
        $keys = [];
        $root = rtrim($workspacePath, DIRECTORY_SEPARATOR);

        if (@is_dir($root.DIRECTORY_SEPARATOR.'.git') || @is_file($root.DIRECTORY_SEPARATOR.'.git')) {
            $keys[] = '.';
        }

        $keys = array_merge($keys, $this->discoverGitRepositories($root));

        foreach ($this->discoverManifestDirectories($root) as $manifestDirectory) {
            $keys[] = $manifestDirectory;
        }

        $keys = $this->providerSafeStringList($keys);
        usort($keys, static function (string $left, string $right): int {
            if ($left === '.') {
                return -1;
            }
            if ($right === '.') {
                return 1;
            }

            return $left <=> $right;
        });

        return array_slice($keys, 0, 24);
    }

    /**
     * @return array<int,string>
     */
    private function discoverManifestDirectories(string $workspacePath, int $maxDepth = 3): array
    {
        $workspacePath = rtrim($workspacePath, DIRECTORY_SEPARATOR);
        if ($workspacePath === '' || ! @is_dir($workspacePath)) {
            return [];
        }

        $directories = [];
        $queue = [['path' => $workspacePath, 'relative' => '', 'depth' => 0]];
        $skip = ['.git', 'node_modules', 'vendor', 'storage', 'target', 'build', 'dist', '.next', '.turbo'];

        while ($queue !== []) {
            $current = array_shift($queue);
            $path = (string) ($current['path'] ?? '');
            $relative = (string) ($current['relative'] ?? '');
            $depth = (int) ($current['depth'] ?? 0);

            if ($relative !== '' && $this->hasWorkspaceManifest($path)) {
                $directories[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            $entries = @scandir($path);
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
                    continue;
                }
                $childPath = $path.DIRECTORY_SEPARATOR.$entry;
                if (! @is_dir($childPath)) {
                    continue;
                }
                $childRelative = $relative === '' ? $entry : $relative.DIRECTORY_SEPARATOR.$entry;
                $queue[] = ['path' => $childPath, 'relative' => $childRelative, 'depth' => $depth + 1];
            }
        }

        sort($directories);

        return $this->listNormalizer->uniqueStrings($directories);
    }

    private function hasWorkspaceManifest(string $path): bool
    {
        foreach (['composer.json', 'package.json', 'tsconfig.json', 'pnpm-workspace.yaml', 'artisan'] as $file) {
            if ($this->fileExists(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function repositoryManifestSignals(string $repoPath, string $repoKey): ?array
    {
        $manifestFiles = [];
        $stack = [];
        $commandHints = [];
        $scriptNames = [];
        $packageManager = null;

        if ($this->fileExists($repoPath.DIRECTORY_SEPARATOR.'composer.json')) {
            $manifestFiles[] = 'composer.json';
            $stack[] = 'php';
            $composer = $this->jsonFile($repoPath.DIRECTORY_SEPARATOR.'composer.json');
            $composerPackages = array_keys(array_merge(
                (array) ($composer['require'] ?? []),
                (array) ($composer['require-dev'] ?? []),
            ));
            if (in_array('laravel/framework', $composerPackages, true) || $this->fileExists($repoPath.DIRECTORY_SEPARATOR.'artisan')) {
                $stack[] = 'laravel';
            }
            $composerScripts = array_keys((array) ($composer['scripts'] ?? []));
            foreach ($composerScripts as $script) {
                if (is_string($script) && $script !== '') {
                    $scriptNames[] = 'composer:'.$script;
                }
            }
            if (in_array('test', $composerScripts, true)) {
                $commandHints[] = $repoKey === '.' ? 'composer test' : 'cd '.$repoKey.' && composer test';
            }
        }

        if ($this->fileExists($repoPath.DIRECTORY_SEPARATOR.'package.json')) {
            $manifestFiles[] = 'package.json';
            $stack[] = 'node';
            $packageManager = $this->fileExists($repoPath.DIRECTORY_SEPARATOR.'pnpm-lock.yaml') ? 'pnpm'
                : ($this->fileExists($repoPath.DIRECTORY_SEPARATOR.'yarn.lock') ? 'yarn' : 'npm');
            $package = $this->jsonFile($repoPath.DIRECTORY_SEPARATOR.'package.json');
            $packages = array_keys(array_merge(
                (array) ($package['dependencies'] ?? []),
                (array) ($package['devDependencies'] ?? []),
            ));
            foreach ([
                'react' => 'react',
                'typescript' => 'typescript',
                'expo' => 'expo',
                '@tauri-apps/api' => 'tauri',
                'vite' => 'vite',
            ] as $dependency => $tag) {
                if (in_array($dependency, $packages, true)) {
                    $stack[] = $tag;
                }
            }
            $scripts = array_keys((array) ($package['scripts'] ?? []));
            foreach ($scripts as $script) {
                if (is_string($script) && $script !== '') {
                    $scriptNames[] = 'npm:'.$script;
                }
            }
            foreach (['test', 'typecheck', 'build'] as $script) {
                if (in_array($script, $scripts, true)) {
                    $command = $packageManager.' run '.$script;
                    $commandHints[] = $repoKey === '.' ? $command : 'cd '.$repoKey.' && '.$command;
                }
            }
        }

        foreach ([
            'tsconfig.json' => 'typescript',
            'pnpm-workspace.yaml' => 'node',
            'artisan' => 'laravel',
            'src-tauri/tauri.conf.json' => 'tauri',
            'apps/desktop/src-tauri/tauri.conf.json' => 'tauri',
            'app.json' => 'expo',
        ] as $file => $tag) {
            if ($this->fileExists($repoPath.DIRECTORY_SEPARATOR.$file)) {
                $manifestFiles[] = $file;
                $stack[] = $tag;
            }
        }

        $manifestFiles = $this->listNormalizer->uniqueStrings($manifestFiles);
        if ($manifestFiles === []) {
            return null;
        }

        $stack = $this->listNormalizer->uniqueStrings($stack);
        sort($stack);
        $scriptNames = $this->listNormalizer->uniqueStrings($scriptNames);
        sort($scriptNames);

        return [
            'repo_key' => $repoKey,
            'manifest_files' => $manifestFiles,
            'stack' => $stack,
            'package_manager' => $packageManager,
            'script_names' => array_slice($scriptNames, 0, 20),
            'command_hints' => $this->listNormalizer->uniqueStrings($commandHints),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $contents = $this->fileGet($path);
        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
