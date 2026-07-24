<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Http\Controllers\AtlasDev\Support\KernelRunExecutor;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\Dispatch\AutonomosLiveDispatcher;
use App\Services\Ai\Aaeos\Control\Dispatch\DevLiveDispatcher;
use App\Services\Ai\Aaeos\Control\Dispatch\ForgeLiveDispatcher;
use App\Services\Ai\Programming\AtlasDev\Execution\AtlasDevExecutionService;
use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutor;
use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraRuntime;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemon;
use Tests\TestCase;

final class AaeosDirectModeAblationTest extends TestCase
{
    public function test_direct_modes_invoke_their_real_container_owned_native_chains(): void
    {
        $cases = [
            AaeosExecutorMode::DEV => new DevLiveDispatcher(app(SeniorEngineerLoopExecutor::class)),
            AaeosExecutorMode::FORGE => new ForgeLiveDispatcher(app(ForgeObraRuntime::class)),
        ];

        foreach ($cases as $mode => $dispatcher) {
            $plan = $this->cyclePlan($mode);
            $options = [
                'live' => true,
                'workspace' => base_path(),
                'execute_provider' => false,
                ...($mode === AaeosExecutorMode::DEV
                    ? ['confirmed_dev_run' => $this->confirmedRun()]
                    : ['forge_commissioning' => $this->forgeCommissioning()]),
            ];
            $direct = $dispatcher->liveDispatch($plan, $options);

            self::assertSame('commissioned', $direct['status']);
            self::assertSame(0, $direct['provider_calls']);
            self::assertFalse($direct['mutation_performed']);
            self::assertArrayNotHasKey('adapter', $direct);
            self::assertArrayNotHasKey('next_commands', $direct);
            if ($mode === AaeosExecutorMode::DEV) {
                self::assertSame(SeniorEngineerLoopExecutor::class, $direct['commissioning']['owner']);
                self::assertSame(KernelRunExecutor::class, $direct['commissioning']['downstream']['owner']);
                self::assertSame(AtlasDevExecutionService::class, $direct['commissioning']['downstream']['downstream']['owner']);
            } else {
                self::assertSame(ForgeObraRuntime::class, $direct['commissioning']['runtime_owner']);
                self::assertTrue($direct['commissioning']['runtime_owner_invoked']);
            }
        }
    }

    public function test_direct_and_aaeos_routed_autonomos_share_the_same_claim_journey_refs(): void
    {
        $nativeWorker = new class implements AtlasNativeWorkerProductionRuntime
        {
            public int $claims = 0;

            public int $forbiddenEffects = 0;

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return [
                    'status' => 'served',
                    'client_id' => $clientId,
                    'authority_nonce' => 'native-nonce-ablation',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                    'lease_expires_at' => '2026-07-24T12:00:00+00:00',
                    'native_journey_ref' => 'native-owner-journey-ablation',
                    'native_cycle_refs' => ['native-owner-cycle-ablation'],
                    'task' => [
                        'task_packet_id' => 'task-ablation-1',
                        'lease_id' => 'lease-ablation-1',
                    ],
                ];
            }

            public function report(string $clientId, array $outcome): array
            {
                $this->forbiddenEffects++;

                return [];
            }

            public function materialize(array $patchPlan): array
            {
                $this->forbiddenEffects++;

                return [];
            }
        };
        $daemon = new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $nativeWorker);

        $intent = 'native autonomos parity';
        $direct = $daemon->run('claim', ['intent' => $intent]);
        $routed = (new AutonomosLiveDispatcher($daemon))->liveDispatch(
            $this->cyclePlan(AaeosExecutorMode::AUTONOMOS),
            ['live' => true],
        );

        self::assertFalse(method_exists($daemon, 'claimTask'));
        self::assertSame('claimed', $direct['status']);
        self::assertSame('claimed', $routed['status']);
        self::assertArrayNotHasKey('cycle_receipt_hash', $direct);
        self::assertArrayNotHasKey('daemon_cycle_hash', $direct);
        self::assertSame('native-owner-journey-ablation', $direct['native_journey_ref']);
        self::assertSame(['native-owner-cycle-ablation'], $direct['native_cycle_refs']);
        self::assertSame(['task-ablation-1'], $direct['native_task_refs']);
        self::assertSame(['lease-ablation-1'], $direct['native_lease_refs']);
        self::assertSame($direct['native_journey_ref'], $routed['native_journey_ref']);
        self::assertSame($direct['native_cycle_refs'], $routed['native_cycle_refs']);
        self::assertSame($direct['native_task_refs'], $routed['native_task_refs']);
        self::assertSame($direct['task'], $routed['task']);
        self::assertSame($direct, $routed['effects'][0]['result']);
        self::assertSame(2, $nativeWorker->claims);
        self::assertSame(0, $nativeWorker->forbiddenEffects);
        self::assertSame(0, $routed['provider_calls']);
        self::assertFalse($routed['task']['worker_executed']);
        self::assertFalse($routed['mutation_performed']);
    }

    /** @return array<string,mixed> */
    private function cyclePlan(string $mode): array
    {
        return [
            'objective' => ['objective' => 'native '.$mode.' parity'],
            'difficulty' => ['level' => $mode === AaeosExecutorMode::DEV ? 2 : 4],
            'admission' => ['allows_execution' => true],
            'world' => [],
        ];
    }

    private function confirmedRun(): ConfirmedDevRun
    {
        $intent = DevIntent::fromArray([
            'raw_goal' => 'native dev parity',
            'workspace' => base_path(),
            'operator_id' => 'native-owner',
            'product_intent_hash' => str_repeat('a', 64),
            'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64),
            'authority_hash' => str_repeat('d', 64),
            'risk_class' => 'R2',
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
            'prompt' => 'native forge parity',
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
