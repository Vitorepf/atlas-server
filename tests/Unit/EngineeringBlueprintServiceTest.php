<?php

namespace Tests\Unit;

use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringBlueprintService;
use Tests\TestCase;

class EngineeringBlueprintServiceTest extends TestCase
{
    public function test_blueprint_maps_acceptance_scenarios_and_database_gate(): void
    {
        $task = new AtlasTask([
            'title' => 'Adicionar migration de contratos',
            'project_id' => '11111111-1111-4111-8111-111111111111',
        ]);
        $task->forceFill(['id' => '22222222-2222-4222-8222-222222222222']);

        $blueprint = app(EngineeringBlueprintService::class)->forTask($task, [
            'goal' => 'Persistir contrato tecnico em metadata.',
            'acceptance_criteria' => [
                'Migration possui rollback seguro.',
                'CLI mostra blueprint no plan-only.',
            ],
            'likely_files' => [
                'database/migrations/2026_05_01_create_engineering_artifacts.php',
                'app/Console/Commands/AtlasCliDevCommand.php',
            ],
            'edge_cases' => ['Task sem projeto vinculado.'],
        ]);

        $this->assertStringStartsWith('eng_', $blueprint['blueprint_id']);
        $this->assertCount(2, $blueprint['acceptance_matrix']);
        $this->assertSame('database_review', data_get($blueprint, 'acceptance_matrix.0.verification_method'));
        $this->assertContains('database_review', collect($blueprint['review_gates'])->pluck('id')->all());
        $this->assertContains('edge_1', collect($blueprint['scenario_inventory'])->pluck('id')->all());
    }

    public function test_blueprint_adds_manual_qa_gate_for_visual_contracts(): void
    {
        $task = new AtlasTask(['title' => 'Ajustar tela mobile']);
        $task->forceFill(['id' => '33333333-3333-4333-8333-333333333333']);

        $blueprint = app(EngineeringBlueprintService::class)->forTask($task, [
            'goal' => 'Corrigir layout visual no frontend mobile.',
            'acceptance_criteria' => ['Screenshot mobile sem sobreposicao.'],
        ]);

        $this->assertContains('manual_qa', collect($blueprint['review_gates'])->pluck('id')->all());
        $this->assertContains('responsive_or_visual', collect($blueprint['scenario_inventory'])->pluck('id')->all());
    }
}
