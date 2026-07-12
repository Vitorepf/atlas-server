<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraId;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraRuntime;
use App\Services\Ai\Programming\Forge\Execution\ForgeTickBudget;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use InvalidArgumentException;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

final class ForgeObraRuntimeTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeLongHorizonStateTable();
        $this->app->instance(AwisExecutionGatePort::class, new class implements AwisExecutionGatePort
        {
            /** @param array<int,string> $conversationTexts */
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return ['allowed' => true, 'status' => 'ready', 'mode' => $mode, 'blockers' => []];
            }
        });
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
        $this->assertSame(str_repeat('b', 64), $snapshot->productIntentHash);
        $this->assertSame(str_repeat('c', 64), $snapshot->specHash);
        $this->assertSame(str_repeat('d', 64), $snapshot->worldModelSnapshotHash);
        $this->assertNull($snapshot->marketDecisionHash);
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

    public function test_commission_fails_closed_when_workspace_execution_gate_denies_mutation(): void
    {
        $this->app->instance(AwisExecutionGatePort::class, new class implements AwisExecutionGatePort
        {
            /** @param array<int,string> $conversationTexts */
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return ['allowed' => false, 'status' => 'blocked', 'mode' => $mode, 'blockers' => ['workspace_not_ready']];
            }
        });

        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra que deve parar antes da persistencia', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forge_workspace_execution_blocked');

        app(ForgeObraRuntime::class)->commission($commissioning);
    }

    public function test_commissioning_binds_capability_market_decision_hash(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra com rota de capacidade comprovada', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'market_decision_hash' => str_repeat('e', 64), 'release_policy' => 'canonical_commit_with_canary',
            'interruption_policy' => 'pause_drain_resume', 'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        self::assertSame(str_repeat('e', 64), $commissioning->marketDecisionHash);
        self::assertSame($commissioning->marketDecisionHash, ForgeCommissioning::fromArray($commissioning->toArray())->marketDecisionHash);
    }

    public function test_snapshot_replays_world_and_market_hashes_after_reload(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra replay de bindings', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'market_decision_hash' => str_repeat('e', 64), 'release_policy' => 'canonical_commit_with_canary',
            'interruption_policy' => 'pause_drain_resume', 'risk_class' => 'R3', 'topology' => 'DAG',
        ]);
        $runtime = app(ForgeObraRuntime::class);
        $created = $runtime->commission($commissioning);
        $this->assertDatabaseHas('ai_forge_intakes', [
            'id' => $created->intakeId,
        ]);
        $persistedBinding = (array) \App\Models\AiForgeIntake::query()->findOrFail($created->intakeId)->rich_input_payload;
        self::assertSame(str_repeat('b', 64), $persistedBinding['product_intent_hash']);
        self::assertSame(str_repeat('c', 64), $persistedBinding['spec_hash']);
        self::assertSame(str_repeat('d', 64), $persistedBinding['world_model_snapshot_hash']);
        self::assertSame(str_repeat('e', 64), $persistedBinding['market_decision_hash']);
        $reloaded = $runtime->snapshot($created->obra);

        self::assertSame(str_repeat('b', 64), $reloaded->productIntentHash);
        self::assertSame(str_repeat('c', 64), $reloaded->specHash);
        self::assertSame(str_repeat('d', 64), $reloaded->worldModelSnapshotHash);
        self::assertSame(str_repeat('e', 64), $reloaded->marketDecisionHash);
    }
}
