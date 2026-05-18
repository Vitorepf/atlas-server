<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationObjectiveDecomposerTest extends TestCase
{
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
    }

    protected function tearDown(): void
    {
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    public function test_simple_prompt_creates_one_objective_with_success_criteria(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $mission = $factory->create('Implementar exporter CSV no painel admin');
        $objectives = $decomposer->decompose($mission);

        $this->assertCount(1, $objectives);
        $first = $objectives->first();
        $this->assertNotEmpty($first->success_criteria, 'success_criteria must not be empty');
        $this->assertSame(1, (int) $first->priority);
    }

    public function test_prompt_with_conjunctions_creates_multiple_objectives(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $mission = $factory->create('Implementar exporter CSV; adicionar testes de regressao; documentar API publica');
        $objectives = $decomposer->decompose($mission);

        $this->assertGreaterThanOrEqual(2, $objectives->count(), 'expected multiple objectives from conjunctions');
        $priorities = $objectives->pluck('priority')->all();
        $this->assertSame(range(1, count($priorities)), $priorities);
        foreach ($objectives as $objective) {
            $this->assertNotEmpty($objective->success_criteria);
        }
    }

    public function test_decompose_is_idempotent(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $mission = $factory->create('Implementar exporter CSV');
        $first = $decomposer->decompose($mission);
        $second = $decomposer->decompose($mission);

        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
    }
}
