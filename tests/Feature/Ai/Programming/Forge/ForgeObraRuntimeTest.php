<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraId;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraRuntime;
use App\Services\Ai\Programming\Forge\Execution\ForgeTickBudget;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

final class ForgeObraRuntimeTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeLongHorizonStateTable();
    }

    protected function tearDown(): void
    {
        $this->dropForgeLongHorizonStateTable();
        parent::tearDown();
    }

    public function test_commission_persists_obra_state_and_tick_plans_a_safe_packet_through_injected_cycle_service(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Implementar refactor multi-modulo do provider router e adicionar testes de regressao',
            'workspace' => base_path(), 'authority_hash' => str_repeat('a', 64),
            'product_intent_hash' => str_repeat('b', 64), 'spec_hash' => str_repeat('c', 64),
            'world_model_snapshot_hash' => str_repeat('d', 64), 'release_policy' => 'canonical_commit_with_canary',
            'interruption_policy' => 'pause_drain_resume', 'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        $runtime = app(ForgeObraRuntime::class);
        $snapshot = $runtime->commission($commissioning);

        $this->assertSame('active', $snapshot->status);
        $this->assertDatabaseCount('ai_forge_intakes', 1);
        $this->assertDatabaseCount('ai_forge_long_horizon_states', 1);

        $tick = $runtime->tick(ForgeObraId::fromString($snapshot->obra->value), ForgeTickBudget::fromArray([
            'max_packets' => 1, 'lease_seconds' => 900, 'allow_provider' => false,
        ]));

        $this->assertSame('planned', $tick->status);
        $this->assertNotNull($tick->cycleId);
        $this->assertDatabaseCount('ai_forge_work_packet_execution_cycles', 1);
        $packet = AiForgeWorkPacket::query()->where('packet_id', $tick->packetId)->firstOrFail();
        $this->assertSame('safe_simulation', AiForgeWorkPacketExecutionCycle::query()->where('work_packet_id', $packet->id)->firstOrFail()->execution_mode);
    }
}
