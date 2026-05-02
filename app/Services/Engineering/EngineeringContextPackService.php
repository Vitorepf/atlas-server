<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringContextPack;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use Illuminate\Support\Facades\Schema;

class EngineeringContextPackService
{
    private EngineeringKnowledgeBaseService $knowledgeBase;
    private EngineeringCodeIntelligenceService $codeIntelligence;

    public function __construct(
        private readonly WorkspaceProfiler $profiler,
        private readonly AtlasMemoryRegistryService $memoryRegistry,
        ?EngineeringKnowledgeBaseService $knowledgeBase = null,
        ?EngineeringCodeIntelligenceService $codeIntelligence = null,
    ) {
        $this->knowledgeBase = $knowledgeBase ?? app(EngineeringKnowledgeBaseService::class);
        $this->codeIntelligence = $codeIntelligence ?? app(EngineeringCodeIntelligenceService::class);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @param  array<int,array<string,mixed>>  $controls
     * @return array<string,mixed>
     */
    public function build(
        AtlasTask $task,
        ?AtlasEngineeringRun $run,
        string $workspace,
        array $contract,
        array $blueprint,
        array $controls,
    ): array {
        $workspace = realpath($workspace) ?: $workspace;
        $profile = $this->profiler->profile($workspace);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $priorRuns = array_values((array) ($metadata['engineering_run_history'] ?? []));
        $memoryRefs = array_values(array_merge(
            (array) ($metadata['memory_refs'] ?? []),
            $this->registryMemoryRefs($task, $run, $workspace),
        ));
        $knowledgeRefs = $this->knowledgeBase->contextRefs([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'engineering_run_id' => $run?->id,
            'workspace' => $workspace,
            'contract' => $contract,
            'blueprint' => $blueprint,
        ], 8);
        $codeRefs = $this->codeIntelligence->contextRefs([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'engineering_run_id' => $run?->id,
            'workspace' => $workspace,
            'contract' => $contract,
            'blueprint' => $blueprint,
        ], 10);
        $selectedFiles = collect((array) ($contract['likely_files'] ?? []))
            ->merge($profile->importantFiles)
            ->merge(collect($knowledgeRefs)->pluck('canonical_path')->all())
            ->merge(collect($codeRefs)->pluck('root_path')->all())
            ->merge(collect($codeRefs)->flatMap(fn (array $ref): array => (array) ($ref['related_tests'] ?? []))->all())
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->unique()
            ->take(80)
            ->values()
            ->all();

        $payload = [
            'schema_version' => 1,
            'task_id' => $task->id,
            'engineering_run_id' => $run?->id,
            'contract' => $contract,
            'blueprint' => $blueprint,
            'repo_profile' => [
                'workspace' => $profile->workspace,
                'repo_root' => $profile->repoRoot,
                'branch' => $profile->branch,
                'head' => $profile->head,
                'stack' => $profile->stack,
                'package_manager' => $profile->packageManager,
                'test_commands' => $profile->testCommands,
                'dirty_files_preview' => array_slice($profile->dirtyFiles, 0, 40),
                'important_files' => $profile->importantFiles,
            ],
            'selected_files' => $selectedFiles,
            'prior_runs' => array_slice($priorRuns, 0, 10),
            'memory_refs' => $memoryRefs,
            'knowledge_refs' => $knowledgeRefs,
            'code_refs' => $codeRefs,
            'prompt_sections' => [
                ['kind' => 'contract', 'title' => 'Task contract', 'priority' => 1],
                ['kind' => 'blueprint', 'title' => 'Frozen blueprint', 'priority' => 2],
                ['kind' => 'controls', 'title' => 'Guides and sensors', 'priority' => 3],
                ['kind' => 'repo_profile', 'title' => 'Workspace profile', 'priority' => 4],
                ['kind' => 'engineering_knowledge', 'title' => 'Canonical engineering knowledge', 'priority' => 5],
                ['kind' => 'code_intelligence', 'title' => 'Indexed code modules and symbols', 'priority' => 6],
            ],
            'token_budget' => [
                'target' => 6000,
                'hard_limit' => 12000,
            ],
            'controls' => collect($controls)->map(fn (array $control): array => [
                'slug' => $control['slug'] ?? null,
                'direction' => $control['direction'] ?? null,
                'execution_type' => $control['execution_type'] ?? null,
                'required' => $control['required'] ?? false,
            ])->values()->all(),
        ];
        $hash = $this->hash($payload);
        $payload['hash'] = $hash;

        if ($run && Schema::hasTable('atlas_engineering_context_packs')) {
            $pack = AtlasEngineeringContextPack::query()->create([
                'engineering_run_id' => $run->id,
                'task_id' => $task->id,
                'hash' => $hash,
                'contract_json' => $contract,
                'blueprint_json' => $blueprint,
                'repo_profile_json' => $payload['repo_profile'],
                'selected_files_json' => $selectedFiles,
                'prior_runs_json' => array_slice($priorRuns, 0, 10),
                'memory_refs_json' => $payload['memory_refs'],
                'prompt_sections_json' => $payload['prompt_sections'],
                'token_budget_json' => $payload['token_budget'],
                'metadata' => [
                    'controls' => $payload['controls'],
                    'knowledge_refs' => $payload['knowledge_refs'],
                    'knowledge_ref_count' => count($payload['knowledge_refs']),
                    'code_refs' => $payload['code_refs'],
                    'code_ref_count' => count($payload['code_refs']),
                    'source' => 'atlas:engineering:runner',
                ],
            ]);

            $run->forceFill([
                'context_pack_id' => $pack->id,
                'context_pack_hash' => $hash,
            ])->save();

            $payload['context_pack_id'] = $pack->id;
        }

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function registryMemoryRefs(AtlasTask $task, ?AtlasEngineeringRun $run, string $workspace): array
    {
        return $this->memoryRegistry->relevantForContext([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'engineering_run_id' => $run?->id,
            'workspace' => $workspace,
        ], [], 12)
            ->map(fn (AtlasMemoryEntry $entry): array => [
                'type' => 'atlas_memory_entry',
                'id' => $entry->id,
                'memory_type' => $entry->memory_type,
                'scope_type' => $entry->scope_type,
                'scope_id' => $entry->scope_id,
                'priority' => $entry->priority,
                'importance' => $entry->importance,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'reason' => match ($entry->scope_type) {
                    'global' => 'global_memory',
                    'workspace' => 'workspace_memory',
                    default => 'scoped_to_engineering_context',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        unset($payload['hash'], $payload['context_pack_id']);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
