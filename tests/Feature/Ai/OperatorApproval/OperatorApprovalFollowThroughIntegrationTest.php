<?php

namespace Tests\Feature\Ai\OperatorApproval;

use App\Models\AiMission;
use App\Models\AiOperatorApproval;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionFollowThroughResult;
use App\Services\Ai\Mission\MissionFollowThroughService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\Concerns\CreatesOperatorApprovalTable;
use Tests\TestCase;

class OperatorApprovalFollowThroughIntegrationTest extends TestCase
{
    use CreatesMissionFoundationTables;
    use CreatesOperatorApprovalTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
        $this->createOperatorApprovalTable();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorApprovalTable();
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    private function plannedMission(string $prompt, string $primaryDomain, string $missionType = MissionFactoryService::TYPE_MISSION): AiMission
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);

        $mission = $factory->create($prompt, [
            'mission_type' => $missionType,
            'primary_domain' => $primaryDomain,
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'test']);

        return $mission->refresh();
    }

    public function test_obra_mission_pauses_at_waiting_for_user(): void
    {
        $mission = $this->plannedMission(
            'Vou construir uma obra completa: rewrite all do sistema de pagamentos',
            'programming',
            MissionFactoryService::TYPE_OBRA,
        );

        $follow = app(MissionFollowThroughService::class);
        $result = $follow->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL, $result->outcome);
        $this->assertTrue($result->terminal());
        $this->assertFalse($result->shouldContinue());

        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_WAITING_APPROVAL, $mission->status);

        $events = $mission->events()->where('event_type', 'follow_through.cycle.approval_required')->count();
        $this->assertSame(1, $events);
    }

    public function test_dev_mission_proceeds_with_allow_auto(): void
    {
        $mission = $this->plannedMission(
            'Missão: implementar feature ABC até cobrir testes finais',
            'programming',
        );

        $follow = app(MissionFollowThroughService::class);
        $result = $follow->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_HANDOFF_DEV, $result->outcome);
        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_RUNNING, $mission->status);
    }

    public function test_research_mission_proceeds_with_allow_auto(): void
    {
        $mission = $this->plannedMission(
            'Missão: pesquisar mercado de IA até concluir relatório',
            'research',
        );

        $follow = app(MissionFollowThroughService::class);
        $result = $follow->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE, $result->outcome);
    }

    public function test_approve_resumes_obra_mission(): void
    {
        $mission = $this->plannedMission(
            'Obra grande: rewrite all do sistema de pagamentos completo',
            'programming',
            MissionFactoryService::TYPE_OBRA,
        );

        $follow = app(MissionFollowThroughService::class);
        $first = $follow->runNext($mission);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL, $first->outcome);

        $approval = AiOperatorApproval::query()
            ->where('mission_id', $mission->id)
            ->where('status', OperatorApprovalCanon::STATUS_PENDING)
            ->first();
        $this->assertNotNull($approval);

        $gate = app(OperatorApprovalGateService::class);
        $gate->approve($approval, 'vitor', 'approved for forge handoff');

        $resumed = $follow->runNext($mission);

        $mission->refresh();
        $this->assertSame(MissionFollowThroughResult::OUTCOME_HANDOFF_FORGE, $resumed->outcome);
        $this->assertSame(MissionLifecycleService::STATUS_RUNNING, $mission->status);

        $approval->refresh();
        $this->assertNotNull($approval->consumed_at, 'approval should be consumed by the resumed cycle');
    }

    public function test_deny_blocks_obra_mission(): void
    {
        $mission = $this->plannedMission(
            'Obra grande: rewrite all do sistema de pagamentos',
            'programming',
            MissionFactoryService::TYPE_OBRA,
        );

        $follow = app(MissionFollowThroughService::class);
        $first = $follow->runNext($mission);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL, $first->outcome);

        $approval = AiOperatorApproval::query()
            ->where('mission_id', $mission->id)
            ->where('status', OperatorApprovalCanon::STATUS_PENDING)
            ->first();

        app(OperatorApprovalGateService::class)->deny($approval, 'vitor', 'not now');

        $blocked = $follow->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_BLOCKED_BY_APPROVAL, $blocked->outcome);
        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_BLOCKED, $mission->status);
    }

    public function test_expired_approval_blocks_resume(): void
    {
        $mission = $this->plannedMission(
            'Obra grande: rewrite all do sistema',
            'programming',
            MissionFactoryService::TYPE_OBRA,
        );

        $follow = app(MissionFollowThroughService::class);
        $first = $follow->runNext($mission);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL, $first->outcome);

        // Force expiry by past-dating expires_at.
        AiOperatorApproval::query()
            ->where('mission_id', $mission->id)
            ->update(['expires_at' => Carbon::now()->subMinute()]);

        $blocked = $follow->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_BLOCKED_BY_APPROVAL, $blocked->outcome);
        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_BLOCKED, $mission->status);
    }

    public function test_waiting_without_decision_remains_waiting(): void
    {
        $mission = $this->plannedMission(
            'Obra grande: rewrite all sistema',
            'programming',
            MissionFactoryService::TYPE_OBRA,
        );

        $follow = app(MissionFollowThroughService::class);
        $first = $follow->runNext($mission);
        $this->assertSame(MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL, $first->outcome);

        $again = $follow->runNext($mission);

        $this->assertSame(MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL, $again->outcome);
        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_WAITING_APPROVAL, $mission->status);
    }

    public function test_follow_through_snapshot_exposes_pending_approvals(): void
    {
        $mission = $this->plannedMission(
            'Obra grande: rewrite all sistema completo',
            'programming',
            MissionFactoryService::TYPE_OBRA,
        );

        $follow = app(MissionFollowThroughService::class);
        $follow->runNext($mission);

        $snapshot = $follow->snapshot($mission);

        $this->assertArrayHasKey('pending_approvals', $snapshot);
        $this->assertNotEmpty($snapshot['pending_approvals']);
        $this->assertSame('mission.handoff_forge', $snapshot['pending_approvals'][0]['requested_action']);
    }
}
