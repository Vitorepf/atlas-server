<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence\Runtime;

use App\Models\AiForgeOutcomeMemory;
use App\Models\AiForgeWorkPacket;
use App\Models\AiTestResult;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasEngineeringTestRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceListNormalizer;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\Support\CommandOutcomeScoringSupport;
use Illuminate\Support\Carbon;

/**
 * GOD-DEBULK FASE C extraction of the AWTR workspace outcome-command-memory engine
 * (outcome ranking, performance histograms, effectiveness/route/tier/working-set/
 * context-delta indexes) from AtlasWorkspaceIntelligenceRuntimeService.
 *
 * Method bodies are moved VERBATIM. Shared private helpers that stay on the façade
 * (providerSafeStringList, limitedProviderSafeStringList, appendUniqueLimited, areaKey)
 * are reached through __call, which rebinds into the façade scope.
 *
 * Pure scoring/stats helpers live in CommandOutcomeScoringSupport; this section
 * keeps I/O and section assembly, with thin wrappers for call-site stability.
 */
final class WorkspaceOutcomeCommandMemorySection
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
        // on the façade (providerSafeStringList, areaKey, …). Rebind the call into the
        // façade scope so those private methods stay reachable without widening their
        // visibility. Upgrade path: promote the shared helpers to a Support class if
        // this section ever needs to run without a mother bound.
        return (function () use ($name, $arguments) {
            return $this->{$name}(...$arguments);
        })->call($this->mother);
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
     * Provider-safe memory that turns Dev/Forge outcomes into command ranking
     * signals. It returns command strings and hashes only; no raw logs, no raw
     * diffs and no model/provider text.
     *
     * @param  array<string,mixed>|null  $profile
     * @param  array<int,string>  $candidateCommands
     * @param  array<string,mixed>  $repositoryInventory
     * @return array<string,mixed>
     */
    public function workspaceOutcomeCommandMemory(?array $profile, array $candidateCommands, array $repositoryInventory = []): array
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
                $changedFiles = $this->listNormalizer->stringsFromArrayCast($packet->expected_files ?? []);
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
                $changedFiles = $this->changedFilesFromCommandMetadata($metadata);
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
                $changedFiles = $this->changedFilesFromCommandMetadata($metadata);
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
     * @return list<string>
     */
    private function changedFilesFromCommandMetadata(array $metadata): array
    {
        return array_values(array_filter(array_merge(
            (array) ($metadata['changed_files'] ?? []),
            (array) ($metadata['expected_files'] ?? []),
            (array) ($metadata['files'] ?? []),
        ), 'is_string'));
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
        return CommandOutcomeScoringSupport::cappedDurationSamples($samples, $durationMs, $limit);
    }

    /**
     * @param  array<int,mixed>  $samples
     */
    private function durationPercentile(array $samples, float $percentile): ?int
    {
        return CommandOutcomeScoringSupport::durationPercentile($samples, $percentile);
    }

    private function durationBucket(int $durationMs): string
    {
        return CommandOutcomeScoringSupport::durationBucket($durationMs);
    }

    /**
     * @param  array<string,mixed>  $profiles
     */
    private function recordPerformanceProfile(array &$profiles, string $key, int $durationMs): void
    {
        CommandOutcomeScoringSupport::recordPerformanceProfile($profiles, $key, $durationMs);
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
        return $this->listNormalizer->uniqueMappedStrings(
            $refs,
            static fn (mixed $ref): string => CommandOutcomeScoringSupport::normalizeCacheBackedHashRef($ref, $canonicalPrefix),
        );
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function commandPerformanceScore(array $stats): int
    {
        return CommandOutcomeScoringSupport::commandPerformanceScore($stats);
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function commandPerformanceGrade(array $stats): string
    {
        return CommandOutcomeScoringSupport::commandPerformanceGrade($stats);
    }

    private function commandRecencyScore(string $observedAt): int
    {
        return CommandOutcomeScoringSupport::commandRecencyScore($observedAt);
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

    private function outcomePolarity(string $status): int
    {
        return CommandOutcomeScoringSupport::outcomePolarity($status);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function emptyEffectivenessStats(string $refKey, string $ref, array $extra = []): array
    {
        return CommandOutcomeScoringSupport::emptyEffectivenessStats($refKey, $ref, $extra);
    }

    /**
     * @param  array<string,mixed>  $bucket
     * @param  array<string,mixed>  $stats
     */
    private function accumulateEffectivenessStats(array &$bucket, array $stats): void
    {
        CommandOutcomeScoringSupport::accumulateEffectivenessStats($bucket, $stats);
    }

    /**
     * @param  array<string,array<string,mixed>>  $items
     * @return array<string,array<string,mixed>>
     */
    private function finalizeEffectivenessStats(array $items): array
    {
        return CommandOutcomeScoringSupport::finalizeEffectivenessStats($items);
    }
}
