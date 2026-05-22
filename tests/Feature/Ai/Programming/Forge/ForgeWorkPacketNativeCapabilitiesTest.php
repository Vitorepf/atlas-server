<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeMultiAgentSchedule;
use App\Models\AiForgeOutcomeMemory;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Models\AiForgeWorkPacketWorkcellRoute;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateCanon;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleService;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

class ForgeWorkPacketNativeCapabilitiesTest extends TestCase
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

    public function test_plan_execution_embeds_all_pre_execution_forge_native_blocks(): void
    {
        $intake = $this->readyIntake();
        $packet = $this->cycles->selectPacket($intake);
        $this->assertNotNull($packet);

        $plan = $this->cycles->planExecution($packet);
        $capabilities = $plan['plan']['forge_native_capabilities'] ?? null;

        $this->assertIsArray($capabilities);
        $this->assertSame('atlas.forge.work_packet_capabilities.v1', $capabilities['schema_version']);
        $this->assertSame('ready', $capabilities['status']);
        $this->assertSame(64, strlen((string) $capabilities['capability_hash']));

        foreach (['FWPIR', 'FOCG', 'FTIR', 'FSWR', 'FSORB', 'FOSG', 'FPPR', 'FOSR'] as $block) {
            $this->assertArrayHasKey($block, $capabilities['blocks']);
            $this->assertIsArray($capabilities['blocks'][$block]);
        }

        $this->assertSame('passed', $capabilities['blocks']['FOCG']['status']);
        $this->assertNotEmpty($capabilities['blocks']['FTIR']['commands']);
        $this->assertTrue($capabilities['blocks']['FPPR']['provider_safe']);
        $this->assertFalse($capabilities['claim_policy']['provider_calls_made']);
    }

    public function test_context_gate_blocks_high_risk_packet_without_context_or_likely_files(): void
    {
        $intake = $this->readyIntake();
        $packet = AiForgeWorkPacket::query()->create([
            'schema_version' => ForgeIntakeCanon::WORK_PACKET_SCHEMA_VERSION,
            'uuid' => 'packet-high-risk-no-context',
            'intake_id' => $intake->id,
            'packet_position' => 99,
            'packet_id' => 'wp-high-risk-no-context',
            'title' => 'Critical unknown rewrite',
            'objective' => 'Change unknown core behavior',
            'scope' => null,
            'expected_files' => null,
            'dependencies' => null,
            'risks' => ['unknown core impact'],
            'acceptance_criteria' => ['operator approves design'],
            'required_evidence' => ['work_packet_receipts'],
            'suggested_tests' => null,
            'status' => ForgeIntakeCanon::PACKET_STATUS_PROPOSED,
            'owner' => null,
            'role_slot' => null,
            'risk_band' => 'high',
            'packet_hash' => str_repeat('a', 64),
        ]);

        $plan = $this->cycles->planExecution($packet);
        $capabilities = $plan['plan']['forge_native_capabilities'];

        $this->assertSame('blocked', $capabilities['status']);
        $this->assertSame('blocked', $capabilities['blocks']['FOCG']['status']);
        $this->assertContains('likely_files_for_high_risk_packet', $capabilities['blocks']['FOCG']['missing']);
        $this->assertFalse($capabilities['blocks']['FPPR']['sendable']);
    }

    public function test_failure_transition_adds_failure_intelligence_capsule(): void
    {
        [$packet, $cycle, $state] = $this->runningCycle();

        $cycle = $this->cycles->fail(
            $cycle,
            'test_assertion_failed_in_provider_router',
            null,
            [['kind' => 'test_log', 'ref' => 'log://assertion']],
            $state,
        );

        $capsule = $cycle->repair_hook['failure_intelligence'] ?? null;
        // FFIR — Forge Failure Intelligence Runtime.
        $this->assertIsArray($capsule);
        $this->assertSame('atlas.forge.failure_intelligence.v1', $capsule['schema_version']);
        $this->assertSame('verification_failure', $capsule['failure_class']);
        $this->assertSame(ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_ADD_TESTS, $cycle->repair_hook['hint']);
        $this->assertSame((string) $packet->packet_id, $capsule['packet_id']);
        $this->assertSame(64, strlen((string) $capsule['failure_hash']));
        $this->assertIsArray($cycle->next_action['outcome_memory'] ?? null);
    }

    public function test_start_cycle_materializes_multi_workcell_schedule_and_packet_route(): void
    {
        [$packet, $cycle] = $this->runningCycle();

        $binding = $cycle->execution_plan['forge_workcell_schedule'] ?? null;
        $this->assertIsArray($binding);
        $this->assertSame('atlas.forge.workcell_schedule_binding.v1', $binding['schema_version']);
        $this->assertSame(64, strlen((string) $binding['schedule_hash']));
        $this->assertSame(64, strlen((string) $binding['route_hash']));

        $schedule = AiForgeMultiAgentSchedule::query()->find($binding['multi_agent_schedule_id']);
        $route = AiForgeWorkPacketWorkcellRoute::query()->find($binding['route_id']);

        $this->assertNotNull($schedule);
        $this->assertNotNull($route);
        $this->assertSame((string) $packet->packet_id, $route->work_packet_canonical_id);
        $this->assertSame($cycle->id, $route->execution_cycle_id);
        $this->assertSame($schedule->id, $route->multi_agent_schedule_id);
    }

    public function test_success_transition_adds_outcome_memory(): void
    {
        [$packet, $cycle, $state] = $this->runningCycle();

        $cycle = $this->cycles->complete(
            $cycle,
            [
                ['kind' => 'work_packet_receipts', 'ref' => 'wpr://1'],
                ['kind' => 'verification_receipt', 'ref' => 'vr://1'],
            ],
            $this->passingGate(),
            $state,
        );

        $memory = $cycle->next_action['outcome_memory'] ?? null;
        // FOMR — Forge Outcome Memory Runtime.
        $this->assertIsArray($memory);
        $this->assertSame('atlas.forge.outcome_memory.v1', $memory['schema_version']);
        $this->assertSame('success', $memory['outcome_status']);
        $this->assertContains('work_packet_receipts', $memory['evidence_kinds']);
        $this->assertTrue($memory['should_promote_to_aemor']);
        $this->assertSame((string) $packet->packet_id, $memory['packet_id']);

        $persisted = AiForgeOutcomeMemory::query()
            ->where('execution_cycle_id', $cycle->id)
            ->where('work_packet_canonical_id', (string) $packet->packet_id)
            ->first();

        $this->assertNotNull($persisted);
        $this->assertSame($persisted->id, $memory['memory_id']);
        $this->assertSame('success', $persisted->outcome_status);
        $this->assertSame(64, strlen((string) $persisted->outcome_memory_hash));
    }

    /**
     * @return array{0:AiForgeWorkPacket,1:AiForgeWorkPacketExecutionCycle,2:AiForgeLongHorizonState}
     */
    private function runningCycle(): array
    {
        $intake = $this->readyIntake();
        $state = $this->longHorizon->initializeForIntake($intake);
        $packet = $this->cycles->selectPacket($intake, $state);
        $this->assertNotNull($packet);
        $state = $this->longHorizon->recordCycle($state, [
            'active_work_packets' => [(string) $packet->packet_id],
        ]);

        $plan = $this->cycles->planExecution($packet);
        $cycle = $this->cycles->startCycle($intake, $packet, $plan, $state);

        return [$packet, $cycle, $state];
    }

    private function readyIntake(): AiForgeIntake
    {
        return app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar pacote Forge com testes e documentacao para provider router.',
            [
                'workspace_slug' => 'atlas-server',
                'context_refs' => [
                    'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
                    'app/Services/Ai/Programming/Forge',
                ],
                'work_packets' => [[
                    'id' => 'wp-forge-provider',
                    'title' => 'Forge provider router hardening',
                    'objective' => 'Implementar hardening do provider router do Forge',
                    'scope' => 'app/Services/Ai/Programming/Forge',
                    'expected_files' => [
                        'app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php',
                    ],
                    'suggested_tests' => [
                        'tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php',
                    ],
                    'acceptance_criteria' => [
                        'Focused tests pass',
                        'Evidence receipt attached',
                    ],
                    'required_evidence' => [
                        'work_packet_receipts',
                        'verification_receipt',
                    ],
                    'risk_band' => 'medium',
                ]],
            ],
        );
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
            'evidence_present' => ['work_packet_receipts', 'verification_receipt'],
            'evidence_missing' => [],
            'all_passed' => true,
            'failure_reasons' => [],
        ];
    }
}
