<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateCanon;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleException;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleService;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

class ForgeWorkPacketExecutionCycleServiceTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;

    private ForgeWorkPacketExecutionCycleService $cycles;

    private ForgeLongHorizonStateService $longHorizon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeLongHorizonStateTable();
        $this->cycles = app(ForgeWorkPacketExecutionCycleService::class);
        $this->longHorizon = app(ForgeLongHorizonStateService::class);
    }

    protected function tearDown(): void
    {
        $this->dropForgeLongHorizonStateTable();
        parent::tearDown();
    }

    public function test_select_packet_picks_first_proposed_when_no_state(): void
    {
        $intake = $this->readyIntake();
        $packet = $this->cycles->selectPacket($intake);

        $this->assertNotNull($packet);
        $this->assertSame(ForgeIntakeCanon::PACKET_STATUS_PROPOSED, $packet->status);
    }

    public function test_select_packet_respects_active_set_when_state_provided(): void
    {
        $intake = $this->readyIntake();
        $state = $this->longHorizon->initializeForIntake($intake);

        $packets = $intake->workPackets()->orderBy('packet_position')->get();
        $this->assertGreaterThanOrEqual(2, $packets->count(), 'sanity: prompt must yield ≥2 packets');
        $second = (string) $packets[1]->packet_id;

        $state = $this->longHorizon->recordCycle($state, ['active_work_packets' => [$second]]);

        $selected = $this->cycles->selectPacket($intake, $state);
        $this->assertNotNull($selected);
        $this->assertSame($second, (string) $selected->packet_id, 'selectPacket must prefer an active packet when state nominates one');
    }

    public function test_select_packet_skips_blocked_packets_from_state(): void
    {
        $intake = $this->readyIntake();
        $state = $this->longHorizon->initializeForIntake($intake);
        $packets = $intake->workPackets()->orderBy('packet_position')->get();
        $first = (string) $packets[0]->packet_id;

        $state = $this->longHorizon->recordCycle($state, [
            'blockers' => [[
                'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_PACKET,
                'target' => $first,
                'reason' => 'provider_timeout',
            ]],
        ]);

        $selected = $this->cycles->selectPacket($intake, $state);
        $this->assertNotNull($selected);
        $this->assertNotSame($first, (string) $selected->packet_id, 'blocked packet must be excluded');
    }

    public function test_plan_execution_defaults_to_safe_simulation_and_appends_simulation_log_evidence_kind(): void
    {
        $intake = $this->readyIntake();
        $packet = $this->cycles->selectPacket($intake);

        $plan = $this->cycles->planExecution($packet);

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::MODE_SAFE_SIMULATION, $plan['execution_mode']);
        $this->assertContains('simulation_log', $plan['evidence_kinds_required']);
        $this->assertNotEmpty($plan['expected_artifacts']);
        $this->assertSame(
            ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_ATTACH_EVIDENCE,
            $plan['initial_next_action']['kind'],
        );
    }

    public function test_start_cycle_with_safe_simulation_yields_running_status(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();

        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING, $cycle->status);
        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::MODE_SAFE_SIMULATION, $cycle->execution_mode);
        $this->assertSame(1, $cycle->cycle_position);
        $this->assertSame(64, strlen((string) $cycle->cycle_hash));
        $this->assertSame([], $cycle->evidence_refs);
        $this->assertNull($cycle->gate_result);
    }

    public function test_complete_safe_simulation_with_evidence_and_passing_gate_marks_packet_done_and_advances_state(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $evidenceRefs = [
            ['kind' => 'work_packet_receipts', 'ref' => 'wpr://1'],
            ['kind' => 'verification_receipt', 'ref' => 'vr://1'],
            ['kind' => 'simulation_log', 'ref' => 'sim://1'],
        ];
        $gateResult = $this->passingGate();

        $cycle = $this->cycles->complete($cycle, $evidenceRefs, $gateResult, $state);

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::STATUS_SUCCESS, $cycle->status);
        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS, $cycle->outcome_status);
        $this->assertNotNull($cycle->completed_at);
        $this->assertCount(3, $cycle->evidence_refs);

        // Packet status updated.
        $packet->refresh();
        $this->assertSame(ForgeIntakeCanon::PACKET_STATUS_DONE, $packet->status);

        // Long-horizon state moved the packet from active → completed.
        $state->refresh();
        $this->assertContains((string) $packet->packet_id, $state->completed_work_packets);
        $this->assertNotContains((string) $packet->packet_id, $state->active_work_packets);
    }

    public function test_complete_refuses_when_evidence_refs_is_empty(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $this->expectException(ForgeWorkPacketExecutionCycleException::class);
        $this->expectExceptionMessage('without evidence_refs');
        $this->cycles->complete($cycle, [], $this->passingGate(), $state);
    }

    public function test_complete_refuses_when_gate_result_has_no_passed_gate(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $failingGate = [
            'milestone_id' => ForgeIntakeCanon::MILESTONE_IMPLEMENTATION,
            'gates' => [
                ['gate_id' => 'work_packets_scoped', 'status' => 'failed', 'reason' => 'no_packets'],
            ],
            'evidence_present' => [],
            'evidence_missing' => [],
            'all_passed' => false,
            'failure_reasons' => ['work_packets_scoped:no_packets'],
        ];

        $this->expectException(ForgeWorkPacketExecutionCycleException::class);
        $this->expectExceptionMessage('no gate is passed');
        $this->cycles->complete($cycle, [['kind' => 'work_packet_receipts', 'ref' => 'wpr://x']], $failingGate, $state);
    }

    public function test_fail_records_repair_hook_and_packet_blocker_in_state(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $cycle = $this->cycles->fail(
            $cycle,
            'provider_timeout',
            ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT,
            [['kind' => 'partial_log', 'ref' => 'log://1']],
            $state,
        );

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::STATUS_FAILED, $cycle->status);
        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::OUTCOME_FAILED, $cycle->outcome_status);
        $this->assertSame('provider_timeout', $cycle->failure_reason);
        $this->assertNotNull($cycle->repair_hook);
        $this->assertSame(
            ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT,
            $cycle->repair_hook['hint'],
        );
        $this->assertSame(
            ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_RESOLVE_BLOCKER,
            $cycle->next_action['kind'],
        );

        // Packet status NOT changed by fail() — only cycle is terminal.
        $packet->refresh();
        $this->assertNotSame(ForgeIntakeCanon::PACKET_STATUS_DONE, $packet->status);

        // Long-horizon state recorded a packet-scope blocker.
        $state->refresh();
        $matching = collect($state->blockers)->where('target', (string) $packet->packet_id);
        $this->assertNotEmpty($matching, 'state must carry a packet-scope blocker after fail()');
        $this->assertSame(ForgeLongHorizonStateCanon::STATUS_BLOCKED, $state->status);
    }

    public function test_block_terminal_transition_records_blocker_and_resolve_next_action(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $cycle = $this->cycles->block($cycle, 'external_dependency_unavailable', $state);

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED, $cycle->status);
        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::OUTCOME_BLOCKED, $cycle->outcome_status);
        $this->assertSame('external_dependency_unavailable', $cycle->failure_reason);

        $state->refresh();
        $hasBlocker = collect($state->blockers)
            ->where('target', (string) $packet->packet_id)
            ->where('reason', 'external_dependency_unavailable')
            ->isNotEmpty();
        $this->assertTrue($hasBlocker);
    }

    public function test_start_cycle_with_mode_blocked_creates_terminal_cycle_immediately(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet, [
            'execution_mode' => ForgeWorkPacketExecutionCycleCanon::MODE_BLOCKED,
            'reason' => 'awaiting_operator_decision',
        ]);

        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::STATUS_BLOCKED, $cycle->status);
        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::OUTCOME_BLOCKED, $cycle->outcome_status);
        $this->assertNotNull($cycle->completed_at);
        $this->assertSame(
            ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_RESOLVE_BLOCKER,
            $cycle->next_action['kind'],
        );

        // mode=blocked also propagates a packet-scope blocker.
        $state->refresh();
        $this->assertTrue(
            collect($state->blockers)->where('target', (string) $packet->packet_id)->isNotEmpty(),
            'mode=blocked must register a packet blocker on the long-horizon state',
        );
    }

    public function test_complete_then_complete_again_throws_terminal_guard(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);
        $cycle = $this->cycles->complete(
            $cycle,
            [['kind' => 'work_packet_receipts', 'ref' => 'wpr://1']],
            $this->passingGate(),
            $state,
        );

        $this->expectException(ForgeWorkPacketExecutionCycleException::class);
        $this->expectExceptionMessage('terminal status');
        $this->cycles->complete(
            $cycle,
            [['kind' => 'work_packet_receipts', 'ref' => 'wpr://2']],
            $this->passingGate(),
            $state,
        );
    }

    public function test_invalid_execution_mode_throws_during_plan(): void
    {
        $intake = $this->readyIntake();
        $packet = $this->cycles->selectPacket($intake);

        $this->expectException(ForgeWorkPacketExecutionCycleException::class);
        $this->cycles->planExecution($packet, ['execution_mode' => 'wishful_thinking']);
    }

    public function test_canonical_cycle_projection_is_stable_json(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);
        $cycle = $this->cycles->complete(
            $cycle,
            [['kind' => 'work_packet_receipts', 'ref' => 'wpr://1']],
            $this->passingGate(),
            $state,
        );

        $projection = $cycle->toCanonicalArray();
        $json = json_encode($projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $decoded = json_decode((string) $json, true);
        $this->assertSame('atlas.forge.work_packet_execution_cycle.v1', $decoded['schema']);
        $this->assertSame($cycle->cycle_hash, $decoded['cycle_hash']);
        $this->assertSame((string) $packet->packet_id, $decoded['work_packet_canonical_id']);
        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::OUTCOME_SUCCESS, $decoded['outcome_status']);
    }

    public function test_no_eligible_packet_returns_null_so_caller_can_emit_next_action(): void
    {
        $intake = $this->readyIntake();
        // Mark every packet done so no eligible packet remains.
        AiForgeWorkPacket::query()
            ->where('intake_id', $intake->id)
            ->update(['status' => ForgeIntakeCanon::PACKET_STATUS_DONE]);

        $selected = $this->cycles->selectPacket($intake);
        $this->assertNull($selected, 'caller is responsible for emitting next_action=no_packets_left');
    }

    public function test_successful_completion_emits_run_next_packet_action_when_more_remain(): void
    {
        [$intake, $packet, $state] = $this->bootstrap();
        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        $cycle = $this->cycles->complete(
            $cycle,
            [['kind' => 'work_packet_receipts', 'ref' => 'wpr://1']],
            $this->passingGate(),
            $state,
        );

        $this->assertSame(
            ForgeWorkPacketExecutionCycleCanon::NEXT_ACTION_RUN_NEXT_PACKET,
            $cycle->next_action['kind'],
            'completion must point at the next packet when packets remain',
        );
    }

    /**
     * @return array{0:AiForgeIntake,1:AiForgeWorkPacket,2:AiForgeLongHorizonState}
     */
    private function bootstrap(): array
    {
        $intake = $this->readyIntake();
        $state = $this->longHorizon->initializeForIntake($intake);
        $packet = $this->cycles->selectPacket($intake, $state);
        $this->assertNotNull($packet);

        // Nominate as active so success transitions update both lists.
        $state = $this->longHorizon->recordCycle($state, [
            'active_work_packets' => [(string) $packet->packet_id],
        ]);

        return [$intake, $packet, $state];
    }

    private function readyIntake(): AiForgeIntake
    {
        return app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar refactor multi-modulo do provider router; depois migrar billing engine; por fim adicionar suite de tests de regressao.',
            [
                'workspace_slug' => 'atlas-server',
                'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate(),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function allowedWorkspaceExecutionGate(): array
    {
        return [
            'schema_version' => 'atlas.workspace_intelligence.execution_gate.v1',
            'mode' => 'forge',
            'workspace_id' => 'atlas-server',
            'allowed' => true,
            'status' => 'passed',
            'blockers' => [],
            'required_contracts' => [
                'awco_execution_readiness' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function passingGate(): array
    {
        return [
            'milestone_id' => ForgeIntakeCanon::MILESTONE_IMPLEMENTATION,
            'gates' => [
                ['gate_id' => 'work_packet_acceptance', 'status' => ForgeLongHorizonStateCanon::GATE_STATUS_PASSED, 'reason' => null],
            ],
            'evidence_present' => ['work_packet_receipts'],
            'evidence_missing' => [],
            'all_passed' => true,
            'failure_reasons' => [],
        ];
    }
}
