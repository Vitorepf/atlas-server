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
use App\Services\Ai\Programming\Forge\Execution\ForgeObraSupervisor;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionPort;
use App\Services\Ai\Programming\Forge\ForgeProviderLifecyclePort;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

final class ForgeObraRuntimeTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeLongHorizonStateTable();
        $this->reservationMigration()->up();
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
        $this->reservationMigration()->down();
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

    #[DataProvider('packetScaleFixture')]
    public function test_packet_scale_fixture_materializes_one_cycle_per_packet_without_duplicate_effect(int $packetCount): void
    {
        $parts = [];
        $workPackets = [];
        for ($position = 1; $position <= $packetCount; $position++) {
            $parts[] = 'Implementar o packet Forge '.$position.' com verificacao independente';
            $workPackets[] = [
                'id' => sprintf('scale-wp-%03d', $position),
                'title' => 'Scale packet '.$position,
                'objective' => 'Implementar o packet Forge '.$position,
                'scope' => 'fixture/packet-'.$position,
            ];
        }
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => implode('; ', $parts), 'workspace' => base_path(),
            'authority_hash' => str_repeat(dechex($packetCount), 64),
            'product_intent_hash' => str_repeat('b', 64), 'spec_hash' => str_repeat('c', 64),
            'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
            'work_packets' => $workPackets,
        ]);
        $this->app->instance(ForgeWorkPacketExecutionPort::class, new class($this->fixtureReleasedOutcome()) implements ForgeWorkPacketExecutionPort
        {
            public function __construct(private readonly EngineeringOutcome $outcome) {}

            public function execute(ExecutionOrder $order): EngineeringOutcome
            {
                return $this->outcome;
            }
        });
        $runtime = app(ForgeObraRuntime::class);
        $snapshot = $runtime->commission($commissioning);

        self::assertSame($packetCount, AiForgeWorkPacket::query()->where('intake_id', $snapshot->intakeId)->count());
        $cycleIds = [];
        $packetIds = [];
        for ($attempt = 0; $attempt < $packetCount; $attempt++) {
            $tick = $runtime->tick($snapshot->obra, ForgeTickBudget::fromArray([
                'max_packets' => 1, 'lease_seconds' => 900, 'allow_provider' => true,
            ]));
            self::assertSame('executed', $tick->status, (string) $tick->reason);
            $cycleIds[] = (string) $tick->cycleId;
            $packetIds[] = (string) $tick->packetId;
        }

        self::assertCount($packetCount, array_unique($cycleIds));
        self::assertCount($packetCount, array_unique($packetIds));
        self::assertSame($packetCount, AiForgeWorkPacketExecutionCycle::query()
            ->where('intake_id', $snapshot->intakeId)->count());
    }

    /** @return array<string,array{int}> */
    public static function packetScaleFixture(): array
    {
        return ['one_packet' => [1], 'three_packets' => [3], 'ten_packets' => [10]];
    }

    private function fixtureReleasedOutcome(): EngineeringOutcome
    {
        $roles = [];
        foreach (\App\Services\Ai\EngineeringKernel\EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $roles[$role] = [
                'status' => 'pass', 'evidence_hash' => hash('sha256', $role),
                'signature' => hash('sha256', 'sign-'.$role), 'receipt_ref' => 'role-event-'.$role,
                'receipt_event_hash' => hash('sha256', 'event-'.$role),
            ];
        }
        $hashes = [];
        foreach (['order', 'intent', 'spec', 'world', 'baseline', 'diff', 'evidence', 'release'] as $key) {
            $hashes[$key] = hash('sha256', 'fixture-'.$key);
        }

        return EngineeringOutcome::fromArray([
            'schema_version' => 'atlas.engineering_outcome.v2', 'run_id' => 'forge-scale-fixture',
            'delivery_id' => 'forge-scale-fixture', 'status' => 'released', 'correlated_hashes' => $hashes,
            'role_dispositions' => $roles,
            'evidence_bundle' => ['hash' => $hashes['evidence'], 'status' => 'accepted'],
            'provider_receipt' => ['status' => 'fixture'], 'sandbox_receipt' => ['status' => 'fixture'],
            'release_receipt' => ['status' => 'fixture', 'hash' => $hashes['release']],
            'canary_rollback_receipt' => ['status' => 'fixture'], 'operator_effort' => ['active_seconds' => 0],
            'cost' => ['amount' => 0, 'currency' => 'USD'], 'tokens' => ['input' => 0, 'output' => 0],
            'elapsed_ms' => 1, 'uncertainties' => [],
            'observation_schedule' => array_fill_keys(EngineeringOutcome::WINDOWS, 'pending'), 'claim_eligible' => false,
        ]);
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

        $operations = [];
        $this->app->instance(ForgeProviderLifecyclePort::class, new class($operations) implements ForgeProviderLifecyclePort
        {
            public function __construct(private array &$operations) {}

            public function start(array $request): array { $this->operations[] = 'start'; return ['status' => 'ready']; }
            public function poll(array $request): array { $this->operations[] = 'poll'; return ['status' => 'ready']; }
            public function heartbeat(array $request): array { $this->operations[] = 'heartbeat'; return ['status' => 'ready']; }
            public function cancel(array $request): array { $this->operations[] = 'cancel'; return ['status' => 'ready']; }
        });

        $started = $runtime->providerStart($snapshot->obra, (string) $cycle->uuid);
        self::assertSame('started', $started['status']);
        self::assertNotEmpty($started['provider_execution_id']);
        $startedReplay = $runtime->providerStart($snapshot->obra, (string) $cycle->uuid);
        self::assertTrue($startedReplay['replayed']);
        self::assertSame($started['provider_execution_id'], $startedReplay['provider_execution_id']);

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
        self::assertSame(['start', 'poll', 'heartbeat', 'cancel', 'cancel'], $operations);
    }

    public function test_unattended_supervisor_renews_provider_lifecycle_with_the_cycle_fence(): void
    {
        $this->app->instance(ForgeWorkPacketExecutionPort::class, new class implements ForgeWorkPacketExecutionPort
        {
            public function execute(ExecutionOrder $order): EngineeringOutcome
            {
                throw new \RuntimeException('fixture_provider_crash_after_start');
            }
        });

        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Implementar refactor multi-modulo do provider router e adicionar testes de regressao para supervisor Forge', 'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ]);
        $runtime = app(ForgeObraRuntime::class);
        $snapshot = $runtime->commission($commissioning);

        try {
            $runtime->tick($snapshot->obra, ForgeTickBudget::fromArray([
                'max_packets' => 1, 'lease_seconds' => 900, 'allow_provider' => true,
            ]));
            self::fail('fixture provider crash must interrupt the real tick');
        } catch (\RuntimeException $exception) {
            self::assertSame('fixture_provider_crash_after_start', $exception->getMessage());
        }

        $supervised = app(ForgeObraSupervisor::class)->run([$snapshot->intakeId], 900);

        self::assertSame('ok', $supervised['status']);
        self::assertCount(1, $supervised['heartbeats']);
        self::assertSame('ok', $supervised['heartbeats'][0]['provider_heartbeat']['status']);
        self::assertSame(1, $supervised['active_obra_count']);
        self::assertSame(0, $supervised['stale_heartbeat_count']);
    }

    private function reservationMigration(): object
    {
        return require database_path('migrations/2026_07_11_130000_create_atlas_task_scope_reservations_table.php');
    }
}
