<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use App\Services\Ai\Programming\Forge\Execution\ForgeControlCommand;
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
        $this->assertDatabaseHas('ai_forge_intakes', [
            'id' => $snapshot->intakeId,
            'commissioning_hash' => $commissioning->commissioningHash,
        ]);

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

    public function test_duplicate_commissioning_returns_the_same_obra_without_creating_a_second_intake(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra idempotente de reprocessamento', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        $runtime = app(ForgeObraRuntime::class);
        $first = $runtime->commission($commissioning);
        $second = $runtime->commission($commissioning);

        self::assertSame($first->obra->value, $second->obra->value);
        self::assertDatabaseCount('ai_forge_intakes', 1);
        self::assertDatabaseCount('ai_forge_long_horizon_states', 1);
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

    public function test_replayed_control_command_has_zero_duplicate_effect(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra com controle resiliente a retry', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        $runtime = app(ForgeObraRuntime::class);
        $commissioned = $runtime->commission($commissioning);

        $paused = $runtime->control($commissioned->obra, ForgeControlCommand::fromString('pause'));
        $pausedReplay = $runtime->control($commissioned->obra, ForgeControlCommand::fromString('pause'));
        $stateAfterPause = AiForgeLongHorizonState::query()->where('intake_id', $commissioned->intakeId)->firstOrFail();

        self::assertSame(1, $stateAfterPause->cycle_count);
        self::assertSame($paused->stateHash, $pausedReplay->stateHash);

        $runtime->control($commissioned->obra, ForgeControlCommand::fromString('resume'));
        $resumedReplay = $runtime->control($commissioned->obra, ForgeControlCommand::fromString('resume'));
        $stateAfterResume = $stateAfterPause->fresh();

        self::assertSame(2, $stateAfterResume?->cycle_count);
        self::assertSame($resumedReplay->stateHash, $stateAfterResume?->state_hash);
    }

    public function test_drain_and_cancel_controls_are_replay_safe(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra com drain e cancel replay-safe', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        $runtime = app(ForgeObraRuntime::class);
        $commissioned = $runtime->commission($commissioning);

        $drained = $runtime->control($commissioned->obra, ForgeControlCommand::fromString('drain'));
        $drainedReplay = $runtime->control($commissioned->obra, ForgeControlCommand::fromString('drain'));
        self::assertSame($drained->stateHash, $drainedReplay->stateHash);

        $drainedTick = $runtime->tick($commissioned->obra, ForgeTickBudget::fromArray([
            'max_packets' => 1, 'lease_seconds' => 900, 'allow_provider' => false,
        ]));
        self::assertSame('blocked', $drainedTick->status);
        self::assertSame('forge_control_drain', $drainedTick->reason);

        $cancelled = $runtime->control($commissioned->obra, ForgeControlCommand::fromString('cancel'));
        $cancelledReplay = $runtime->control($commissioned->obra, ForgeControlCommand::fromString('cancel'));
        self::assertSame($cancelled->stateHash, $cancelledReplay->stateHash);

        $state = AiForgeLongHorizonState::query()->where('intake_id', $commissioned->intakeId)->firstOrFail();
        $reasons = collect((array) $state->blockers)->pluck('reason')->all();
        self::assertContains('forge_control_drain', $reasons);
        self::assertContains('forge_control_cancel', $reasons);
    }

    public function test_orphaned_running_cycle_is_recovered_once_without_duplicate_transition(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra com recovery de ciclo orfao', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        $runtime = app(ForgeObraRuntime::class);
        $commissioned = $runtime->commission($commissioning);
        $tick = $runtime->tick($commissioned->obra, ForgeTickBudget::fromArray([
            'max_packets' => 1, 'lease_seconds' => 900, 'allow_provider' => false,
        ]));

        $recovered = $runtime->recoverOrphanedCycle($commissioned->obra, $tick->cycleId);
        $replay = $runtime->recoverOrphanedCycle($commissioned->obra, $tick->cycleId);

        self::assertTrue($recovered['recovered']);
        self::assertSame('recovered', $recovered['status']);
        self::assertFalse($replay['recovered']);
        self::assertSame('no_running_cycle', $replay['reason']);
        self::assertSame('blocked', AiForgeWorkPacketExecutionCycle::query()
            ->where(function ($query) use ($tick): void {
                $query->where('uuid', $tick->cycleId)->orWhere('id', $tick->cycleId);
            })
            ->value('status'));
    }

    public function test_heartbeat_is_fail_closed_when_obra_has_no_running_cycle(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Obra sem ciclo ativo para heartbeat', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);

        $commissioned = app(ForgeObraRuntime::class)->commission($commissioning);
        $heartbeat = app(ForgeObraRuntime::class)->heartbeat($commissioned->obra);

        self::assertSame('atlas.forge.heartbeat.v1', $heartbeat['schema']);
        self::assertSame('idle', $heartbeat['status']);
        self::assertFalse($heartbeat['renewed']);
        self::assertSame('no_running_cycle', $heartbeat['reason']);
    }

    public function test_provider_lifecycle_persists_start_poll_heartbeat_and_fenced_cancel(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Implementar refactor multi-modulo do provider router e adicionar testes de regressao para o lifecycle Forge', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);
        $runtime = app(ForgeObraRuntime::class);
        $snapshot = $runtime->commission($commissioning);
        $tick = $runtime->tick($snapshot->obra, ForgeTickBudget::fromArray([
            'max_packets' => 1, 'lease_seconds' => 900, 'allow_provider' => false,
        ]));

        $cycle = AiForgeWorkPacketExecutionCycle::query()
            ->where(function ($query) use ($tick): void {
                $query->where('uuid', $tick->cycleId)->orWhere('id', $tick->cycleId);
            })
            ->firstOrFail();
        $plan = (array) $cycle->execution_plan;
        $plan['scope_reservation'] = [
            'id' => 'reservation-provider-test', 'lease_owner' => 'forge-obra-runtime',
            'lease_token' => 'provider-test-token', 'fencing_token' => 7,
        ];
        $cycle->forceFill(['execution_mode' => 'real', 'execution_plan' => $plan])->save();

        $started = $runtime->providerStart($snapshot->obra, (string) $cycle->uuid);
        self::assertSame('started', $started['status']);
        self::assertNotEmpty($started['provider_execution_id']);

        $polled = $runtime->providerPoll($snapshot->obra, (string) $cycle->uuid, $started['fencing_token']);
        self::assertSame('running', $polled['status']);

        $heartbeat = $runtime->providerHeartbeat($snapshot->obra, (string) $cycle->uuid, $started['fencing_token']);
        self::assertSame('ok', $heartbeat['status']);

        $cancelled = $runtime->providerCancel($snapshot->obra, (string) $cycle->uuid, $started['fencing_token'], 'test_cancel');
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('test_cancel', $cancelled['reason']);

        $replay = $runtime->providerCancel($snapshot->obra, (string) $cycle->uuid, $started['fencing_token'], 'test_cancel');
        self::assertSame('cancelled', $replay['status']);
        self::assertTrue($replay['replayed']);
    }
}
