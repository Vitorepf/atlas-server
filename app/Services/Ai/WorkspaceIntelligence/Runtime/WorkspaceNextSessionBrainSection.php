<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence\Runtime;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceListNormalizer;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\Support\ExecutionOptimizationPolicySupport;

/**
 * GOD-DEBULK FASE C extraction of the AWIS workspace next-session-brain engine
 * (context-loading plan, context-delta plan, workspace working set, execution
 * optimization policy, scoped execution routes and validation-tier routing) from
 * AtlasWorkspaceIntelligenceRuntimeService.
 *
 * Pure prefer/block/defer lanes, scoped routes and validation-tier maps live in
 * ExecutionOptimizationPolicySupport. Shared private helpers that stay on the façade
 * (rankCommandsByOutcome, providerSafeStringList) are reached through __call, which
 * rebinds into the façade scope.
 */
final class WorkspaceNextSessionBrainSection
{
    private ?AtlasWorkspaceIntelligenceRuntimeService $mother = null;

    public function __construct(
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
        // on the façade (rankCommandsByOutcome, providerSafeStringList). Rebind the call
        // into the façade scope so those private methods stay reachable without widening
        // their visibility. Upgrade path: promote the shared helpers to a Support class
        // if this section ever needs to run without a mother bound.
        return (function () use ($name, $arguments) {
            return $this->{$name}(...$arguments);
        })->call($this->mother);
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
    public function workspaceNextSessionBrain(
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
        // Input sanitization stays on section (provider-safe string list via mother).
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

        $lanes = ExecutionOptimizationPolicySupport::classifyCommandLanes(
            $avoidCommands,
            $slowCommands,
            $flakyCommands,
            $fastCommands,
            $heavyCommands,
            $rankedCandidates,
        );
        $preferred = $lanes['preferred'];
        $standard = $lanes['standard'];
        $deferred = $lanes['deferred'];
        $blocked = $lanes['blocked'];

        $policyFeedback = ExecutionOptimizationPolicySupport::policyFeedbackFromProfiles(
            (array) ($contextLoadingPlan['execution_policy_effectiveness_profiles'] ?? []),
        );
        $standardCommandLimit = $policyFeedback['standard_command_limit'];
        $deepRequiresOperator = $policyFeedback['deep_requires_operator'];

        $tierFeedback = ExecutionOptimizationPolicySupport::validationTierEffectivenessFeedback(
            (array) ($contextLoadingPlan['validation_tier_effectiveness_profiles'] ?? []),
        );
        $routeProfiles = (array) ($contextLoadingPlan['execution_route_effectiveness_profiles'] ?? []);
        $areaRoutes = ExecutionOptimizationPolicySupport::scopedExecutionRoutes(
            (array) ($contextLoadingPlan['area_performance_profiles'] ?? []),
            $blocked,
            $deferred,
            ExecutionOptimizationPolicySupport::routeEffectivenessFeedback($routeProfiles, 'area'),
            $tierFeedback,
            'area',
        );
        $stackRoutes = ExecutionOptimizationPolicySupport::scopedExecutionRoutes(
            (array) ($contextLoadingPlan['stack_performance_profiles'] ?? []),
            $blocked,
            $deferred,
            ExecutionOptimizationPolicySupport::routeEffectivenessFeedback($routeProfiles, 'stack'),
            $tierFeedback,
            'stack',
        );
        $validationTierRouting = ExecutionOptimizationPolicySupport::validationTierRoutingSummary(
            $areaRoutes,
            $stackRoutes,
            $tierFeedback,
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
                'observed_policy_count' => $policyFeedback['observed_policy_count'],
                'effective_policy_refs' => array_slice($policyFeedback['effective_policy_refs'], 0, 6),
                'mixed_policy_refs' => array_slice($policyFeedback['mixed_policy_refs'], 0, 6),
                'failing_policy_refs' => array_slice($policyFeedback['failing_policy_refs'], 0, 6),
                'next_adjustment' => $policyFeedback['next_adjustment'],
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
                    'observed_route_count' => count($routeProfiles),
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
            'validation_tiers' => ExecutionOptimizationPolicySupport::validationTierDefinitions($deepRequiresOperator),
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
}
