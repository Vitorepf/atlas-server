<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringProjectBlueprint;
use App\Models\AtlasTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngineeringTaskGenerationService
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function generate(AtlasEngineeringProjectBlueprint $blueprint, array $options = []): array
    {
        if ($blueprint->status !== 'frozen') {
            throw ValidationException::withMessages(['blueprint' => 'Tasks so podem ser geradas a partir de blueprint congelado.']);
        }

        $payload = is_array($blueprint->blueprint_json) ? $blueprint->blueprint_json : [];
        $tasks = $this->tasksFromBlueprint($payload);
        $created = [];
        $skipped = [];
        $updated = [];

        DB::transaction(function () use ($blueprint, $payload, $tasks, $options, &$created, &$skipped, &$updated): void {
            foreach ($tasks as $definition) {
                $sourceKey = $this->sourceKey($blueprint, $definition);
                $existing = AtlasTask::query()
                    ->where('project_id', $blueprint->project_id)
                    ->where('metadata->engineering_generation->source_key', $sourceKey)
                    ->first();

                if ($existing && ! (bool) ($options['force'] ?? false)) {
                    $skipped[] = [
                        'task_id' => $existing->id,
                        'title' => $existing->title,
                        'reason' => 'existing_generated_task',
                    ];

                    continue;
                }

                if ($existing && data_get($existing->metadata, 'engineering_generation.source') !== 'project_blueprint') {
                    $skipped[] = [
                        'task_id' => $existing->id,
                        'title' => $existing->title,
                        'reason' => 'human_task_not_overwritten',
                    ];

                    continue;
                }

                $data = $this->taskAttributes($blueprint, $payload, $definition, $sourceKey);
                if ($existing) {
                    $existing->forceFill($data)->save();
                    $updated[] = $this->taskPayload($existing->refresh());

                    continue;
                }

                $created[] = $this->taskPayload(AtlasTask::query()->create($data));
            }
        });

        return [
            'project_id' => $blueprint->project_id,
            'project_blueprint_id' => $blueprint->id,
            'project_blueprint_version' => $blueprint->version,
            'created_count' => count($created),
            'updated_count' => count($updated),
            'skipped_count' => count($skipped),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @return array<int,array<string,mixed>>
     */
    private function tasksFromBlueprint(array $blueprint): array
    {
        return collect((array) ($blueprint['phase_plan'] ?? []))
            ->filter(fn (mixed $phase): bool => is_array($phase))
            ->flatMap(function (array $phase): array {
                return collect((array) ($phase['tasks'] ?? []))
                    ->filter(fn (mixed $task): bool => is_array($task))
                    ->map(fn (array $task): array => [
                        ...$task,
                        'phase_id' => $phase['id'] ?? null,
                        'phase_title' => $phase['title'] ?? null,
                        'phase_order' => $phase['order'] ?? null,
                    ])
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $blueprintPayload
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function taskAttributes(
        AtlasEngineeringProjectBlueprint $blueprint,
        array $blueprintPayload,
        array $definition,
        string $sourceKey,
    ): array {
        $contract = $this->contract($blueprint, $blueprintPayload, $definition);
        $metadata = [
            'engineering_contract' => $contract,
            'engineering_generation' => [
                'source' => 'project_blueprint',
                'source_key' => $sourceKey,
                'project_blueprint_id' => $blueprint->id,
                'project_blueprint_version' => $blueprint->version,
                'project_blueprint_hash' => $blueprint->content_hash,
                'phase_id' => $definition['phase_id'] ?? null,
                'generated_at' => now()->toJSON(),
            ],
        ];

        return [
            'title' => (string) ($definition['title'] ?? $definition['goal'] ?? 'Engineering task'),
            'description' => (string) ($definition['goal'] ?? $definition['title'] ?? ''),
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'project_id' => $blueprint->project_id,
            'project_step_id' => $definition['project_step_id'] ?? null,
            'estimated_minutes' => (int) ($definition['estimated_minutes'] ?? 60),
            'energy_required' => 'high',
            'execution_mode' => 'deep_work',
            'starter_step' => (string) ($definition['goal'] ?? $definition['title'] ?? ''),
            'minimum_viable_action' => collect((array) ($definition['acceptance_criteria'] ?? []))
                ->map(fn (mixed $criterion): string => is_array($criterion)
                    ? (string) ($criterion['statement'] ?? '')
                    : (string) $criterion)
                ->filter()
                ->first(),
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string,mixed>  $blueprintPayload
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function contract(AtlasEngineeringProjectBlueprint $blueprint, array $blueprintPayload, array $definition): array
    {
        $acceptanceCriteria = collect((array) ($definition['acceptance_criteria'] ?? []))
            ->map(fn (mixed $criterion, int $index): array => is_array($criterion) ? [
                'id' => (string) ($criterion['id'] ?? 'ac_'.($index + 1)),
                'statement' => (string) ($criterion['statement'] ?? $criterion['criterion'] ?? ''),
                'verification_method' => (string) ($criterion['verification_method'] ?? 'test'),
            ] : [
                'id' => 'ac_'.($index + 1),
                'statement' => (string) $criterion,
                'verification_method' => 'test',
            ])
            ->filter(fn (array $criterion): bool => trim($criterion['statement']) !== '')
            ->values()
            ->all();

        return [
            'contract_version' => 'atlas.engineering.task_contract.v1',
            'source' => 'project_blueprint',
            'type' => (string) ($definition['type'] ?? 'feature'),
            'goal' => (string) ($definition['goal'] ?? $definition['title'] ?? ''),
            'context' => array_values((array) ($definition['context'] ?? [])),
            'in_scope' => array_values((array) ($definition['in_scope'] ?? [])),
            'out_of_scope' => array_values((array) ($definition['out_of_scope'] ?? ['Escopo fora da fase sem novo blueprint.'])),
            'acceptance_criteria' => $acceptanceCriteria,
            'likely_files' => array_values((array) ($definition['likely_files'] ?? [])),
            'allowed_paths' => array_values((array) ($definition['allowed_paths'] ?? [])),
            'strict_file_scope' => (bool) ($definition['strict_file_scope'] ?? false),
            'patterns_to_follow' => array_values((array) ($definition['patterns_to_follow'] ?? [])),
            'patterns_to_avoid' => array_values((array) ($definition['patterns_to_avoid'] ?? [])),
            'edge_cases' => array_values((array) ($definition['edge_cases'] ?? [])),
            'dependencies' => [
                'blocked_by' => array_values((array) ($definition['blocked_by'] ?? [])),
                'blocks' => array_values((array) ($definition['blocks'] ?? [])),
            ],
            'test_coverage' => array_values((array) ($definition['test_coverage'] ?? [])),
            'definition_of_done' => array_values((array) ($definition['definition_of_done'] ?? [])),
            'refs' => [
                'project_blueprint_id' => $blueprint->id,
                'project_blueprint_version' => $blueprint->version,
                'project_blueprint_hash' => $blueprint->content_hash,
                'scenario_refs' => array_values((array) ($definition['scenario_refs'] ?? [])),
                'phase_id' => $definition['phase_id'] ?? null,
                'knowledge_refs' => [
                    'atlas-engineering-blueprint',
                    'atlas-engineering-blueprint-contracts',
                    'atlas-engineering-blueprint-quality-gates',
                ],
                'code_refs' => data_get($blueprintPayload, 'technical_context.repositories', []),
            ],
        ];
    }

    private function sourceKey(AtlasEngineeringProjectBlueprint $blueprint, array $definition): string
    {
        return hash('sha256', implode(':', [
            $blueprint->id,
            $definition['phase_id'] ?? '',
            $definition['id'] ?? '',
            $definition['title'] ?? '',
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(AtlasTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'project_id' => $task->project_id,
            'project_step_id' => $task->project_step_id,
            'status' => $task->status,
            'engineering_contract' => data_get($task->metadata, 'engineering_contract'),
            'engineering_generation' => data_get($task->metadata, 'engineering_generation'),
        ];
    }
}
