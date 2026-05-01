<?php

namespace Tests\Unit;

use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringTaskContractService;
use Tests\TestCase;

class EngineeringTaskContractServiceTest extends TestCase
{
    public function test_builds_contract_from_task_project_step_and_metadata(): void
    {
        $project = new AtlasProject([
            'title' => 'Atlas CLI',
            'goal' => 'Transformar o CLI em executor tecnico confiavel.',
            'desired_outcome' => 'Dev workflow auditavel.',
            'minimum_viable_outcome' => 'Contrato tecnico no plano.',
            'definition_of_done' => "Plano mostra criterios\nValidacao registrada",
            'project_type' => 'technical_build',
        ]);
        $project->forceFill(['id' => '11111111-1111-4111-8111-111111111111']);

        $step = new AtlasProjectStep([
            'title' => 'Contratos de tarefa',
            'expected_output' => 'atlas:cli:dev recebe --task-id e monta contrato.',
            'acceptance_criteria' => "- Plano contem engineering_contract\n- Prompt contem criterios de aceite",
        ]);
        $step->forceFill(['id' => '22222222-2222-4222-8222-222222222222']);

        $task = new AtlasTask([
            'title' => 'Corrigir regressao no CLI dev',
            'description' => 'Falha ao carregar contexto de tarefa estruturada.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'project_id' => $project->id,
            'project_step_id' => $step->id,
            'estimated_minutes' => 90,
            'minimum_viable_action' => 'Adicionar --task-id sem quebrar o modo interativo.',
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Carregar contrato tecnico antes de chamar provider.',
                    'likely_files' => ['app/Console/Commands/AtlasCliDevCommand.php'],
                ],
                'acceptance_criteria' => ['Plan-only expoe contrato normalizado.'],
                'test_coverage' => ['Teste unitario do contrato e feature do plan-only.'],
            ],
        ]);
        $task->forceFill(['id' => '33333333-3333-4333-8333-333333333333']);
        $task->setRelation('project', $project);
        $task->setRelation('projectStep', $step);

        $contract = app(EngineeringTaskContractService::class)->forTask($task);

        $this->assertSame('bugfix', $contract['type']);
        $this->assertSame('Carregar contrato tecnico antes de chamar provider.', $contract['goal']);
        $this->assertContains('Plan-only expoe contrato normalizado.', $contract['acceptance_criteria']);
        $this->assertContains('Plano contem engineering_contract', $contract['acceptance_criteria']);
        $this->assertContains('Validacao registrada', $contract['definition_of_done']);
        $this->assertContains('app/Console/Commands/AtlasCliDevCommand.php', $contract['likely_files']);
        $this->assertSame('S', $contract['estimated_size']);
        $this->assertSame('33333333-3333-4333-8333-333333333333', data_get($contract, 'refs.task_id'));
    }

    public function test_default_prompt_uses_contract_goal(): void
    {
        $task = new AtlasTask(['title' => 'Implementar fluxo']);
        $prompt = app(EngineeringTaskContractService::class)->defaultPrompt($task, [
            'goal' => 'Gerar plano profissional de engenharia.',
        ]);

        $this->assertSame('Executar contrato tecnico Atlas: Gerar plano profissional de engenharia.', $prompt);
    }
}
