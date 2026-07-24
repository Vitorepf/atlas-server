<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction\RuntimeDaemon;

use App\Console\Commands\AtlasSelfConstructionRuntimeDaemonCommand;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemon;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonCycle;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeDaemonParityTest extends TestCase
{
    private string $factsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factsPath = storage_path('framework/testing/aaeos-runtime-daemon-parity.json');
        file_put_contents($this->factsPath, json_encode($this->facts(), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);

        parent::tearDown();
    }

    public function test_command_and_native_service_share_status_composition(): void
    {
        $native = app(AtlasSelfConstructionRuntimeDaemon::class)->run('status', $this->facts());

        $exit = Artisan::call('atlas:self-construction:runtime-daemon', [
            'action' => 'status',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $command = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame($native, $command);
        self::assertSame('atlas_native', $native['final_runtime_owner']);
        self::assertSame('atlas_server', $native['steady_state_runtime_owner']);
    }

    public function test_command_and_native_service_share_plan_composition(): void
    {
        $native = app(AtlasSelfConstructionRuntimeDaemon::class)->run('plan', $this->facts());

        $exit = Artisan::call('atlas:self-construction:runtime-daemon', [
            'action' => 'plan',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $command = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame($native, $command);
        self::assertSame($native['daemon_cycle_hash'], $command['daemon_cycle_hash']);
    }

    public function test_command_compatibility_shim_delegates_productive_cycle_composition(): void
    {
        $command = app(AtlasSelfConstructionRuntimeDaemonCommand::class);
        $method = new \ReflectionMethod($command, 'productiveCycle');
        $method->setAccessible(true);

        self::assertInstanceOf(AtlasSelfConstructionRuntimeDaemonCycle::class, $method->invoke($command));
        self::assertFalse(method_exists($command, 'resolveCallbacks'));
    }

    public function test_claim_is_available_only_through_run_and_projects_only_native_owner_refs(): void
    {
        $claimer = new class implements AtlasNativeWorkerProductionRuntime
        {
            public function claim(string $clientId): ?array
            {
                return [
                    'status' => 'served',
                    'authority_nonce' => 'native-nonce',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                    'lease_expires_at' => '2026-07-24T12:00:00+00:00',
                    'native_journey_ref' => 'native-owner-journey',
                    'native_cycle_refs' => ['native-owner-cycle'],
                    'task' => ['task_packet_id' => 'native-task', 'lease_id' => 'native-lease'],
                ];
            }

            public function report(string $clientId, array $outcome): array
            {
                throw new \LogicException('report_must_not_run');
            }

            public function materialize(array $patchPlan): array
            {
                throw new \LogicException('materialize_must_not_run');
            }
        };
        $daemon = new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer);
        $claim = $daemon->run('claim');

        self::assertFalse(method_exists($daemon, 'claimTask'));
        self::assertSame('claim', $claim['action']);
        self::assertSame('native-owner-journey', $claim['native_journey_ref']);
        self::assertSame(['native-owner-cycle'], $claim['native_cycle_refs']);
        self::assertSame(['native-task'], $claim['native_task_refs']);
        self::assertSame(['native-lease'], $claim['native_lease_refs']);
        self::assertArrayNotHasKey('cycle_receipt_hash', $claim);
        self::assertArrayNotHasKey('daemon_cycle_hash', $claim);
        self::assertSame(0, $claim['provider_calls']);
        self::assertFalse($claim['worker_executed']);
        self::assertFalse($claim['mutation_performed']);
    }

    public function test_command_dry_claim_withholds_the_native_owner_call(): void
    {
        $claimer = new class implements AtlasNativeWorkerProductionRuntime
        {
            public int $claims = 0;

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return null;
            }

            public function report(string $clientId, array $outcome): array
            {
                throw new \LogicException('report_must_not_run');
            }

            public function materialize(array $patchPlan): array
            {
                throw new \LogicException('materialize_must_not_run');
            }
        };
        $this->app->instance(
            AtlasSelfConstructionRuntimeDaemon::class,
            new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer),
        );

        $exit = Artisan::call('atlas:self-construction:runtime-daemon', [
            'action' => 'claim',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $claim = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame('planned', $claim['status']);
        self::assertTrue($claim['dry_run']);
        self::assertSame(0, $claimer->claims);
        self::assertNull($claim['native_journey_ref']);
        self::assertSame([], $claim['native_cycle_refs']);
        self::assertSame([], $claim['native_task_refs']);
        self::assertSame([], $claim['native_lease_refs']);
    }

    /** @return array<string,mixed> */
    private function facts(): array
    {
        return [
            'daemon_state' => [
                'status' => 'planned',
                'safety_stop' => false,
                'pause_requested' => false,
                'stop_requested' => false,
            ],
            'heartbeat_event' => [
                'type' => 'heartbeat',
                'now_at' => '2026-07-24T08:30:00-03:00',
            ],
            'planned_actions' => [['kind' => 'native_tick']],
        ];
    }
}
