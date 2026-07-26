<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\PlanCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\TaskCompiler;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use App\Services\Ai\Support\AiStringListNormalizer;
use Throwable;

final class ProgrammingWorkItemSpecPlanService
{
    public function __construct(
        private readonly ProgrammingGovernanceService $governance,
        private readonly ProgrammingSpecCompiler $specCompiler,
        private readonly PlanCompiler $planCompiler,
        private readonly TaskCompiler $taskCompiler,
    ) {}

    public function compile(
        AtlasProject $project,
        string $workItem,
        array $data = [],
    ): array {

        try {
            $item = $this->governance->find($workItem);
        } catch (\RuntimeException) {
            return ['http_status' => 404, 'payload' => $this->blockResponse($project, null, ['work_item_not_found'], 'work_item_not_found')];
        }

        if (! $this->workItemBelongsToProject($project, $item)) {
            return ['http_status' => 403, 'payload' => $this->blockResponse($project, $item, ['work_item_not_bound_to_obra'], 'work_item_not_bound_to_obra')];
        }

        if ($item->spec_hash !== null && $item->plan_hash !== null && (array) $item->tasks_json !== []) {
            return ['http_status' => 200, 'payload' => $this->specPlanPayload(
                $project,
                $this->governance,
                $item->refresh(),
                status: 'already_planned',
                compiled: false,
            )];
        }

        try {
            $spec = $this->specForWorkItem($item, $this->specCompiler, $data);
            $critique = $this->specCompiler->critique(['spec' => $spec]);
        } catch (Throwable $e) {
            return ['http_status' => 500, 'payload' => $this->blockResponse($project, $item, [
                'spec_compiler_failed',
            ], $e->getMessage())];
        }

        $scopeBlockers = $this->specScopeBlockers($spec);
        $critiqueBlockers = collect((array) ($critique['blocking_issues'] ?? []))
            ->map(fn (mixed $issue): string => is_array($issue)
                ? (string) (($issue['field'] ?? 'spec').':'.($issue['reason'] ?? 'invalid'))
                : 'spec_critic_blocked')
            ->values()
            ->all();
        $blockers = array_values(array_unique(array_merge($scopeBlockers, $critiqueBlockers)));
        if ($blockers !== []) {
            return ['http_status' => 422, 'payload' => $this->blockResponse($project, $item, $blockers, 'spec_or_context_insufficient', [
                'critique' => $critique,
                'spec' => $spec,
            ])];
        }

        if ($item->spec_hash === null) {
            $this->governance->attachSpec($item, $spec);
            $item = $item->refresh();
        } else {
            $spec = (array) $item->spec_json;
        }

        $contextPack = $this->contextPackFor($project, $item, $spec, $data);
        $plan = $this->planCompiler->compile($spec, $contextPack);
        $compiledTasks = $this->taskCompiler->compile($plan);
        $tasks = $this->taskContractsFromCompiledTasks($compiledTasks, $plan, $spec, $item, $data);

        if ($tasks === []) {
            return ['http_status' => 422, 'payload' => $this->blockResponse($project, $item, [
                'plan_compiler_produced_no_tasks',
            ], 'plan_compiler_produced_no_tasks', [
                'plan' => $plan,
            ])];
        }

        $plan['context_pack'] = $contextPack->toArray();
        $plan['source_authority'] = 'ProgrammingSpecCompiler + SDD PlanCompiler + SDD TaskCompiler';

        $this->governance->attachPlan($item, $plan, $tasks);
        $item = $this->clearGap($item->refresh(), 'plan_autogeneration');

        return ['http_status' => 201, 'payload' => $this->specPlanPayload(
            $project,
            $this->governance,
            $item,
            status: 'planned',
            compiled: true,
            contextPack: $contextPack->toArray(),
            critique: $critique,
        )];
    }


    private function workItemBelongsToProject(AtlasProject $project, AtlasProgrammingWorkItem $workItem): bool
    {
        $projectId = (string) $project->getKey();
        $projectMetadata = is_array($project->metadata) ? $project->metadata : [];
        if ((string) data_get($projectMetadata, 'programming_work_item_id', '') === (string) $workItem->id
            || (string) data_get($projectMetadata, 'programming_work_item_code', '') === (string) $workItem->code
        ) {
            return true;
        }

        $itemMetadata = is_array($workItem->metadata_json) ? $workItem->metadata_json : [];
        if ((string) data_get($itemMetadata, 'obra_id', '') === $projectId
            || (string) data_get($itemMetadata, 'atlas_project_id', '') === $projectId
        ) {
            return true;
        }

        $projectWorkspace = trim((string) data_get($projectMetadata, 'workspace_path', ''));

        return $projectWorkspace !== '' && trim((string) ($workItem->workspace ?? '')) === $projectWorkspace;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */

    private function specForWorkItem(
        AtlasProgrammingWorkItem $workItem,
        ProgrammingSpecCompiler $compiler,
        array $data,
    ): array {
        if ($workItem->spec_hash !== null && (array) $workItem->spec_json !== []) {
            return (array) $workItem->spec_json;
        }

        $compiled = $compiler->compile($workItem);
        $spec = (array) ($compiled['spec'] ?? []);

        foreach ([
            'likely_files' => 'likely_files',
            'validation_commands' => 'tests',
            'acceptance_criteria' => 'completion_criteria',
            'evidence_required' => 'evidence_required',
        ] as $inputKey => $specKey) {
            $value = AiStringListNormalizer::trimmedStringsFromArrayCast($data[$inputKey] ?? []);
            if ($value !== []) {
                $spec[$specKey] = $value;
            }
        }

        return $spec;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return list<string>
     */

    private function specScopeBlockers(array $spec): array
    {
        $likely = AiStringListNormalizer::trimmedStringsFromArrayCast($spec['likely_files'] ?? []);
        if ($likely === []) {
            return ['likely_files_required'];
        }

        $unknown = collect($likely)
            ->contains(fn (string $file): bool => str_contains(strtolower($file), 'unknown') || str_contains($file, 'fill in'));
        if ($unknown) {
            return ['likely_files_must_be_explicit'];
        }

        $tests = AiStringListNormalizer::trimmedStringsFromArrayCast($spec['tests'] ?? []);
        if ($tests === []) {
            return ['validation_commands_required'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $data
     */

    private function contextPackFor(AtlasProject $project, AtlasProgrammingWorkItem $workItem, array $spec, array $data): ContextPack
    {
        $packages = array_values(array_unique(array_merge([
            'atlas.code.forge.v1',
            'atlas.programming.governance.v1',
            'atlas.programming.sdd_compilers.v1',
            $workItem->risk_level === 'high' || $workItem->risk_level === 'critical'
                ? 'atlas.risk.high.v1'
                : 'atlas.risk.standard.v1',
        ], AiStringListNormalizer::trimmedStringsFromArrayCast($data['context_packages'] ?? []))));

        $payload = [
            'schema_version' => 'atlas.code.programming_spec_plan_context.v1',
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'spec_hash' => $workItem->spec_hash,
            'likely_files' => AiStringListNormalizer::trimmedStringsFromArrayCast($spec['likely_files'] ?? []),
            'validation_commands' => AiStringListNormalizer::trimmedStringsFromArrayCast($spec['tests'] ?? []),
            'source_authority' => 'ProgrammingWorkItemSpecPlanService::compile',
        ];
        $digest = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return new ContextPack(
            stack: (string) ($data['context_stack'] ?? 'atlas-code-forge'),
            packages: $packages,
            payload: $payload,
            digest: $digest,
        );
    }

    /**
     * @param  list<array<string,mixed>>  $compiledTasks
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */

    private function taskContractsFromCompiledTasks(
        array $compiledTasks,
        array $plan,
        array $spec,
        AtlasProgrammingWorkItem $workItem,
        array $data,
    ): array {
        $validationCommands = AiStringListNormalizer::trimmedStringsFromArrayCast($data['validation_commands'] ?? []);
        if ($validationCommands === []) {
            $validationCommands = collect((array) ($plan['test_plan'] ?? []))
                ->map(fn (mixed $entry): ?string => is_array($entry) && is_string($entry['command'] ?? null)
                    ? (string) $entry['command']
                    : null)
                ->filter()
                ->values()
                ->all();
        }

        $acceptance = AiStringListNormalizer::trimmedStringsFromArrayCast($data['acceptance_criteria'] ?? []);
        if ($acceptance === []) {
            $acceptance = AiStringListNormalizer::trimmedStringsFromArrayCast($spec['completion_criteria'] ?? []);
        }
        $evidenceRequired = AiStringListNormalizer::trimmedStringsFromArrayCast($data['evidence_required'] ?? []);
        if ($evidenceRequired === []) {
            $evidenceRequired = AiStringListNormalizer::trimmedStringsFromArrayCast($spec['evidence_required'] ?? []);
        }

        $docsRequired = collect(AiStringListNormalizer::trimmedStringsFromArrayCast($spec['likely_files'] ?? []))
            ->filter(fn (string $file): bool => str_starts_with($file, 'docs/'))
            ->values()
            ->all();

        return collect($compiledTasks)
            ->map(function (array $task, int $index) use ($validationCommands, $acceptance, $evidenceRequired, $docsRequired, $spec, $workItem): array {
                $allowed = AiStringListNormalizer::trimmedStringsFromArrayCast($task['allowed_files'] ?? []);
                $isTestTask = (string) ($task['type'] ?? '') === 'test';

                return [
                    'task_id' => (string) ($task['code'] ?? sprintf('%s-task-%02d', (string) $workItem->code, $index + 1)),
                    'title' => (string) ($task['title'] ?? $spec['objective'] ?? 'Forge task'),
                    'objective' => (string) ($spec['objective'] ?? $workItem->intent_text),
                    'owner' => $task['type'] ?? $workItem->owner ?? 'atlas-code',
                    'allowed_files' => $allowed,
                    'forbidden_files' => AiStringListNormalizer::trimmedStringsFromArrayCast($task['forbidden_files'] ?? []),
                    'expected_files' => $allowed,
                    'dependencies' => AiStringListNormalizer::trimmedStringsFromArrayCast($task['depends_on'] ?? []),
                    'risk_level' => (string) $workItem->risk_level,
                    'validation_commands' => $isTestTask
                        ? AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($task, 'metadata.commands', []))
                        : $validationCommands,
                    'acceptance_criteria' => $acceptance,
                    'rollback' => (string) ($spec['rollback'] ?? data_get($plan, 'rollback_plan.description', 'Revert and re-run validation.')),
                    'evidence_required' => $evidenceRequired,
                    'docs_required' => $docsRequired,
                    'cartography_required' => $allowed !== [] && ! collect($allowed)->every(fn (string $file): bool => str_starts_with($file, 'docs/')),
                    'source_authority' => 'atlas.sdd_task_compiler.v1',
                    'order_index' => (int) ($task['order_index'] ?? $index + 1),
                ];
            })
            ->values()
            ->all();
    }


    private function clearGap(AtlasProgrammingWorkItem $workItem, string $gapName): AtlasProgrammingWorkItem
    {
        $gaps = collect((array) $workItem->gaps_json)
            ->reject(fn (mixed $gap): bool => is_array($gap) && ($gap['name'] ?? null) === $gapName)
            ->values()
            ->all();

        $workItem->forceFill(['gaps_json' => $gaps])->save();

        return $workItem->refresh();
    }


    private function blockResponse(
        AtlasProject $project,
        ?AtlasProgrammingWorkItem $workItem,
        array $blockers,
        string $reason,
        array $extra = [],
    ): array {
        return array_merge([
            'schema_version' => 'atlas.code.programming_work_item_spec_binding_response.v1',
            'work_id' => (string) $project->getKey(),
            'obra_id' => (string) $project->getKey(),
            'status' => 'blocked',
            'compiled' => false,
            'work_item_id' => $workItem ? (string) $workItem->id : null,
            'work_item_code' => $workItem ? (string) $workItem->code : null,
            'spec_hash' => $workItem?->spec_hash,
            'plan_hash' => $workItem?->plan_hash,
            'tasks_count' => $workItem ? count((array) $workItem->tasks_json) : 0,
            'gate_requirements' => [],
            'blockers' => $blockers,
            'reason' => $reason,
            'next_action' => 'fix_spec_context_then_retry',
            'evidence_refs' => [],
        ], $extra);
    }

    /**
     * @param  array<string,mixed>|null  $contextPack
     * @param  array<string,mixed>|null  $critique
     * @return array<string,mixed>
     */

    private function specPlanPayload(
        AtlasProject $project,
        ProgrammingGovernanceService $governance,
        AtlasProgrammingWorkItem $workItem,
        string $status,
        bool $compiled,
        ?array $contextPack = null,
        ?array $critique = null,
    ): array {
        $snapshot = $this->governance->snapshot($workItem);

        return [
            'schema_version' => 'atlas.code.programming_work_item_spec_binding_response.v1',
            'work_id' => (string) $project->getKey(),
            'obra_id' => (string) $project->getKey(),
            'status' => $status,
            'compiled' => $compiled,
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'spec_hash' => $workItem->spec_hash,
            'plan_hash' => $workItem->plan_hash,
            'tasks_count' => count((array) $workItem->tasks_json),
            'gate_requirements' => $snapshot['required_gates'],
            'blockers' => [],
            'next_action' => 'run_forge_live_execution',
            'context_pack' => $contextPack ?? data_get($workItem->plan_json, 'context_pack'),
            'critique' => $critique,
            'evidence_refs' => $snapshot['evidence_refs'],
            'programming_governance' => [
                'schema_version' => 'atlas.code.programming_governance_snapshot.v1',
                'source_authority' => 'atlas_programming_work_items',
                'work_item' => $snapshot,
                'spec' => $snapshot['spec'] === [] ? null : $snapshot['spec'],
                'plan' => $snapshot['plan'] === [] ? null : $snapshot['plan'],
                'tasks' => $snapshot['tasks'],
                'gate_runs' => [],
                'reviews' => [],
                'evidence_refs' => $snapshot['evidence_refs'],
                'artifacts' => [],
                'degraded' => false,
                'degraded_reason' => null,
            ],
        ];
    }
}
