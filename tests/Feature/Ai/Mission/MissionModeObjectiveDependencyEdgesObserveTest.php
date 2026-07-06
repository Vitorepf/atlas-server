<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMissionEvent;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\MissionModeService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

/**
 * Observe-wire: ObjectiveDependencyEdgeInferer → payload 'objective_dependency_edges'
 * da transition draft→planned emitida por MissionModeService::processIntent().
 */
class MissionModeObjectiveDependencyEdgesObserveTest extends TestCase
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

    public function test_transition_planned_carrega_edges_produtor_consumidor_entre_objetivos(): void
    {
        $result = app(MissionModeService::class)->processIntent(
            'Minha missao: criar relatorio de vendas; testar relatorio ate concluir',
        );

        $this->assertTrue($result->activated());
        $this->assertSame(2, $result->objectives->count());

        $event = AiMissionEvent::query()
            ->where('mission_id', $result->mission->id)
            ->where('event_type', 'mission.transition')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(MissionLifecycleService::STATUS_PLANNED, $event->payload['to']);

        $envelope = $event->payload['objective_dependency_edges'];
        $this->assertIsArray($envelope);
        $this->assertSame('atlas.aaeos.objective_dependency_edges.v1', $envelope['schema_version']);
        $this->assertNotEmpty($envelope['edges'], 'criar→testar com artefato compartilhado deve gerar edge');

        $producer = $result->objectives->first();
        $consumer = $result->objectives->last();
        $edge = $envelope['edges'][0];

        $this->assertSame((string) $producer->id, $edge['from']);
        $this->assertSame((string) $consumer->id, $edge['to']);
        $this->assertSame('relatorio', $edge['artifact']);
        $this->assertSame('criar->testar', $edge['rule']);
    }

    public function test_sem_verbos_produtor_consumidor_edges_fica_vazio_sem_mudar_veredito(): void
    {
        $result = app(MissionModeService::class)->processIntent(
            'Minha missao: organizar financas ate concluir o ano fiscal',
        );

        $this->assertTrue($result->activated(), 'observe-wire não pode mudar a ativação');

        $event = AiMissionEvent::query()
            ->where('mission_id', $result->mission->id)
            ->where('event_type', 'mission.transition')
            ->orderByDesc('id')
            ->first();

        $envelope = $event->payload['objective_dependency_edges'];
        $this->assertIsArray($envelope);
        $this->assertSame([], $envelope['edges']);
    }
}
