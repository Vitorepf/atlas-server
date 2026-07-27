<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
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
}
