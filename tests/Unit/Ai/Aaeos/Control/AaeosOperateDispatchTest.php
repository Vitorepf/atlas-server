<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosLiveDispatchGateway;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosModeLiveDispatcher;
use App\Services\Ai\Aaeos\Control\Dispatch\DevLiveDispatcher;
use App\Services\Ai\Aaeos\Control\Dispatch\ForgeLiveDispatcher;
use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use Tests\TestCase;

final class AaeosOperateDispatchTest extends TestCase
{
    public function test_live_dev_dispatch_commissions_native_chain_via_gateway(): void
    {
        $gateway = new AaeosLiveDispatchGateway(dev: new DevLiveDispatcher);
        $result = $gateway->dispatch(AaeosExecutorMode::DEV, [
            'objective' => ['objective' => 'fix flaky login', 'raw' => 'fix flaky login'],
            'difficulty' => ['level' => 1],
            'admission' => ['allows_execution' => true],
        ], ['live' => true, 'workspace' => base_path(), 'confirmed_dev_run' => $this->confirmedRun()]);

        $this->assertSame('commissioned', $result['status']);
        $this->assertNotEmpty($result['effects']);
        $this->assertSame('native_dev_commissioned', $result['effects'][0]['kind']);
        $this->assertSame('prepared', $result['commissioning']['status']);
        $this->assertFalse($result['mutation_performed']);
    }

    public function test_live_forge_dispatch_commissions_canonical_runtime(): void
    {
        $gateway = new AaeosLiveDispatchGateway(forge: new ForgeLiveDispatcher);
        $result = $gateway->dispatch(AaeosExecutorMode::FORGE, [
            'objective' => ['objective' => 'multi packet obra auth', 'raw' => 'obra'],
            'difficulty' => ['level' => 4],
        ], ['live' => true, 'workspace' => base_path(), 'forge_commissioning' => $this->forgeCommissioning()]);

        $this->assertSame('commissioned', $result['status']);
        $this->assertSame('native_forge_commissioned', $result['effects'][0]['kind']);
        $this->assertSame('commissioning_only', $result['commissioning']['authority_status']);
        $this->assertFalse($result['mutation_performed']);
    }

    public function test_plan_only_does_not_call_custom_autonomos_dispatcher(): void
    {
        $spy = new class implements AaeosModeLiveDispatcher
        {
            public int $calls = 0;

            public function mode(): string
            {
                return AaeosExecutorMode::AUTONOMOS;
            }

            public function liveDispatch(array $cyclePlan, array $options = []): array
            {
                $this->calls++;

                return ['status' => 'dispatched_live', 'effects' => [], 'provider_calls' => 0];
            }
        };

        $gateway = new AaeosLiveDispatchGateway(autonomos: $spy);
        $result = $gateway->dispatch(AaeosExecutorMode::AUTONOMOS, [
            'objective' => ['objective' => 'x'],
        ], ['live' => false, 'plan_only' => true]);

        $this->assertSame('plan_only', $result['status']);
        $this->assertSame(0, $spy->calls);
    }

    public function test_cycle_live_dev_marks_commissioned_without_execution(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runCycle('fix validation edge', [
            'source' => 'human',
            'interactive' => true,
            'live_dispatch' => true,
            'workspace' => base_path(),
            'confirmed_dev_run' => $this->confirmedRun(),
        ], [], false);

        $this->assertSame(AaeosExecutorMode::DEV, $receipt['mode']['mode']);
        $this->assertTrue($receipt['live_dispatch']);
        $this->assertSame('commissioned', $receipt['status']);
        $this->assertSame('native_dev_commissioned', $receipt['dispatch']['effects'][0]['kind'] ?? null);
    }

    public function test_cycle_maps_live_refusal_to_terminal_failure(): void
    {
        $refusing = new class implements AaeosModeLiveDispatcher
        {
            public function mode(): string
            {
                return AaeosExecutorMode::DEV;
            }

            public function liveDispatch(array $cyclePlan, array $options = []): array
            {
                return ['status' => 'dispatch_skipped', 'effects' => [], 'provider_calls' => 0];
            }
        };
        $runtime = new AaeosCycleRuntime(
            liveGateway: new AaeosLiveDispatchGateway(dev: $refusing),
        );
        $receipt = $runtime->runCycle('refuse unavailable native authority', [
            'source' => 'human',
            'interactive' => true,
            'live_dispatch' => true,
        ], ['force_mode' => AaeosExecutorMode::DEV], false);

        self::assertSame('dispatch_failed', $receipt['status']);
        self::assertSame('blocked', $receipt['effect_level']);
    }

    public function test_dry_run_cycle_stays_compatible(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runAutonomosCycle('evolve with proof', [], true);
        $this->assertSame('dispatched', $receipt['status']);
        $this->assertTrue($receipt['dry_run']);
        $this->assertFalse($receipt['live_dispatch']);
        $this->assertSame(AaeosExecutorMode::AUTONOMOS, $receipt['dispatch']['mode']);
        $this->assertSame([], $receipt['dispatch']['effects']);
        $this->assertArrayNotHasKey('next_commands', $receipt);
    }

    private function confirmedRun(): ConfirmedDevRun
    {
        $intent = DevIntent::fromArray([
            'raw_goal' => 'fix flaky login',
            'workspace' => base_path(),
            'operator_id' => 'native-owner',
            'product_intent_hash' => str_repeat('a', 64),
            'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64),
            'authority_hash' => str_repeat('d', 64),
            'risk_class' => 'R1',
            'duration_regime' => 'interactive',
            'topology' => 'single',
            'mutate' => false,
            'constraints' => [],
        ]);

        return ConfirmedDevRun::fromIntent($intent, $intent->operatorId, $intent->authorityHash);
    }

    private function forgeCommissioning(): ForgeCommissioning
    {
        return ForgeCommissioning::fromArray([
            'prompt' => 'multi packet obra auth',
            'workspace' => base_path(),
            'authority_hash' => str_repeat('a', 64),
            'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64),
            'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => ForgeCommissioning::RELEASE_POLICY_CANONICAL_COMMIT_WITH_CANARY,
            'interruption_policy' => ForgeCommissioning::INTERRUPTION_POLICY_PAUSE_DRAIN_RESUME,
            'risk_class' => 'R4',
            'topology' => 'DAG',
        ]);
    }
}
