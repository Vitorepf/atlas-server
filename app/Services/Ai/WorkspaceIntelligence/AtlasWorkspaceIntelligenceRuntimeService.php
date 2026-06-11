<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Models\AiForgeOutcomeMemory;
use App\Models\AiForgeWorkPacket;
use App\Models\AiTestResult;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasEngineeringTestRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
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
    /**
     * @var array<int,string>
     */
    private const EFFECTIVENESS_COUNTER_KEYS = ['success_count', 'failure_count', 'neutral_count', 'total_count', 'score'];

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
        private readonly AtlasWorkspaceIntelligenceListNormalizer $listNormalizer = new AtlasWorkspaceIntelligenceListNormalizer,
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
        $liveExecutionMemory = $this->workspaceLiveExecutionMemory($profile, $task, $workspaceReport, $twin, $continuity, $changeMemory, $focusMap);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap, $liveExecutionMemory);
        $artifactIntelligence = $this->artifactIntelligence($profile, $task, $artifacts, $twin, $continuity);
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $nextSessionBrain = $this->workspaceNextSessionBrain($profile, $task, $workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $liveExecutionMemory);
        $learningLoop = $this->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain, $liveExecutionMemory);
        $learningSnapshot = $this->workspaceLearningSnapshot($profile, $workspaceReport, $repositoryInventory, $changeMemory, $focusMap, $nextSessionBrain, $learningLoop, $twin, $liveExecutionMemory);
        $nextSessionBrain = $this->attachLearningSnapshotToNextSessionBrain($nextSessionBrain, $learningSnapshot);
        $learningLoop = $this->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain, $liveExecutionMemory);
        $learningSnapshot = $this->workspaceLearningSnapshot($profile, $workspaceReport, $repositoryInventory, $changeMemory, $focusMap, $nextSessionBrain, $learningLoop, $twin, $liveExecutionMemory);
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
            'workspace_live_execution_memory' => $liveExecutionMemory,
            'workspace_next_session_brain' => $nextSessionBrain,
            'workspace_learning_snapshot' => $learningSnapshot,
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
                'workspace_live_execution_memory_provider_safe' => data_get($liveExecutionMemory, 'source_policy.raw_file_content_returned') === false
                    && data_get($liveExecutionMemory, 'source_policy.raw_diff_returned') === false
                    && data_get($liveExecutionMemory, 'source_policy.raw_conversation_returned') === false
                    && data_get($liveExecutionMemory, 'source_policy.absolute_workspace_path_returned') === false,
                'workspace_next_session_brain_provider_safe' => data_get($nextSessionBrain, 'source_policy.raw_file_content_returned') === false
                    && data_get($nextSessionBrain, 'source_policy.raw_conversation_returned') === false
                    && data_get($nextSessionBrain, 'source_policy.absolute_workspace_path_returned') === false,
                'workspace_learning_snapshot_provider_safe' => data_get($learningSnapshot, 'source_policy.raw_file_content_returned') === false
                    && data_get($learningSnapshot, 'source_policy.raw_diff_returned') === false
                    && data_get($learningSnapshot, 'source_policy.raw_log_returned') === false
                    && data_get($learningSnapshot, 'source_policy.raw_provider_text_returned') === false
                    && data_get($learningSnapshot, 'source_policy.absolute_workspace_path_returned') === false,
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
        $liveExecutionMemory = $this->workspaceLiveExecutionMemory($profile, $task, $workspaceReport, $twin, $continuity, $changeMemory, $focusMap);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap, $liveExecutionMemory);

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
        $liveExecutionMemory = $this->workspaceLiveExecutionMemory($profile, $task, $workspaceReport, $twin, $continuity, $changeMemory, $focusMap);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap, $liveExecutionMemory);
        $artifactIntelligence = $this->artifactIntelligence($profile, $task, $artifacts, $twin, $continuity);
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $nextSessionBrain = $this->workspaceNextSessionBrain($profile, $task, $workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $liveExecutionMemory);

        return $this->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain, $liveExecutionMemory);
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
     * @return array<string,mixed>
     */
    public function liveExecutionMemory(?string $workspace = null, string $task = ''): array
    {
        $report = $this->certify(workspace: $workspace, task: $task, conversationTexts: []);

        return (array) ($report['workspace_live_execution_memory'] ?? []);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveProfile(?string $workspace): ?array
    {
        return $this->profiles->findByReference($workspace);
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

    /**
     * @param  array<string,mixed>  $repositoryInventory
     * @return array<string,array<int,string>>
     */
    private function repositoryStackIndex(array $repositoryInventory): array
    {
        $index = [];
        foreach ((array) ($repositoryInventory['repositories'] ?? []) as $repository) {
            if (! is_array($repository)) {
                continue;
            }
            $repoKey = trim((string) ($repository['repo_key'] ?? ''));
            if ($repoKey === '') {
                continue;
            }
            $stack = $this->providerSafeStringList($repository['stack'] ?? []);
            sort($stack);
            $index[$repoKey] = $stack;
        }

        uksort($index, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $index;
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @param  array<string,array<int,string>>  $repositoryStackIndex
     * @return array<int,string>
     */
    private function stacksForCommandAndFiles(string $command, array $changedFiles, array $repositoryStackIndex): array
    {
        $stacks = [];
        foreach ($repositoryStackIndex as $repoKey => $repoStacks) {
            if ($repoKey !== '.' && preg_match('/(?:^|\s)cd\s+'.preg_quote($repoKey, '/').'(?:\s|$|&&|;)/', $command) === 1) {
                $stacks = array_merge($stacks, $repoStacks);
            }
        }

        foreach ($changedFiles as $file) {
            if (! is_string($file)) {
                continue;
            }
            $file = trim(str_replace('\\', '/', $file), '/');
            foreach ($repositoryStackIndex as $repoKey => $repoStacks) {
                if ($repoKey === '.') {
                    continue;
                }
                if ($file === $repoKey || str_starts_with($file, $repoKey.'/')) {
                    $stacks = array_merge($stacks, $repoStacks);
                    break;
                }
            }
        }

        return $this->limitedProviderSafeStringList($stacks, 12);
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
                'reasons' => $this->listNormalizer->uniqueStrings($reasons),
                'stack' => $stack,
                'command_hints' => array_slice(array_values((array) ($repository['command_hints'] ?? [])), 0, 6),
            ];
        }

        usort($focusedRepositories, static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: ((string) $left['repo_key'] <=> (string) $right['repo_key']));
        $focusedRepositories = array_slice($focusedRepositories, 0, 4);

        $focusedAreas = $this->listNormalizer->uniqueStringValues(array_merge(
            $criticalTouched,
            array_slice($criticalAreas, 0, $criticalTouched === [] ? 4 : 2),
            array_values((array) data_get($changeMemory, 'top_level_areas', [])),
        ));

        $focusedCommands = [];
        foreach ($focusedRepositories as $repository) {
            foreach ((array) ($repository['command_hints'] ?? []) as $command) {
                if (is_string($command) && $command !== '') {
                    $focusedCommands[] = $command;
                }
            }
        }
        $focusedCommands = $this->listNormalizer->uniqueStringValues(array_merge(
            $focusedCommands,
            array_values((array) ($profile['test_commands'] ?? [])),
        ));
        $focusedCommands = $this->rankCommandsByOutcome(
            $focusedCommands,
            (array) data_get($twin, 'test_command_intelligence.outcome_memory.command_outcome_index', []),
            $focusedAreas,
        );

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
        $stack = $this->listNormalizer->uniqueStringValues(array_merge(
            $this->detectStack($path, (string) ($profile['stack_summary'] ?? '')),
            array_values((array) data_get($repositoryInventory, 'stack', [])),
        ));
        $docs = $this->ownerDocs($path);
        $commands = $this->listNormalizer->uniqueStringValues(array_merge(
            array_values((array) ($profile['commands'] ?? [])),
            (array) ($profile['test_commands'] ?? []),
            (array) ($profile['build_commands'] ?? []),
            (array) data_get($repositoryInventory, 'command_hints', []),
        ));

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

        $outcomeCommandMemory = $this->workspaceOutcomeCommandMemory($profile, $commands, $repositoryInventory);

        $commandRegistry = [
            'schema_version' => 'atlas.workspace_command_registry.v1',
            'commands' => $commands,
            'command_count' => count($commands),
            'has_test_entrypoint' => (array) ($profile['test_commands'] ?? []) !== [],
            'outcome_memory_hash' => $outcomeCommandMemory['outcome_memory_hash'] ?? null,
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
                'commands' => $this->rankCommandsByOutcome(
                    array_values((array) ($profile['test_commands'] ?? [])),
                    (array) ($outcomeCommandMemory['command_outcome_index'] ?? []),
                ),
                'has_focused_entrypoint' => (array) ($profile['test_commands'] ?? []) !== [],
                'fallback_policy' => 'block_or_request_operator_test_command_when_missing',
                'outcome_memory' => $outcomeCommandMemory,
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
     * Provider-safe memory that turns Dev/Forge outcomes into command ranking
     * signals. It returns command strings and hashes only; no raw logs, no raw
     * diffs and no model/provider text.
     *
     * @param  array<string,mixed>|null  $profile
     * @param  array<int,string>  $candidateCommands
     * @param  array<string,mixed>  $repositoryInventory
     * @return array<string,mixed>
     */
    private function workspaceOutcomeCommandMemory(?array $profile, array $candidateCommands, array $repositoryInventory = []): array
    {
        $base = [
            'schema_version' => 'atlas.workspace_outcome_command_memory.v1',
            'status' => 'limited',
            'workspace_id' => $profile['slug'] ?? null,
            'observed_command_count' => 0,
            'ranked_commands' => [],
            'avoid_commands' => [],
            'command_outcome_index' => [],
            'evidence_window' => [
                'max_dev_outcomes' => 80,
                'max_forge_outcomes' => 80,
                'max_engineering_test_runs' => 120,
                'max_certified_test_results' => 120,
                'raw_outcome_body_returned' => false,
            ],
            'source_policy' => [
                'raw_log_returned' => false,
                'raw_diff_returned' => false,
                'raw_provider_text_returned' => false,
                'absolute_workspace_path_returned' => false,
                'provider_prompt_unit' => 'command_strings_status_counts_duration_buckets_and_outcome_hash_refs_only',
            ],
        ];

        if ($profile === null) {
            $base['blocker_reason'] = 'workspace_not_registered';
            $base['outcome_memory_hash'] = MissionCanonicalHash::sha256($base);

            return $base;
        }

        $workspaceSlug = (string) ($profile['slug'] ?? '');
        $repositoryStackIndex = $this->repositoryStackIndex($repositoryInventory);
        $stats = [];
        foreach ($candidateCommands as $command) {
            $command = trim((string) $command);
            if ($command !== '') {
                $stats[$command] = $this->emptyCommandOutcomeStats($command);
            }
        }

        if (DatabaseTableAvailability::all(['atlas_dev_outcome_memories', 'atlas_dev_task_packets'])) {
            $devOutcomes = AtlasDevOutcomeMemory::query()
                ->with('taskPacket')
                ->whereHas('taskPacket', function ($query) use ($workspaceSlug): void {
                    $query->where('workspace_slug', $workspaceSlug);
                })
                ->latest()
                ->limit(80)
                ->get();

            foreach ($devOutcomes as $outcome) {
                $commands = $this->listNormalizer->uniqueStringValues(array_merge(
                    (array) ($outcome->selected_tests ?? []),
                    (array) ($outcome->taskPacket?->suggested_tests ?? []),
                ));
                $changedFiles = $this->listNormalizer->uniqueStringValues(array_merge(
                    (array) ($outcome->changed_files ?? []),
                    (array) ($outcome->taskPacket?->expected_files ?? []),
                ));
                $contextRefs = (array) ($outcome->taskPacket?->context_refs ?? []);
                $policyRefs = $this->executionPolicyRefs($contextRefs);
                foreach ($commands as $command) {
                    $stacks = $this->stacksForCommandAndFiles((string) $command, $changedFiles, $repositoryStackIndex);
                    $this->recordCommandOutcome(
                        $stats,
                        $command,
                        (string) $outcome->outcome_status,
                        'dev',
                        (string) $outcome->outcome_memory_hash,
                        $changedFiles,
                        $stacks,
                        null,
                        $outcome->created_at?->toISOString(),
                        $policyRefs,
                        $this->executionRouteRefs($contextRefs, (string) $command),
                        $contextRefs,
                        $contextRefs,
                        $contextRefs,
                    );
                }
            }
        }

        if (DatabaseTableAvailability::all(['ai_forge_outcome_memories', 'ai_forge_work_packets', 'ai_forge_intakes'])) {
            $forgeOutcomes = AiForgeOutcomeMemory::query()
                ->latest()
                ->limit(80)
                ->get();
            $packetIds = $this->listNormalizer->uniqueStringValues($forgeOutcomes->pluck('work_packet_id')->all());
            $packets = AiForgeWorkPacket::query()
                ->with('intake')
                ->whereIn('id', $packetIds)
                ->whereHas('intake', function ($query) use ($workspaceSlug): void {
                    $query->where('workspace_slug', $workspaceSlug);
                })
                ->get()
                ->keyBy('id');

            foreach ($forgeOutcomes as $outcome) {
                $packet = $packets->get($outcome->work_packet_id);
                if ($packet === null) {
                    continue;
                }
                $changedFiles = array_values(array_filter((array) ($packet->expected_files ?? []), 'is_string'));
                $contextRefs = (array) ($packet->intake?->context_refs ?? []);
                $policyRefs = $this->executionPolicyRefs($contextRefs);
                foreach (array_values((array) ($packet->suggested_tests ?? [])) as $command) {
                    $stacks = $this->stacksForCommandAndFiles((string) $command, $changedFiles, $repositoryStackIndex);
                    $this->recordCommandOutcome(
                        $stats,
                        $command,
                        (string) $outcome->outcome_status,
                        'forge',
                        (string) $outcome->outcome_memory_hash,
                        $changedFiles,
                        $stacks,
                        null,
                        $outcome->created_at?->toISOString(),
                        $policyRefs,
                        $this->executionRouteRefs($contextRefs, (string) $command),
                        $contextRefs,
                        $contextRefs,
                        $contextRefs,
                    );
                }
            }
        }

        if (DatabaseTableAvailability::all(['atlas_engineering_test_runs', 'atlas_engineering_runs'])) {
            $workspaceNames = $this->listNormalizer->uniqueStringValues([
                $workspaceSlug,
                isset($profile['name']) && is_string($profile['name']) ? (string) $profile['name'] : null,
            ]);
            $engineeringRuns = AtlasEngineeringTestRun::query()
                ->with('run')
                ->whereNotNull('command')
                ->whereHas('run', function ($query) use ($workspaceNames): void {
                    $query->whereIn('workspace_label', $workspaceNames);
                })
                ->latest()
                ->limit(120)
                ->get();

            foreach ($engineeringRuns as $testRun) {
                $metadata = (array) ($testRun->metadata ?? []);
                $changedFiles = array_values(array_filter(array_merge(
                    (array) ($metadata['changed_files'] ?? []),
                    (array) ($metadata['expected_files'] ?? []),
                    (array) ($metadata['files'] ?? []),
                ), 'is_string'));
                $this->recordCommandOutcome(
                    $stats,
                    (string) $testRun->command,
                    (string) $testRun->status,
                    'engineering_test',
                    (string) ($metadata['evidence_hash'] ?? ''),
                    $changedFiles,
                    $this->stacksForCommandAndFiles((string) $testRun->command, $changedFiles, $repositoryStackIndex),
                    is_numeric($testRun->duration_ms) ? (int) $testRun->duration_ms : null,
                    $testRun->created_at?->toISOString(),
                );
            }
        }

        if (DatabaseTableAvailability::has('ai_test_results')) {
            $testResults = AiTestResult::query()
                ->whereNotNull('command')
                ->latest()
                ->limit(120)
                ->get();

            foreach ($testResults as $testResult) {
                $metadata = (array) ($testResult->metadata ?? []);
                $resultWorkspace = trim((string) ($metadata['workspace_slug'] ?? $metadata['workspace'] ?? ''));
                if ($resultWorkspace !== '' && $resultWorkspace !== $workspaceSlug) {
                    continue;
                }
                if ($resultWorkspace === '' && $workspaceSlug !== '') {
                    continue;
                }
                $changedFiles = array_values(array_filter(array_merge(
                    (array) ($metadata['changed_files'] ?? []),
                    (array) ($metadata['expected_files'] ?? []),
                    (array) ($metadata['files'] ?? []),
                ), 'is_string'));
                $durationMs = $this->durationMsFromMetadata($metadata);
                $this->recordCommandOutcome(
                    $stats,
                    (string) $testResult->command,
                    (string) $testResult->status,
                    'test_result',
                    (string) ($testResult->output_hash ?? ''),
                    $changedFiles,
                    $this->stacksForCommandAndFiles((string) $testResult->command, $changedFiles, $repositoryStackIndex),
                    $durationMs,
                    $testResult->created_at?->toISOString(),
                );
            }
        }

        $observed = array_values(array_filter(
            $stats,
            static fn (array $item): bool => (int) $item['total_count'] > 0,
        ));
        usort($observed, static fn (array $left, array $right): int => ((int) $right['effective_score'] <=> (int) $left['effective_score'])
            ?: ((int) $right['success_count'] <=> (int) $left['success_count'])
            ?: ((int) ($left['duration_ms_avg'] ?? PHP_INT_MAX) <=> (int) ($right['duration_ms_avg'] ?? PHP_INT_MAX))
            ?: ((string) $left['command'] <=> (string) $right['command']));

        $index = [];
        foreach ($observed as $item) {
            $index[(string) $item['command']] = $item;
        }

        $payload = array_merge($base, [
            'status' => $observed === [] ? 'limited' : 'ready',
            'observed_command_count' => count($observed),
            'ranked_commands' => array_slice(array_map(
                static fn (array $item): string => (string) $item['command'],
                array_values(array_filter($observed, static fn (array $item): bool => (int) $item['score'] >= 0)),
            ), 0, 12),
            'flaky_commands' => array_slice(array_map(
                static fn (array $item): string => (string) $item['command'],
                array_values(array_filter($observed, static fn (array $item): bool => ($item['stability'] ?? null) === 'mixed')),
            ), 0, 8),
            'slow_commands' => array_slice(array_map(
                static fn (array $item): string => (string) $item['command'],
                array_values(array_filter($observed, static fn (array $item): bool => ($item['performance_grade'] ?? null) === 'slow')),
            ), 0, 8),
            'performance_histogram' => $this->workspaceCommandPerformanceHistogram($observed),
            'area_performance_index' => $this->workspaceScopedPerformanceIndex($observed, 'area_performance', 'atlas.workspace_area_performance_index.v1'),
            'stack_performance_index' => $this->workspaceScopedPerformanceIndex($observed, 'stack_performance', 'atlas.workspace_stack_performance_index.v1'),
            'execution_policy_effectiveness_index' => $this->workspaceExecutionPolicyEffectivenessIndex($observed),
            'execution_route_effectiveness_index' => $this->workspaceExecutionRouteEffectivenessIndex($observed),
            'validation_tier_effectiveness_index' => $this->workspaceValidationTierEffectivenessIndex($observed),
            'working_set_effectiveness_index' => $this->workspaceWorkingSetEffectivenessIndex($observed),
            'context_delta_effectiveness_index' => $this->workspaceContextDeltaEffectivenessIndex($observed),
            'avoid_commands' => array_slice(array_map(
                static fn (array $item): string => (string) $item['command'],
                array_values(array_filter($observed, static fn (array $item): bool => (int) $item['score'] < 0 || ($item['performance_grade'] ?? null) === 'slow')),
            ), 0, 8),
            'command_outcome_index' => $index,
            'blocker_reason' => $observed === [] ? 'no_workspace_outcome_commands_observed' : null,
        ]);
        $payload['outcome_memory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyCommandOutcomeStats(string $command): array
    {
        return [
            'command' => $command,
            'score' => 0,
            'effective_score' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'neutral_count' => 0,
            'total_count' => 0,
            'performance_observed_count' => 0,
            'duration_ms_total' => 0,
            'duration_ms_samples' => [],
            'duration_ms_avg' => null,
            'duration_ms_min' => null,
            'duration_ms_max' => null,
            'duration_ms_p95' => null,
            'duration_bucket_counts' => [
                'under_10s' => 0,
                '10s_to_60s' => 0,
                '1m_to_5m' => 0,
                '5m_to_15m' => 0,
                'over_15m' => 0,
            ],
            'performance_grade' => 'unknown',
            'performance_score' => 0,
            'recency_score' => 0,
            'last_observed_at' => null,
            'last_outcome_status' => null,
            'sources' => [],
            'evidence_refs' => [],
            'area_affinity' => [],
            'stack_affinity' => [],
            'area_performance' => [],
            'stack_performance' => [],
            'execution_policy_refs' => [],
            'execution_route_refs' => [],
            'validation_tier_refs' => [],
            'working_set_refs' => [],
            'context_delta_refs' => [],
            'stability' => 'unknown',
            'confidence' => 0.0,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stats
     */
    private function recordCommandOutcome(
        array &$stats,
        mixed $command,
        string $status,
        string $source,
        string $outcomeHash,
        array $changedFiles = [],
        array $stacks = [],
        ?int $durationMs = null,
        ?string $observedAt = null,
        array $executionPolicyRefs = [],
        array $executionRouteRefs = [],
        array $validationTierRefs = [],
        array $workingSetRefs = [],
        array $contextDeltaRefs = [],
    ): void {
        $command = trim((string) $command);
        if ($command === '') {
            return;
        }

        $stats[$command] ??= $this->emptyCommandOutcomeStats($command);
        $polarity = $this->outcomePolarity($status);
        $stats[$command]['total_count'] = (int) $stats[$command]['total_count'] + 1;
        $stats[$command]['last_outcome_status'] = $status;
        $stats[$command]['last_observed_at'] = $this->latestIsoTimestamp(
            (string) ($stats[$command]['last_observed_at'] ?? ''),
            $observedAt,
        );
        $stats[$command]['sources'] = $this->appendUniqueLimited(
            $stats[$command]['sources'],
            [$source],
            4,
        );

        if ($outcomeHash !== '') {
            $stats[$command]['evidence_refs'] = $this->appendUniqueLimited(
                $stats[$command]['evidence_refs'],
                [$source.'_outcome:'.$outcomeHash],
                8,
            );
        }

        foreach ($this->executionPolicyRefs($executionPolicyRefs) as $policyRef) {
            $stats[$command]['execution_policy_refs'][$policyRef] ??= [
                'policy_ref' => $policyRef,
                'success_count' => 0,
                'failure_count' => 0,
                'neutral_count' => 0,
                'total_count' => 0,
                'score' => 0,
            ];
            $stats[$command]['execution_policy_refs'][$policyRef]['total_count']++;
            if ($polarity > 0) {
                $stats[$command]['execution_policy_refs'][$policyRef]['success_count']++;
                $stats[$command]['execution_policy_refs'][$policyRef]['score'] += 3;
            } elseif ($polarity < 0) {
                $stats[$command]['execution_policy_refs'][$policyRef]['failure_count']++;
                $stats[$command]['execution_policy_refs'][$policyRef]['score'] -= 2;
            } else {
                $stats[$command]['execution_policy_refs'][$policyRef]['neutral_count']++;
                $stats[$command]['execution_policy_refs'][$policyRef]['score'] += 1;
            }
        }

        foreach ($this->executionRouteRefs($executionRouteRefs, $command) as $routeRef) {
            $stats[$command]['execution_route_refs'][$routeRef] ??= [
                'route_ref' => $routeRef,
                'success_count' => 0,
                'failure_count' => 0,
                'neutral_count' => 0,
                'total_count' => 0,
                'score' => 0,
            ];
            $stats[$command]['execution_route_refs'][$routeRef]['total_count']++;
            if ($polarity > 0) {
                $stats[$command]['execution_route_refs'][$routeRef]['success_count']++;
                $stats[$command]['execution_route_refs'][$routeRef]['score'] += 3;
            } elseif ($polarity < 0) {
                $stats[$command]['execution_route_refs'][$routeRef]['failure_count']++;
                $stats[$command]['execution_route_refs'][$routeRef]['score'] -= 2;
            } else {
                $stats[$command]['execution_route_refs'][$routeRef]['neutral_count']++;
                $stats[$command]['execution_route_refs'][$routeRef]['score'] += 1;
            }
        }

        foreach ($this->validationTierRefs($validationTierRefs) as $tierRef) {
            $stats[$command]['validation_tier_refs'][$tierRef] ??= [
                'tier_ref' => $tierRef,
                'success_count' => 0,
                'failure_count' => 0,
                'neutral_count' => 0,
                'total_count' => 0,
                'score' => 0,
            ];
            $stats[$command]['validation_tier_refs'][$tierRef]['total_count']++;
            if ($polarity > 0) {
                $stats[$command]['validation_tier_refs'][$tierRef]['success_count']++;
                $stats[$command]['validation_tier_refs'][$tierRef]['score'] += 3;
            } elseif ($polarity < 0) {
                $stats[$command]['validation_tier_refs'][$tierRef]['failure_count']++;
                $stats[$command]['validation_tier_refs'][$tierRef]['score'] -= 2;
            } else {
                $stats[$command]['validation_tier_refs'][$tierRef]['neutral_count']++;
                $stats[$command]['validation_tier_refs'][$tierRef]['score'] += 1;
            }
        }

        foreach ($this->workingSetRefs($workingSetRefs) as $workingSetRef) {
            $stats[$command]['working_set_refs'][$workingSetRef] ??= [
                'working_set_ref' => $workingSetRef,
                'success_count' => 0,
                'failure_count' => 0,
                'neutral_count' => 0,
                'total_count' => 0,
                'score' => 0,
            ];
            $stats[$command]['working_set_refs'][$workingSetRef]['total_count']++;
            if ($polarity > 0) {
                $stats[$command]['working_set_refs'][$workingSetRef]['success_count']++;
                $stats[$command]['working_set_refs'][$workingSetRef]['score'] += 3;
            } elseif ($polarity < 0) {
                $stats[$command]['working_set_refs'][$workingSetRef]['failure_count']++;
                $stats[$command]['working_set_refs'][$workingSetRef]['score'] -= 2;
            } else {
                $stats[$command]['working_set_refs'][$workingSetRef]['neutral_count']++;
                $stats[$command]['working_set_refs'][$workingSetRef]['score'] += 1;
            }
        }

        foreach ($this->contextDeltaRefs($contextDeltaRefs) as $deltaRef) {
            $stats[$command]['context_delta_refs'][$deltaRef] ??= [
                'context_delta_ref' => $deltaRef,
                'success_count' => 0,
                'failure_count' => 0,
                'neutral_count' => 0,
                'total_count' => 0,
                'score' => 0,
            ];
            $stats[$command]['context_delta_refs'][$deltaRef]['total_count']++;
            if ($polarity > 0) {
                $stats[$command]['context_delta_refs'][$deltaRef]['success_count']++;
                $stats[$command]['context_delta_refs'][$deltaRef]['score'] += 3;
            } elseif ($polarity < 0) {
                $stats[$command]['context_delta_refs'][$deltaRef]['failure_count']++;
                $stats[$command]['context_delta_refs'][$deltaRef]['score'] -= 2;
            } else {
                $stats[$command]['context_delta_refs'][$deltaRef]['neutral_count']++;
                $stats[$command]['context_delta_refs'][$deltaRef]['score'] += 1;
            }
        }

        if ($durationMs !== null && $durationMs > 0) {
            $stats[$command]['performance_observed_count'] = (int) $stats[$command]['performance_observed_count'] + 1;
            $stats[$command]['duration_ms_total'] = (int) $stats[$command]['duration_ms_total'] + $durationMs;
            $stats[$command]['duration_ms_samples'] = $this->cappedDurationSamples(
                (array) ($stats[$command]['duration_ms_samples'] ?? []),
                $durationMs,
            );
            $stats[$command]['duration_ms_avg'] = (int) round(
                (int) $stats[$command]['duration_ms_total'] / max((int) $stats[$command]['performance_observed_count'], 1),
            );
            $stats[$command]['duration_ms_min'] = $stats[$command]['duration_ms_min'] === null
                ? $durationMs
                : min((int) $stats[$command]['duration_ms_min'], $durationMs);
            $stats[$command]['duration_ms_max'] = $stats[$command]['duration_ms_max'] === null
                ? $durationMs
                : max((int) $stats[$command]['duration_ms_max'], $durationMs);
            $stats[$command]['duration_ms_p95'] = $this->durationPercentile(
                (array) ($stats[$command]['duration_ms_samples'] ?? []),
                0.95,
            );
            $bucket = $this->durationBucket($durationMs);
            $stats[$command]['duration_bucket_counts'][$bucket] = (int) ($stats[$command]['duration_bucket_counts'][$bucket] ?? 0) + 1;
        }

        if ($polarity > 0) {
            $stats[$command]['success_count'] = (int) $stats[$command]['success_count'] + 1;
            $stats[$command]['score'] = (int) $stats[$command]['score'] + 3;
        } elseif ($polarity < 0) {
            $stats[$command]['failure_count'] = (int) $stats[$command]['failure_count'] + 1;
            $stats[$command]['score'] = (int) $stats[$command]['score'] - 2;
        } else {
            $stats[$command]['neutral_count'] = (int) $stats[$command]['neutral_count'] + 1;
            $stats[$command]['score'] = (int) $stats[$command]['score'] + 1;
        }

        $areas = [];
        foreach ($changedFiles as $file) {
            $area = $this->areaKey($file);
            if ($area !== null) {
                $areas[] = $area;
            }
        }
        foreach ($this->listNormalizer->uniqueStrings($areas) as $area) {
            $stats[$command]['area_affinity'][$area] = (int) ($stats[$command]['area_affinity'][$area] ?? 0) + max($polarity, 1);
            if ($durationMs !== null && $durationMs > 0) {
                $this->recordPerformanceProfile($stats[$command]['area_performance'], $area, $durationMs);
            }
        }
        arsort($stats[$command]['area_affinity']);
        $stats[$command]['area_affinity'] = array_slice($stats[$command]['area_affinity'], 0, 12, true);
        foreach ($this->listNormalizer->uniqueStringValues($stacks) as $stack) {
            $stack = trim($stack);
            if ($stack === '') {
                continue;
            }
            $stats[$command]['stack_affinity'][$stack] = (int) ($stats[$command]['stack_affinity'][$stack] ?? 0) + max($polarity, 1);
            if ($durationMs !== null && $durationMs > 0) {
                $this->recordPerformanceProfile($stats[$command]['stack_performance'], $stack, $durationMs);
            }
        }
        arsort($stats[$command]['stack_affinity']);
        $stats[$command]['stack_affinity'] = array_slice($stats[$command]['stack_affinity'], 0, 12, true);
        $stats[$command]['stability'] = (int) $stats[$command]['success_count'] > 0 && (int) $stats[$command]['failure_count'] > 0
            ? 'mixed'
            : ((int) $stats[$command]['failure_count'] > 0 ? 'failing' : ((int) $stats[$command]['success_count'] > 0 ? 'stable' : 'unknown'));
        $stats[$command]['performance_score'] = $this->commandPerformanceScore($stats[$command]);
        $stats[$command]['performance_grade'] = $this->commandPerformanceGrade($stats[$command]);
        $stats[$command]['recency_score'] = $this->commandRecencyScore((string) ($stats[$command]['last_observed_at'] ?? ''));
        $stats[$command]['effective_score'] = (int) $stats[$command]['score']
            + (int) $stats[$command]['performance_score']
            + ((int) $stats[$command]['performance_score'] < 0 ? 0 : (int) $stats[$command]['recency_score']);
        $stats[$command]['confidence'] = round(min(0.95, (int) $stats[$command]['total_count'] / 5), 2);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function durationMsFromMetadata(array $metadata): ?int
    {
        foreach (['duration_ms', 'elapsed_ms', 'runtime_ms'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                return max(1, (int) $metadata[$key]);
            }
        }

        foreach (['duration_sec', 'elapsed_sec', 'runtime_sec'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                return max(1, (int) round(((float) $metadata[$key]) * 1000));
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $samples
     * @return array<int,int>
     */
    private function cappedDurationSamples(array $samples, int $durationMs, int $limit = 24): array
    {
        $samples = array_values(array_filter(array_map(
            static fn (mixed $sample): int => is_numeric($sample) ? max(1, (int) $sample) : 0,
            $samples,
        ), static fn (int $sample): bool => $sample > 0));
        $samples[] = max(1, $durationMs);

        return array_slice($samples, max(0, count($samples) - $limit));
    }

    /**
     * @param  array<int,mixed>  $samples
     */
    private function durationPercentile(array $samples, float $percentile): ?int
    {
        $samples = array_values(array_filter(array_map(
            static fn (mixed $sample): int => is_numeric($sample) ? max(1, (int) $sample) : 0,
            $samples,
        ), static fn (int $sample): bool => $sample > 0));
        if ($samples === []) {
            return null;
        }

        sort($samples);
        $index = (int) ceil(max(0.0, min(1.0, $percentile)) * count($samples)) - 1;

        return $samples[max(0, min(count($samples) - 1, $index))];
    }

    private function durationBucket(int $durationMs): string
    {
        return match (true) {
            $durationMs <= 10_000 => 'under_10s',
            $durationMs <= 60_000 => '10s_to_60s',
            $durationMs <= 300_000 => '1m_to_5m',
            $durationMs <= 900_000 => '5m_to_15m',
            default => 'over_15m',
        };
    }

    /**
     * @param  array<string,mixed>  $profiles
     */
    private function recordPerformanceProfile(array &$profiles, string $key, int $durationMs): void
    {
        $key = trim($key);
        if ($key === '' || $durationMs <= 0) {
            return;
        }

        $profiles[$key] ??= $this->emptyPerformanceProfile($key);
        $profiles[$key]['observed_count'] = (int) $profiles[$key]['observed_count'] + 1;
        $profiles[$key]['duration_ms_total'] = (int) $profiles[$key]['duration_ms_total'] + $durationMs;
        $profiles[$key]['duration_ms_samples'] = $this->cappedDurationSamples(
            (array) ($profiles[$key]['duration_ms_samples'] ?? []),
            $durationMs,
        );
        $profiles[$key]['duration_ms_avg'] = (int) round(
            (int) $profiles[$key]['duration_ms_total'] / max((int) $profiles[$key]['observed_count'], 1),
        );
        $profiles[$key]['duration_ms_min'] = $profiles[$key]['duration_ms_min'] === null
            ? $durationMs
            : min((int) $profiles[$key]['duration_ms_min'], $durationMs);
        $profiles[$key]['duration_ms_max'] = $profiles[$key]['duration_ms_max'] === null
            ? $durationMs
            : max((int) $profiles[$key]['duration_ms_max'], $durationMs);
        $profiles[$key]['duration_ms_p95'] = $this->durationPercentile((array) $profiles[$key]['duration_ms_samples'], 0.95);
        $bucket = $this->durationBucket($durationMs);
        $profiles[$key]['duration_bucket_counts'][$bucket] = (int) ($profiles[$key]['duration_bucket_counts'][$bucket] ?? 0) + 1;
        $profiles[$key]['performance_grade'] = $this->commandPerformanceGrade([
            'duration_ms_p95' => $profiles[$key]['duration_ms_p95'],
            'duration_ms_avg' => $profiles[$key]['duration_ms_avg'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPerformanceProfile(string $key): array
    {
        return [
            'key' => $key,
            'observed_count' => 0,
            'duration_ms_total' => 0,
            'duration_ms_samples' => [],
            'duration_ms_avg' => null,
            'duration_ms_min' => null,
            'duration_ms_max' => null,
            'duration_ms_p95' => null,
            'duration_bucket_counts' => [
                'under_10s' => 0,
                '10s_to_60s' => 0,
                '1m_to_5m' => 0,
                '5m_to_15m' => 0,
                'over_15m' => 0,
            ],
            'performance_grade' => 'unknown',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function workspaceScopedPerformanceIndex(array $observed, string $field, string $schemaVersion): array
    {
        $profiles = [];
        foreach ($observed as $commandStats) {
            $command = (string) ($commandStats['command'] ?? '');
            foreach ((array) ($commandStats[$field] ?? []) as $key => $profile) {
                if (! is_string($key) || ! is_array($profile)) {
                    continue;
                }
                foreach ((array) ($profile['duration_ms_samples'] ?? []) as $sample) {
                    if (is_numeric($sample)) {
                        $this->recordPerformanceProfile($profiles, $key, (int) $sample);
                    }
                }
                if ($command !== '') {
                    $profiles[$key]['commands'] = $this->appendUniqueLimited(
                        $profiles[$key]['commands'] ?? [],
                        [$command],
                        8,
                    );
                }
            }
        }

        uasort($profiles, static fn (array $left, array $right): int => ((int) ($right['observed_count'] ?? 0) <=> (int) ($left['observed_count'] ?? 0))
            ?: ((int) ($left['duration_ms_p95'] ?? PHP_INT_MAX) <=> (int) ($right['duration_ms_p95'] ?? PHP_INT_MAX))
            ?: ((string) ($left['key'] ?? '') <=> (string) ($right['key'] ?? '')));

        $profiles = array_slice($profiles, 0, 16, true);
        $payload = [
            'schema_version' => $schemaVersion,
            'scope_count' => count($profiles),
            'profiles' => array_values($profiles),
            'source_policy' => [
                'raw_logs_returned' => false,
                'raw_file_content_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['index_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function workspaceCommandPerformanceHistogram(array $observed): array
    {
        $payload = [
            'schema_version' => 'atlas.workspace_command_performance_histogram.v1',
            'observed_command_count' => count($observed),
            'performance_observed_command_count' => 0,
            'bucket_counts' => [
                'under_10s' => 0,
                '10s_to_60s' => 0,
                '1m_to_5m' => 0,
                '5m_to_15m' => 0,
                'over_15m' => 0,
            ],
            'fast_commands' => [],
            'heavy_commands' => [],
            'slow_commands' => [],
            'source_policy' => [
                'raw_logs_returned' => false,
                'command_output_returned' => false,
            ],
        ];

        foreach ($observed as $item) {
            if ((int) ($item['performance_observed_count'] ?? 0) <= 0) {
                continue;
            }
            $payload['performance_observed_command_count']++;
            foreach ((array) ($item['duration_bucket_counts'] ?? []) as $bucket => $count) {
                if (isset($payload['bucket_counts'][$bucket])) {
                    $payload['bucket_counts'][$bucket] += (int) $count;
                }
            }

            $command = (string) ($item['command'] ?? '');
            $grade = (string) ($item['performance_grade'] ?? 'unknown');
            if ($command === '') {
                continue;
            }
            if ($grade === 'fast') {
                $payload['fast_commands'][] = $command;
            } elseif ($grade === 'heavy') {
                $payload['heavy_commands'][] = $command;
            } elseif ($grade === 'slow') {
                $payload['slow_commands'][] = $command;
            }
        }

        $payload['fast_commands'] = array_slice($this->providerSafeStringList($payload['fast_commands']), 0, 8);
        $payload['heavy_commands'] = array_slice($this->providerSafeStringList($payload['heavy_commands']), 0, 8);
        $payload['slow_commands'] = array_slice($this->providerSafeStringList($payload['slow_commands']), 0, 8);
        $payload['histogram_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function workspaceExecutionPolicyEffectivenessIndex(array $observed): array
    {
        $policies = [];
        foreach ($observed as $commandStats) {
            $command = (string) ($commandStats['command'] ?? '');
            foreach ((array) ($commandStats['execution_policy_refs'] ?? []) as $policyRef => $policyStats) {
                if (! is_string($policyRef) || ! is_array($policyStats)) {
                    continue;
                }
                $policies[$policyRef] ??= $this->emptyEffectivenessStats('policy_ref', $policyRef);
                $this->accumulateEffectivenessStats($policies[$policyRef], $policyStats);
                if ($command !== '') {
                    $policies[$policyRef]['commands'] = $this->appendUniqueLimited(
                        $policies[$policyRef]['commands'] ?? [],
                        [$command],
                        8,
                    );
                }
            }
        }

        $policies = $this->finalizeEffectivenessStats($policies);

        uasort($policies, static fn (array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
            ?: ((int) ($right['total_count'] ?? 0) <=> (int) ($left['total_count'] ?? 0))
            ?: ((string) ($left['policy_ref'] ?? '') <=> (string) ($right['policy_ref'] ?? '')));

        $payload = [
            'schema_version' => 'atlas.workspace_execution_policy_effectiveness_index.v1',
            'policy_count' => count($policies),
            'policies' => array_slice(array_values($policies), 0, 16),
            'source_policy' => [
                'raw_logs_returned' => false,
                'raw_provider_text_returned' => false,
                'raw_file_content_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['index_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function executionPolicyRefs(array $refs): array
    {
        return $this->cacheBackedHashRefs($refs, 'execution_optimization_policy');
    }

    /**
     * @param  array<int,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function workspaceExecutionRouteEffectivenessIndex(array $observed): array
    {
        $routes = [];
        foreach ($observed as $commandStats) {
            $command = (string) ($commandStats['command'] ?? '');
            foreach ((array) ($commandStats['execution_route_refs'] ?? []) as $routeRef => $routeStats) {
                if (! is_string($routeRef) || ! is_array($routeStats)) {
                    continue;
                }
                $routes[$routeRef] ??= $this->emptyEffectivenessStats('route_ref', $routeRef);
                $this->accumulateEffectivenessStats($routes[$routeRef], $routeStats);
                if ($command !== '') {
                    $routes[$routeRef]['commands'] = $this->appendUniqueLimited(
                        $routes[$routeRef]['commands'] ?? [],
                        [$command],
                        8,
                    );
                }
            }
        }

        $routes = $this->finalizeEffectivenessStats($routes);

        uasort($routes, static fn (array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
            ?: ((int) ($right['total_count'] ?? 0) <=> (int) ($left['total_count'] ?? 0))
            ?: ((string) ($left['route_ref'] ?? '') <=> (string) ($right['route_ref'] ?? '')));

        $payload = [
            'schema_version' => 'atlas.workspace_execution_route_effectiveness_index.v1',
            'route_count' => count($routes),
            'routes' => array_slice(array_values($routes), 0, 16),
            'source_policy' => [
                'raw_logs_returned' => false,
                'raw_provider_text_returned' => false,
                'raw_file_content_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['index_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function executionRouteRefs(array $refs, string $command): array
    {
        $commandHash = hash('sha256', $command);

        return $this->listNormalizer->uniqueMappedStrings(
            $refs,
            static function (mixed $ref) use ($commandHash): string {
                $ref = trim((string) $ref);
                if (preg_match('/^(area|stack):[a-f0-9]{64}$/', $ref) === 1) {
                    return $ref;
                }
                if (preg_match('/^awis_execution_route_command:([a-f0-9]{64}):(area|stack):([a-f0-9]{64})$/', $ref, $matches) !== 1) {
                    return '';
                }

                return $matches[1] === $commandHash ? $matches[2].':'.$matches[3] : '';
            },
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function workspaceValidationTierEffectivenessIndex(array $observed): array
    {
        $tiers = [];
        foreach ($observed as $commandStats) {
            $command = (string) ($commandStats['command'] ?? '');
            foreach ((array) ($commandStats['validation_tier_refs'] ?? []) as $tierRef => $tierStats) {
                if (! is_string($tierRef) || ! is_array($tierStats)) {
                    continue;
                }
                $tiers[$tierRef] ??= $this->emptyEffectivenessStats('tier_ref', $tierRef, [
                    'tier' => str_starts_with($tierRef, 'tier:') ? substr($tierRef, strlen('tier:')) : $tierRef,
                ]);
                $this->accumulateEffectivenessStats($tiers[$tierRef], $tierStats);
                if ($command !== '') {
                    $tiers[$tierRef]['commands'] = $this->appendUniqueLimited(
                        $tiers[$tierRef]['commands'] ?? [],
                        [$command],
                        8,
                    );
                }
            }
        }

        $tiers = $this->finalizeEffectivenessStats($tiers);

        uasort($tiers, static fn (array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
            ?: ((int) ($right['total_count'] ?? 0) <=> (int) ($left['total_count'] ?? 0))
            ?: ((string) ($left['tier_ref'] ?? '') <=> (string) ($right['tier_ref'] ?? '')));

        $payload = [
            'schema_version' => 'atlas.workspace_validation_tier_effectiveness_index.v1',
            'tier_count' => count($tiers),
            'tiers' => array_slice(array_values($tiers), 0, 8),
            'source_policy' => [
                'raw_logs_returned' => false,
                'raw_provider_text_returned' => false,
                'raw_file_content_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['index_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function validationTierRefs(array $refs): array
    {
        return $this->listNormalizer->uniqueMappedStrings(
            $refs,
            static function (mixed $ref): string {
                $ref = trim((string) $ref);
                if (preg_match('/^tier:(instant|standard|deep)$/', $ref) === 1) {
                    return $ref;
                }
                if (preg_match('/^awis_validation_tier:(instant|standard|deep)$/', $ref, $matches) !== 1) {
                    return '';
                }

                return 'tier:'.$matches[1];
            },
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function workspaceWorkingSetEffectivenessIndex(array $observed): array
    {
        $workingSets = [];
        foreach ($observed as $commandStats) {
            $command = (string) ($commandStats['command'] ?? '');
            foreach ((array) ($commandStats['working_set_refs'] ?? []) as $workingSetRef => $workingSetStats) {
                if (! is_string($workingSetRef) || ! is_array($workingSetStats)) {
                    continue;
                }
                $workingSets[$workingSetRef] ??= $this->emptyEffectivenessStats('working_set_ref', $workingSetRef);
                $this->accumulateEffectivenessStats($workingSets[$workingSetRef], $workingSetStats);
                if ($command !== '') {
                    $workingSets[$workingSetRef]['commands'] = $this->appendUniqueLimited(
                        $workingSets[$workingSetRef]['commands'] ?? [],
                        [$command],
                        8,
                    );
                }
            }
        }

        $workingSets = $this->finalizeEffectivenessStats($workingSets);

        uasort($workingSets, static fn (array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
            ?: ((int) ($right['total_count'] ?? 0) <=> (int) ($left['total_count'] ?? 0))
            ?: ((string) ($left['working_set_ref'] ?? '') <=> (string) ($right['working_set_ref'] ?? '')));

        $payload = [
            'schema_version' => 'atlas.workspace_working_set_effectiveness_index.v1',
            'working_set_count' => count($workingSets),
            'working_sets' => array_slice(array_values($workingSets), 0, 8),
            'source_policy' => [
                'raw_logs_returned' => false,
                'raw_provider_text_returned' => false,
                'raw_file_content_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['index_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function workingSetRefs(array $refs): array
    {
        return $this->cacheBackedHashRefs($refs, 'workspace_working_set');
    }

    /**
     * @param  array<int,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function workspaceContextDeltaEffectivenessIndex(array $observed): array
    {
        $plans = [];
        foreach ($observed as $commandStats) {
            $command = (string) ($commandStats['command'] ?? '');
            foreach ((array) ($commandStats['context_delta_refs'] ?? []) as $deltaRef => $deltaStats) {
                if (! is_string($deltaRef) || ! is_array($deltaStats)) {
                    continue;
                }
                $plans[$deltaRef] ??= $this->emptyEffectivenessStats('context_delta_ref', $deltaRef);
                $this->accumulateEffectivenessStats($plans[$deltaRef], $deltaStats);
                if ($command !== '') {
                    $plans[$deltaRef]['commands'] = $this->appendUniqueLimited(
                        $plans[$deltaRef]['commands'] ?? [],
                        [$command],
                        8,
                    );
                }
            }
        }

        $plans = $this->finalizeEffectivenessStats($plans);

        uasort($plans, static fn (array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
            ?: ((int) ($right['total_count'] ?? 0) <=> (int) ($left['total_count'] ?? 0))
            ?: ((string) ($left['context_delta_ref'] ?? '') <=> (string) ($right['context_delta_ref'] ?? '')));

        $payload = [
            'schema_version' => 'atlas.workspace_context_delta_effectiveness_index.v1',
            'context_delta_plan_count' => count($plans),
            'context_delta_plans' => array_slice(array_values($plans), 0, 8),
            'source_policy' => [
                'raw_logs_returned' => false,
                'raw_provider_text_returned' => false,
                'raw_file_content_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['index_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function contextDeltaRefs(array $refs): array
    {
        return $this->cacheBackedHashRefs($refs, 'context_delta_plan');
    }

    /**
     * @return array<int,string>
     */
    private function cacheBackedHashRefs(array $refs, string $canonicalPrefix): array
    {
        $cachePrefix = 'awis_cache:'.$canonicalPrefix.':';
        $canonicalPattern = '/^'.preg_quote($canonicalPrefix, '/').':[a-f0-9]{64}$/';

        return $this->listNormalizer->uniqueMappedStrings(
            $refs,
            static function (mixed $ref) use ($cachePrefix, $canonicalPattern, $canonicalPrefix): string {
                $ref = trim((string) $ref);
                if (preg_match($canonicalPattern, $ref) === 1) {
                    return $ref;
                }
                if (! str_starts_with($ref, $cachePrefix)) {
                    return '';
                }

                $hash = substr($ref, strlen($cachePrefix));

                return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $canonicalPrefix.':'.$hash : '';
            },
        );
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function commandPerformanceScore(array $stats): int
    {
        $duration = $stats['duration_ms_p95'] ?? $stats['duration_ms_avg'] ?? null;
        if (! is_numeric($duration) || (int) $duration <= 0) {
            return 0;
        }

        $duration = (int) $duration;

        return match (true) {
            $duration <= 10_000 => 2,
            $duration <= 60_000 => 1,
            $duration <= 300_000 => 0,
            $duration <= 900_000 => -1,
            default => -3,
        };
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function commandPerformanceGrade(array $stats): string
    {
        $duration = $stats['duration_ms_p95'] ?? $stats['duration_ms_avg'] ?? null;
        if (! is_numeric($duration) || (int) $duration <= 0) {
            return 'unknown';
        }

        $duration = (int) $duration;

        return match (true) {
            $duration <= 10_000 => 'fast',
            $duration <= 60_000 => 'normal',
            $duration <= 300_000 => 'heavy',
            default => 'slow',
        };
    }

    private function commandRecencyScore(string $observedAt): int
    {
        if ($observedAt === '') {
            return 0;
        }

        try {
            $days = Carbon::parse($observedAt)->diffInDays(Carbon::now());
        } catch (\Throwable) {
            return 0;
        }

        return match (true) {
            $days <= 2 => 2,
            $days <= 14 => 1,
            $days >= 90 => -1,
            default => 0,
        };
    }

    private function latestIsoTimestamp(string $current, ?string $candidate): ?string
    {
        $candidate = is_string($candidate) ? trim($candidate) : '';
        if ($candidate === '') {
            return $current !== '' ? $current : null;
        }
        if ($current === '') {
            return $candidate;
        }

        try {
            return Carbon::parse($candidate)->greaterThan(Carbon::parse($current))
                ? $candidate
                : $current;
        } catch (\Throwable) {
            return $current;
        }
    }

    private function areaKey(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_starts_with($path, '..')) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return null;
        }

        return implode('/', array_slice($parts, 0, min(2, count($parts))));
    }

    private function outcomePolarity(string $status): int
    {
        $status = mb_strtolower(trim($status));
        if (in_array($status, ['success', 'succeeded', 'passed', 'completed', 'approved', 'healthy', 'ready'], true)) {
            return 1;
        }
        if (in_array($status, ['failed', 'failure', 'blocked', 'error', 'rejected', 'cancelled', 'canceled'], true)) {
            return -1;
        }

        return 0;
    }

    /**
     * @param  array<int,string>  $commands
     * @param  array<string,array<string,mixed>>  $outcomeIndex
     * @return array<int,string>
     */
    private function rankCommandsByOutcome(array $commands, array $outcomeIndex, array $areas = []): array
    {
        $commands = $this->listNormalizer->uniqueStrings($commands);
        $positions = array_flip($commands);

        $areaKeys = $this->listNormalizer->uniqueMappedStrings(
            $areas,
            fn (mixed $area): ?string => $this->areaKey($area),
        );

        usort($commands, function (string $left, string $right) use ($outcomeIndex, $positions, $areaKeys): int {
            $leftStats = (array) ($outcomeIndex[$left] ?? []);
            $rightStats = (array) ($outcomeIndex[$right] ?? []);
            $leftObserved = (int) ($leftStats['total_count'] ?? 0) > 0;
            $rightObserved = (int) ($rightStats['total_count'] ?? 0) > 0;

            if ($leftObserved !== $rightObserved) {
                return $leftObserved ? -1 : 1;
            }

            $leftScore = (int) ($leftStats['effective_score'] ?? $leftStats['score'] ?? 0) + $this->commandAreaScore($leftStats, $areaKeys);
            $rightScore = (int) ($rightStats['effective_score'] ?? $rightStats['score'] ?? 0) + $this->commandAreaScore($rightStats, $areaKeys);

            return ($rightScore <=> $leftScore)
                ?: ((int) ($rightStats['success_count'] ?? 0) <=> (int) ($leftStats['success_count'] ?? 0))
                ?: ((int) ($leftStats['duration_ms_avg'] ?? PHP_INT_MAX) <=> (int) ($rightStats['duration_ms_avg'] ?? PHP_INT_MAX))
                ?: (($positions[$left] ?? 0) <=> ($positions[$right] ?? 0));
        });

        return $commands;
    }

    /**
     * @param  array<string,mixed>  $stats
     * @param  array<int,string>  $areaKeys
     */
    private function commandAreaScore(array $stats, array $areaKeys): int
    {
        if ($areaKeys === []) {
            return 0;
        }

        $affinity = (array) ($stats['area_affinity'] ?? []);
        $score = 0;
        foreach ($areaKeys as $area) {
            foreach ($affinity as $knownArea => $weight) {
                if (! is_string($knownArea)) {
                    continue;
                }
                if ($knownArea === $area || str_starts_with($knownArea, $area.'/') || str_starts_with($area, $knownArea.'/')) {
                    $score += (int) $weight;
                }
            }
        }

        return $score;
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
            'active_decisions' => $this->listNormalizer->uniqueStrings($decisions),
            'open_blockers' => $this->listNormalizer->uniqueStrings($blockers),
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
            'decision_ledger' => $this->listNormalizer->uniqueStrings($decisions),
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
     * Provider-safe live memory that turns the current workspace state into a
     * durable startup contract for the next session.
     *
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $workspaceReport
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $continuity
     * @param  array<string,mixed>  $changeMemory
     * @param  array<string,mixed>  $focusMap
     * @return array<string,mixed>
     */
    private function workspaceLiveExecutionMemory(
        ?array $profile,
        string $task,
        array $workspaceReport,
        array $twin,
        array $continuity,
        array $changeMemory,
        array $focusMap,
    ): array {
        $workspaceId = $profile['slug'] ?? data_get($workspaceReport, 'workspace_id');
        $repositoryInventory = (array) data_get($twin, 'repository_inventory', []);
        $repositories = array_values(array_filter((array) data_get($repositoryInventory, 'repositories', []), 'is_array'));
        $focusedRepositories = array_values((array) data_get($focusMap, 'focused_repositories', []));
        $focusedAreas = array_values((array) data_get($focusMap, 'focused_areas', []));
        $focusedCommands = array_values((array) data_get($focusMap, 'focused_commands', []));
        $contextUnits = array_values((array) data_get($focusMap, 'context_units', []));

        $repositoryMemory = array_slice(array_map(static fn (array $repository): array => [
            'repo_key' => (string) ($repository['repo_key'] ?? ''),
            'stack' => array_slice(array_values((array) ($repository['stack'] ?? [])), 0, 8),
            'manifest_count' => count((array) ($repository['manifest_files'] ?? [])),
            'script_count' => count((array) ($repository['script_names'] ?? [])),
        ], $repositories), 0, 8);

        $payload = [
            'schema_version' => 'atlas.awis.workspace_live_execution_memory.v1',
            'status' => data_get($workspaceReport, 'workspace_active') === true ? 'ready' : 'blocked',
            'workspace_id' => $workspaceId,
            'task_hash' => trim($task) !== '' ? hash('sha256', trim($task)) : null,
            'startup_packet' => [
                'load_first' => $this->listNormalizer->uniqueStringValues(array_merge([
                    'workspace_binding',
                    'workspace_live_execution_memory',
                    'repository_inventory',
                    'workspace_change_memory',
                    'workspace_focus_map',
                ], $contextUnits, [
                    'context_pack',
                    'test_plan',
                    'workspace_runbook',
                ])),
                'use_as_summary' => [
                    'reusable_workspace_state',
                    'focused_repositories_and_areas',
                    'validated_commands_and_risk_boundaries',
                    'artifact_backed_handoff_without_raw_conversation',
                ],
                'validate_before_trust' => [
                    'workspace_hash',
                    'repository_inventory_hash',
                    'workspace_change_hash',
                    'workspace_focus_hash',
                    'current_truth_pack_hash',
                ],
                'avoid' => [
                    'raw_conversation_replay',
                    'absolute_workspace_path_in_provider_prompt',
                    'cross_workspace_raw_memory_transfer',
                    'auto_promotion_without_evidence',
                ],
                'human_boundary' => [
                    'mutative_execution_requires_operator_or_certified_contract',
                    'canonical_doc_changes_require_human_review',
                ],
            ],
            'workspace_learning' => [
                'repositories' => $repositoryMemory,
                'focused_repositories' => array_map(static fn (mixed $repo): array => is_array($repo) ? [
                    'repo_key' => (string) ($repo['repo_key'] ?? ''),
                    'score' => (int) ($repo['score'] ?? 0),
                    'reasons' => array_slice(array_values((array) ($repo['reasons'] ?? [])), 0, 6),
                ] : [], array_slice($focusedRepositories, 0, 6)),
                'focused_areas' => array_slice($focusedAreas, 0, 10),
                'focused_commands' => array_slice($focusedCommands, 0, 10),
                'changed_files_preview' => array_slice(array_values((array) data_get($changeMemory, 'changed_files_preview', [])), 0, 10),
                'canonical_source_count' => count((array) data_get($continuity, 'current_truth_pack.canonical_sources', [])),
            ],
            'automation_loop' => [
                'before_send' => ['refresh_workspace_hashes', 'load_context_pack', 'validate_contracts'],
                'after_success' => ['record_outcome', 'promote_candidate_patterns_with_evidence', 'refresh_artifact_lake'],
                'after_failure' => ['create_failure_capsule', 'prefer_last_known_good_context', 'raise_review_flag'],
                'on_drift' => ['regenerate_focus_map', 'recompute_delta_plan', 'demote_stale_candidates'],
            ],
            'promotion_rules' => [
                'promote_to_gold' => ['repeated_success', 'same_workspace_hash_family', 'human_or_test_evidence'],
                'preserve_as_artifact' => ['handoff_packet', 'workspace_runbook', 'context_pack'],
                'revalidate' => ['workspace_hash_changed', 'repository_inventory_hash_changed', 'task_hash_changed'],
                'demote' => ['failed_validation', 'stale_workspace_hash', 'contradicted_by_canonical_docs'],
            ],
            'persistence_contract' => [
                'stored_with_awis_snapshot' => true,
                'embedded_in_artifact_lake' => 'workspace_runbook.body.live_execution_memory',
                'cross_session_replay' => 'provider_safe_hashes_counts_and_refs_only',
                'raw_conversation_stored' => false,
                'auto_promotes_memory' => false,
            ],
            'cache_keys' => [
                'workspace_hash' => data_get($workspaceReport, 'workspace_hash'),
                'repository_inventory_hash' => data_get($repositoryInventory, 'inventory_hash'),
                'workspace_change_hash' => data_get($changeMemory, 'change_hash'),
                'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                'current_truth_pack_hash' => MissionCanonicalHash::sha256(data_get($continuity, 'current_truth_pack', [])),
            ],
            'source_policy' => [
                'raw_file_content_returned' => false,
                'raw_diff_returned' => false,
                'raw_log_returned' => false,
                'raw_manifest_returned' => false,
                'raw_conversation_returned' => false,
                'absolute_workspace_path_returned' => false,
                'provider_prompt_unit' => 'startup_contract_hashes_counts_refs_and_short_labels_only',
            ],
        ];
        $payload['live_memory_hash'] = MissionCanonicalHash::sha256($payload);
        $payload['startup_packet']['validate_before_trust'][] = 'workspace_live_execution_memory_hash';
        $payload['cache_keys']['workspace_live_execution_memory_hash'] = $payload['live_memory_hash'];

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $continuity
     * @param  array<string,mixed>  $focusMap
     * @return array<string,mixed>
     */
    private function artifacts(?array $profile, string $task, array $twin, array $continuity, array $focusMap, array $liveExecutionMemory): array
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
                'live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
                'startup_packet' => data_get($liveExecutionMemory, 'startup_packet', []),
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
                'live_execution_memory' => $liveExecutionMemory,
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
                'required_inputs' => ['workspace_id', 'artifact_hash', 'source_hashes', 'task_packet', 'context_pack', 'test_plan', 'workspace_live_execution_memory'],
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
                'context_units' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet', 'workspace_live_execution_memory'],
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
     * @param  array<string,mixed>  $liveExecutionMemory
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
        array $liveExecutionMemory,
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
                    'workspace_live_execution_memory',
                    'workspace_next_session_brain',
                ],
                'memory_candidate_hash' => MissionCanonicalHash::sha256([
                    'workspace_id' => $workspaceId,
                    'decisions' => $decisions,
                    'blockers' => $blockers,
                    'patterns' => data_get($evolution, 'pattern_library.patterns', []),
                    'workspace_change_hash' => $changeHash,
                    'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                    'workspace_live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
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
                'workspace_live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
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
                        data_get($liveExecutionMemory, 'live_memory_hash') !== null ? 'awis_workspace_live_execution_memory:'.data_get($liveExecutionMemory, 'live_memory_hash') : null,
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
     * @param  array<string,mixed>  $liveExecutionMemory
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
        array $liveExecutionMemory,
    ): array {
        $workspaceId = $profile['slug'] ?? null;
        $focusedRepositories = array_values((array) data_get($focusMap, 'focused_repositories', []));
        $focusedAreas = array_values((array) data_get($focusMap, 'focused_areas', []));
        $focusedCommands = array_values((array) data_get($focusMap, 'focused_commands', []));
        $contextUnits = array_values((array) data_get($focusMap, 'context_units', []));
        $criticalChanges = array_values((array) data_get($changeMemory, 'critical_areas_touched', []));
        $repositoryInventory = (array) data_get($twin, 'repository_inventory', []);
        $outcomeCommandMemory = (array) data_get($twin, 'test_command_intelligence.outcome_memory', []);
        $outcomeIndex = (array) ($outcomeCommandMemory['command_outcome_index'] ?? []);
        $outcomeRankedCommands = array_values((array) ($outcomeCommandMemory['ranked_commands'] ?? []));
        $outcomeAvoidCommands = array_values((array) ($outcomeCommandMemory['avoid_commands'] ?? []));
        $repositories = array_values(array_filter(
            (array) data_get($repositoryInventory, 'repositories', []),
            'is_array',
        ));
        $focusedRepoKeys = $this->listNormalizer->uniqueMappedStrings(
            $focusedRepositories,
            static fn (mixed $repo): string => is_array($repo) ? (string) ($repo['repo_key'] ?? '') : '',
        );
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
        $artifactHashes = $this->listNormalizer->uniqueMappedStrings(
            (array) data_get($artifactFabric, 'artifacts', []),
            static fn (mixed $artifact): string => is_array($artifact) ? (string) ($artifact['artifact_hash'] ?? '') : '',
        );
        $ownerDocs = array_values((array) data_get($twin, 'living_code_map.owner_docs', []));
        $loadOrder = $this->listNormalizer->uniqueStringValues(array_merge([
            'workspace_binding',
            'workspace_live_execution_memory',
            'repository_inventory',
            'workspace_change_memory',
            'workspace_focus_map',
        ], $contextUnits, [
            'artifact_graph',
            'contract_certification',
            'workspace_runbook',
        ]));

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
            'workspace_live_execution_memory_hash:'.(string) data_get($liveExecutionMemory, 'live_memory_hash', ''),
            'artifact_graph_hash:'.(string) data_get($artifactIntelligence, 'artifact_graph.graph_hash', ''),
            'contract_hash:'.(string) data_get($contracts, 'contract_hash', ''),
            data_get($evolution, 'evolution_hash') !== null ? 'evolution_hash:'.(string) data_get($evolution, 'evolution_hash') : null,
        ], static fn (?string $value): bool => is_string($value) && ! str_ends_with($value, ':')));
        $workspaceWorkingSet = $this->workspaceWorkingSet(
            $workspaceId,
            $focusedRepositories,
            $focusedAreas,
            $focusedCommands,
            $contextUnits,
            $repositoryInventory,
            $outcomeCommandMemory,
            $changeMemory,
        );
        $contextDeltaPlan = $this->contextDeltaPlan(
            $workspaceId,
            $workspaceReport,
            $repositoryInventory,
            $changeMemory,
            $focusMap,
            $workspaceWorkingSet,
            $outcomeCommandMemory,
        );

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
                'live_execution_memory_ref' => 'awis_live_memory:'.(string) data_get($liveExecutionMemory, 'live_memory_hash', ''),
                'current_truth_pack_hash' => MissionCanonicalHash::sha256(data_get($continuity, 'current_truth_pack', [])),
            ],
            'context_loading_plan' => [
                'schema_version' => 'atlas.awis.context_loading_plan.v1',
                'mode' => 'folder_first_provider_safe_resume',
                'repository_inventory_hash' => data_get($repositoryInventory, 'inventory_hash'),
                'live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
                'live_execution_startup_packet' => data_get($liveExecutionMemory, 'startup_packet', []),
                'repository_count' => (int) data_get($repositoryInventory, 'repository_count', 0),
                'working_set_hash' => $workspaceWorkingSet['working_set_hash'],
                'workspace_working_set' => $workspaceWorkingSet,
                'context_delta_plan_hash' => $contextDeltaPlan['delta_plan_hash'],
                'context_delta_plan' => $contextDeltaPlan,
                'stack_tags' => array_slice(array_values((array) data_get($repositoryInventory, 'stack', [])), 0, 16),
                'focused_manifest_refs' => array_slice($focusedManifestRefs, 0, 6),
                'command_hints' => array_slice($this->rankCommandsByOutcome(
                    array_values((array) data_get($repositoryInventory, 'command_hints', [])),
                    $outcomeIndex,
                    $focusedAreas,
                ), 0, 10),
                'outcome_ranked_commands' => array_slice($outcomeRankedCommands, 0, 12),
                'area_ranked_commands' => array_slice($this->rankCommandsByOutcome(
                    $this->listNormalizer->uniqueStringValues(array_merge($focusedCommands, $outcomeRankedCommands)),
                    $outcomeIndex,
                    $focusedAreas,
                ), 0, 12),
                'flaky_commands' => array_slice(array_values((array) ($outcomeCommandMemory['flaky_commands'] ?? [])), 0, 8),
                'slow_commands' => array_slice(array_values((array) ($outcomeCommandMemory['slow_commands'] ?? [])), 0, 8),
                'avoid_commands' => array_slice($outcomeAvoidCommands, 0, 8),
                'outcome_command_memory_hash' => $outcomeCommandMemory['outcome_memory_hash'] ?? null,
                'command_performance_policy' => [
                    'prefer_recent_stable_fast_commands' => true,
                    'slow_command_threshold_ms' => 300_000,
                    'uses_duration_p95' => true,
                    'uses_duration_buckets' => true,
                    'raw_logs_returned' => false,
                ],
                'command_performance_histogram_hash' => data_get($outcomeCommandMemory, 'performance_histogram.histogram_hash'),
                'area_performance_index_hash' => data_get($outcomeCommandMemory, 'area_performance_index.index_hash'),
                'stack_performance_index_hash' => data_get($outcomeCommandMemory, 'stack_performance_index.index_hash'),
                'execution_policy_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'execution_policy_effectiveness_index.index_hash'),
                'execution_policy_effectiveness_profiles' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'execution_policy_effectiveness_index.policies', [])), 0, 8),
                'execution_route_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'execution_route_effectiveness_index.index_hash'),
                'execution_route_effectiveness_profiles' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'execution_route_effectiveness_index.routes', [])), 0, 8),
                'validation_tier_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'validation_tier_effectiveness_index.index_hash'),
                'validation_tier_effectiveness_profiles' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'validation_tier_effectiveness_index.tiers', [])), 0, 8),
                'working_set_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'working_set_effectiveness_index.index_hash'),
                'working_set_effectiveness_profiles' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'working_set_effectiveness_index.working_sets', [])), 0, 8),
                'context_delta_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'context_delta_effectiveness_index.index_hash'),
                'context_delta_effectiveness_profiles' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'context_delta_effectiveness_index.context_delta_plans', [])), 0, 8),
                'command_performance_histogram' => [
                    'bucket_counts' => (array) data_get($outcomeCommandMemory, 'performance_histogram.bucket_counts', []),
                    'fast_commands' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'performance_histogram.fast_commands', [])), 0, 6),
                    'heavy_commands' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'performance_histogram.heavy_commands', [])), 0, 6),
                    'slow_commands' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'performance_histogram.slow_commands', [])), 0, 6),
                    'raw_logs_returned' => false,
                ],
                'area_performance_profiles' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'area_performance_index.profiles', [])), 0, 8),
                'stack_performance_profiles' => array_slice(array_values((array) data_get($outcomeCommandMemory, 'stack_performance_index.profiles', [])), 0, 8),
                'cache_keys' => [
                    'workspace_hash' => data_get($workspaceReport, 'workspace_hash'),
                    'repository_inventory_hash' => data_get($repositoryInventory, 'inventory_hash'),
                    'workspace_working_set_hash' => $workspaceWorkingSet['working_set_hash'],
                    'context_delta_plan_hash' => $contextDeltaPlan['delta_plan_hash'],
                    'workspace_change_hash' => data_get($changeMemory, 'change_hash'),
                    'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                    'workspace_live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
                    'command_registry_hash' => data_get($twin, 'command_registry.command_registry_hash'),
                    'outcome_command_memory_hash' => $outcomeCommandMemory['outcome_memory_hash'] ?? null,
                    'command_performance_histogram_hash' => data_get($outcomeCommandMemory, 'performance_histogram.histogram_hash'),
                    'area_performance_index_hash' => data_get($outcomeCommandMemory, 'area_performance_index.index_hash'),
                    'stack_performance_index_hash' => data_get($outcomeCommandMemory, 'stack_performance_index.index_hash'),
                    'execution_policy_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'execution_policy_effectiveness_index.index_hash'),
                    'execution_route_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'execution_route_effectiveness_index.index_hash'),
                    'validation_tier_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'validation_tier_effectiveness_index.index_hash'),
                    'working_set_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'working_set_effectiveness_index.index_hash'),
                    'context_delta_effectiveness_index_hash' => data_get($outcomeCommandMemory, 'context_delta_effectiveness_index.index_hash'),
                ],
                'refresh_triggers' => [
                    'workspace_hash_changed',
                    'repository_inventory_hash_changed',
                    'workspace_change_hash_changed',
                    'workspace_live_execution_memory_hash_changed',
                    'context_delta_plan_hash_changed',
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
                'uses_outcome_command_memory' => true,
                'uses_command_performance_memory' => true,
                'uses_hash_cache_keys_for_resume' => true,
                'uses_workspace_working_set' => true,
                'uses_context_delta_plan' => true,
                'uses_live_execution_memory' => true,
                'raw_file_scan_required_for_provider_prompt' => false,
                'max_focused_repositories' => 4,
                'max_focused_commands' => 10,
                'max_manifest_refs' => 6,
                'max_hot_areas' => 8,
                'max_hot_commands' => 10,
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
        $optimizationPolicy = $this->workspaceExecutionOptimizationPolicy((array) $payload['context_loading_plan']);
        $payload['context_loading_plan']['execution_optimization_policy'] = $optimizationPolicy;
        $payload['context_loading_plan']['execution_optimization_policy_hash'] = $optimizationPolicy['policy_hash'];
        $payload['context_loading_plan']['cache_keys']['execution_optimization_policy_hash'] = $optimizationPolicy['policy_hash'];
        $payload['performance_budget']['uses_execution_optimization_policy'] = true;
        $payload['performance_budget']['execution_optimization_policy_hash'] = $optimizationPolicy['policy_hash'];
        $payload['brain_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * Provider-safe incremental context plan. It tells the next session what can
     * be reused from cache and what must be refreshed when folder signals move.
     *
     * @param  array<string,mixed>  $workspaceReport
     * @param  array<string,mixed>  $repositoryInventory
     * @param  array<string,mixed>  $changeMemory
     * @param  array<string,mixed>  $focusMap
     * @param  array<string,mixed>  $workspaceWorkingSet
     * @param  array<string,mixed>  $outcomeCommandMemory
     * @return array<string,mixed>
     */
    private function contextDeltaPlan(
        mixed $workspaceId,
        array $workspaceReport,
        array $repositoryInventory,
        array $changeMemory,
        array $focusMap,
        array $workspaceWorkingSet,
        array $outcomeCommandMemory,
    ): array {
        $changedAreas = array_slice($this->listNormalizer->uniqueStringValues(array_merge(
            $this->providerSafeStringList(data_get($changeMemory, 'critical_areas_touched', [])),
            $this->providerSafeStringList(data_get($changeMemory, 'changed_areas', [])),
            $this->providerSafeStringList(data_get($focusMap, 'focused_areas', [])),
        )), 0, 12);
        $hotAreas = $this->providerSafeStringList(data_get($workspaceWorkingSet, 'hot_areas', []));
        $hotOverlap = array_values(array_intersect($hotAreas, $changedAreas));

        $workspaceHash = data_get($workspaceReport, 'workspace_hash');
        $inventoryHash = data_get($repositoryInventory, 'inventory_hash');
        $workingSetHash = data_get($workspaceWorkingSet, 'working_set_hash');
        $changeHash = data_get($changeMemory, 'change_hash');
        $focusHash = data_get($focusMap, 'focus_hash');
        $outcomeMemoryHash = data_get($outcomeCommandMemory, 'outcome_memory_hash');
        $histogramHash = data_get($outcomeCommandMemory, 'performance_histogram.histogram_hash');
        $areaIndexHash = data_get($outcomeCommandMemory, 'area_performance_index.index_hash');
        $stackIndexHash = data_get($outcomeCommandMemory, 'stack_performance_index.index_hash');

        $reuseRefs = array_values(array_filter([
            is_string($inventoryHash) ? 'awis_cache:repository_inventory:'.$inventoryHash : null,
            is_string($workingSetHash) ? 'awis_cache:workspace_working_set:'.$workingSetHash : null,
            is_string($outcomeMemoryHash) ? 'awis_cache:outcome_command_memory:'.$outcomeMemoryHash : null,
            is_string($histogramHash) ? 'awis_cache:command_performance_histogram:'.$histogramHash : null,
            is_string($areaIndexHash) ? 'awis_cache:area_performance_index:'.$areaIndexHash : null,
            is_string($stackIndexHash) ? 'awis_cache:stack_performance_index:'.$stackIndexHash : null,
        ], 'is_string'));
        $refreshRefs = array_values(array_filter([
            is_string($workspaceHash) ? 'workspace_hash:'.$workspaceHash : null,
            is_string($changeHash) ? 'workspace_change_hash:'.$changeHash : null,
            is_string($focusHash) ? 'workspace_focus_hash:'.$focusHash : null,
            ...array_map(static fn (string $area): string => 'context_delta:changed_area:'.hash('sha256', $area), $changedAreas),
        ], 'is_string'));

        $decision = $hotOverlap !== []
            ? 'partial_refresh_hot_overlap'
            : ($changedAreas !== [] ? 'reuse_hot_context_refresh_changed_areas' : 'reuse_hot_context_with_hash_checks');

        $payload = [
            'schema_version' => 'atlas.awis.context_delta_plan.v1',
            'workspace_id' => is_string($workspaceId) ? $workspaceId : null,
            'status' => 'ready',
            'mode' => 'hash_based_incremental_context_resume',
            'decision' => $decision,
            'changed_area_count' => count($changedAreas),
            'hot_area_overlap_count' => count($hotOverlap),
            'changed_area_hashes' => array_map(static fn (string $area): string => hash('sha256', $area), array_slice($changedAreas, 0, 8)),
            'hot_overlap_area_hashes' => array_map(static fn (string $area): string => hash('sha256', $area), array_slice($hotOverlap, 0, 8)),
            'reuse_refs' => array_slice($reuseRefs, 0, 12),
            'refresh_refs' => array_slice($refreshRefs, 0, 16),
            'feedback' => $this->contextDeltaFeedbackSummary((array) data_get($outcomeCommandMemory, 'context_delta_effectiveness_index.context_delta_plans', [])),
            'refresh_triggers' => [
                'workspace_hash_changed',
                'repository_inventory_hash_changed',
                'workspace_change_hash_changed',
                'workspace_focus_hash_changed',
                'workspace_working_set_hash_changed',
            ],
            'prewarm_order' => [
                'context_delta_plan',
                'workspace_working_set',
                'repository_inventory',
                'outcome_command_memory',
                'execution_optimization_policy',
            ],
            'source_hashes' => [
                'workspace_hash' => $workspaceHash,
                'repository_inventory_hash' => $inventoryHash,
                'workspace_working_set_hash' => $workingSetHash,
                'workspace_change_hash' => $changeHash,
                'workspace_focus_hash' => $focusHash,
                'outcome_command_memory_hash' => $outcomeMemoryHash,
                'command_performance_histogram_hash' => $histogramHash,
                'area_performance_index_hash' => $areaIndexHash,
                'stack_performance_index_hash' => $stackIndexHash,
            ],
            'source_policy' => [
                'raw_file_content_returned' => false,
                'raw_diff_returned' => false,
                'raw_manifest_returned' => false,
                'script_bodies_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['delta_plan_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @return array<string,mixed>
     */
    private function contextDeltaFeedbackSummary(array $profiles): array
    {
        $effective = 0;
        $mixed = 0;
        $failing = 0;
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            match ((string) ($profile['effectiveness'] ?? 'unknown')) {
                'effective' => $effective++,
                'mixed' => $mixed++,
                'failing' => $failing++,
                default => null,
            };
        }

        return [
            'schema_version' => 'atlas.awis.context_delta_feedback.v1',
            'observed_delta_plan_count' => count(array_filter($profiles, 'is_array')),
            'effective_delta_plan_count' => $effective,
            'mixed_delta_plan_count' => $mixed,
            'failing_delta_plan_count' => $failing,
            'next_adjustment' => $failing > 0 || $mixed > 0
                ? 'prefer_partial_refresh_until_delta_stabilizes'
                : ($effective > 0 ? 'reuse_incremental_delta_shape' : 'collect_context_delta_outcome_feedback'),
            'raw_logs_returned' => false,
        ];
    }

    /**
     * Provider-safe hot context plan for the next session. This is the AWIS
     * working set: what should be kept warm without loading raw file content.
     *
     * @param  array<int,mixed>  $focusedRepositories
     * @param  array<int,mixed>  $focusedAreas
     * @param  array<int,mixed>  $focusedCommands
     * @param  array<int,mixed>  $contextUnits
     * @param  array<string,mixed>  $repositoryInventory
     * @param  array<string,mixed>  $outcomeCommandMemory
     * @param  array<string,mixed>  $changeMemory
     * @return array<string,mixed>
     */
    private function workspaceWorkingSet(
        mixed $workspaceId,
        array $focusedRepositories,
        array $focusedAreas,
        array $focusedCommands,
        array $contextUnits,
        array $repositoryInventory,
        array $outcomeCommandMemory,
        array $changeMemory,
    ): array {
        $hotRepositories = array_slice(array_values(array_filter(array_map(
            static fn (mixed $repo): array => is_array($repo) ? [
                'repo_key' => (string) ($repo['repo_key'] ?? ''),
                'score' => (int) ($repo['score'] ?? 0),
                'stack' => array_slice(array_values((array) ($repo['stack'] ?? [])), 0, 6),
            ] : [],
            $focusedRepositories,
        ), static fn (array $repo): bool => ($repo['repo_key'] ?? '') !== '')), 0, 4);

        $areaProfileKeys = array_values(array_filter(array_map(
            static fn (mixed $profile): string => is_array($profile) ? (string) ($profile['key'] ?? '') : '',
            (array) data_get($outcomeCommandMemory, 'area_performance_index.profiles', []),
        ), static fn (string $key): bool => $key !== ''));
        $hotAreas = array_slice($this->listNormalizer->uniqueMappedStrings(
            array_merge($focusedAreas, (array) data_get($changeMemory, 'critical_areas_touched', []), $areaProfileKeys),
            static fn (mixed $area): string => is_string($area) ? $area : (is_array($area) ? (string) ($area['key'] ?? '') : ''),
        ), 0, 8);

        $stackProfileKeys = array_values(array_filter(array_map(
            static fn (mixed $profile): string => is_array($profile) ? (string) ($profile['key'] ?? '') : '',
            (array) data_get($outcomeCommandMemory, 'stack_performance_index.profiles', []),
        ), static fn (string $key): bool => $key !== ''));
        $hotStacks = array_slice($this->listNormalizer->uniqueStringValues(array_merge(
            (array) data_get($repositoryInventory, 'stack', []),
            $stackProfileKeys,
        )), 0, 12);

        $hotCommands = array_slice($this->rankCommandsByOutcome($this->listNormalizer->uniqueStringValues(array_merge(
            $focusedCommands,
            (array) data_get($outcomeCommandMemory, 'performance_histogram.fast_commands', []),
            (array) data_get($outcomeCommandMemory, 'ranked_commands', []),
        )), (array) data_get($outcomeCommandMemory, 'command_outcome_index', []), $hotAreas), 0, 10);

        $cacheRefs = array_values(array_filter([
            data_get($repositoryInventory, 'inventory_hash') !== null ? 'awis_cache:repository_inventory:'.data_get($repositoryInventory, 'inventory_hash') : null,
            data_get($outcomeCommandMemory, 'outcome_memory_hash') !== null ? 'awis_cache:outcome_command_memory:'.data_get($outcomeCommandMemory, 'outcome_memory_hash') : null,
            data_get($outcomeCommandMemory, 'performance_histogram.histogram_hash') !== null ? 'awis_cache:command_performance_histogram:'.data_get($outcomeCommandMemory, 'performance_histogram.histogram_hash') : null,
            data_get($outcomeCommandMemory, 'area_performance_index.index_hash') !== null ? 'awis_cache:area_performance_index:'.data_get($outcomeCommandMemory, 'area_performance_index.index_hash') : null,
            data_get($outcomeCommandMemory, 'stack_performance_index.index_hash') !== null ? 'awis_cache:stack_performance_index:'.data_get($outcomeCommandMemory, 'stack_performance_index.index_hash') : null,
        ], 'is_string'));

        $payload = [
            'schema_version' => 'atlas.awis.workspace_working_set.v1',
            'workspace_id' => is_string($workspaceId) ? $workspaceId : null,
            'status' => $hotRepositories !== [] || $hotAreas !== [] || $hotCommands !== [] ? 'ready' : 'limited',
            'mode' => 'provider_safe_hot_context_set',
            'hot_repositories' => $hotRepositories,
            'hot_areas' => $hotAreas,
            'hot_stacks' => $hotStacks,
            'hot_commands' => $hotCommands,
            'hot_context_units' => array_slice($this->listNormalizer->uniqueStringValues($contextUnits), 0, 10),
            'cache_refs' => array_slice($cacheRefs, 0, 12),
            'prewarm_plan' => [
                'load_cache_refs_first' => true,
                'load_manifest_names_only' => true,
                'load_recent_outcome_indexes' => true,
                'load_raw_file_content' => false,
                'max_hot_repositories' => 4,
                'max_hot_areas' => 8,
                'max_hot_commands' => 10,
            ],
            'feedback' => $this->workingSetFeedbackSummary((array) data_get($outcomeCommandMemory, 'working_set_effectiveness_index.working_sets', [])),
            'source_policy' => [
                'raw_file_content_returned' => false,
                'raw_diff_returned' => false,
                'raw_manifest_returned' => false,
                'script_bodies_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['working_set_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @return array<string,mixed>
     */
    private function workingSetFeedbackSummary(array $profiles): array
    {
        $effective = 0;
        $mixed = 0;
        $failing = 0;
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            match ((string) ($profile['effectiveness'] ?? 'unknown')) {
                'effective' => $effective++,
                'mixed' => $mixed++,
                'failing' => $failing++,
                default => null,
            };
        }

        return [
            'schema_version' => 'atlas.awis.workspace_working_set_feedback.v1',
            'observed_working_set_count' => count(array_filter($profiles, 'is_array')),
            'effective_working_set_count' => $effective,
            'mixed_working_set_count' => $mixed,
            'failing_working_set_count' => $failing,
            'next_adjustment' => $failing > 0 || $mixed > 0
                ? 'refresh_hot_areas_and_recompute_command_order'
                : ($effective > 0 ? 'reuse_effective_hot_context_shape' : 'collect_working_set_outcome_feedback'),
            'raw_logs_returned' => false,
        ];
    }

    /**
     * Turns learned command outcomes into a small execution policy for Dev and
     * Forge. The payload is provider-safe: command strings, buckets and hashes
     * only, no output, logs or file content.
     *
     * @param  array<string,mixed>  $contextLoadingPlan
     * @return array<string,mixed>
     */
    private function workspaceExecutionOptimizationPolicy(array $contextLoadingPlan): array
    {
        $avoidCommands = $this->providerSafeStringList($contextLoadingPlan['avoid_commands'] ?? []);
        $slowCommands = $this->providerSafeStringList($contextLoadingPlan['slow_commands'] ?? []);
        $flakyCommands = $this->providerSafeStringList($contextLoadingPlan['flaky_commands'] ?? []);
        $fastCommands = $this->providerSafeStringList(data_get($contextLoadingPlan, 'command_performance_histogram.fast_commands', []));
        $heavyCommands = $this->providerSafeStringList(data_get($contextLoadingPlan, 'command_performance_histogram.heavy_commands', []));
        $rankedCandidates = $this->providerSafeStringList(array_merge(
            (array) ($contextLoadingPlan['area_ranked_commands'] ?? []),
            (array) ($contextLoadingPlan['outcome_ranked_commands'] ?? []),
            (array) ($contextLoadingPlan['command_hints'] ?? []),
        ));
        $blocked = $this->listNormalizer->uniqueStringValues(array_merge($avoidCommands, $slowCommands));
        $deferred = $this->listNormalizer->uniqueStringValues(array_merge(
            $heavyCommands,
            $slowCommands,
            $flakyCommands,
        ));

        $preferred = array_values(array_filter(
            $this->listNormalizer->uniqueStringValues(array_merge($fastCommands, $rankedCandidates)),
            static fn (string $command): bool => ! in_array($command, $blocked, true)
                && ($fastCommands === [] || in_array($command, $fastCommands, true)),
        ));
        if ($preferred === []) {
            $preferred = array_values(array_filter(
                $rankedCandidates,
                static fn (string $command): bool => ! in_array($command, $blocked, true)
                    && ! in_array($command, $deferred, true),
            ));
        }
        $standard = array_values(array_filter(
            $rankedCandidates,
            static fn (string $command): bool => ! in_array($command, $blocked, true)
                && ! in_array($command, $preferred, true)
                && ! in_array($command, $deferred, true),
        ));
        $policyProfiles = array_values(array_filter(
            (array) ($contextLoadingPlan['execution_policy_effectiveness_profiles'] ?? []),
            'is_array',
        ));
        $effectivePolicyRefs = [];
        $mixedPolicyRefs = [];
        $failingPolicyRefs = [];
        foreach ($policyProfiles as $profile) {
            $policyRef = (string) ($profile['policy_ref'] ?? '');
            if ($policyRef === '') {
                continue;
            }

            match ((string) ($profile['effectiveness'] ?? 'unknown')) {
                'effective' => $effectivePolicyRefs[] = $policyRef,
                'mixed' => $mixedPolicyRefs[] = $policyRef,
                'failing' => $failingPolicyRefs[] = $policyRef,
                default => null,
            };
        }
        $needsTighterPolicy = $failingPolicyRefs !== [] || $mixedPolicyRefs !== [];
        $standardCommandLimit = $needsTighterPolicy ? 4 : 8;
        $deepRequiresOperator = true;
        $nextAdjustment = 'collect_policy_outcome_feedback';
        if ($needsTighterPolicy) {
            $nextAdjustment = 'tighten_default_to_preferred_fast_commands';
        } elseif ($effectivePolicyRefs !== []) {
            $nextAdjustment = 'reuse_effective_policy_shape';
        }
        $areaRoutes = $this->scopedExecutionRoutes(
            (array) ($contextLoadingPlan['area_performance_profiles'] ?? []),
            $blocked,
            $deferred,
            $this->routeEffectivenessFeedback((array) ($contextLoadingPlan['execution_route_effectiveness_profiles'] ?? []), 'area'),
            $this->validationTierEffectivenessFeedback((array) ($contextLoadingPlan['validation_tier_effectiveness_profiles'] ?? [])),
            'area',
        );
        $stackRoutes = $this->scopedExecutionRoutes(
            (array) ($contextLoadingPlan['stack_performance_profiles'] ?? []),
            $blocked,
            $deferred,
            $this->routeEffectivenessFeedback((array) ($contextLoadingPlan['execution_route_effectiveness_profiles'] ?? []), 'stack'),
            $this->validationTierEffectivenessFeedback((array) ($contextLoadingPlan['validation_tier_effectiveness_profiles'] ?? [])),
            'stack',
        );
        $validationTierRouting = $this->validationTierRoutingSummary(
            $areaRoutes,
            $stackRoutes,
            $this->validationTierEffectivenessFeedback((array) ($contextLoadingPlan['validation_tier_effectiveness_profiles'] ?? [])),
        );

        $payload = [
            'schema_version' => 'atlas.awis.execution_optimization_policy.v1',
            'mode' => 'prefer_fast_stable_area_relevant_commands',
            'preferred_commands' => array_slice($preferred, 0, 6),
            'standard_commands' => array_slice($standard, 0, $standardCommandLimit),
            'deferred_commands' => array_slice(array_values(array_diff($deferred, $blocked)), 0, 8),
            'blocked_commands' => array_slice($blocked, 0, 8),
            'policy_feedback' => [
                'enabled' => true,
                'observed_policy_count' => count($policyProfiles),
                'effective_policy_refs' => array_slice($this->listNormalizer->uniqueStrings($effectivePolicyRefs), 0, 6),
                'mixed_policy_refs' => array_slice($this->listNormalizer->uniqueStrings($mixedPolicyRefs), 0, 6),
                'failing_policy_refs' => array_slice($this->listNormalizer->uniqueStrings($failingPolicyRefs), 0, 6),
                'next_adjustment' => $nextAdjustment,
                'standard_command_limit' => $standardCommandLimit,
                'raw_logs_returned' => false,
            ],
            'scope_routing' => [
                'schema_version' => 'atlas.awis.execution_scope_routing.v1',
                'area_routes' => $areaRoutes,
                'stack_routes' => $stackRoutes,
                'route_count' => count($areaRoutes) + count($stackRoutes),
                'route_policy' => 'prefer_scope_specific_commands_before_global_ranked_commands',
                'feedback' => [
                    'enabled' => true,
                    'observed_route_count' => count((array) ($contextLoadingPlan['execution_route_effectiveness_profiles'] ?? [])),
                    'effective_routes_reused' => count(array_filter(
                        array_merge($areaRoutes, $stackRoutes),
                        static fn (array $route): bool => ($route['feedback_effectiveness'] ?? null) === 'effective',
                    )),
                    'guarded_routes' => count(array_filter(
                        array_merge($areaRoutes, $stackRoutes),
                        static fn (array $route): bool => in_array(($route['feedback_effectiveness'] ?? null), ['mixed', 'failing'], true),
                    )),
                    'raw_logs_returned' => false,
                ],
                'raw_logs_returned' => false,
            ],
            'validation_tiers' => [
                'instant' => [
                    'max_command_count' => 2,
                    'prefer_performance_grade' => 'fast',
                    'max_expected_duration_ms' => 60_000,
                    'requires_effective_or_fast_route' => true,
                ],
                'standard' => [
                    'max_command_count' => 4,
                    'allow_performance_grades' => ['fast', 'normal', 'heavy'],
                    'max_expected_duration_ms' => 300_000,
                    'default_for_unknown_routes' => true,
                ],
                'deep' => [
                    'requires_operator_or_high_risk_context' => $deepRequiresOperator,
                    'allow_deferred_commands' => true,
                    'max_expected_duration_ms' => 900_000,
                    'required_for_mixed_or_failing_routes' => true,
                ],
            ],
            'validation_tier_routing' => $validationTierRouting,
            'selection_policy' => [
                'prepend_preferred_commands_to_task_packets' => true,
                'exclude_blocked_commands_from_default_packets' => true,
                'defer_heavy_or_flaky_commands_until_risk_requires_them' => true,
                'area_relevance_beats_manifest_order' => true,
                'scope_routes_override_global_order_when_present' => true,
                'route_feedback_controls_validation_depth' => true,
                'raw_logs_returned' => false,
            ],
            'source_hashes' => [
                'outcome_command_memory_hash' => $contextLoadingPlan['outcome_command_memory_hash'] ?? null,
                'command_performance_histogram_hash' => $contextLoadingPlan['command_performance_histogram_hash'] ?? null,
                'area_performance_index_hash' => $contextLoadingPlan['area_performance_index_hash'] ?? null,
                'stack_performance_index_hash' => $contextLoadingPlan['stack_performance_index_hash'] ?? null,
                'execution_policy_effectiveness_index_hash' => $contextLoadingPlan['execution_policy_effectiveness_index_hash'] ?? null,
                'execution_route_effectiveness_index_hash' => $contextLoadingPlan['execution_route_effectiveness_index_hash'] ?? null,
                'validation_tier_effectiveness_index_hash' => $contextLoadingPlan['validation_tier_effectiveness_index_hash'] ?? null,
            ],
            'source_policy' => [
                'raw_logs_returned' => false,
                'raw_diff_returned' => false,
                'raw_file_content_returned' => false,
                'absolute_workspace_path_returned' => false,
            ],
        ];
        $payload['policy_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @param  array<int,string>  $blocked
     * @param  array<int,string>  $deferred
     * @param  array<string,string>  $routeFeedback
     * @param  array<string,string>  $tierFeedback
     * @return array<int,array<string,mixed>>
     */
    private function scopedExecutionRoutes(array $profiles, array $blocked, array $deferred, array $routeFeedback, array $tierFeedback, string $routeKind): array
    {
        $routes = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $key = trim((string) ($profile['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $commands = $this->providerSafeStringList($profile['commands'] ?? []);
            $blockedCommands = array_values(array_intersect($commands, $blocked));
            $deferredCommands = array_values(array_diff(array_intersect($commands, $deferred), $blockedCommands));
            $preferredCommands = array_values(array_diff($commands, $blockedCommands, $deferredCommands));
            $routeRef = $routeKind.':'.hash('sha256', $key);
            $feedbackEffectiveness = $routeFeedback[$routeRef] ?? null;
            if (in_array($feedbackEffectiveness, ['mixed', 'failing'], true)) {
                $deferredCommands = $this->listNormalizer->uniqueStringValues(array_merge($deferredCommands, $preferredCommands));
                $preferredCommands = [];
            }
            $routeMode = $preferredCommands !== []
                ? 'prefer_scope_commands'
                : ($deferredCommands !== [] || $blockedCommands !== [] ? 'deep_validation_only' : 'observe_more');
            $validationTier = $this->validationTierForExecutionRoute(
                $feedbackEffectiveness ?? 'unknown',
                (string) ($profile['performance_grade'] ?? 'unknown'),
                $routeMode,
                $preferredCommands,
                $deferredCommands,
                $blockedCommands,
                $tierFeedback,
            );
            $routes[] = [
                'key' => $key,
                'route_ref' => $routeRef,
                'observed_count' => (int) ($profile['observed_count'] ?? 0),
                'performance_grade' => (string) ($profile['performance_grade'] ?? 'unknown'),
                'duration_ms_p95' => is_numeric($profile['duration_ms_p95'] ?? null) ? (int) $profile['duration_ms_p95'] : null,
                'preferred_commands' => array_slice($preferredCommands, 0, 4),
                'deferred_commands' => array_slice($deferredCommands, 0, 4),
                'blocked_commands' => array_slice($blockedCommands, 0, 4),
                'feedback_effectiveness' => $feedbackEffectiveness ?? 'unknown',
                'route_mode' => $routeMode,
                'recommended_validation_tier' => $validationTier['tier'],
                'validation_reason' => $validationTier['reason'],
            ];
        }

        return array_slice($routes, 0, 8);
    }

    /**
     * @param  array<int,string>  $preferredCommands
     * @param  array<int,string>  $deferredCommands
     * @param  array<int,string>  $blockedCommands
     * @param  array<string,string>  $tierFeedback
     * @return array{tier:string,reason:string}
     */
    private function validationTierForExecutionRoute(
        string $feedbackEffectiveness,
        string $performanceGrade,
        string $routeMode,
        array $preferredCommands,
        array $deferredCommands,
        array $blockedCommands,
        array $tierFeedback = [],
    ): array {
        if (in_array($feedbackEffectiveness, ['mixed', 'failing'], true)) {
            return ['tier' => 'deep', 'reason' => 'route_feedback_requires_guarded_validation'];
        }

        if ($routeMode === 'deep_validation_only' || $blockedCommands !== []) {
            return ['tier' => 'deep', 'reason' => 'scope_contains_blocked_or_slow_commands'];
        }

        if ($feedbackEffectiveness === 'effective' && $performanceGrade === 'fast' && $preferredCommands !== []) {
            if (in_array(($tierFeedback['tier:instant'] ?? 'unknown'), ['mixed', 'failing'], true)) {
                return ['tier' => 'standard', 'reason' => 'instant_tier_feedback_guarded'];
            }

            return ['tier' => 'instant', 'reason' => 'effective_fast_scope_route'];
        }

        if ($preferredCommands !== [] && $deferredCommands === []) {
            return ['tier' => 'standard', 'reason' => 'scope_has_stable_preferred_commands'];
        }

        return ['tier' => 'standard', 'reason' => 'observe_route_until_feedback_is_stronger'];
    }

    /**
     * @param  array<int,array<string,mixed>>  $areaRoutes
     * @param  array<int,array<string,mixed>>  $stackRoutes
     * @param  array<string,string>  $tierFeedback
     * @return array<string,mixed>
     */
    private function validationTierRoutingSummary(array $areaRoutes, array $stackRoutes, array $tierFeedback = []): array
    {
        $routes = array_merge($areaRoutes, $stackRoutes);
        $tierCounts = ['instant' => 0, 'standard' => 0, 'deep' => 0];
        foreach ($routes as $route) {
            $tier = (string) ($route['recommended_validation_tier'] ?? 'standard');
            if (! array_key_exists($tier, $tierCounts)) {
                $tier = 'standard';
            }
            $tierCounts[$tier]++;
        }

        return [
            'schema_version' => 'atlas.awis.validation_tier_routing.v1',
            'mode' => 'route_and_risk_aware_validation_depth',
            'default_tier' => 'standard',
            'instant_route_count' => $tierCounts['instant'],
            'standard_route_count' => $tierCounts['standard'],
            'deep_route_count' => $tierCounts['deep'],
            'route_count' => count($routes),
            'tier_feedback' => [
                'enabled' => true,
                'instant_effectiveness' => $tierFeedback['tier:instant'] ?? 'unknown',
                'standard_effectiveness' => $tierFeedback['tier:standard'] ?? 'unknown',
                'deep_effectiveness' => $tierFeedback['tier:deep'] ?? 'unknown',
                'instant_guarded' => in_array(($tierFeedback['tier:instant'] ?? 'unknown'), ['mixed', 'failing'], true),
                'raw_logs_returned' => false,
            ],
            'selection_policy' => [
                'effective_fast_routes_use_instant_validation' => true,
                'unknown_routes_use_standard_validation' => true,
                'mixed_or_failing_routes_use_deep_validation' => true,
                'raw_logs_returned' => false,
            ],
            'raw_logs_returned' => false,
        ];
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @return array<string,string>
     */
    private function routeEffectivenessFeedback(array $profiles, string $routeKind): array
    {
        $feedback = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $routeRef = (string) ($profile['route_ref'] ?? '');
            if (! str_starts_with($routeRef, $routeKind.':')) {
                continue;
            }
            $effectiveness = (string) ($profile['effectiveness'] ?? 'unknown');
            if (in_array($effectiveness, ['effective', 'mixed', 'failing'], true)) {
                $feedback[$routeRef] = $effectiveness;
            }
        }

        return $feedback;
    }

    /**
     * @param  array<int,mixed>  $profiles
     * @return array<string,string>
     */
    private function validationTierEffectivenessFeedback(array $profiles): array
    {
        $feedback = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $tierRef = (string) ($profile['tier_ref'] ?? '');
            if (preg_match('/^tier:(instant|standard|deep)$/', $tierRef) !== 1) {
                continue;
            }
            $effectiveness = (string) ($profile['effectiveness'] ?? 'unknown');
            if (in_array($effectiveness, ['effective', 'mixed', 'failing'], true)) {
                $feedback[$tierRef] = $effectiveness;
            }
        }

        return $feedback;
    }

    /**
     * @return array<int,string>
     */
    private function providerSafeStringList(mixed $values): array
    {
        return $this->listNormalizer->uniqueSingleLineStrings($values);
    }

    /**
     * @return array<int,string>
     */
    private function limitedProviderSafeStringList(mixed $values, int $limit): array
    {
        return array_slice($this->providerSafeStringList($values), 0, $limit);
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,mixed>
     */
    private function appendUniqueLimited(mixed $existing, array $items, int $limit): array
    {
        return array_slice($this->listNormalizer->uniqueStrings(array_merge((array) $existing, $items)), 0, $limit);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function emptyEffectivenessStats(string $refKey, string $ref, array $extra = []): array
    {
        return [
            $refKey => $ref,
            ...$extra,
            'success_count' => 0,
            'failure_count' => 0,
            'neutral_count' => 0,
            'total_count' => 0,
            'score' => 0,
            'commands' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $bucket
     * @param  array<string,mixed>  $stats
     */
    private function accumulateEffectivenessStats(array &$bucket, array $stats): void
    {
        foreach (self::EFFECTIVENESS_COUNTER_KEYS as $key) {
            $bucket[$key] = (int) $bucket[$key] + (int) ($stats[$key] ?? 0);
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $items
     * @return array<string,array<string,mixed>>
     */
    private function finalizeEffectivenessStats(array $items): array
    {
        foreach ($items as $key => $item) {
            $total = max((int) ($item['total_count'] ?? 0), 1);
            $items[$key]['success_rate'] = round((int) ($item['success_count'] ?? 0) / $total, 2);
            $items[$key]['effectiveness'] = match (true) {
                (int) ($item['failure_count'] ?? 0) > 0 && (int) ($item['success_count'] ?? 0) > 0 => 'mixed',
                (int) ($item['failure_count'] ?? 0) > 0 => 'failing',
                (int) ($item['success_count'] ?? 0) > 0 => 'effective',
                default => 'unknown',
            };
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $workspaceReport
     * @param  array<string,mixed>  $repositoryInventory
     * @param  array<string,mixed>  $changeMemory
     * @param  array<string,mixed>  $focusMap
     * @param  array<string,mixed>  $nextSessionBrain
     * @param  array<string,mixed>  $learningLoop
     * @param  array<string,mixed>  $twin
     * @param  array<string,mixed>  $liveExecutionMemory
     * @return array<string,mixed>
     */
    private function workspaceLearningSnapshot(
        ?array $profile,
        array $workspaceReport,
        array $repositoryInventory,
        array $changeMemory,
        array $focusMap,
        array $nextSessionBrain,
        array $learningLoop,
        array $twin,
        array $liveExecutionMemory,
    ): array {
        $outcomeMemory = (array) data_get($twin, 'test_command_intelligence.outcome_memory', []);
        $histogram = (array) data_get($outcomeMemory, 'performance_histogram', []);
        $areaIndex = (array) data_get($outcomeMemory, 'area_performance_index', []);
        $stackIndex = (array) data_get($outcomeMemory, 'stack_performance_index', []);
        $policyEffectivenessIndex = (array) data_get($outcomeMemory, 'execution_policy_effectiveness_index', []);
        $routeEffectivenessIndex = (array) data_get($outcomeMemory, 'execution_route_effectiveness_index', []);
        $validationTierEffectivenessIndex = (array) data_get($outcomeMemory, 'validation_tier_effectiveness_index', []);
        $workingSetEffectivenessIndex = (array) data_get($outcomeMemory, 'working_set_effectiveness_index', []);
        $contextDeltaEffectivenessIndex = (array) data_get($outcomeMemory, 'context_delta_effectiveness_index', []);
        $workingSet = (array) data_get($nextSessionBrain, 'context_loading_plan.workspace_working_set', []);
        $contextDeltaPlan = (array) data_get($nextSessionBrain, 'context_loading_plan.context_delta_plan', []);
        $bucketCounts = (array) ($histogram['bucket_counts'] ?? []);

        $payload = [
            'schema_version' => 'atlas.awis.workspace_learning_snapshot.v1',
            'status' => data_get($learningLoop, 'status') === 'ready' ? 'ready' : 'limited',
            'workspace_id' => $profile['slug'] ?? data_get($workspaceReport, 'workspace_id'),
            'workspace_hash' => data_get($workspaceReport, 'workspace_hash'),
            'learning_score' => (float) data_get($learningLoop, 'evidence_learning.learning_score', 0.0),
            'readiness_score' => (float) data_get($nextSessionBrain, 'readiness_score', 0.0),
            'workspace_state' => [
                'workspace_ready' => data_get($workspaceReport, 'readiness_status') === 'ready',
                'workspace_path_exists' => data_get($workspaceReport, 'workspace_path_exists') === true,
                'repository_inventory_status' => data_get($repositoryInventory, 'status'),
                'change_memory_status' => data_get($changeMemory, 'status'),
                'focus_map_status' => data_get($focusMap, 'status'),
                'next_session_brain_status' => data_get($nextSessionBrain, 'status'),
                'learning_loop_closed' => data_get($learningLoop, 'closed_loop.loop_closed') === true,
            ],
            'component_hashes' => [
                'repository_inventory_hash' => data_get($repositoryInventory, 'inventory_hash'),
                'workspace_working_set_hash' => data_get($workingSet, 'working_set_hash'),
                'context_delta_plan_hash' => data_get($contextDeltaPlan, 'delta_plan_hash'),
                'workspace_change_hash' => data_get($changeMemory, 'change_hash'),
                'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                'workspace_live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
                'outcome_command_memory_hash' => data_get($outcomeMemory, 'outcome_memory_hash'),
                'command_performance_histogram_hash' => data_get($histogram, 'histogram_hash'),
                'area_performance_index_hash' => data_get($areaIndex, 'index_hash'),
                'stack_performance_index_hash' => data_get($stackIndex, 'index_hash'),
                'execution_policy_effectiveness_index_hash' => data_get($policyEffectivenessIndex, 'index_hash'),
                'execution_route_effectiveness_index_hash' => data_get($routeEffectivenessIndex, 'index_hash'),
                'validation_tier_effectiveness_index_hash' => data_get($validationTierEffectivenessIndex, 'index_hash'),
                'working_set_effectiveness_index_hash' => data_get($workingSetEffectivenessIndex, 'index_hash'),
                'context_delta_effectiveness_index_hash' => data_get($contextDeltaEffectivenessIndex, 'index_hash'),
            ],
            'learned_signal_counts' => [
                'repository_count' => (int) data_get($repositoryInventory, 'repository_count', 0),
                'working_set_hot_area_count' => count((array) data_get($workingSet, 'hot_areas', [])),
                'working_set_hot_command_count' => count((array) data_get($workingSet, 'hot_commands', [])),
                'context_delta_changed_area_count' => (int) data_get($contextDeltaPlan, 'changed_area_count', 0),
                'context_delta_hot_overlap_count' => (int) data_get($contextDeltaPlan, 'hot_area_overlap_count', 0),
                'changed_file_count' => (int) data_get($changeMemory, 'changed_file_count', 0),
                'focused_repository_count' => count((array) data_get($focusMap, 'focused_repositories', [])),
                'focused_area_count' => count((array) data_get($focusMap, 'focused_areas', [])),
                'live_memory_repository_count' => count((array) data_get($liveExecutionMemory, 'workspace_learning.repositories', [])),
                'live_memory_command_count' => count((array) data_get($liveExecutionMemory, 'workspace_learning.focused_commands', [])),
                'observed_command_count' => (int) data_get($outcomeMemory, 'observed_command_count', 0),
                'ranked_command_count' => count((array) data_get($outcomeMemory, 'ranked_commands', [])),
                'flaky_command_count' => count((array) data_get($outcomeMemory, 'flaky_commands', [])),
                'slow_command_count' => count((array) data_get($outcomeMemory, 'slow_commands', [])),
                'avoid_command_count' => count((array) data_get($outcomeMemory, 'avoid_commands', [])),
                'fast_command_count' => count((array) data_get($histogram, 'fast_commands', [])),
                'heavy_command_count' => count((array) data_get($histogram, 'heavy_commands', [])),
                'area_performance_profile_count' => count((array) data_get($areaIndex, 'profiles', [])),
                'stack_performance_profile_count' => count((array) data_get($stackIndex, 'profiles', [])),
                'execution_policy_profile_count' => count((array) data_get($policyEffectivenessIndex, 'policies', [])),
                'execution_route_profile_count' => count((array) data_get($routeEffectivenessIndex, 'routes', [])),
                'validation_tier_profile_count' => count((array) data_get($validationTierEffectivenessIndex, 'tiers', [])),
                'working_set_effectiveness_profile_count' => count((array) data_get($workingSetEffectivenessIndex, 'working_sets', [])),
                'context_delta_effectiveness_profile_count' => count((array) data_get($contextDeltaEffectivenessIndex, 'context_delta_plans', [])),
            ],
            'performance_memory' => [
                'bucket_counts' => [
                    'under_10s' => (int) ($bucketCounts['under_10s'] ?? 0),
                    '10s_to_60s' => (int) ($bucketCounts['10s_to_60s'] ?? 0),
                    '1m_to_5m' => (int) ($bucketCounts['1m_to_5m'] ?? 0),
                    '5m_to_15m' => (int) ($bucketCounts['5m_to_15m'] ?? 0),
                    'over_15m' => (int) ($bucketCounts['over_15m'] ?? 0),
                ],
                'uses_duration_p95' => true,
                'uses_duration_buckets' => true,
                'raw_logs_returned' => false,
            ],
            'persistence_policy' => [
                'stored_with_awis_snapshot' => true,
                'replay_scope' => 'same_workspace_hash_or_latest_workspace_review',
                'auto_promotes_memory' => false,
                'cross_workspace_learning_allowed' => false,
                'canonical_doc_rewrite_allowed' => false,
            ],
            'source_policy' => [
                'raw_file_content_returned' => false,
                'raw_diff_returned' => false,
                'raw_log_returned' => false,
                'raw_provider_text_returned' => false,
                'raw_conversation_returned' => false,
                'absolute_workspace_path_returned' => false,
                'provider_prompt_unit' => 'workspace_hashes_scores_counts_and_cache_refs_only',
            ],
        ];
        $payload['snapshot_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $nextSessionBrain
     * @param  array<string,mixed>  $learningSnapshot
     * @return array<string,mixed>
     */
    private function attachLearningSnapshotToNextSessionBrain(array $nextSessionBrain, array $learningSnapshot): array
    {
        $snapshotHash = data_get($learningSnapshot, 'snapshot_hash');
        if (! is_string($snapshotHash) || $snapshotHash === '') {
            return $nextSessionBrain;
        }

        data_set($nextSessionBrain, 'context_loading_plan.learning_snapshot_hash', $snapshotHash);
        data_set($nextSessionBrain, 'context_loading_plan.learning_score', data_get($learningSnapshot, 'learning_score', 0.0));
        data_set($nextSessionBrain, 'context_loading_plan.cache_keys.workspace_learning_snapshot_hash', $snapshotHash);
        $refreshTriggers = array_values((array) data_get($nextSessionBrain, 'context_loading_plan.refresh_triggers', []));
        $refreshTriggers[] = 'workspace_learning_snapshot_hash_changed';
        data_set($nextSessionBrain, 'context_loading_plan.refresh_triggers', $this->listNormalizer->uniqueStringValues($refreshTriggers));

        unset($nextSessionBrain['brain_hash']);
        $nextSessionBrain['brain_hash'] = MissionCanonicalHash::sha256($nextSessionBrain);

        return $nextSessionBrain;
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

        $requirements = [
            'desktop_workspace_picker_present' => $this->fileContains($desktopSurfacePath, '<AtlasAiWorkspacePicker'),
            'desktop_last_project_selector_tested' => $this->fileContainsAll($desktopSelectorTestPath, [
                'last persisted project',
                'persistWorkspaceSlug',
            ]),
            'desktop_workspace_lock_present' => $this->fileContainsAll($desktopSurfacePath, [
                'workspaceLock',
                'effectiveWorkspaceSlug',
            ]),
            'desktop_picker_search_and_create_present' => $this->fileContainsAll($desktopPickerPath, [
                'Pesquisar projetos',
                "onOpenWorkspaceProfile?.('create')",
            ]),
            'desktop_drag_merge_room_present' => (
                $this->fileContainsAny($desktopThreadListPath, [
                    'atlas-ai-conversation-merge-room',
                    'ProjectSpacesPanel',
                    'pointerFusionSpaceTargetId',
                ])
            )
                && (
                    $this->fileContainsAny($desktopThreadListPath, [
                        'Solte em outra conversa para criar um pack AWIS',
                        'Solte em outra conversa para criar um Space',
                        'Solte sobre outra conversa para criar um Space',
                    ])
                ),
            'desktop_drag_fusion_persists_artifact' => $this->fileContains($desktopSurfacePath, 'refreshConversationFusion(threadIds, { persist: true })')
                && $this->fileContains($desktopFusionTestPath, 'Drag thread-to-thread fusion'),
            'mobile_workspace_model_present' => $this->fileContains($mobileModelPath, 'workspaceContextFromThreadAndTrace'),
            'mobile_context_sheet_awis_present' => $this->fileContainsAll($mobileContextPath, [
                'workspace AWIS',
                'fixo nesta conversa',
            ]),
            'mobile_workspace_context_tested' => $this->fileContains($mobileTestPath, 'Mobile ContextSheet must expose AWIS workspace scope'),
            'mobile_workspace_selector_present' => $this->fileContainsAll($mobileSheetPath, [
                'listAtlasWorkspaceProfiles',
                '<AtlasAiWorkspaceSheet',
            ])
                && $this->fileContains($mobileFooterPath, 'workspaceLabel')
                && $this->fileContains($mobileSelectorSheetPath, 'Escolher projeto'),
            'mobile_workspace_selector_search_present' => $this->fileContainsAll($mobileSelectorSheetPath, [
                'TextInput',
                'workspacePickerOptions',
            ]),
            'mobile_workspace_create_present' => $this->fileContains($mobileSheetPath, 'createAtlasWorkspaceProfile')
                && $this->fileContains($mobileSelectorSheetPath, 'ADICIONAR NOVO PROJETO')
                && $this->fileContains($mobileSelectorTestPath, 'Mobile Atlas AI must create workspace profiles from the selector'),
            'mobile_workspace_lock_payload_present' => $this->fileContains($mobileSheetPath, 'mobileWorkspacePayload(mobileWorkspaceLock)')
                && $this->fileContains($mobileSelectorModelPath, 'atlas.mobile_ai.workspace_scope.v1'),
            'mobile_workspace_selector_tested' => $this->fileContainsAll($mobileSelectorTestPath, [
                'Mobile Atlas AI must fetch workspace profiles from backend',
                'Mobile Atlas AI submit must use locked workspace slug',
            ]),
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

        $requirements = [
            'migration_present' => $this->fileExists($migrationPath),
            'model_present' => $this->fileExists($modelPath),
            'api_list_route' => $this->fileContains($routePath, "Route::get('/projects/workspaces'"),
            'api_create_route' => $this->fileContains($routePath, "Route::post('/projects/workspaces'"),
            'api_show_route' => $this->fileContains($routePath, "Route::get('/projects/workspaces/{slug}'"),
            'api_update_route' => $this->fileContains($routePath, "Route::patch('/projects/workspaces/{slug}'"),
            'api_archive_route' => $this->fileContains($routePath, "Route::delete('/projects/workspaces/{slug}'"),
            'controller_index' => $this->fileContains($controllerPath, 'function index('),
            'controller_show' => $this->fileContains($controllerPath, 'function show('),
            'controller_store' => $this->fileContains($controllerPath, 'function store('),
            'controller_update' => $this->fileContains($controllerPath, 'function update('),
            'controller_destroy' => $this->fileContains($controllerPath, 'function destroy('),
            'service_upsert' => $this->fileContains($servicePath, 'function upsertPersistedProfile('),
            'service_archive' => $this->fileContains($servicePath, 'function archivePersistedProfile('),
            'cli_register' => $this->fileContains($commandPath, 'registerWorkspace('),
            'cli_list' => $this->fileContains($commandPath, "'list'"),
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

        return $this->providerSafeStringList($stack);
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

    private function fileContains(string $path, string $needle): bool
    {
        return $this->fileContainsAll($path, [$needle]);
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function fileContainsAll(string $path, array $needles): bool
    {
        $matches = $this->fileNeedleMatches($path, $needles);
        if ($matches === []) {
            return false;
        }

        foreach ($matches as $matched) {
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function fileContainsAny(string $path, array $needles): bool
    {
        foreach ($this->fileNeedleMatches($path, $needles) as $matched) {
            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $needles
     * @return array<string,bool>
     */
    private function fileNeedleMatches(string $path, array $needles): array
    {
        $needles = $this->listNormalizer->uniqueStrings($needles);
        if ($needles === [] || ! is_file($path)) {
            return [];
        }

        $matches = array_fill_keys($needles, false);
        $maxNeedleBytes = max(array_map('strlen', $needles));
        $overlapBytes = max(0, $maxNeedleBytes - 1);

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $matches;
        }

        $tail = '';
        try {
            while (! feof($handle)) {
                $chunk = (string) fread($handle, 8192);
                if ($chunk === '') {
                    break;
                }

                $haystack = $tail.$chunk;
                foreach ($matches as $needle => $matched) {
                    if (! $matched && str_contains($haystack, $needle)) {
                        $matches[$needle] = true;
                    }
                }

                if (! in_array(false, $matches, true)) {
                    break;
                }

                $tail = $overlapBytes > 0 ? substr($haystack, -$overlapBytes) : '';
            }
        } finally {
            fclose($handle);
        }

        return $matches;
    }

    private function fileGet(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return (string) (@file_get_contents($path) ?: '');
    }
}
