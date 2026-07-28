<?php

namespace App\Services\Engineering;

use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;

class EngineeringPhasePlannerService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function forProject(AtlasProject $project): array
    {
        $project->loadMissing(['steps', 'tasks']);
        $steps = $project->steps->sortBy('step_order')->values();

        if ($steps->isEmpty()) {
            return [$this->phase('phase_1', 'Implementacao principal', 1, [
                $this->taskFromProject($project),
            ])];
        }

        return $steps
            ->map(fn (AtlasProjectStep $step, int $index): array => $this->phase(
                'phase_'.($index + 1),
                (string) ($step->title ?: 'Fase '.($index + 1)),
                $index + 1,
                [$this->taskFromStep($project, $step, $index + 1)],
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $tasks
     * @return array<string,mixed>
     */
    private function phase(string $id, string $title, int $order, array $tasks): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'order' => $order,
            'objective' => $title,
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskFromProject(AtlasProject $project): array
    {
        $goal = (string) ($project->desired_outcome ?: $project->goal ?: $project->title);

        return [
            'id' => 'task_project_main',
            'title' => $goal,
            'type' => $this->inferType($goal.' '.$project->description),
            'goal' => $goal,
            'context' => array_values(array_filter([
                'Projeto: '.$project->title,
                $project->description ? 'Descricao: '.$project->description : null,
                $project->minimum_viable_outcome ? 'MVO: '.$project->minimum_viable_outcome : null,
            ])),
            'in_scope' => [$goal],
            'out_of_scope' => ['Mudancas fora do blueprint congelado sem nova revisao.'],
            'acceptance_criteria' => $this->criteria($project->definition_of_done ?: $project->minimum_viable_outcome ?: $goal),
            'likely_files' => [],
            'allowed_paths' => [],
            'edge_cases' => ['Projeto sem task humana previa.'],
            'test_coverage' => ['Executar validacao mais proxima do workspace.'],
            'definition_of_done' => $this->criteriaStatements($project->definition_of_done ?: $goal),
            'scenario_refs' => ['scenario_happy_path'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskFromStep(AtlasProject $project, AtlasProjectStep $step, int $order): array
    {
        $goal = (string) ($step->expected_output ?: $step->title);

        return [
            'id' => 'task_phase_'.$order,
            'project_step_id' => $step->id,
            'title' => (string) ($step->title ?: $goal),
            'type' => $this->inferType($goal.' '.$step->description.' '.$project->description),
            'goal' => $goal,
            'context' => array_values(array_filter([
                'Projeto: '.$project->title,
                'Etapa: '.$step->title,
                $step->description,
            ])),
            'in_scope' => [$goal],
            'out_of_scope' => ['Nao ampliar escopo alem da fase '.$order.'.'],
            'acceptance_criteria' => $this->criteria($step->acceptance_criteria ?: $step->expected_output ?: $goal),
            'likely_files' => $this->stringList(data_get($step->metadata, 'likely_files', [])),
            'allowed_paths' => $this->stringList(data_get($step->metadata, 'allowed_paths', [])),
            'edge_cases' => $this->stringList(data_get($step->metadata, 'risks', [])),
            'test_coverage' => $this->stringList(data_get($step->metadata, 'test_coverage', ['Executar teste focado da fase.'])),
            'definition_of_done' => $this->criteriaStatements($step->acceptance_criteria ?: $goal),
            'scenario_refs' => ['scenario_phase_'.$order],
        ];
    }

    /**
     * @return array<int,array{id:string,statement:string,verification_method:string}>
     */
    private function criteria(?string $text): array
    {
        $items = $this->stringList($text);
        if ($items === []) {
            $items = ['Resultado definido no blueprint foi entregue e validado.'];
        }

        return collect($items)
            ->map(fn (string $item, int $index): array => [
                'id' => 'ac_'.($index + 1),
                'statement' => $item,
                'verification_method' => $this->verificationMethod($item),
            ])
            ->values()
            ->all();
    }

    /**
     * `ui` PRECISA de fronteira de palavra. Sem ela, o criterio de aceite em portugues
     * casa por acidente em aqui · muito · construir · seguir · cuidado · requisito ·
     * gratuito · circuito — medido: 8 de 9 frases realistas casavam, e a unica que era
     * MESMO de interface ("ajustar a tela de login") nao casava. O classificador estava
     * invertido na pratica.
     *
     * E o custo aqui e maior que um rotulo errado: `manual_qa` num sistema com zero humano
     * no loop significa NUNCA VERIFICADO. O acidente de substring convertia criterio
     * testavel em criterio que ninguem confere, em silencio.
     */
    private function verificationMethod(string $text): string
    {
        $text = mb_strtolower($text);

        return match (true) {
            str_contains($text, 'migration') || str_contains($text, 'postgres') || str_contains($text, 'schema') => 'database_review',
            preg_match('/\bui\b/u', $text) === 1 || str_contains($text, 'tela') || str_contains($text, 'visual') || str_contains($text, 'screenshot') => 'manual_qa',
            default => 'test',
        };
    }

    /**
     * @return array<int,string>
     */
    private function criteriaStatements(?string $text): array
    {
        return collect($this->criteria($text))
            ->map(fn (array $criterion): string => $criterion['statement'])
            ->values()
            ->all();
    }

    private function inferType(string $text): string
    {
        $text = mb_strtolower($text);

        return match (true) {
            str_contains($text, 'migration') || str_contains($text, 'schema') || str_contains($text, 'postgres') => 'migration',
            str_contains($text, 'bug') || str_contains($text, 'corrigir') || str_contains($text, 'falha') => 'bugfix',
            str_contains($text, 'refator') => 'refactor',
            str_contains($text, 'doc') => 'docs',
            default => 'feature',
        };
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return EngineeringStringListNormalizer::uniqueBulletListStrings($value);
    }
}
