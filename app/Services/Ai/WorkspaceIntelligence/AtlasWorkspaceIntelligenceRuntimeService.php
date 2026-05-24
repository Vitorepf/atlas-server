<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Services\AtlasCode\GitWorkspaceInspector;
use Illuminate\Support\Carbon;

/**
 * Atlas Workspace Intelligence System runtime.
 *
 * Read-only first implementation of the AWIS family:
 * - AWIS: workspace binding/readiness.
 * - AWTR: workspace twin projection.
 * - ACIOS: long-conversation continuity without raw prompt stuffing.
 * - AWAF: operational artifacts.
 * - AWAIR: artifact intelligence, replay, simulation and visual projection.
 * - AWCO: artifact certification/readiness.
 * - AWEF: cross-workspace pattern hints without private transfer.
 */
final class AtlasWorkspaceIntelligenceRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.workspace_intelligence.runtime.v1';

    public const FAMILY = [
        'AWIS' => 'Atlas Workspace Intelligence System',
        'AWTR' => 'Atlas Workspace Twin Runtime',
        'ACIOS' => 'Atlas Continuity Intelligence OS',
        'AWAF' => 'Atlas Workspace Artifact Fabric',
        'AWAIR' => 'Atlas Workspace Artifact Intelligence Runtime',
        'AWCO' => 'Atlas Workspace Contract Orchestrator',
        'AWEF' => 'Atlas Workspace Evolution Fabric',
    ];

    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $profiles,
        private readonly AtlasWorkspaceExecutionBoundaryAuditService $boundaryAudit,
        private readonly GitWorkspaceInspector $gitWorkspace,
    ) {}

    /**
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    public function certify(?string $workspace = null, string $task = '', array $conversationTexts = []): array
    {
        $profile = $this->resolveProfile($workspace);
        $workspaceReport = $this->workspaceReport($profile);
        $changeMemory = $this->workspaceChangeMemory($profile);
        $twin = $this->workspaceTwin($profile);
        $repositoryInventory = (array) data_get($twin, 'repository_inventory', []);
        $continuity = $this->continuity($profile, $conversationTexts);
        $focusMap = $this->workspaceFocusMap($profile, $task, $twin, $changeMemory);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap);
        $artifactIntelligence = $this->artifactIntelligence($profile, $task, $artifacts, $twin, $continuity);
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $nextSessionBrain = $this->workspaceNextSessionBrain($profile, $task, $workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap);
        $learningLoop = $this->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain);
        $executionBoundaries = $this->executionBoundarySummary($this->boundaryAudit->audit());
        $registryEditing = $this->registryEditingSummary();
        $surfaceContracts = $this->surfaceContractSummary();
        $checks = $this->checks($workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $executionBoundaries, $registryEditing, $surfaceContracts, $learningLoop, $changeMemory, $focusMap, $nextSessionBrain, $repositoryInventory);
        $summary = $this->summary($checks);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toISOString(),
            'status' => $this->status($summary),
            'summary' => $summary,
            'family' => self::FAMILY,
            'workspace' => $workspaceReport,
            'repository_inventory' => $repositoryInventory,
            'workspace_change_memory' => $changeMemory,
            'workspace_focus_map' => $focusMap,
            'workspace_next_session_brain' => $nextSessionBrain,
            'awtr' => $twin,
            'acios' => $continuity,
            'awaf' => $artifacts,
            'awair' => $artifactIntelligence,
            'awco' => $contracts,
            'awef' => $evolution,
            'awis_learning_loop' => $learningLoop,
            'execution_boundaries' => $executionBoundaries,
            'registry_editing' => $registryEditing,
            'surface_contracts' => $surfaceContracts,
            'checks' => $checks,
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'transfers_raw_cross_workspace' => false,
                'raw_conversation_used_as_prompt' => false,
                'known_execution_boundaries_audited' => data_get($executionBoundaries, 'status') === 'ready',
                'unclassified_workspace_process_boundaries_allowed' => false,
                'execution_boundary_audit_required' => true,
                'ui_registry_editing_complete' => data_get($registryEditing, 'status') === 'ready',
                'desktop_mobile_surface_contracts_complete' => data_get($surfaceContracts, 'status') === 'ready',
                'workspace_learning_loop_closed' => data_get($learningLoop, 'closed_loop.loop_closed') === true,
                'workspace_repository_inventory_provider_safe' => data_get($repositoryInventory, 'source_policy.raw_manifest_returned') === false
                    && data_get($repositoryInventory, 'source_policy.script_bodies_returned') === false
                    && data_get($repositoryInventory, 'source_policy.absolute_workspace_path_returned') === false,
                'workspace_change_memory_provider_safe' => data_get($changeMemory, 'source_policy.raw_diff_returned') === false
                    && data_get($changeMemory, 'source_policy.absolute_workspace_path_returned') === false,
                'workspace_focus_map_provider_safe' => data_get($focusMap, 'source_policy.raw_file_content_returned') === false
                    && data_get($focusMap, 'source_policy.absolute_workspace_path_returned') === false,
                'workspace_next_session_brain_provider_safe' => data_get($nextSessionBrain, 'source_policy.raw_file_content_returned') === false
                    && data_get($nextSessionBrain, 'source_policy.raw_conversation_returned') === false
                    && data_get($nextSessionBrain, 'source_policy.absolute_workspace_path_returned') === false,
            ],
        ];

        $payload['runtime_hash'] = $this->hashWithoutGeneratedAt($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function twin(?string $workspace = null): array
    {
        return $this->workspaceTwin($this->resolveProfile($workspace));
    }

    /**
     * @return array<string,mixed>
     */
    public function contractOrchestration(?string $workspace = null, string $task = ''): array
    {
        $profile = $this->resolveProfile($workspace);
        $workspaceReport = $this->workspaceReport($profile);
        $twin = $this->workspaceTwin($profile);
        $continuity = $this->continuity($profile, []);
        $changeMemory = $this->workspaceChangeMemory($profile);
        $focusMap = $this->workspaceFocusMap($profile, $task, $twin, $changeMemory);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap);

        return $this->contracts($workspaceReport, $artifacts);
    }

    /**
     * @return array<string,mixed>
     */
    public function evolutionFabric(?string $workspace = null): array
    {
        $profile = $this->resolveProfile($workspace);

        return $this->evolution($profile, $this->workspaceTwin($profile));
    }

    /**
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    public function learningLoop(?string $workspace = null, string $task = '', array $conversationTexts = []): array
    {
        $profile = $this->resolveProfile($workspace);
        $workspaceReport = $this->workspaceReport($profile);
        $changeMemory = $this->workspaceChangeMemory($profile);
        $twin = $this->workspaceTwin($profile);
        $continuity = $this->continuity($profile, $conversationTexts);
        $focusMap = $this->workspaceFocusMap($profile, $task, $twin, $changeMemory);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap);
        $artifactIntelligence = $this->artifactIntelligence($profile, $task, $artifacts, $twin, $continuity);
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $nextSessionBrain = $this->workspaceNextSessionBrain($profile, $task, $workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap);

        return $this->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain);
    }

    /**
     * @return array<string,mixed>
     */
    public function nextSessionBrain(?string $workspace = null, string $task = ''): array
    {
        $report = $this->certify(workspace: $workspace, task: $task, conversationTexts: []);

        return (array) ($report['workspace_next_session_brain'] ?? []);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveProfile(?string $workspace): ?array
    {
        $slug = is_string($workspace) && trim($workspace) !== ''
            ? trim($workspace)
            : $this->profiles->defaultSlug();

        return $this->profiles->findBySlug($slug) ?? $this->profiles->findByPath($slug);
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function workspaceReport(?array $profile): array
    {
        if ($profile === null) {
            return [
                'schema_version' => 'atlas.awis.workspace_binding.v1',
                'status' => 'blocked',
                'workspace_id' => null,
                'workspace_active' => false,
                'readiness_status' => 'blocked',
                'blockers' => ['workspace_not_registered'],
            ];
        }

        $path = (string) ($profile['workspace_path'] ?? '');
        $exists = (bool) ($profile['workspace_path_exists'] ?? false);
        $rootHash = $exists ? $this->workspaceRootHash($path) : null;
        $blockers = [];
        if (! $exists) {
            $blockers[] = 'workspace_path_missing_or_inaccessible';
        }
        if ((array) ($profile['test_commands'] ?? []) === []) {
            $blockers[] = 'workspace_test_commands_missing';
        }

        return [
            'schema_version' => 'atlas.awis.workspace_binding.v1',
            'status' => $blockers === [] ? 'ready' : 'limited',
            'workspace_id' => (string) $profile['slug'],
            'workspace_name' => (string) $profile['name'],
            'workspace_active' => true,
            'workspace_path_exists' => $exists,
            'workspace_hash' => $rootHash,
            'memory_scope' => 'workspace',
            'cartography_scope' => 'workspace',
            'readiness_status' => $blockers === [] ? 'ready' : 'limited',
            'blockers' => $blockers,
            'commands_count' => count((array) ($profile['commands'] ?? [])),
            'test_commands_count' => count((array) ($profile['test_commands'] ?? [])),
            'critical_areas_count' => count((array) ($profile['critical_areas'] ?? [])),
        ];
    }

    /**
     * Provider-safe memory of current workspace changes.
     *
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function workspaceChangeMemory(?array $profile): array
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

        $files = array_values(array_unique($files));
        $files = array_values(array_filter(
            $files,
            static fn (mixed $file): bool => is_string($file) && trim($file) !== '' && ! str_starts_with(trim($file), '/'),
        ));
        $preview = array_slice($files, 0, 20);
        $topLevelAreas = array_values(array_unique(array_filter(array_map(
            static function (string $file): string {
                $normalized = str_replace('\\', '/', $file);
                $first = explode('/', $normalized, 2)[0] ?? '';

                return trim($first);
            },
            $files,
        ))));
        sort($topLevelAreas);

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

        return array_slice(array_values(array_unique($repos)), 0, 12);
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

        return array_values(array_unique($touched));
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function workspaceRepositoryInventory(?array $profile): array
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

        $stack = array_values(array_unique(array_filter($stack, 'is_string')));
        sort($stack);
        $commandHints = array_values(array_unique(array_filter($commandHints, 'is_string')));

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

        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
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

        return array_values(array_unique($directories));
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

        $manifestFiles = array_values(array_unique($manifestFiles));
        if ($manifestFiles === []) {
            return null;
        }

        $stack = array_values(array_unique($stack));
        sort($stack);
        $scriptNames = array_values(array_unique($scriptNames));
        sort($scriptNames);

        return [
            'repo_key' => $repoKey,
            'manifest_files' => $manifestFiles,
            'stack' => $stack,
            'package_manager' => $packageManager,
            'script_names' => array_slice($scriptNames, 0, 20),
            'command_hints' => array_values(array_unique($commandHints)),
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

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $changeMemory
     * @return array<string,mixed>
     */
    private function workspaceFocusMap(?array $profile, string $task, array $twin, array $changeMemory): array
    {
        $task = mb_strtolower(trim($task));
        $changedFiles = array_values((array) data_get($changeMemory, 'changed_files_preview', []));
        $criticalAreas = array_values((array) ($profile['critical_areas'] ?? []));
        $criticalTouched = array_values((array) data_get($changeMemory, 'critical_areas_touched', []));
        $repositories = array_values(array_filter(
            (array) data_get($twin, 'repository_inventory.repositories', []),
            'is_array',
        ));
        $focusedRepositories = [];

        foreach ($repositories as $repository) {
            $repoKey = (string) ($repository['repo_key'] ?? '');
            if ($repoKey === '') {
                continue;
            }

            $stack = array_values((array) ($repository['stack'] ?? []));
            $score = 0;
            $reasons = [];
            foreach ($changedFiles as $file) {
                if (is_string($file) && ($repoKey === '.' || $file === $repoKey || str_starts_with($file, $repoKey.'/'))) {
                    $score += 4;
                    $reasons[] = 'changed_files';
                    break;
                }
            }
            foreach ($criticalTouched as $area) {
                if (is_string($area) && ($repoKey === '.' || $area === $repoKey || str_starts_with($area, $repoKey.'/'))) {
                    $score += 3;
                    $reasons[] = 'critical_area_touched';
                    break;
                }
            }
            foreach ($stack as $tag) {
                if (is_string($tag) && $task !== '' && str_contains($task, mb_strtolower($tag))) {
                    $score += 2;
                    $reasons[] = 'task_mentions_stack';
                    break;
                }
            }
            if ($task !== '' && str_contains($task, str_replace(['-', '_'], ' ', mb_strtolower($repoKey)))) {
                $score += 2;
                $reasons[] = 'task_mentions_repo';
            }
            if ($score === 0 && count($focusedRepositories) < 2) {
                $score = 1;
                $reasons[] = 'workspace_baseline';
            }

            if ($score <= 0) {
                continue;
            }

            $focusedRepositories[] = [
                'repo_key' => $repoKey,
                'score' => $score,
                'reasons' => array_values(array_unique($reasons)),
                'stack' => $stack,
                'command_hints' => array_slice(array_values((array) ($repository['command_hints'] ?? [])), 0, 6),
            ];
        }

        usort($focusedRepositories, static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: ((string) $left['repo_key'] <=> (string) $right['repo_key']));
        $focusedRepositories = array_slice($focusedRepositories, 0, 4);

        $focusedAreas = array_values(array_unique(array_filter(array_merge(
            $criticalTouched,
            array_slice($criticalAreas, 0, $criticalTouched === [] ? 4 : 2),
            array_values((array) data_get($changeMemory, 'top_level_areas', [])),
        ), 'is_string')));

        $focusedCommands = [];
        foreach ($focusedRepositories as $repository) {
            foreach ((array) ($repository['command_hints'] ?? []) as $command) {
                if (is_string($command) && $command !== '') {
                    $focusedCommands[] = $command;
                }
            }
        }
        $focusedCommands = array_values(array_unique(array_merge(
            $focusedCommands,
            array_values((array) ($profile['test_commands'] ?? [])),
        )));

        $payload = [
            'schema_version' => 'atlas.awis.workspace_focus_map.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $profile['slug'] ?? null,
            'task_hash' => trim($task) !== '' ? hash('sha256', $task) : null,
            'focused_repositories' => $focusedRepositories,
            'focused_areas' => array_slice($focusedAreas, 0, 8),
            'focused_files_preview' => array_slice($changedFiles, 0, 12),
            'focused_commands' => array_slice($focusedCommands, 0, 10),
            'context_units' => [
                'workspace_brief',
                'repository_inventory',
                'workspace_change_memory',
                'focused_files_preview',
                'focused_commands',
                'risk_sheet',
            ],
            'source_policy' => [
                'raw_file_content_returned' => false,
                'raw_diff_returned' => false,
                'raw_manifest_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['focus_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function workspaceTwin(?array $profile): array
    {
        if ($profile === null) {
            return [
                'schema_version' => 'atlas.workspace_twin.v1',
                'status' => 'blocked',
                'blockers' => ['workspace_not_registered'],
            ];
        }

        $path = (string) ($profile['workspace_path'] ?? '');
        $exists = (bool) ($profile['workspace_path_exists'] ?? false);
        $repositoryInventory = $this->workspaceRepositoryInventory($profile);
        $stack = array_values(array_unique(array_merge(
            $this->detectStack($path, (string) ($profile['stack_summary'] ?? '')),
            array_values((array) data_get($repositoryInventory, 'stack', [])),
        )));
        $docs = $this->ownerDocs($path);
        $commands = array_values(array_filter(array_merge(
            array_values((array) ($profile['commands'] ?? [])),
            (array) ($profile['test_commands'] ?? []),
            (array) ($profile['build_commands'] ?? []),
            (array) data_get($repositoryInventory, 'command_hints', []),
        ), 'is_string'));

        $genome = [
            'schema_version' => 'atlas.workspace_genome.v1',
            'workspace_id' => (string) $profile['slug'],
            'stack' => $stack,
            'docs_status' => (string) ($profile['docs_status'] ?? 'unknown'),
            'production_status' => (string) ($profile['production_status'] ?? 'unknown'),
            'risk_floor' => (string) data_get($profile, 'safety.risk_floor', $profile['default_risk'] ?? 'medium'),
            'owner_docs' => $docs,
            'test_families' => array_values((array) ($profile['test_commands'] ?? [])),
            'risk_zones' => array_values((array) ($profile['critical_areas'] ?? [])),
            'repository_count' => (int) data_get($repositoryInventory, 'repository_count', 0),
        ];
        $genome['genome_hash'] = MissionCanonicalHash::sha256($genome);

        $livingCodeMap = [
            'schema_version' => 'atlas.workspace_living_code_map.v1',
            'root_exists' => $exists,
            'repository_inventory' => $repositoryInventory,
            'owner_docs' => $docs,
            'critical_areas' => array_values((array) ($profile['critical_areas'] ?? [])),
            'source_policy' => 'repo_docs_code_tests_and_receipts_only',
        ];
        $livingCodeMap['code_map_hash'] = MissionCanonicalHash::sha256($livingCodeMap);

        $commandRegistry = [
            'schema_version' => 'atlas.workspace_command_registry.v1',
            'commands' => $commands,
            'command_count' => count($commands),
            'has_test_entrypoint' => (array) ($profile['test_commands'] ?? []) !== [],
        ];
        $commandRegistry['command_registry_hash'] = MissionCanonicalHash::sha256($commandRegistry);

        $riskMap = $this->riskMap($profile);
        $riskMap['risk_map_hash'] = MissionCanonicalHash::sha256($riskMap);

        $payload = [
            'schema_version' => 'atlas.workspace_twin.v1',
            'status' => $exists ? 'ready' : 'limited',
            'workspace_id' => (string) $profile['slug'],
            'genome' => $genome,
            'living_code_map' => $livingCodeMap,
            'context_autopilot' => [
                'schema_version' => 'atlas.workspace_context_autopilot.v1',
                'strategy' => 'owner_docs_plus_task_artifacts_plus_focused_tests',
                'required_context_units' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
                'raw_conversation_policy' => 'hash_and_excerpt_only',
                'stale_policy' => 'block_mutative_execution_when_workspace_or_twin_not_ready',
            ],
            'test_command_intelligence' => [
                'schema_version' => 'atlas.workspace_test_command_intelligence.v1',
                'commands' => array_values((array) ($profile['test_commands'] ?? [])),
                'has_focused_entrypoint' => (array) ($profile['test_commands'] ?? []) !== [],
                'fallback_policy' => 'block_or_request_operator_test_command_when_missing',
            ],
            'command_registry' => $commandRegistry,
            'risk_fragility_map' => $riskMap,
            'repository_inventory' => $repositoryInventory,
            'provider_skill_memory' => [
                'schema_version' => 'atlas.workspace_provider_skill_memory.v1',
                'status' => 'shadow',
                'decision_policy' => 'never_route_provider_from_unverified_preference',
            ],
            'workspace_learning_loop' => [
                'schema_version' => 'atlas.workspace_learning_loop.v1',
                'status' => 'ready_for_outcome_bridge',
                'feeds' => ['AEMOR', 'AWEF', 'workspace_runbook', 'awis_learning_loop'],
                'default_loop' => 'event_understanding_memory_context_action_evidence_learning',
            ],
            'stale' => false,
        ];
        $payload['twin_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    private function continuity(?array $profile, array $conversationTexts): array
    {
        $segments = [];
        $decisions = [];
        $blockers = [];
        foreach ($conversationTexts as $index => $text) {
            $clean = trim($text);
            if ($clean === '') {
                continue;
            }
            $segments[] = [
                'source' => 'conversation_'.$index,
                'source_hash' => hash('sha256', $clean),
                'excerpt' => mb_substr(preg_replace('/\s+/', ' ', $clean) ?? $clean, 0, 180),
            ];
            if (preg_match_all('/\b(decidido|decisao|decision|bloqueio|blocker|feito|done)\b/iu', $clean, $matches)) {
                foreach ($matches[1] as $match) {
                    $lower = mb_strtolower((string) $match);
                    if (str_contains($lower, 'bloque') || str_contains($lower, 'blocker')) {
                        $blockers[] = 'conversation_mentions_blocker';
                    } else {
                        $decisions[] = 'conversation_mentions_'.$lower;
                    }
                }
            }
        }

        $truthPack = [
            'workspace_id' => $profile['slug'] ?? null,
            'current_goal' => null,
            'active_decisions' => array_values(array_unique($decisions)),
            'open_blockers' => array_values(array_unique($blockers)),
            'canonical_sources' => [
                'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                'docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md',
            ],
            'raw_conversation_in_prompt' => false,
        ];

        $payload = [
            'schema_version' => 'atlas.continuity_intelligence.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $profile['slug'] ?? null,
            'raw_archive' => [
                'conversation_count' => count($conversationTexts),
                'archive_hash' => MissionCanonicalHash::sha256(array_map(
                    static fn (string $text): string => hash('sha256', $text),
                    $conversationTexts,
                )),
                'access_mode' => 'audit_only',
            ],
            'segmentation_map' => $segments,
            'decision_ledger' => array_values(array_unique($decisions)),
            'conflict_report' => [],
            'current_truth_pack' => $truthPack,
            'task_context_pack_policy' => [
                'uses_raw_conversation' => false,
                'uses_hash_refs' => true,
                'requires_workspace' => true,
            ],
        ];
        $payload['continuity_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $continuity
     * @param  array<string,mixed>  $focusMap
     * @return array<string,mixed>
     */
    private function artifacts(?array $profile, string $task, array $twin, array $continuity, array $focusMap): array
    {
        $workspaceId = $profile['slug'] ?? null;
        $task = trim($task) !== '' ? trim($task) : 'workspace readiness and context preparation';

        $artifacts = [
            $this->artifact('workspace_brief', $workspaceId, [
                'summary' => (string) ($profile['stack_summary'] ?? 'workspace unavailable'),
                'stack' => data_get($twin, 'genome.stack', []),
                'risk_floor' => data_get($twin, 'genome.risk_floor'),
                'repository_count' => data_get($twin, 'repository_inventory.repository_count', 0),
            ]),
            $this->artifact('task_packet', $workspaceId, [
                'task' => $task,
                'scope' => 'workspace_scoped',
                'likely_repositories' => data_get($focusMap, 'focused_repositories', []),
                'likely_areas' => data_get($focusMap, 'focused_areas', data_get($twin, 'risk_fragility_map.sensitive_areas', [])),
                'definition_of_done' => ['tests selected', 'artifact certified', 'outcome recorded'],
            ]),
            $this->artifact('context_pack', $workspaceId, [
                'sources' => data_get($continuity, 'current_truth_pack.canonical_sources', []),
                'context_units' => data_get($focusMap, 'context_units', []),
                'raw_conversation_included' => false,
                'raw_file_content_included' => false,
                'raw_diff_included' => false,
                'learning_loop_required' => true,
            ]),
            $this->artifact('execution_plan', $workspaceId, [
                'steps' => ['inspect', 'patch_or_plan', 'run_focused_tests', 'record_outcome'],
                'mutative_execution_requires_certified_contracts' => true,
            ]),
            $this->artifact('test_plan', $workspaceId, [
                'focused_tests' => data_get($focusMap, 'focused_commands', data_get($twin, 'test_command_intelligence.commands', [])),
                'skip_reason' => data_get($focusMap, 'focused_commands') === [] ? 'no_test_commands_registered' : null,
            ]),
            $this->artifact('risk_sheet', $workspaceId, [
                'risk_floor' => data_get($twin, 'genome.risk_floor'),
                'sensitive_areas' => data_get($twin, 'risk_fragility_map.sensitive_areas', []),
                'focused_areas' => data_get($focusMap, 'focused_areas', []),
                'critical_changes_require_review' => data_get($focusMap, 'focused_files_preview') !== [],
            ]),
            $this->artifact('handoff_packet', $workspaceId, [
                'provider_safe' => true,
                'raw_conversation_included' => false,
                'allowed_context' => data_get($focusMap, 'context_units', ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet']),
            ]),
            $this->artifact('failure_capsule', $workspaceId, [
                'status' => 'empty_until_failure',
                'captures' => ['minimal_error', 'likely_cause', 'suspect_files', 'next_attempt'],
            ]),
            $this->artifact('outcome_record', $workspaceId, [
                'status' => 'pending',
                'records_future_run' => true,
            ]),
            $this->artifact('workspace_runbook', $workspaceId, [
                'commands' => data_get($twin, 'command_registry', []),
                'risk_floor' => data_get($twin, 'genome.risk_floor'),
                'docs' => data_get($twin, 'living_code_map.owner_docs', []),
            ]),
        ];

        return [
            'schema_version' => 'atlas.workspace_artifact_fabric.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $workspaceId,
            'artifacts' => $artifacts,
            'artifact_count' => count($artifacts),
            'artifact_fabric_hash' => MissionCanonicalHash::sha256($artifacts),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $artifactFabric
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $continuity
     * @return array<string,mixed>
     */
    private function artifactIntelligence(?array $profile, string $task, array $artifactFabric, array $twin, array $continuity): array
    {
        $artifacts = array_values(array_filter(
            (array) ($artifactFabric['artifacts'] ?? []),
            'is_array',
        ));
        $workspaceId = $profile['slug'] ?? null;
        $nodes = array_map(
            static fn (array $artifact): array => [
                'id' => (string) ($artifact['artifact_hash'] ?? ''),
                'type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'status' => (string) ($artifact['status'] ?? 'unknown'),
                'consumer' => match ((string) ($artifact['artifact_type'] ?? '')) {
                    'task_packet', 'context_pack', 'test_plan', 'risk_sheet' => 'atlas_dev',
                    'workspace_brief', 'execution_plan', 'handoff_packet', 'outcome_record' => 'atlas_forge',
                    'failure_capsule' => 'repair_loop',
                    'workspace_runbook' => 'cartography_and_human',
                    default => 'unknown',
                },
            ],
            $artifacts,
        );

        $edges = [];
        $byType = collect($artifacts)->keyBy('artifact_type');
        foreach ([
            ['workspace_brief', 'task_packet', 'informs'],
            ['task_packet', 'context_pack', 'requires'],
            ['task_packet', 'test_plan', 'requires'],
            ['task_packet', 'risk_sheet', 'requires'],
            ['context_pack', 'handoff_packet', 'projects'],
            ['test_plan', 'execution_plan', 'validates'],
            ['risk_sheet', 'execution_plan', 'guards'],
            ['failure_capsule', 'outcome_record', 'feeds'],
            ['outcome_record', 'workspace_runbook', 'updates'],
        ] as [$from, $to, $relation]) {
            $fromArtifact = $byType->get($from);
            $toArtifact = $byType->get($to);
            if (is_array($fromArtifact) && is_array($toArtifact)) {
                $edges[] = [
                    'from' => (string) ($fromArtifact['artifact_hash'] ?? ''),
                    'to' => (string) ($toArtifact['artifact_hash'] ?? ''),
                    'relation' => $relation,
                ];
            }
        }

        $quality = array_map(function (array $artifact): array {
            $hasWorkspace = ($artifact['workspace_id'] ?? null) !== null;
            $hasSources = (array) ($artifact['source_hashes'] ?? []) !== [];

            return [
                'artifact_type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'artifact_hash' => (string) ($artifact['artifact_hash'] ?? ''),
                'coverage' => $hasWorkspace && $hasSources ? 1.0 : 0.0,
                'freshness' => $hasWorkspace ? 'ready' : 'blocked',
                'source_integrity' => $hasSources ? 'ready' : 'blocked',
                'consumer_fit' => ($artifact['status'] ?? null) === 'blocked' ? 'blocked' : 'ready',
                'quality_score' => $hasWorkspace && $hasSources ? 0.98 : 0.0,
            ];
        }, $artifacts);

        $payload = [
            'schema_version' => 'atlas.workspace_artifact_intelligence.v1',
            'status' => $workspaceId === null ? 'blocked' : 'ready',
            'workspace_id' => $workspaceId,
            'workspace_hash' => $this->artifactWorkspaceHash($profile),
            'artifact_lake' => [
                'schema_version' => 'atlas.workspace_artifact_lake.v1',
                'artifact_count' => count($artifacts),
                'certifiable_artifacts' => count(array_filter($artifacts, static fn (array $artifact): bool => ($artifact['workspace_id'] ?? null) !== null)),
                'lake_hash' => MissionCanonicalHash::sha256($artifacts),
            ],
            'artifact_graph' => [
                'schema_version' => 'atlas.workspace_artifact_graph.v1',
                'nodes' => $nodes,
                'edges' => $edges,
                'graph_hash' => MissionCanonicalHash::sha256([$nodes, $edges]),
            ],
            'artifact_branching' => [
                'schema_version' => 'atlas.workspace_artifact_branching.v1',
                'branches' => [
                    ['id' => 'minimal_patch', 'risk' => data_get($twin, 'genome.risk_floor', 'medium')],
                    ['id' => 'forge_escalation', 'risk' => 'controlled_high'],
                ],
            ],
            'artifact_replay' => [
                'schema_version' => 'atlas.workspace_artifact_replay.v1',
                'replay_ready' => $workspaceId !== null && count($artifacts) >= 10,
                'required_inputs' => ['workspace_id', 'artifact_hash', 'source_hashes', 'task_packet', 'context_pack', 'test_plan'],
                'raw_conversation_required' => false,
            ],
            'artifact_simulation' => [
                'schema_version' => 'atlas.workspace_artifact_simulation.v1',
                'decision' => $workspaceId === null ? 'blocked' : 'ready',
                'blockers' => $workspaceId === null ? ['workspace_not_registered'] : [],
                'likely_areas' => data_get($twin, 'risk_fragility_map.sensitive_areas', []),
                'escalate_to_forge_when' => ['multi_domain', 'high_uncertainty', 'repeated_failure'],
            ],
            'artifact_context_compiler' => [
                'schema_version' => 'atlas.workspace_artifact_context_compiler.v1',
                'task' => trim($task) !== '' ? trim($task) : 'workspace readiness and context preparation',
                'context_units' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
                'raw_conversation_included' => false,
                'current_truth_pack_hash' => MissionCanonicalHash::sha256(data_get($continuity, 'current_truth_pack', [])),
            ],
            'artifact_quality_governor' => [
                'schema_version' => 'atlas.workspace_artifact_quality_governor.v1',
                'quality' => $quality,
                'minimum_executable_score' => 0.95,
                'all_executable_artifacts_ready' => collect($quality)->every(fn (array $item): bool => (float) $item['quality_score'] >= 0.95),
            ],
            'artifact_cartography_projection' => [
                'schema_version' => 'atlas.workspace_artifact_cartography_projection.v1',
                'visual_layers' => ['workspace', 'artifact_graph', 'stale_nodes', 'blockers', 'provider_handoff'],
                'text_policy' => 'modal_only_for_details',
                'human_scan_mode' => 'graph_first',
            ],
            'artifact_marketplace' => [
                'schema_version' => 'atlas.workspace_artifact_marketplace.v1',
                'privacy_policy' => 'patterns_only_no_raw_cross_workspace',
                'reusable_templates' => ['login_test_plan', 'auth_risk_sheet', 'provider_handoff_packet'],
            ],
            'artifact_outcome_learning' => [
                'schema_version' => 'atlas.workspace_artifact_outcome_learning.v1',
                'feeds' => ['AEMOR', 'AWEF', 'workspace_runbook', 'awis_learning_loop'],
                'requires_real_outcome' => true,
            ],
        ];
        $payload['artifact_intelligence_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $continuity
     * @param  array<string,mixed>  $artifactFabric
     * @param  array<string,mixed>  $artifactIntelligence
     * @param  array<string,mixed>  $contracts
     * @param  array<string,mixed>  $evolution
     * @param  array<string,mixed>  $changeMemory
     * @param  array<string,mixed>  $focusMap
     * @param  array<string,mixed>  $nextSessionBrain
     * @return array<string,mixed>
     */
    private function workspaceLearningLoop(
        ?array $profile,
        string $task,
        array $continuity,
        array $artifactFabric,
        array $artifactIntelligence,
        array $contracts,
        array $evolution,
        array $changeMemory,
        array $focusMap,
        array $nextSessionBrain,
    ): array {
        $workspaceId = $profile['slug'] ?? null;
        $artifacts = array_values(array_filter((array) ($artifactFabric['artifacts'] ?? []), 'is_array'));
        $artifactHashes = array_values(array_filter(array_map(
            static fn (array $artifact): string => (string) ($artifact['artifact_hash'] ?? ''),
            $artifacts,
        )));
        $decisions = array_values((array) data_get($continuity, 'current_truth_pack.active_decisions', []));
        $blockers = array_values((array) data_get($continuity, 'current_truth_pack.open_blockers', []));
        $riskZones = array_values((array) ($profile['critical_areas'] ?? []));
        $criticalChanges = array_values((array) data_get($changeMemory, 'critical_areas_touched', []));
        $changeHash = (string) data_get($changeMemory, 'change_hash', '');

        $events = array_values(array_filter([
            [
                'event_type' => 'workspace_bound',
                'event_status' => $workspaceId === null ? 'blocked' : 'observed',
                'source_ref' => $workspaceId === null ? 'workspace:missing' : 'workspace:'.$workspaceId,
            ],
            trim($task) !== '' ? [
                'event_type' => 'task_received',
                'event_status' => 'observed',
                'source_ref' => 'task:'.hash('sha256', trim($task)),
            ] : null,
            count((array) data_get($continuity, 'segmentation_map', [])) > 0 ? [
                'event_type' => 'conversation_segmented',
                'event_status' => 'observed',
                'source_ref' => 'archive:'.(string) data_get($continuity, 'raw_archive.archive_hash', ''),
            ] : null,
            count($artifactHashes) > 0 ? [
                'event_type' => 'artifacts_compiled',
                'event_status' => 'observed',
                'source_ref' => 'artifact_fabric:'.(string) ($artifactFabric['artifact_fabric_hash'] ?? ''),
            ] : null,
            data_get($changeMemory, 'status') !== 'blocked' ? [
                'event_type' => 'workspace_changes_observed',
                'event_status' => data_get($changeMemory, 'dirty') === true ? 'dirty' : 'clean',
                'source_ref' => $changeHash !== '' ? 'workspace_change_memory:'.$changeHash : 'workspace_change_memory:unknown',
            ] : null,
        ]));

        $nextAction = $this->workspaceLearningNextAction($contracts, $artifactIntelligence);
        $loopClosed = $workspaceId !== null
            && data_get($contracts, 'execution_readiness_status') === 'ready'
            && data_get($artifactIntelligence, 'artifact_replay.replay_ready') === true
            && $nextAction['status'] !== 'blocked';

        $payload = [
            'schema_version' => 'atlas.awis.workspace_intelligence_loop.v1',
            'status' => $loopClosed ? 'ready' : 'blocked',
            'workspace_id' => $workspaceId,
            'event_intake' => [
                'schema_version' => 'atlas.awis.event_intake.v1',
                'events' => $events,
                'event_count' => count($events),
                'raw_conversation_stored' => false,
            ],
            'understanding' => [
                'schema_version' => 'atlas.awis.event_understanding.v1',
                'decisions' => $decisions,
                'blockers' => $blockers,
                'risk_zones' => $riskZones,
                'changed_top_level_areas' => array_values((array) data_get($changeMemory, 'top_level_areas', [])),
                'critical_areas_touched' => $criticalChanges,
                'summary_hash' => MissionCanonicalHash::sha256([$decisions, $blockers, $riskZones, $criticalChanges]),
            ],
            'operational_memory' => [
                'schema_version' => 'atlas.awis.operational_memory_update.v1',
                'memory_scope' => 'workspace',
                'promotion_policy' => 'candidate_only_until_evidence_review',
                'canonical_doc_rewrite_allowed' => false,
                'candidate_units' => [
                    'current_truth_pack',
                    'workspace_runbook_delta',
                    'failure_signature_candidate',
                    'pattern_library_signal',
                    'workspace_change_memory',
                    'workspace_focus_map',
                    'workspace_next_session_brain',
                ],
                'memory_candidate_hash' => MissionCanonicalHash::sha256([
                    'workspace_id' => $workspaceId,
                    'decisions' => $decisions,
                    'blockers' => $blockers,
                    'patterns' => data_get($evolution, 'pattern_library.patterns', []),
                    'workspace_change_hash' => $changeHash,
                    'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                    'workspace_next_session_brain_hash' => data_get($nextSessionBrain, 'brain_hash'),
                ]),
            ],
            'context_application' => [
                'schema_version' => 'atlas.awis.context_application.v1',
                'default_context_units' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
                'context_pack_hash' => (string) data_get($artifactIntelligence, 'artifact_context_compiler.current_truth_pack_hash', ''),
                'artifact_hashes' => $artifactHashes,
                'workspace_change_memory_hash' => $changeHash !== '' ? $changeHash : null,
                'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                'workspace_next_session_brain_hash' => data_get($nextSessionBrain, 'brain_hash'),
                'focused_repositories' => data_get($focusMap, 'focused_repositories', []),
                'focused_areas' => data_get($focusMap, 'focused_areas', []),
                'focused_commands' => data_get($focusMap, 'focused_commands', []),
                'changed_files_preview' => array_values((array) data_get($changeMemory, 'changed_files_preview', [])),
                'critical_changes_require_review' => $criticalChanges !== [],
                'raw_conversation_included' => false,
                'raw_diff_included' => false,
                'provider_prompt_allowed' => data_get($contracts, 'execution_readiness_status') === 'ready',
                'stale_policy' => 'regenerate_awis_before_mutative_execution',
            ],
            'next_action' => $nextAction,
            'evidence_learning' => [
                'schema_version' => 'atlas.awis.evidence_learning.v1',
                'evidence_refs' => array_values(array_filter(array_merge(
                    array_map(static fn (string $hash): string => 'awis_artifact:'.$hash, array_slice($artifactHashes, 0, 5)),
                    [
                        ($artifactFabric['artifact_fabric_hash'] ?? null) !== null ? 'awis_artifact_fabric:'.$artifactFabric['artifact_fabric_hash'] : null,
                        data_get($artifactIntelligence, 'artifact_graph.graph_hash') !== null ? 'awis_artifact_graph:'.data_get($artifactIntelligence, 'artifact_graph.graph_hash') : null,
                        ($contracts['contract_hash'] ?? null) !== null ? 'awis_contract:'.$contracts['contract_hash'] : null,
                        ($evolution['evolution_hash'] ?? null) !== null ? 'awef:'.$evolution['evolution_hash'] : null,
                        $changeHash !== '' ? 'awis_workspace_change_memory:'.$changeHash : null,
                        data_get($focusMap, 'focus_hash') !== null ? 'awis_workspace_focus_map:'.data_get($focusMap, 'focus_hash') : null,
                        data_get($nextSessionBrain, 'brain_hash') !== null ? 'awis_workspace_next_session_brain:'.data_get($nextSessionBrain, 'brain_hash') : null,
                    ],
                ))),
                'missing_evidence' => $loopClosed ? [] : ['ready_workspace_contract_or_replay_required'],
                'feedback_targets' => ['AEMOR', 'AWEF', 'workspace_runbook', 'context_autopilot'],
                'learning_score' => $loopClosed ? 0.98 : 0.0,
            ],
            'closed_loop' => [
                'event_to_understanding' => count($events) > 0,
                'understanding_to_memory' => true,
                'memory_to_context' => count($artifactHashes) > 0,
                'context_to_next_action' => $nextAction['status'] !== 'blocked',
                'next_action_to_evidence' => true,
                'evidence_to_learning' => true,
                'loop_closed' => $loopClosed,
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'spends_tokens' => false,
                'raw_conversation_returned' => false,
                'raw_diff_returned' => false,
                'absolute_workspace_path_returned' => false,
                'auto_promotes_memory' => false,
                'cross_workspace_learning_allowed' => false,
            ],
        ];
        $payload['loop_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $contracts
     * @param  array<string,mixed>  $artifactIntelligence
     * @return array<string,mixed>
     */
    private function workspaceLearningNextAction(array $contracts, array $artifactIntelligence): array
    {
        if (data_get($contracts, 'execution_readiness_status') !== 'ready') {
            return [
                'schema_version' => 'atlas.awis.next_action.v1',
                'status' => 'blocked',
                'action' => 'regenerate_or_certify_workspace_artifacts',
                'target' => 'AWCO',
                'reasons' => ['workspace_contract_not_ready'],
            ];
        }

        $simulationDecision = (string) data_get($artifactIntelligence, 'artifact_simulation.decision', 'blocked');
        $target = in_array('multi_domain', (array) data_get($artifactIntelligence, 'artifact_simulation.escalate_to_forge_when', []), true)
            ? 'atlas_dev_or_forge'
            : 'atlas_dev';

        return [
            'schema_version' => 'atlas.awis.next_action.v1',
            'status' => $simulationDecision === 'blocked' ? 'blocked' : 'ready',
            'action' => 'prepare_provider_safe_handoff',
            'target' => $target,
            'reasons' => ['workspace_bound', 'artifacts_certified', 'raw_conversation_excluded'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $profile
     */
    private function artifactWorkspaceHash(?array $profile): ?string
    {
        if ($profile === null || ! (bool) ($profile['workspace_path_exists'] ?? false)) {
            return null;
        }

        return $this->workspaceRootHash((string) ($profile['workspace_path'] ?? ''));
    }

    /**
     * Provider-safe packet that lets the next session restart with the right
     * repo, context units, commands and memory candidates without rereading the
     * whole workspace or replaying raw conversation.
     *
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $workspaceReport
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $continuity
     * @param  array<string,mixed>  $artifactFabric
     * @param  array<string,mixed>  $artifactIntelligence
     * @param  array<string,mixed>  $contracts
     * @param  array<string,mixed>  $evolution
     * @param  array<string,mixed>  $changeMemory
     * @param  array<string,mixed>  $focusMap
     * @return array<string,mixed>
     */
    private function workspaceNextSessionBrain(
        ?array $profile,
        string $task,
        array $workspaceReport,
        array $twin,
        array $continuity,
        array $artifactFabric,
        array $artifactIntelligence,
        array $contracts,
        array $evolution,
        array $changeMemory,
        array $focusMap,
    ): array {
        $workspaceId = $profile['slug'] ?? null;
        $focusedRepositories = array_values((array) data_get($focusMap, 'focused_repositories', []));
        $focusedAreas = array_values((array) data_get($focusMap, 'focused_areas', []));
        $focusedCommands = array_values((array) data_get($focusMap, 'focused_commands', []));
        $contextUnits = array_values((array) data_get($focusMap, 'context_units', []));
        $criticalChanges = array_values((array) data_get($changeMemory, 'critical_areas_touched', []));
        $repositoryInventory = (array) data_get($twin, 'repository_inventory', []);
        $repositories = array_values(array_filter(
            (array) data_get($repositoryInventory, 'repositories', []),
            'is_array',
        ));
        $focusedRepoKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $repo): string => is_array($repo) ? (string) ($repo['repo_key'] ?? '') : '',
            $focusedRepositories,
        ), static fn (string $repoKey): bool => $repoKey !== '')));
        $focusedManifestRefs = [];
        foreach ($repositories as $repository) {
            $repoKey = (string) ($repository['repo_key'] ?? '');
            if ($repoKey === '' || ($focusedRepoKeys !== [] && ! in_array($repoKey, $focusedRepoKeys, true))) {
                continue;
            }
            $focusedManifestRefs[] = [
                'repo_key' => $repoKey,
                'manifest_files' => array_slice(array_values((array) ($repository['manifest_files'] ?? [])), 0, 8),
                'stack' => array_slice(array_values((array) ($repository['stack'] ?? [])), 0, 8),
                'script_names' => array_slice(array_values((array) ($repository['script_names'] ?? [])), 0, 12),
            ];
        }
        $artifactHashes = array_values(array_filter(array_map(
            static fn (mixed $artifact): string => is_array($artifact) ? (string) ($artifact['artifact_hash'] ?? '') : '',
            (array) data_get($artifactFabric, 'artifacts', []),
        )));
        $ownerDocs = array_values((array) data_get($twin, 'living_code_map.owner_docs', []));
        $loadOrder = array_values(array_unique(array_filter(array_merge([
            'workspace_binding',
            'repository_inventory',
            'workspace_change_memory',
            'workspace_focus_map',
        ], $contextUnits, [
            'artifact_graph',
            'contract_certification',
            'workspace_runbook',
        ]), 'is_string')));

        $readinessInputs = [
            data_get($workspaceReport, 'readiness_status') === 'ready',
            data_get($contracts, 'execution_readiness_status') === 'ready',
            data_get($artifactIntelligence, 'artifact_replay.replay_ready') === true,
            data_get($focusMap, 'status') === 'ready',
            in_array(data_get($repositoryInventory, 'status'), ['ready', 'limited'], true),
            data_get($changeMemory, 'source_policy.raw_diff_returned') === false,
        ];
        $readySignals = count(array_filter($readinessInputs));
        $readinessScore = round($readySignals / max(count($readinessInputs), 1), 2);
        $brainStatus = $workspaceId !== null && $readinessScore >= 0.8 ? 'ready' : 'blocked';

        $executionPriority = [];
        foreach (array_slice($focusedCommands, 0, 4) as $command) {
            if (is_string($command) && trim($command) !== '') {
                $executionPriority[] = [
                    'command' => trim($command),
                    'why' => 'focused_command_from_workspace_inventory_or_profile',
                    'requires_operator_approval' => true,
                ];
            }
        }

        $memoryCandidates = array_values(array_filter([
            'workspace_change_hash:'.(string) data_get($changeMemory, 'change_hash', ''),
            'workspace_focus_hash:'.(string) data_get($focusMap, 'focus_hash', ''),
            'artifact_graph_hash:'.(string) data_get($artifactIntelligence, 'artifact_graph.graph_hash', ''),
            'contract_hash:'.(string) data_get($contracts, 'contract_hash', ''),
            data_get($evolution, 'evolution_hash') !== null ? 'evolution_hash:'.(string) data_get($evolution, 'evolution_hash') : null,
        ], static fn (?string $value): bool => is_string($value) && ! str_ends_with($value, ':')));

        $payload = [
            'schema_version' => 'atlas.awis.workspace_next_session_brain.v1',
            'status' => $brainStatus,
            'workspace_id' => $workspaceId,
            'task_hash' => trim($task) !== '' ? hash('sha256', trim($task)) : null,
            'readiness_score' => $readinessScore,
            'readiness_signals' => [
                'workspace_ready' => data_get($workspaceReport, 'readiness_status') === 'ready',
                'contracts_ready' => data_get($contracts, 'execution_readiness_status') === 'ready',
                'artifact_replay_ready' => data_get($artifactIntelligence, 'artifact_replay.replay_ready') === true,
                'focus_ready' => data_get($focusMap, 'status') === 'ready',
                'repository_inventory_ready' => in_array(data_get($repositoryInventory, 'status'), ['ready', 'limited'], true),
                'provider_safe_change_memory' => data_get($changeMemory, 'source_policy.raw_diff_returned') === false,
            ],
            'resume_packet' => [
                'load_order' => $loadOrder,
                'focused_repositories' => array_map(
                    static fn (mixed $repo): array => is_array($repo) ? [
                        'repo_key' => (string) ($repo['repo_key'] ?? ''),
                        'score' => (int) ($repo['score'] ?? 0),
                        'reasons' => array_values((array) ($repo['reasons'] ?? [])),
                        'stack' => array_values((array) ($repo['stack'] ?? [])),
                    ] : [],
                    $focusedRepositories,
                ),
                'focused_areas' => array_slice($focusedAreas, 0, 8),
                'owner_docs' => array_slice($ownerDocs, 0, 8),
                'artifact_refs' => array_map(static fn (string $hash): string => 'awis_artifact:'.$hash, array_slice($artifactHashes, 0, 6)),
                'current_truth_pack_hash' => MissionCanonicalHash::sha256(data_get($continuity, 'current_truth_pack', [])),
            ],
            'context_loading_plan' => [
                'schema_version' => 'atlas.awis.context_loading_plan.v1',
                'mode' => 'folder_first_provider_safe_resume',
                'repository_inventory_hash' => data_get($repositoryInventory, 'inventory_hash'),
                'repository_count' => (int) data_get($repositoryInventory, 'repository_count', 0),
                'stack_tags' => array_slice(array_values((array) data_get($repositoryInventory, 'stack', [])), 0, 16),
                'focused_manifest_refs' => array_slice($focusedManifestRefs, 0, 6),
                'command_hints' => array_slice(array_values((array) data_get($repositoryInventory, 'command_hints', [])), 0, 10),
                'cache_keys' => [
                    'workspace_hash' => data_get($workspaceReport, 'workspace_hash'),
                    'repository_inventory_hash' => data_get($repositoryInventory, 'inventory_hash'),
                    'workspace_change_hash' => data_get($changeMemory, 'change_hash'),
                    'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                    'command_registry_hash' => data_get($twin, 'command_registry.command_registry_hash'),
                ],
                'refresh_triggers' => [
                    'workspace_hash_changed',
                    'repository_inventory_hash_changed',
                    'workspace_change_hash_changed',
                    'task_hash_changed',
                ],
                'provider_policy' => [
                    'load_manifest_names_only' => true,
                    'load_script_names_only' => true,
                    'raw_manifest_returned' => false,
                    'script_bodies_returned' => false,
                    'absolute_workspace_path_returned' => false,
                ],
            ],
            'execution_priority' => $executionPriority,
            'review_required' => [
                'critical_changes_require_review' => $criticalChanges !== [],
                'critical_areas_touched' => $criticalChanges,
                'stale_policy' => 'regenerate_before_mutative_execution_when_workspace_hash_or_focus_hash_changes',
            ],
            'memory_candidates' => [
                'promotion_policy' => 'candidate_only_until_evidence_review',
                'candidate_refs' => $memoryCandidates,
                'auto_promote' => false,
                'cross_workspace_reuse' => false,
            ],
            'performance_budget' => [
                'uses_repository_inventory' => true,
                'uses_git_status_only_for_change_memory' => true,
                'uses_manifest_names_only_for_folder_context' => true,
                'uses_hash_cache_keys_for_resume' => true,
                'raw_file_scan_required_for_provider_prompt' => false,
                'max_focused_repositories' => 4,
                'max_focused_commands' => 10,
                'max_manifest_refs' => 6,
            ],
            'source_policy' => [
                'raw_file_content_returned' => false,
                'raw_diff_returned' => false,
                'raw_manifest_returned' => false,
                'raw_conversation_returned' => false,
                'absolute_workspace_path_returned' => false,
                'provider_prompt_unit' => 'hash_refs_focus_map_artifact_refs_and_command_names_only',
            ],
        ];
        $payload['brain_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $workspaceReport
     * @param  array<string,mixed>  $artifactFabric
     * @return array<string,mixed>
     */
    private function contracts(array $workspaceReport, array $artifactFabric): array
    {
        $certifications = [];
        foreach ((array) ($artifactFabric['artifacts'] ?? []) as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $blockers = [];
            if (($workspaceReport['workspace_active'] ?? false) !== true) {
                $blockers[] = 'workspace_not_active';
            }
            if (($artifact['workspace_id'] ?? null) === null) {
                $blockers[] = 'artifact_missing_workspace_id';
            }
            if ((array) ($artifact['source_hashes'] ?? []) === []) {
                $blockers[] = 'artifact_missing_source_hashes';
            }

            $certifications[] = [
                'schema_version' => 'atlas.workspace_artifact_certification.v1',
                'artifact_type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'artifact_hash' => (string) ($artifact['artifact_hash'] ?? ''),
                'status' => $blockers === [] ? 'certified' : 'blocked',
                'blocking_reasons' => $blockers,
            ];
        }

        $blocked = array_values(array_filter(
            $certifications,
            static fn (array $cert): bool => ($cert['status'] ?? null) !== 'certified',
        ));

        $payload = [
            'schema_version' => 'atlas.workspace_contract_orchestrator.v1',
            'status' => $blocked === [] ? 'ready' : 'blocked',
            'certification_envelope' => [
                'schema_version' => 'atlas.workspace_contract_certification_envelope.v1',
                'artifact_count' => count($certifications),
                'certified_count' => count($certifications) - count($blocked),
                'blocked_count' => count($blocked),
                'quality_policy' => 'all_artifacts_must_have_workspace_and_source_hashes',
            ],
            'certifications' => $certifications,
            'blocked_artifacts' => $blocked,
            'blocked_count' => count($blocked),
            'execution_readiness_status' => $blocked === [] ? 'ready' : 'blocked',
            'versioning_policy' => [
                'schema_version' => 'atlas.workspace_contract_versioning_policy.v1',
                'version_source' => 'artifact_hash_plus_workspace_hash',
                'reissue_required_when' => ['workspace_hash_changes', 'artifact_hash_changes', 'source_hashes_change'],
            ],
            'invalidation_rules' => [
                'schema_version' => 'atlas.workspace_contract_invalidation_rules.v1',
                'block_when' => ['missing_workspace_id', 'missing_source_hashes', 'workspace_not_active', 'artifact_stale'],
                'never_autocertify_from' => ['raw_conversation', 'provider_freeform_text', 'cartography_visual_only'],
            ],
            'orchestration_plan' => [
                'schema_version' => 'atlas.workspace_contract_orchestration_plan.v1',
                'mutative_consumers' => ['atlas_dev', 'atlas_forge', 'provider_invocation', 'subagent_handoff'],
                'required_before_provider' => ['workspace_binding', 'artifact_certification', 'shadow_execution'],
            ],
        ];
        $payload['contract_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $twin
     * @return array<string,mixed>
     */
    private function evolution(?array $profile, array $twin): array
    {
        $patterns = [];
        if (in_array('laravel', (array) data_get($twin, 'genome.stack', []), true)) {
            $patterns[] = [
                'pattern_id' => 'laravel_artisan_test_gate',
                'privacy_level' => 'abstracted',
                'applies_to' => ['php', 'laravel'],
                'evidence_refs' => ['workspace.test_commands'],
            ];
        }
        if (in_array('typescript', (array) data_get($twin, 'genome.stack', []), true)) {
            $patterns[] = [
                'pattern_id' => 'typescript_typecheck_before_release',
                'privacy_level' => 'abstracted',
                'applies_to' => ['typescript'],
                'evidence_refs' => ['workspace.build_commands'],
            ];
        }

        $patternLibrary = [
            'schema_version' => 'atlas.workspace_pattern_library.v1',
            'patterns' => $patterns,
            'pattern_count' => count($patterns),
            'privacy_level' => 'abstracted_only',
        ];
        $failureSignatureBank = [
            'schema_version' => 'atlas.workspace_failure_signature_bank.v1',
            'signatures' => [
                [
                    'signature_id' => 'stale_workspace_context',
                    'avoidance_policy' => ['refresh_awis', 'rebuild_twin', 'regenerate_artifacts'],
                    'confidence' => 0.8,
                    'evidence_refs' => ['awis.execution_gate'],
                ],
            ],
        ];

        $payload = [
            'schema_version' => 'atlas.workspace_evolution_fabric.v1',
            'status' => $profile === null ? 'blocked' : 'ready',
            'workspace_id' => $profile['slug'] ?? null,
            'privacy_preserving_transfer' => true,
            'pattern_library' => $patternLibrary,
            'failure_signature_bank' => $failureSignatureBank,
            'privacy_transfer_gate' => [
                'schema_version' => 'atlas.workspace_privacy_transfer_gate.v1',
                'allows_raw_cross_workspace' => false,
                'allowed_transfer_units' => ['abstract_pattern', 'failure_signature', 'test_strategy', 'runbook_shape'],
                'block_when' => ['raw_context_present', 'workspace_specific_secret', 'customer_data_present'],
            ],
            'workspace_benchmark' => [
                'schema_version' => 'atlas.workspace_benchmark_shadow.v1',
                'mode' => 'read_only_shadow',
                'signals' => ['has_tests', 'has_owner_docs', 'has_awis_gate', 'has_artifact_graph'],
                'score_basis' => 'structural_evidence_only',
            ],
        ];
        $payload['evolution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function checks(array ...$sections): array
    {
        $checks = [
            $this->check('awis_workspace_binding', data_get($sections, '0.workspace_active') === true, 'critical'),
            $this->check('awis_workspace_readiness', data_get($sections, '0.readiness_status') === 'ready', 'critical'),
            $this->check('awtr_workspace_twin', data_get($sections, '1.twin_hash') !== null, 'critical'),
            $this->check('acios_no_raw_conversation_prompt', data_get($sections, '2.task_context_pack_policy.uses_raw_conversation') === false, 'critical'),
            $this->check('awaf_artifacts_generated', (int) data_get($sections, '3.artifact_count', 0) >= 10, 'critical'),
            $this->check('awair_artifact_intelligence_ready', data_get($sections, '4.artifact_replay.replay_ready') === true, 'critical'),
            $this->check('awco_artifacts_certified', data_get($sections, '5.execution_readiness_status') === 'ready', 'critical'),
            $this->check('awef_privacy_preserving_transfer', data_get($sections, '6.privacy_preserving_transfer') === true, 'critical'),
            $this->check('awis_execution_boundaries_audited', data_get($sections, '7.status') === 'ready'
                && (int) data_get($sections, '7.process_inventory_unclassified', 1) === 0, 'critical'),
            $this->check('awis_registry_editing_contract_complete', data_get($sections, '8.status') === 'ready', 'critical'),
            $this->check('awis_desktop_mobile_surface_contracts_complete', data_get($sections, '9.status') === 'ready', 'critical'),
            $this->check('awis_workspace_intelligence_loop_closed', data_get($sections, '10.closed_loop.loop_closed') === true, 'critical'),
            $this->check('awis_workspace_change_memory_provider_safe', data_get($sections, '11.source_policy.raw_diff_returned') === false
                && data_get($sections, '11.source_policy.absolute_workspace_path_returned') === false, 'critical'),
            $this->check('awis_workspace_focus_map_provider_safe', data_get($sections, '12.source_policy.raw_file_content_returned') === false
                && data_get($sections, '12.source_policy.absolute_workspace_path_returned') === false, 'critical'),
            $this->check('awis_workspace_next_session_brain_ready_provider_safe', data_get($sections, '13.status') === 'ready'
                && data_get($sections, '13.source_policy.raw_file_content_returned') === false
                && data_get($sections, '13.source_policy.raw_conversation_returned') === false
                && data_get($sections, '13.source_policy.absolute_workspace_path_returned') === false, 'critical'),
            $this->check('awis_repository_inventory_provider_safe', in_array(data_get($sections, '14.status'), ['ready', 'limited'], true)
                && data_get($sections, '14.source_policy.raw_manifest_returned') === false
                && data_get($sections, '14.source_policy.script_bodies_returned') === false
                && data_get($sections, '14.source_policy.absolute_workspace_path_returned') === false, 'critical'),
        ];

        return $checks;
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceContractSummary(): array
    {
        $desktopSurfacePath = $this->basePath('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx');
        $desktopPickerPath = $this->basePath('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiWorkspacePicker.tsx');
        $desktopThreadListPath = $this->basePath('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiThreadList.tsx');
        $desktopFusionTestPath = $this->basePath('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/conversationFusionContract.test.ts');
        $desktopSelectorTestPath = $this->basePath('../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/workspaceSelectorContract.test.ts');
        $mobileModelPath = $this->basePath('../atlas-app/components/sheets/atlas-ai/AtlasAiWorkspaceModel.ts');
        $mobileSelectorModelPath = $this->basePath('../atlas-app/components/sheets/atlas-ai/AtlasAiMobileWorkspaceModel.ts');
        $mobileSelectorSheetPath = $this->basePath('../atlas-app/components/sheets/atlas-ai/AtlasAiWorkspaceSheet.tsx');
        $mobileSheetPath = $this->basePath('../atlas-app/components/sheets/AtlasAiSheet.tsx');
        $mobileFooterPath = $this->basePath('../atlas-app/components/sheets/atlas-ai/AtlasAiComposerFooter.tsx');
        $mobileContextPath = $this->basePath('../atlas-app/components/sheets/atlas-ai/AtlasAiContextSheet.tsx');
        $mobileTestPath = $this->basePath('../atlas-app/scripts/atlas-ai-workspace-context.test.ts');
        $mobileSelectorTestPath = $this->basePath('../atlas-app/scripts/atlas-ai-mobile-workspace-selector.test.ts');

        $desktopSurface = $this->fileGet($desktopSurfacePath);
        $desktopPicker = $this->fileGet($desktopPickerPath);
        $desktopThreadList = $this->fileGet($desktopThreadListPath);
        $desktopFusionTest = $this->fileGet($desktopFusionTestPath);
        $desktopSelectorTest = $this->fileGet($desktopSelectorTestPath);
        $mobileModel = $this->fileGet($mobileModelPath);
        $mobileSelectorModel = $this->fileGet($mobileSelectorModelPath);
        $mobileSelectorSheet = $this->fileGet($mobileSelectorSheetPath);
        $mobileSheet = $this->fileGet($mobileSheetPath);
        $mobileFooter = $this->fileGet($mobileFooterPath);
        $mobileContext = $this->fileGet($mobileContextPath);
        $mobileTest = $this->fileGet($mobileTestPath);
        $mobileSelectorTest = $this->fileGet($mobileSelectorTestPath);

        $requirements = [
            'desktop_workspace_picker_present' => str_contains($desktopSurface, '<AtlasAiWorkspacePicker'),
            'desktop_last_project_selector_tested' => str_contains($desktopSelectorTest, 'last persisted project')
                && str_contains($desktopSelectorTest, 'persistWorkspaceSlug'),
            'desktop_workspace_lock_present' => str_contains($desktopSurface, 'workspaceLock') && str_contains($desktopSurface, 'effectiveWorkspaceSlug'),
            'desktop_picker_search_and_create_present' => str_contains($desktopPicker, 'Pesquisar projetos') && str_contains($desktopPicker, "onOpenWorkspaceProfile?.('create')"),
            'desktop_drag_merge_room_present' => (
                str_contains($desktopThreadList, 'atlas-ai-conversation-merge-room')
                || str_contains($desktopThreadList, 'ProjectSpacesPanel')
                || str_contains($desktopThreadList, 'pointerFusionSpaceTargetId')
            )
                && (
                    str_contains($desktopThreadList, 'Solte em outra conversa para criar um pack AWIS')
                    || str_contains($desktopThreadList, 'Solte em outra conversa para criar um Space')
                    || str_contains($desktopThreadList, 'Solte sobre outra conversa para criar um Space')
                ),
            'desktop_drag_fusion_persists_artifact' => str_contains($desktopSurface, 'refreshConversationFusion(threadIds, { persist: true })') && str_contains($desktopFusionTest, 'Drag thread-to-thread fusion'),
            'mobile_workspace_model_present' => str_contains($mobileModel, 'workspaceContextFromThreadAndTrace'),
            'mobile_context_sheet_awis_present' => str_contains($mobileContext, 'workspace AWIS') && str_contains($mobileContext, 'fixo nesta conversa'),
            'mobile_workspace_context_tested' => str_contains($mobileTest, 'Mobile ContextSheet must expose AWIS workspace scope'),
            'mobile_workspace_selector_present' => str_contains($mobileSheet, 'listAtlasWorkspaceProfiles')
                && str_contains($mobileFooter, 'workspaceLabel')
                && str_contains($mobileSheet, '<AtlasAiWorkspaceSheet')
                && str_contains($mobileSelectorSheet, 'Escolher projeto'),
            'mobile_workspace_selector_search_present' => str_contains($mobileSelectorSheet, 'TextInput')
                && str_contains($mobileSelectorSheet, 'workspacePickerOptions'),
            'mobile_workspace_create_present' => str_contains($mobileSheet, 'createAtlasWorkspaceProfile')
                && str_contains($mobileSelectorSheet, 'ADICIONAR NOVO PROJETO')
                && str_contains($mobileSelectorTest, 'Mobile Atlas AI must create workspace profiles from the selector'),
            'mobile_workspace_lock_payload_present' => str_contains($mobileSheet, 'mobileWorkspacePayload(mobileWorkspaceLock)')
                && str_contains($mobileSelectorModel, 'atlas.mobile_ai.workspace_scope.v1'),
            'mobile_workspace_selector_tested' => str_contains($mobileSelectorTest, 'Mobile Atlas AI must fetch workspace profiles from backend')
                && str_contains($mobileSelectorTest, 'Mobile Atlas AI submit must use locked workspace slug'),
        ];
        $missing = array_keys(array_filter($requirements, static fn (bool $ok): bool => ! $ok));

        $payload = [
            'schema_version' => 'atlas.workspace_intelligence.surface_contracts.v1',
            'status' => $missing === [] ? 'ready' : 'blocked',
            'requirements' => $requirements,
            'missing' => $missing,
            'surface_policy' => [
                'desktop_conversation_workspace_mutation_allowed_after_start' => false,
                'desktop_drag_thread_to_thread_creates_space' => true,
                'mobile_context_sheet_must_show_awis_scope' => true,
                'mobile_conversation_workspace_mutation_allowed_after_start' => false,
                'mobile_submit_must_emit_awis_workspace_scope' => true,
            ],
        ];
        $payload['surface_contract_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function registryEditingSummary(): array
    {
        $routePath = $this->basePath('routes/api.php');
        $controllerPath = $this->appPath('Http/Controllers/AtlasCodeWorkspaceController.php');
        $servicePath = $this->appPath('Services/AtlasCode/AtlasCodeWorkspaceProfileService.php');
        $modelPath = $this->appPath('Models/AtlasWorkspaceProfile.php');
        $migrationPath = $this->databasePath('migrations/2026_05_25_022000_create_atlas_workspace_profiles.php');
        $commandPath = $this->appPath('Console/Commands/AtlasWorkspaceIntelligenceCommand.php');

        $routeSource = $this->fileGet($routePath);
        $controllerSource = $this->fileGet($controllerPath);
        $serviceSource = $this->fileGet($servicePath);
        $commandSource = $this->fileGet($commandPath);

        $requirements = [
            'migration_present' => $this->fileExists($migrationPath),
            'model_present' => $this->fileExists($modelPath),
            'api_list_route' => str_contains($routeSource, "Route::get('/projects/workspaces'"),
            'api_create_route' => str_contains($routeSource, "Route::post('/projects/workspaces'"),
            'api_show_route' => str_contains($routeSource, "Route::get('/projects/workspaces/{slug}'"),
            'api_update_route' => str_contains($routeSource, "Route::patch('/projects/workspaces/{slug}'"),
            'api_archive_route' => str_contains($routeSource, "Route::delete('/projects/workspaces/{slug}'"),
            'controller_index' => str_contains($controllerSource, 'function index('),
            'controller_show' => str_contains($controllerSource, 'function show('),
            'controller_store' => str_contains($controllerSource, 'function store('),
            'controller_update' => str_contains($controllerSource, 'function update('),
            'controller_destroy' => str_contains($controllerSource, 'function destroy('),
            'service_upsert' => str_contains($serviceSource, 'function upsertPersistedProfile('),
            'service_archive' => str_contains($serviceSource, 'function archivePersistedProfile('),
            'cli_register' => str_contains($commandSource, 'registerWorkspace('),
            'cli_list' => str_contains($commandSource, "'list'"),
        ];
        $missing = array_keys(array_filter($requirements, static fn (bool $ok): bool => ! $ok));

        $payload = [
            'schema_version' => 'atlas.workspace_intelligence.registry_editing.v1',
            'status' => $missing === [] ? 'ready' : 'blocked',
            'requirements' => $requirements,
            'missing' => $missing,
            'api_actions' => ['list', 'show', 'create', 'update', 'archive'],
            'cli_actions' => ['list', 'register'],
            'storage' => [
                'table' => 'atlas_workspace_profiles',
                'archive_policy' => 'status_archived_no_hard_delete',
            ],
        ];
        $payload['registry_editing_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $audit
     * @return array<string,mixed>
     */
    private function executionBoundarySummary(array $audit): array
    {
        return [
            'schema_version' => 'atlas.workspace_intelligence.execution_boundary_summary.v1',
            'status' => (string) ($audit['status'] ?? 'blocked'),
            'guarded_boundaries_total' => (int) data_get($audit, 'summary.total', 0),
            'guarded_boundaries_failed' => (int) data_get($audit, 'summary.failed', 0),
            'process_inventory_total' => (int) data_get($audit, 'process_inventory.total', 0),
            'process_inventory_failed' => (int) data_get($audit, 'process_inventory.failed', 0),
            'process_inventory_unclassified' => (int) data_get($audit, 'process_inventory.unclassified', 0),
            'process_inventory_by_classification' => (array) data_get($audit, 'process_inventory.by_classification', []),
            'audit_hash' => (string) ($audit['audit_hash'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, string $severity): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'severity' => $severity,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summary(array $checks): array
    {
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'));
        $critical = array_values(array_filter($failed, static fn (array $check): bool => ($check['severity'] ?? null) === 'critical'));

        return [
            'total' => count($checks),
            'passed' => count($checks) - count($failed),
            'failed' => count($failed),
            'critical_failed' => count($critical),
        ];
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function status(array $summary): string
    {
        return ($summary['critical_failed'] ?? 0) > 0
            ? 'blocked'
            : 'ready';
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function riskMap(array $profile): array
    {
        $areas = array_values((array) ($profile['critical_areas'] ?? []));

        return [
            'risk_floor' => (string) data_get($profile, 'safety.risk_floor', $profile['default_risk'] ?? 'medium'),
            'sensitive_areas' => $areas,
            'requires_senior_review' => in_array((string) ($profile['production_status'] ?? ''), ['production'], true),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function detectStack(string $path, string $summary): array
    {
        $stack = [];
        $summary = mb_strtolower($summary);
        foreach (['laravel', 'php', 'typescript', 'react', 'expo', 'tauri', 'node'] as $needle) {
            if (str_contains($summary, $needle)) {
                $stack[] = $needle;
            }
        }
        $files = [
            'composer.json' => 'php',
            'artisan' => 'laravel',
            'package.json' => 'node',
            'tsconfig.json' => 'typescript',
            'atlas-app/app.json' => 'expo',
            'atlas-desktop/apps/desktop/src-tauri/tauri.conf.json' => 'tauri',
        ];
        foreach ($files as $file => $tag) {
            if ($path !== '' && $this->fileExists(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file)) {
                $stack[] = $tag;
            }
        }

        return array_values(array_unique($stack));
    }

    /**
     * @return array<int,string>
     */
    private function ownerDocs(string $path): array
    {
        $docs = [
            'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            'docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md',
            'docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md',
            'docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md',
        ];

        return array_values(array_filter(
            $docs,
            fn (string $doc): bool => $path === ''
                || $this->fileExists(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'atlas-server'.DIRECTORY_SEPARATOR.$doc)
                || $this->fileExists($this->basePath($doc)),
        ));
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function artifact(string $type, ?string $workspaceId, array $body): array
    {
        $payload = [
            'schema_version' => 'atlas.workspace_artifact.v1',
            'artifact_type' => $type,
            'workspace_id' => $workspaceId,
            'status' => $workspaceId === null ? 'blocked' : 'draft',
            'body' => $body,
            'source_hashes' => [
                hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            ],
            'valid_until' => null,
        ];
        $payload['artifact_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function workspaceRootHash(string $path): string
    {
        $real = realpath($path) ?: $path;
        $gitHead = null;
        $headPath = rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'HEAD';
        if (is_file($headPath)) {
            $head = trim((string) @file_get_contents($headPath));
            $gitHead = $head;
            if (str_starts_with($head, 'ref: ')) {
                $refPath = rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.trim(mb_substr($head, 5));
                if (is_file($refPath)) {
                    $gitHead .= '|'.trim((string) @file_get_contents($refPath));
                }
            }
        }

        $structuralFiles = [
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
            'tsconfig.json',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md',
            'atlas-server/docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md',
        ];
        $structuralHashes = [];
        foreach ($structuralFiles as $file) {
            $filePath = rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
            if (is_file($filePath)) {
                $structuralHashes[$file] = hash_file('sha256', $filePath) ?: null;
            }
        }

        return MissionCanonicalHash::sha256([
            'realpath' => $real,
            'git_head' => $gitHead,
            'structural_hashes' => $structuralHashes,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashWithoutGeneratedAt(array $payload): string
    {
        unset($payload['generated_at'], $payload['runtime_hash']);

        return MissionCanonicalHash::sha256($payload);
    }

    private function basePath(string $path = ''): string
    {
        try {
            if (function_exists('app') && method_exists(app(), 'basePath')) {
                return base_path($path);
            }
        } catch (\Throwable) {
            // Pure PHPUnit tests can instantiate this service without Laravel's Application.
        }

        return dirname(__DIR__, 4).($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    private function appPath(string $path = ''): string
    {
        return $this->basePath('app'.($path !== '' ? '/'.ltrim($path, '/') : ''));
    }

    private function databasePath(string $path = ''): string
    {
        return $this->basePath('database'.($path !== '' ? '/'.ltrim($path, '/') : ''));
    }

    private function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    private function fileGet(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return (string) (@file_get_contents($path) ?: '');
    }
}
