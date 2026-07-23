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
use App\Services\Ai\WorkspaceIntelligence\Runtime\WorkspaceDiscoverySection;
use App\Services\Ai\WorkspaceIntelligence\Runtime\WorkspaceLearningLoopSection;
use App\Services\Ai\WorkspaceIntelligence\Runtime\WorkspaceNextSessionBrainSection;
use App\Services\Ai\WorkspaceIntelligence\Runtime\WorkspaceOutcomeCommandMemorySection;
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

    private ?WorkspaceOutcomeCommandMemorySection $outcomeCommandMemorySection = null;

    private function outcomeCommandMemorySection(): WorkspaceOutcomeCommandMemorySection
    {
        return $this->outcomeCommandMemorySection ??= app(WorkspaceOutcomeCommandMemorySection::class)->setMother($this);
    }

    private ?WorkspaceNextSessionBrainSection $nextSessionBrainSection = null;

    private function nextSessionBrainSection(): WorkspaceNextSessionBrainSection
    {
        return $this->nextSessionBrainSection ??= app(WorkspaceNextSessionBrainSection::class)->setMother($this);
    }

    private ?WorkspaceDiscoverySection $discoverySection = null;

    private function discoverySection(): WorkspaceDiscoverySection
    {
        return $this->discoverySection ??= app(WorkspaceDiscoverySection::class)->setMother($this);
    }

    private ?WorkspaceLearningLoopSection $learningLoopSection = null;

    private function learningLoopSection(): WorkspaceLearningLoopSection
    {
        return $this->learningLoopSection ??= app(WorkspaceLearningLoopSection::class)->setMother($this);
    }

    /**
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    public function certify(?string $workspace = null, string $task = '', array $conversationTexts = []): array
    {
        $profile = $this->resolveProfile($workspace);
        $workspaceReport = $this->workspaceReport($profile);
        $changeMemory = $this->discoverySection()->workspaceChangeMemory($profile);
        $twin = $this->workspaceTwin($profile);
        $repositoryInventory = (array) data_get($twin, 'repository_inventory', []);
        $continuity = $this->continuity($profile, $conversationTexts);
        $focusMap = $this->workspaceFocusMap($profile, $task, $twin, $changeMemory);
        $liveExecutionMemory = $this->workspaceLiveExecutionMemory($profile, $task, $workspaceReport, $twin, $continuity, $changeMemory, $focusMap);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap, $liveExecutionMemory);
        $artifactIntelligence = $this->artifactIntelligence($profile, $task, $artifacts, $twin, $continuity);
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $nextSessionBrain = $this->nextSessionBrainSection()->workspaceNextSessionBrain($profile, $task, $workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $liveExecutionMemory);
        $learningLoop = $this->learningLoopSection()->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain, $liveExecutionMemory);
        $learningSnapshot = $this->workspaceLearningSnapshot($profile, $workspaceReport, $repositoryInventory, $changeMemory, $focusMap, $nextSessionBrain, $learningLoop, $twin, $liveExecutionMemory);
        $nextSessionBrain = $this->attachLearningSnapshotToNextSessionBrain($nextSessionBrain, $learningSnapshot);
        $learningLoop = $this->learningLoopSection()->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain, $liveExecutionMemory);
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
        $changeMemory = $this->discoverySection()->workspaceChangeMemory($profile);
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
        $changeMemory = $this->discoverySection()->workspaceChangeMemory($profile);
        $twin = $this->workspaceTwin($profile);
        $continuity = $this->continuity($profile, $conversationTexts);
        $focusMap = $this->workspaceFocusMap($profile, $task, $twin, $changeMemory);
        $liveExecutionMemory = $this->workspaceLiveExecutionMemory($profile, $task, $workspaceReport, $twin, $continuity, $changeMemory, $focusMap);
        $artifacts = $this->artifacts($profile, $task, $twin, $continuity, $focusMap, $liveExecutionMemory);
        $artifactIntelligence = $this->artifactIntelligence($profile, $task, $artifacts, $twin, $continuity);
        $contracts = $this->contracts($workspaceReport, $artifacts);
        $evolution = $this->evolution($profile, $twin);
        $nextSessionBrain = $this->nextSessionBrainSection()->workspaceNextSessionBrain($profile, $task, $workspaceReport, $twin, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $liveExecutionMemory);

        return $this->learningLoopSection()->workspaceLearningLoop($profile, $task, $continuity, $artifacts, $artifactIntelligence, $contracts, $evolution, $changeMemory, $focusMap, $nextSessionBrain, $liveExecutionMemory);
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
        $repositoryInventory = $this->discoverySection()->workspaceRepositoryInventory($profile);
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

        $outcomeCommandMemory = $this->outcomeCommandMemorySection()->workspaceOutcomeCommandMemory($profile, $commands, $repositoryInventory);

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
     */
    private function artifactWorkspaceHash(?array $profile): ?string
    {
        if ($profile === null || ! (bool) ($profile['workspace_path_exists'] ?? false)) {
            return null;
        }

        return $this->workspaceRootHash((string) ($profile['workspace_path'] ?? ''));
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
