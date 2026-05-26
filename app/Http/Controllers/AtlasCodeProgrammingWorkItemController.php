<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\PlanCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\TaskCompiler;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Atlas Code -> Programming Governance WorkItem binding.
 *
 * A professional Forge cockpit cannot depend on a side-channel to create the
 * governed WorkItem. This endpoint creates one from the selected Obra and
 * stores the binding in both records.
 */
final class AtlasCodeProgrammingWorkItemController extends Controller
{
    public function store(
        Request $request,
        AtlasProject $project,
        ProgrammingGovernanceService $governance,
    ): JsonResponse {
        $data = $request->validate([
            'intent' => ['nullable', 'string', 'max:2000'],
            'owner' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'max:40'],
            'mode' => ['nullable', Rule::in(['compact', 'structural'])],
            'risk' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
        ]);

        $existing = $this->existingWorkItem($project);
        if ($existing) {
            return response()->json($this->responsePayload($project, $governance, $existing, created: false));
        }

        $intent = $this->intentFor($project, is_string($data['intent'] ?? null) ? $data['intent'] : null);
        if ($intent === '') {
            return response()->json([
                'schema_version' => 'atlas.code.programming_work_item_binding_response.v1',
                'work_id' => (string) $project->getKey(),
                'created' => false,
                'status' => 'blocked',
                'error' => 'programming_intent_required',
            ], 422);
        }

        $workspace = trim((string) data_get($project->metadata, 'workspace_path', ''));
        $snapshot = $governance->intake($intent, array_filter([
            'owner' => $data['owner'] ?? 'atlas-code',
            'type' => $data['type'] ?? null,
            'mode' => $data['mode'] ?? null,
            'risk' => $data['risk'] ?? null,
            'workspace' => $workspace !== '' ? $workspace : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $workItem = AtlasProgrammingWorkItem::query()->findOrFail((string) $snapshot['id']);
        $workItem->forceFill([
            'metadata_json' => $this->workItemMetadata($project, (array) $workItem->metadata_json),
        ])->save();

        $this->rememberBinding($project, $workItem);

        return response()->json($this->responsePayload($project->refresh(), $governance, $workItem->refresh(), created: true), 201);
    }

    public function compileSpecPlan(
        Request $request,
        AtlasProject $project,
        string $workItem,
        ProgrammingGovernanceService $governance,
        ProgrammingSpecCompiler $specCompiler,
        PlanCompiler $planCompiler,
        TaskCompiler $taskCompiler,
    ): JsonResponse {
        $data = $request->validate([
            'likely_files' => ['nullable', 'array', 'min:1', 'max:80'],
            'likely_files.*' => ['string', 'max:500'],
            'validation_commands' => ['nullable', 'array', 'min:1', 'max:20'],
            'validation_commands.*' => ['string', 'max:500'],
            'acceptance_criteria' => ['nullable', 'array', 'min:1', 'max:30'],
            'acceptance_criteria.*' => ['string', 'max:500'],
            'evidence_required' => ['nullable', 'array', 'min:1', 'max:20'],
            'evidence_required.*' => ['string', 'max:120'],
            'context_stack' => ['nullable', 'string', 'max:80'],
            'context_packages' => ['nullable', 'array', 'max:20'],
            'context_packages.*' => ['string', 'max:120'],
        ]);

        try {
            $item = $governance->find($workItem);
        } catch (\RuntimeException) {
            return response()->json($this->blockResponse($project, null, [
                'work_item_not_found',
            ], 'work_item_not_found'), 404);
        }

        if (! $this->workItemBelongsToProject($project, $item)) {
            return response()->json($this->blockResponse($project, $item, [
                'work_item_not_bound_to_obra',
            ], 'work_item_not_bound_to_obra'), 403);
        }

        if ($item->spec_hash !== null && $item->plan_hash !== null && (array) $item->tasks_json !== []) {
            return response()->json($this->specPlanPayload(
                $project,
                $governance,
                $item->refresh(),
                status: 'already_planned',
                compiled: false,
            ));
        }

        try {
            $spec = $this->specForWorkItem($item, $specCompiler, $data);
            $critique = $specCompiler->critique(['spec' => $spec]);
        } catch (Throwable $e) {
            return response()->json($this->blockResponse($project, $item, [
                'spec_compiler_failed',
            ], $e->getMessage()), 500);
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
            return response()->json($this->blockResponse($project, $item, $blockers, 'spec_or_context_insufficient', [
                'critique' => $critique,
                'spec' => $spec,
            ]), 422);
        }

        if ($item->spec_hash === null) {
            $governance->attachSpec($item, $spec);
            $item = $item->refresh();
        } else {
            $spec = (array) $item->spec_json;
        }

        $contextPack = $this->contextPackFor($project, $item, $spec, $data);
        $plan = $planCompiler->compile($spec, $contextPack);
        $compiledTasks = $taskCompiler->compile($plan);
        $tasks = $this->taskContractsFromCompiledTasks($compiledTasks, $plan, $spec, $item, $data);

        if ($tasks === []) {
            return response()->json($this->blockResponse($project, $item, [
                'plan_compiler_produced_no_tasks',
            ], 'plan_compiler_produced_no_tasks', [
                'plan' => $plan,
            ]), 422);
        }

        $plan['context_pack'] = $contextPack->toArray();
        $plan['source_authority'] = 'ProgrammingSpecCompiler + SDD PlanCompiler + SDD TaskCompiler';

        $governance->attachPlan($item, $plan, $tasks);
        $item = $this->clearGap($item->refresh(), 'plan_autogeneration');

        return response()->json($this->specPlanPayload(
            $project,
            $governance,
            $item,
            status: 'planned',
            compiled: true,
            contextPack: $contextPack->toArray(),
            critique: $critique,
        ), 201);
    }

    private function existingWorkItem(AtlasProject $project): ?AtlasProgrammingWorkItem
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $id = (string) data_get($metadata, 'programming_work_item_id', '');
        if ($id !== '') {
            $item = AtlasProgrammingWorkItem::query()->where('id', $id)->first();
            if ($item) {
                return $item;
            }
        }

        $code = (string) data_get($metadata, 'programming_work_item_code', '');
        if ($code !== '') {
            $item = AtlasProgrammingWorkItem::query()->where('code', $code)->first();
            if ($item) {
                return $item;
            }
        }

        $projectId = (string) $project->getKey();
        $workspace = trim((string) data_get($metadata, 'workspace_path', ''));

        return AtlasProgrammingWorkItem::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->first(function (AtlasProgrammingWorkItem $item) use ($projectId, $workspace): bool {
                $itemMetadata = is_array($item->metadata_json) ? $item->metadata_json : [];
                if ((string) data_get($itemMetadata, 'obra_id', '') === $projectId
                    || (string) data_get($itemMetadata, 'atlas_project_id', '') === $projectId
                ) {
                    return true;
                }

                return $workspace !== '' && trim((string) ($item->workspace ?? '')) === $workspace;
            });
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
            $value = $this->stringList($data[$inputKey] ?? []);
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
        $likely = $this->stringList($spec['likely_files'] ?? []);
        if ($likely === []) {
            return ['likely_files_required'];
        }

        $unknown = collect($likely)
            ->contains(fn (string $file): bool => str_contains(strtolower($file), 'unknown') || str_contains($file, 'fill in'));
        if ($unknown) {
            return ['likely_files_must_be_explicit'];
        }

        $tests = $this->stringList($spec['tests'] ?? []);
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
        ], $this->stringList($data['context_packages'] ?? []))));

        $payload = [
            'schema_version' => 'atlas.code.programming_spec_plan_context.v1',
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'spec_hash' => $workItem->spec_hash,
            'likely_files' => $this->stringList($spec['likely_files'] ?? []),
            'validation_commands' => $this->stringList($spec['tests'] ?? []),
            'source_authority' => 'AtlasCodeProgrammingWorkItemController::compileSpecPlan',
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
        $validationCommands = $this->stringList($data['validation_commands'] ?? []);
        if ($validationCommands === []) {
            $validationCommands = collect((array) ($plan['test_plan'] ?? []))
                ->map(fn (mixed $entry): ?string => is_array($entry) && is_string($entry['command'] ?? null)
                    ? (string) $entry['command']
                    : null)
                ->filter()
                ->values()
                ->all();
        }

        $acceptance = $this->stringList($data['acceptance_criteria'] ?? []);
        if ($acceptance === []) {
            $acceptance = $this->stringList($spec['completion_criteria'] ?? []);
        }
        $evidenceRequired = $this->stringList($data['evidence_required'] ?? []);
        if ($evidenceRequired === []) {
            $evidenceRequired = $this->stringList($spec['evidence_required'] ?? []);
        }

        $docsRequired = collect($this->stringList($spec['likely_files'] ?? []))
            ->filter(fn (string $file): bool => str_starts_with($file, 'docs/'))
            ->values()
            ->all();

        return collect($compiledTasks)
            ->map(function (array $task, int $index) use ($validationCommands, $acceptance, $evidenceRequired, $docsRequired, $spec, $workItem): array {
                $allowed = $this->stringList($task['allowed_files'] ?? []);
                $isTestTask = (string) ($task['type'] ?? '') === 'test';

                return [
                    'task_id' => (string) ($task['code'] ?? sprintf('%s-task-%02d', (string) $workItem->code, $index + 1)),
                    'title' => (string) ($task['title'] ?? $spec['objective'] ?? 'Forge task'),
                    'objective' => (string) ($spec['objective'] ?? $workItem->intent_text),
                    'owner' => $task['type'] ?? $workItem->owner ?? 'atlas-code',
                    'allowed_files' => $allowed,
                    'forbidden_files' => $this->stringList($task['forbidden_files'] ?? []),
                    'expected_files' => $allowed,
                    'dependencies' => $this->stringList($task['depends_on'] ?? []),
                    'risk_level' => (string) $workItem->risk_level,
                    'validation_commands' => $isTestTask
                        ? $this->stringList(data_get($task, 'metadata.commands', []))
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

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', (array) $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function intentFor(AtlasProject $project, ?string $explicit): string
    {
        foreach ([
            $explicit,
            $project->goal,
            $project->desired_outcome,
            $project->description,
            $project->title,
        ] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function workItemMetadata(AtlasProject $project, array $metadata): array
    {
        return array_merge($metadata, [
            'obra_id' => (string) $project->getKey(),
            'atlas_project_id' => (string) $project->getKey(),
            'atlas_code_binding' => [
                'schema_version' => 'atlas.code.programming_work_item_binding.v1',
                'surface_id' => 'atlas_code',
                'flow_id' => 'programming.forge',
                'source_authority' => 'AtlasProject.metadata.programming_work_item_id',
                'bound_at' => now()->toJSON(),
            ],
        ]);
    }

    private function rememberBinding(AtlasProject $project, AtlasProgrammingWorkItem $workItem): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $metadata['programming_work_item_id'] = (string) $workItem->id;
        $metadata['programming_work_item_code'] = (string) $workItem->code;
        $metadata['latest_programming_work_item_bound_at'] = now()->toJSON();

        $project->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @return array<string,mixed>
     */
    private function responsePayload(
        AtlasProject $project,
        ProgrammingGovernanceService $governance,
        AtlasProgrammingWorkItem $workItem,
        bool $created,
    ): array {
        $snapshot = $governance->snapshot($workItem);

        return [
            'schema_version' => 'atlas.code.programming_work_item_binding_response.v1',
            'work_id' => (string) $project->getKey(),
            'created' => $created,
            'status' => 'bound',
            'binding' => [
                'schema_version' => 'atlas.code.programming_work_item_binding.v1',
                'surface_id' => 'atlas_code',
                'flow_id' => 'programming.forge',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'source_authority' => 'AtlasProject.metadata.programming_work_item_id',
            ],
            'work_item' => $snapshot,
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

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
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
        $snapshot = $governance->snapshot($workItem);

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
