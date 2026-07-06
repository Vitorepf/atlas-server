<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMissionEvent;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

/**
 * Observe-wire: ObjectivePairMutualExclusivityScorer → payload 'pair_exclusivity'
 * do evento 'objective.created' emitido por ObjectiveDecomposerService::decompose().
 */
class ObjectiveDecomposerPairExclusivityObserveTest extends TestCase
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

    /**
     * @return \Illuminate\Support\Collection<int,AiMissionEvent>
     */
    private function objectiveCreatedEvents(string $prompt)
    {
        $mission = app(MissionFactoryService::class)->create($prompt);
        $objectives = app(ObjectiveDecomposerService::class)->decompose($mission);
        $this->assertCount(2, $objectives, 'prompt deve decompor em exatamente 2 objetivos');

        return AiMissionEvent::query()
            ->where('mission_id', $mission->id)
            ->where('event_type', 'objective.created')
            ->orderBy('id')
            ->get();
    }

    public function test_clausulas_duplicadas_marcam_par_como_duplicate_com_exclusividade_zero(): void
    {
        $events = $this->objectiveCreatedEvents('Implementar exporter CSV; implementar exporter CSV');

        $this->assertCount(2, $events);
        $this->assertNull(
            $events[0]->payload['pair_exclusivity'],
            'primeiro objetivo não tem par anterior — campo deve ser null explícito',
        );

        $pair = $events[1]->payload['pair_exclusivity'];
        $this->assertIsArray($pair);
        $this->assertSame('atlas.aaeos.objective_pair_exclusivity.v1', $pair['schema_version']);
        $this->assertSame('duplicate', $pair['classification']);
        $this->assertSame(0.0, (float) $pair['exclusivity']);
    }

    public function test_clausulas_disjuntas_marcam_par_como_disjoint_com_exclusividade_total(): void
    {
        $events = $this->objectiveCreatedEvents('Implementar exporter CSV; documentar arquitetura completa');

        $pair = $events[1]->payload['pair_exclusivity'];
        $this->assertIsArray($pair);
        $this->assertSame('disjoint', $pair['classification']);
        $this->assertSame(1.0, (float) $pair['exclusivity']);
        $this->assertGreaterThanOrEqual(0.0, (float) $pair['exclusivity']);
        $this->assertLessThanOrEqual(1.0, (float) $pair['exclusivity']);
    }
}
