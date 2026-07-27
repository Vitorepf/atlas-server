<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Pure programming work-item contract rules.
 *
 * Both ProgrammingWorkItemSpecPlanService and AtlasCodeProgrammingWorkItemController
 * carried byte-identical private copies of these two methods; the controller did
 * not call the service at all. Neither body touches $this, so the rules have a
 * single owner here and both callers delegate.
 */
final class ProgrammingWorkItemContractSupport
{
    /**
     * @param  list<array<string,mixed>>  $compiledTasks
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */
    public static function taskContractsFromCompiledTasks(
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

    public static function workItemBelongsToProject(AtlasProject $project, AtlasProgrammingWorkItem $workItem): bool
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

    public static function blockResponse(
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

    public static function existingWorkItem(AtlasProject $project): ?AtlasProgrammingWorkItem
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

    public static function intentFor(AtlasProject $project, ?string $explicit): string
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

    public static function rememberBinding(AtlasProject $project, AtlasProgrammingWorkItem $workItem): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $metadata['programming_work_item_id'] = (string) $workItem->id;
        $metadata['programming_work_item_code'] = (string) $workItem->code;
        $metadata['latest_programming_work_item_bound_at'] = now()->toJSON();

        $project->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function specForWorkItem(
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
    public static function specScopeBlockers(array $spec): array
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
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public static function workItemMetadata(AtlasProject $project, array $metadata): array
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
}
