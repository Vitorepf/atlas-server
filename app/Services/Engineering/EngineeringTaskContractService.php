<?php

namespace App\Services\Engineering;

use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;

class EngineeringTaskContractService
{
    /**
     * @return array<string,mixed>
     */
    public function forTask(AtlasTask $task): array
    {
        [$project, $step] = $this->loadedContext($task);
        $metadata = $this->arrayValue($task->metadata);
        $existing = $this->arrayValue(data_get($metadata, 'engineering_contract', []));

        $acceptanceCriteria = $this->mergeLists(
            $this->listValue($existing['acceptance_criteria'] ?? []),
            $this->listValue($metadata['acceptance_criteria'] ?? []),
            $this->listValue($metadata['criteria'] ?? []),
            $this->listValue($step?->getAttribute('acceptance_criteria')),
            $this->listValue($project?->getAttribute('definition_of_done')),
        );

        return [
            'contract_version' => max(1, (int) ($existing['contract_version'] ?? 1)),
            'source' => $this->stringValue($existing['source'] ?? null) ?: 'atlas_task',
            'type' => $this->stringValue($existing['type'] ?? null) ?: $this->inferType($task, $project, $step),
            'goal' => $this->stringValue($existing['goal'] ?? null) ?: $this->goal($task, $project, $step),
            'context' => $this->mergeLists(
                $this->listValue($existing['context'] ?? []),
                $this->context($task, $project, $step),
            ),
            'in_scope' => $this->mergeLists(
                $this->listValue($existing['in_scope'] ?? []),
                $this->inScope($task, $step),
            ),
            'out_of_scope' => $this->mergeLists(
                $this->listValue($existing['out_of_scope'] ?? []),
                $this->listValue($metadata['out_of_scope'] ?? []),
            ),
            'acceptance_criteria' => $acceptanceCriteria,
            'likely_files' => $this->mergeLists(
                $this->listValue($existing['likely_files'] ?? []),
                $this->listValue($metadata['likely_files'] ?? []),
                $this->listValue($metadata['files'] ?? []),
            ),
            'patterns_to_follow' => $this->mergeLists(
                $this->listValue($existing['patterns_to_follow'] ?? []),
                $this->listValue($metadata['patterns_to_follow'] ?? []),
            ),
            'patterns_to_avoid' => $this->mergeLists(
                $this->listValue($existing['patterns_to_avoid'] ?? []),
                $this->listValue($metadata['patterns_to_avoid'] ?? []),
            ),
            'edge_cases' => $this->mergeLists(
                $this->listValue($existing['edge_cases'] ?? []),
                $this->listValue($metadata['edge_cases'] ?? []),
                $this->listValue($metadata['risks'] ?? []),
            ),
            'dependencies' => $this->dependencies($existing, $metadata),
            'test_coverage' => $this->testCoverage($existing, $metadata, $acceptanceCriteria),
            'estimated_size' => $this->estimatedSize($task, $existing),
            'definition_of_done' => $this->definitionOfDone($existing, $metadata, $acceptanceCriteria, $project),
            'refs' => $this->taskSummary($task),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function taskSummary(AtlasTask $task): array
    {
        return array_filter([
            'task_id' => $task->getAttribute('id'),
            'title' => $task->getAttribute('title'),
            'status' => $task->getAttribute('status'),
            'priority' => $task->getAttribute('priority'),
            'domain' => $task->getAttribute('domain'),
            'project_id' => $task->getAttribute('project_id'),
            'project_step_id' => $task->getAttribute('project_step_id'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    public function defaultPrompt(AtlasTask $task, array $contract): string
    {
        $goal = $this->stringValue($contract['goal'] ?? null) ?: (string) $task->getAttribute('title');

        return trim('Executar contrato tecnico Atlas: '.$goal);
    }

    /**
     * @return array{0:?AtlasProject,1:?AtlasProjectStep}
     */
    private function loadedContext(AtlasTask $task): array
    {
        if ($task->exists) {
            $task->loadMissing(['project', 'projectStep']);
        }

        $project = $task->relationLoaded('project') ? $task->getRelation('project') : null;
        $step = $task->relationLoaded('projectStep') ? $task->getRelation('projectStep') : null;

        return [
            $project instanceof AtlasProject ? $project : null,
            $step instanceof AtlasProjectStep ? $step : null,
        ];
    }

    private function goal(AtlasTask $task, ?AtlasProject $project, ?AtlasProjectStep $step): string
    {
        return $this->firstString([
            $task->getAttribute('title'),
            $step?->getAttribute('expected_output'),
            $project?->getAttribute('goal'),
        ]) ?: 'Executar tarefa tecnica Atlas.';
    }

    /**
     * @return array<int,string>
     */
    private function context(AtlasTask $task, ?AtlasProject $project, ?AtlasProjectStep $step): array
    {
        return $this->mergeLists(
            $this->listValue($project?->getAttribute('title') ? 'Projeto: '.$project->getAttribute('title') : null),
            $this->listValue($project?->getAttribute('goal') ? 'Meta do projeto: '.$project->getAttribute('goal') : null),
            $this->listValue($project?->getAttribute('desired_outcome') ? 'Outcome desejado: '.$project->getAttribute('desired_outcome') : null),
            $this->listValue($project?->getAttribute('minimum_viable_outcome') ? 'Outcome minimo: '.$project->getAttribute('minimum_viable_outcome') : null),
            $this->listValue($step?->getAttribute('title') ? 'Etapa: '.$step->getAttribute('title') : null),
            $this->listValue($step?->getAttribute('expected_output') ? 'Saida esperada da etapa: '.$step->getAttribute('expected_output') : null),
            $this->listValue($task->getAttribute('description')),
        );
    }

    /**
     * @return array<int,string>
     */
    private function inScope(AtlasTask $task, ?AtlasProjectStep $step): array
    {
        return $this->mergeLists(
            $this->listValue($task->getAttribute('starter_step')),
            $this->listValue($task->getAttribute('minimum_viable_action')),
            $this->listValue($step?->getAttribute('expected_output')),
            $this->listValue($task->getAttribute('description')),
        );
    }

    private function inferType(AtlasTask $task, ?AtlasProject $project, ?AtlasProjectStep $step): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $task->getAttribute('title'),
            $task->getAttribute('description'),
            $project?->getAttribute('project_type'),
            $project?->getAttribute('description'),
            $step?->getAttribute('title'),
            $step?->getAttribute('description'),
        ], fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')));

        return match (true) {
            $this->containsAny($haystack, ['bug', 'erro', 'falha', 'corrigir', 'consertar', 'regress']) => 'bugfix',
            $this->containsAny($haystack, ['refator', 'cleanup', 'reorganizar']) => 'refactor',
            $this->containsAny($haystack, ['teste', 'test ', 'phpunit', 'pest']) => 'test',
            $this->containsAny($haystack, ['doc', 'readme', 'manual']) => 'docs',
            $this->containsAny($haystack, ['deploy', 'ci', 'docker', 'infra', 'migrat']) => 'infra',
            default => 'feature',
        };
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $metadata
     * @return array{blocked_by:array<int,string>,blocks:array<int,string>}
     */
    private function dependencies(array $existing, array $metadata): array
    {
        $existingDependencies = $this->arrayValue($existing['dependencies'] ?? []);
        $metadataDependencies = $this->arrayValue($metadata['dependencies'] ?? []);

        return [
            'blocked_by' => $this->mergeLists(
                $this->listValue($existingDependencies['blocked_by'] ?? []),
                $this->listValue($metadataDependencies['blocked_by'] ?? []),
                $this->listValue($metadata['blocked_by'] ?? []),
            ),
            'blocks' => $this->mergeLists(
                $this->listValue($existingDependencies['blocks'] ?? []),
                $this->listValue($metadataDependencies['blocks'] ?? []),
                $this->listValue($metadata['blocks'] ?? []),
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $metadata
     * @param  array<int,string>  $acceptanceCriteria
     * @return array<int,string>
     */
    private function testCoverage(array $existing, array $metadata, array $acceptanceCriteria): array
    {
        $explicit = $this->mergeLists(
            $this->listValue($existing['test_coverage'] ?? []),
            $this->listValue($metadata['test_coverage'] ?? []),
            $this->listValue($metadata['tests'] ?? []),
            $this->listValue($metadata['validation'] ?? []),
        );

        if ($explicit !== []) {
            return $explicit;
        }

        $default = ['Executar o comando de validacao mais proximo do workspace.'];
        if ($acceptanceCriteria !== []) {
            $default[] = 'Mapear no resumo quais criterios foram cobertos por teste, inspecao ou QA manual.';
        }

        return $default;
    }

    /**
     * @param  array<string,mixed>  $existing
     */
    private function estimatedSize(AtlasTask $task, array $existing): string
    {
        $explicit = strtoupper((string) ($existing['estimated_size'] ?? ''));
        if (in_array($explicit, ['XS', 'S', 'M', 'L', 'XL'], true)) {
            return $explicit;
        }

        $minutes = (int) ($task->getAttribute('estimated_minutes') ?: 25);

        return match (true) {
            $minutes <= 30 => 'XS',
            $minutes <= 90 => 'S',
            $minutes <= 240 => 'M',
            $minutes <= 360 => 'L',
            default => 'XL',
        };
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $metadata
     * @param  array<int,string>  $acceptanceCriteria
     * @return array<int,string>
     */
    private function definitionOfDone(array $existing, array $metadata, array $acceptanceCriteria, ?AtlasProject $project): array
    {
        return $this->mergeLists(
            $this->listValue($existing['definition_of_done'] ?? []),
            $this->listValue($metadata['definition_of_done'] ?? []),
            $this->listValue($project?->getAttribute('definition_of_done')),
            $acceptanceCriteria,
            [
                'Diff limitado ao escopo do contrato.',
                'Validacao registrada com comando, resultado e lacunas.',
                'Risco residual declarado antes de concluir.',
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    private function listValue(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $entry) {
                $items = array_merge($items, $this->listValue($entry));
            }

            return $items;
        }

        if (! is_scalar($value)) {
            return [];
        }

        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/\r?\n|;/', $text) ?: [$text];

        return collect($parts)
            ->map(fn (string $part): string => trim(preg_replace('/^\s*(?:[-*]|\d+[.)])\s*/', '', $part) ?? $part))
            ->filter(fn (string $part): bool => $part !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  ...$lists
     * @return array<int,string>
     */
    private function mergeLists(array ...$lists): array
    {
        $seen = [];
        $result = [];

        foreach ($lists as $list) {
            foreach ($list as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }

                $key = mb_strtolower($item);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return array_slice($result, 0, 40);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $value = $this->stringValue($value);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
