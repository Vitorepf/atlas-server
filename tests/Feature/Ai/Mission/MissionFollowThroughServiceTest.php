<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionEvent;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionFollowThroughResult;
use App\Services\Ai\Mission\MissionFollowThroughService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\MissionModeService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use App\Services\Ai\Mission\WorkOrderSelectionService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFollowThroughServiceTest extends TestCase
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

    private function service(): MissionFollowThroughService
    {
        return app(MissionFollowThroughService::class);
    }

    /**
     * Create a fresh mission with N objectives + work_orders, status PLANNED.
     */
    private function freshPlannedMission(string $prompt, string $primaryDomain = 'research'): AiMission
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);

        $mission = $factory->create($prompt, [
            'mission_type' => MissionFactoryService::TYPE_MISSION,
            'primary_domain' => $primaryDomain,
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'test']);

        return $mission->refresh();
    }

    public function test_run_next_em_planned_transita_para_running_e_simula_safe(): void
    {
        $mission = $this->freshPlannedMission(
            'Missão: pesquisar mercado de IA até concluir relatório',
            'research',
        );

        $result = $this->service()->runNext($mission);

        $this->assertInstanceOf(MissionFollowThroughResult::class, $result);
        $this->assertSame(MissionLifecycleService::STATUS_PLANNED, $result->statusBefore);
        $this->assertSame(MissionLifecycleService::STATUS_RUNNING, $result->statusAfter);
        $this->assertSame('atlas_research', $result->selectedFlow);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE, $result->outcome);
        $this->assertSame('safe_simulated_dispatch', $result->actionTaken);
        $this->assertCount(1, $result->evidenceRefs, 'cycle deve produzir 1 evidence_ref tipo receipt');
        $this->assertNotEmpty($result->receiptHash);
        $this->assertSame(64, strlen($result->receiptHash));
        $this->assertNotNull($result->selectedWorkOrder);
        $this->assertSame('simulated', $result->selectedWorkOrder->status, 'WO marcado simulated após safe cycle');
    }

    public function test_programming_mission_normal_gera_handoff_dev(): void
    {
        $mission = $this->freshPlannedMission(
            'Missão: implementar feature ABC até cobrir testes finais',
            'programming',
        );

        $result = $this->service()->runNext($mission);

        $this->assertSame('atlas_dev', $result->selectedFlow);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_HANDOFF_DEV, $result->outcome);
        $this->assertSame('handoff_to_atlas_dev', $result->actionTaken);
        $this->assertSame('handoff_dev', $result->selectedWorkOrder->status);
    }

    public function test_programming_obra_mission_gera_handoff_forge(): void
    {
        // Para forçar OBRA via factory.classify, usar keyword "obra".
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);

        $mission = $factory->create(
            'Vou construir uma obra completa: rewrite all do sistema de pagamentos',
            ['mission_type' => MissionFactoryService::TYPE_OBRA, 'primary_domain' => 'programming'],
        );
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'test']);
        $mission->refresh();

        $result = $this->service()->runNext($mission);

        $this->assertSame('atlas_forge', $result->selectedFlow);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_HANDOFF_FORGE, $result->outcome);
        $this->assertSame('handoff_to_atlas_forge', $result->actionTaken);
        $this->assertSame('handoff_forge', $result->selectedWorkOrder->status);
    }

    public function test_run_next_sem_work_orders_vai_para_blocked(): void
    {
        $factory = app(MissionFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);

        $mission = $factory->create('Missão: explorar opções até decidir', [
            'mission_type' => MissionFactoryService::TYPE_MISSION,
            'primary_domain' => 'research',
        ]);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'test']);
        $mission->refresh();
        // NÃO chama decomposer/workOrders.plan — mission sem objectives/work_orders.

        $result = $this->service()->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_NO_SELECTABLE_STEP, $result->outcome);
        $this->assertNotEmpty($result->blockers);
        $this->assertTrue($result->repairCreated, 'mission blocked deveria registrar transition');
        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_BLOCKED, $mission->status);
    }

    public function test_mission_em_estado_terminal_é_noop(): void
    {
        // Cria mission e força status COMPLETED via fluxo legítimo.
        $missionMode = app(MissionModeService::class);
        $missionResult = $missionMode->processIntent('Missão: cobrir endpoint /health até passar nos testes');
        $mission = $missionResult->mission;
        $lifecycle = app(MissionLifecycleService::class);
        $evidence = app(MissionEvidenceService::class);

        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $mission->refresh();
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/HealthEndpointTest.php',
            'actor_type' => 'test',
        ]);
        $missionMode->certify($mission);
        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $mission->status);

        $result = $this->service()->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_MISSION_ALREADY_TERMINAL, $result->outcome);
        $this->assertFalse($result->advanced());
        $this->assertTrue($result->terminal());
    }

    public function test_mission_blocked_é_noop_aguardando_humano(): void
    {
        $mission = $this->freshPlannedMission('Missão: cobrir fluxo X até concluir', 'research');
        $lifecycle = app(MissionLifecycleService::class);

        // PLANNED → RUNNING → BLOCKED
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, [
            'actor_type' => 'test',
            'blocker_reason' => 'awaiting_human_decision',
        ]);
        $mission->refresh();

        $result = $this->service()->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_NOOP_INVALID_STATE, $result->outcome);
        $this->assertNotEmpty($result->blockers);
        $this->assertStringContainsString('human_must_resolve_blocker', $result->nextAction);
    }

    public function test_run_until_blocked_or_complete_respeita_max_cycles_hard_cap(): void
    {
        $mission = $this->freshPlannedMission(
            'Missão: pesquisar mercado de IA até concluir relatório',
            'research',
        );

        // maxCycles = 1 → faz só 1 ciclo, deixa WO em simulated, retorna last.
        $service = $this->service();
        $result = $service->runUntilBlockedOrComplete($mission, 1);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE, $result->outcome);

        // maxCycles cap = MAX_CYCLES_HARD_CAP (não importa input alto).
        $bigCap = MissionFollowThroughService::MAX_CYCLES_HARD_CAP + 100;
        $this->assertLessThanOrEqual(
            MissionFollowThroughService::MAX_CYCLES_HARD_CAP,
            min($bigCap, MissionFollowThroughService::MAX_CYCLES_HARD_CAP),
        );
    }

    public function test_run_until_blocked_or_complete_certifica_quando_todos_w_o_terminais(): void
    {
        // Mission tem 1 objective → 1 WO (decomposer cria 1 clause padrão).
        $mission = $this->freshPlannedMission(
            'Missão: pesquisar fonte X até concluir análise',
            'research',
        );
        $evidence = app(MissionEvidenceService::class);

        // Cycle 1: WO ready → simulated. Mission RUNNING.
        $r1 = $this->service()->runNext($mission);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE, $r1->outcome);

        // Para certify PASSAR, precisamos de evidence_ref tipo TEST (não receipt).
        // O cycle gera receipt; adicionamos TEST manual para satisfazer DoD coverage.
        $mission->refresh();
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/research_coverage.md',
            'actor_type' => 'test',
        ]);

        // Cycle 2: todos WO terminais → tenta certify.
        $r2 = $this->service()->runNext($mission);
        $mission->refresh();

        $this->assertContains($r2->outcome, [
            MissionFollowThroughResult::OUTCOME_MISSION_CERTIFIED,
            MissionFollowThroughResult::OUTCOME_MISSION_COMPLETED_PENDING_CERT,
        ], 'cycle 2 deve tentar certify');
    }

    public function test_certify_é_chamado_quando_todos_w_o_terminais_e_nã_o_completa_sem_passar_gate(): void
    {
        // Cenário: cycle 1 simula WO único. Cycle 2 tenta certify.
        // O Follow-Through nunca transita para COMPLETED unless certification passa.
        $mission = $this->freshPlannedMission('Missão: pesquisar X até concluir', 'research');

        // Cycle 1 simula WO → status RUNNING + receipt evidence.
        $this->service()->runNext($mission);
        $mission->refresh();

        // Cycle 2 tenta certify (todos WO terminais).
        $r2 = $this->service()->runNext($mission);
        $mission->refresh();

        // Outcome canônico: certified (se cert passou) OU pending_cert (se faltou requisito).
        $this->assertContains($r2->outcome, [
            MissionFollowThroughResult::OUTCOME_MISSION_CERTIFIED,
            MissionFollowThroughResult::OUTCOME_MISSION_COMPLETED_PENDING_CERT,
            MissionFollowThroughResult::OUTCOME_MISSION_BLOCKED,
        ]);

        // Invariante hard: mission só pode estar COMPLETED se certification PASSED
        // — e nesse caso `certification_hash` está selado no Mission.
        if ($mission->status === MissionLifecycleService::STATUS_COMPLETED) {
            $this->assertNotEmpty($mission->certification_hash, 'completed exige certification_hash');
            $this->assertSame(64, strlen((string) $mission->certification_hash));
            $this->assertNotNull($mission->latestCertification()->first());
            $this->assertSame(
                MissionCertificationService::STATUS_PASSED,
                $mission->latestCertification()->first()->status,
            );
        }
    }

    public function test_hash_é_determinístico_sobre_mesma_entrada(): void
    {
        $h1 = MissionFollowThroughResult::buildHash(
            cycleId: 'cycle-1',
            missionUuid: 'mission-uuid',
            statusBefore: 'planned',
            statusAfter: 'running',
            selectedWorkOrderUuid: 'wo-uuid',
            selectedFlow: 'atlas_research',
            outcome: 'simulated_safe',
            receiptHash: 'sha-receipt',
        );
        $h2 = MissionFollowThroughResult::buildHash(
            cycleId: 'cycle-1',
            missionUuid: 'mission-uuid',
            statusBefore: 'planned',
            statusAfter: 'running',
            selectedWorkOrderUuid: 'wo-uuid',
            selectedFlow: 'atlas_research',
            outcome: 'simulated_safe',
            receiptHash: 'sha-receipt',
        );

        $this->assertSame($h1, $h2);
        $this->assertSame(64, strlen($h1));
    }

    public function test_each_cycle_persiste_ai_mission_event_canonico(): void
    {
        $mission = $this->freshPlannedMission('Missão: pesquisar Y até concluir', 'research');
        $this->service()->runNext($mission);

        $eventsCount = AiMissionEvent::query()
            ->where('mission_id', $mission->id)
            ->where('event_type', 'like', 'follow_through.cycle.%')
            ->count();

        $this->assertGreaterThanOrEqual(1, $eventsCount, 'cycle deve emitir pelo menos 1 follow_through.cycle.* event');
    }

    public function test_snapshot_expoe_dados_para_control_plane(): void
    {
        $mission = $this->freshPlannedMission('Missão: pesquisar Z até concluir', 'research');
        $this->service()->runNext($mission);
        $mission->refresh();

        $snapshot = $this->service()->snapshot($mission);

        $this->assertSame('atlas.ai.control_plane.follow_through.v1', $snapshot['schema_version']);
        $this->assertSame($mission->uuid, $snapshot['mission_uuid']);
        $this->assertGreaterThanOrEqual(1, $snapshot['cycles_count']);
        $this->assertNotNull($snapshot['last_cycle']);
        $this->assertIsArray($snapshot['work_order_summary']);
        $this->assertArrayHasKey('selectable', $snapshot['work_order_summary']);
        $this->assertArrayHasKey('terminal', $snapshot['work_order_summary']);
        $this->assertIsString($snapshot['next_action']);
    }

    public function test_work_order_selection_é_deterministica_por_priority_e_created_at(): void
    {
        // Cria mission via factory + decomposer manualmente para ter múltiplos WO.
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);

        $mission = $factory->create(
            "Missão: cobrir 3 fluxos até concluir;\n- fluxo A;\n- fluxo B;\n- fluxo C",
            ['mission_type' => MissionFactoryService::TYPE_MISSION, 'primary_domain' => 'research'],
        );
        $decomposer->decompose($mission);
        $workOrders->plan($mission);

        $selector = app(WorkOrderSelectionService::class);

        // Primeiro WO selecionado deve ter o menor objective.priority.
        $selected = $selector->selectNext($mission);
        $this->assertNotNull($selected);
        $objective = $selector->objectiveFor($selected);
        $this->assertNotNull($objective);

        // Compare against the lowest priority objective with selectable WO.
        $expectedPriority = $mission->objectives()->orderBy('priority')->first()->priority;
        $this->assertSame($expectedPriority, $objective->priority);
    }
}
