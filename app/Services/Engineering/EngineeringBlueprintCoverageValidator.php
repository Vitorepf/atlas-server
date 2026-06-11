<?php

namespace App\Services\Engineering;

class EngineeringBlueprintCoverageValidator
{
    /**
     * @param  array<string,mixed>  $blueprint
     * @return array<string,mixed>
     */
    public function validate(array $blueprint): array
    {
        $errors = [];
        $warnings = [];
        $missingEvidence = [];
        $blockingGates = [];

        $inventory = $this->array($blueprint['inventory'] ?? []);
        $scenarios = $this->arrayList($blueprint['scenarios'] ?? []);
        $phasePlan = $this->arrayList($blueprint['phase_plan'] ?? []);
        $scenarioAcceptanceRefs = collect($scenarios)
            ->flatMap(fn (array $scenario): array => EngineeringStringListNormalizer::nonEmptyScalarStrings($scenario['acceptance_refs'] ?? []))
            ->unique()
            ->values()
            ->all();

        if ($inventory === []) {
            $errors[] = $this->issue('inventory_missing', 'Project blueprint precisa declarar inventory verificavel.', 'inventory');
        }

        if ($scenarios === []) {
            $errors[] = $this->issue('scenarios_missing', 'Project blueprint precisa declarar pelo menos um scenario.', 'scenarios');
        }

        if ($phasePlan === []) {
            $errors[] = $this->issue('phase_plan_missing', 'Project blueprint precisa declarar phase_plan antes do freeze.', 'phase_plan');
        }

        foreach ($this->arrayList($inventory['screens'] ?? []) as $screen) {
            $id = $this->id($screen, 'screen');
            $states = EngineeringStringListNormalizer::nonEmptyScalarStrings($screen['states'] ?? []);
            $requirements = EngineeringStringListNormalizer::nonEmptyScalarStrings($screen['visual_requirements'] ?? []);
            if (! $this->containsAll($states, ['loading', 'ready', 'error'])) {
                $errors[] = $this->issue('screen_required_states_missing', "Tela {$id} precisa cobrir loading, ready e error.", 'inventory.screens');
            }
            if ($requirements !== [] && ! $this->hasScenarioType($scenarios, 'visual')) {
                $errors[] = $this->issue('visual_scenario_missing', "Tela {$id} declara requisito visual sem scenario visual.", 'scenarios');
            }
            if ($requirements !== []) {
                $missingEvidence[] = [
                    'gate' => 'manual_qa',
                    'target_id' => $id,
                    'reason' => 'UI visual exige QA manual ou evidencia visual.',
                ];
            }
        }

        foreach ($this->arrayList($inventory['api_surfaces'] ?? []) as $api) {
            $id = $this->id($api, 'api');
            if (EngineeringStringListNormalizer::nonEmptyScalarStrings($api['failure_modes'] ?? []) === []) {
                $errors[] = $this->issue('api_failure_modes_missing', "API {$id} precisa declarar failure path.", 'inventory.api_surfaces');
            }
        }

        foreach ($this->arrayList($inventory['data_entities'] ?? []) as $entity) {
            $text = mb_strtolower(json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            if ($this->containsAny($text, ['migration', 'schema', 'postgres', 'jsonb', 'index', 'constraint'])) {
                $missingEvidence[] = [
                    'gate' => 'database_review',
                    'target_id' => $this->id($entity, 'entity'),
                    'reason' => 'Entidade/schema exige Postgres review.',
                ];
            }
        }

        foreach ($scenarios as $scenario) {
            $id = $this->id($scenario, 'scenario');
            if (EngineeringStringListNormalizer::nonEmptyScalarStrings($scenario['acceptance_refs'] ?? []) === []) {
                $errors[] = $this->issue('scenario_acceptance_missing', "Scenario {$id} precisa referenciar acceptance criterion.", 'scenarios');
            }
            if (EngineeringStringListNormalizer::nonEmptyScalarStrings($scenario['evidence_required'] ?? []) === []) {
                $warnings[] = $this->issue('scenario_evidence_missing', "Scenario {$id} nao declara evidence_required.", 'scenarios');
            }
        }

        foreach ($phasePlan as $phase) {
            $tasks = $this->arrayList($phase['tasks'] ?? []);
            if ($tasks === []) {
                $errors[] = $this->issue('phase_tasks_missing', 'Phase plan precisa declarar tasks geraveis.', 'phase_plan');
            }
            foreach ($tasks as $task) {
                $taskId = $this->id($task, 'task');
                $acceptance = $this->arrayList($task['acceptance_criteria'] ?? []);
                if ($acceptance === []) {
                    $errors[] = $this->issue('task_acceptance_missing', "Task {$taskId} precisa de acceptance criteria.", 'phase_plan');
                }
                if (EngineeringStringListNormalizer::nonEmptyScalarStrings($task['definition_of_done'] ?? []) === []) {
                    $errors[] = $this->issue('task_dod_missing', "Task {$taskId} precisa de definition_of_done.", 'phase_plan');
                }
                foreach ($acceptance as $criterion) {
                    $criterionId = (string) ($criterion['id'] ?? '');
                    if ($criterionId !== '' && ! in_array($criterionId, $scenarioAcceptanceRefs, true)) {
                        $warnings[] = $this->issue('acceptance_without_scenario', "Acceptance {$criterionId} nao tem scenario associado.", 'scenarios');
                    }
                }
            }
        }

        $blockingGates = collect($errors)
            ->map(fn (array $issue): array => [
                'id' => $issue['code'],
                'status' => 'blocked',
                'reason' => $issue['message'],
            ])
            ->values()
            ->all();

        return [
            'status' => $errors === [] ? 'passed' : 'failed',
            'blocking' => $errors !== [],
            'errors' => $errors,
            'warnings' => $warnings,
            'missing_evidence' => $missingEvidence,
            'blocking_gates' => $blockingGates,
            'summary' => [
                'screen_count' => count($this->arrayList($inventory['screens'] ?? [])),
                'api_surface_count' => count($this->arrayList($inventory['api_surfaces'] ?? [])),
                'data_entity_count' => count($this->arrayList($inventory['data_entities'] ?? [])),
                'scenario_count' => count($scenarios),
                'phase_count' => count($phasePlan),
            ],
            'validated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $code, string $message, string $path): array
    {
        return compact('code', 'message', 'path');
    }

    private function id(array $value, string $fallback): string
    {
        return trim((string) ($value['id'] ?? $value['route'] ?? $value['title'] ?? $fallback));
    }

    private function hasScenarioType(array $scenarios, string $type): bool
    {
        return collect($scenarios)->contains(fn (array $scenario): bool => (string) ($scenario['type'] ?? '') === $type);
    }

    /**
     * @return array<string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function arrayList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->all();
    }

    private function containsAll(array $values, array $required): bool
    {
        $normalized = array_map(fn (string $value): string => mb_strtolower($value), $values);

        foreach ($required as $item) {
            if (! in_array($item, $normalized, true)) {
                return false;
            }
        }

        return true;
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
}
