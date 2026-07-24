<?php

namespace Tests\Feature\Ai\Aaeos;

use App\Models\AiDualCoreRouteDecision;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosLiveDispatchGateway;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosModeLiveDispatcher;
use App\Services\Ai\DualCore\DualCoreRouteDecisionService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Mockery;
use Tests\TestCase;

class AtlasAaeosRunCommandTest extends TestCase
{
    public function test_dry_run_port_emits_a_defined_successful_json_receipt(): void
    {
        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p0 dry run',
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful();
    }

    public function test_invalid_mode_is_a_non_zero_repair_required_run_receipt(): void
    {
        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p0 invalid mode',
            '--mode' => 'not_a_mode',
            '--dry-run' => true,
            '--json' => true,
        ])->assertFailed();
    }

    public function test_live_dispatch_failure_exits_non_zero(): void
    {
        $this->app->instance(AaeosCycleRuntime::class, $this->dispatchFailingRuntime());

        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p0 run dispatch failure',
            '--live' => true,
            '--json' => true,
        ])->assertFailed();
    }

    private function dispatchFailingRuntime(): AaeosCycleRuntime
    {
        $dispatcher = new class implements AaeosModeLiveDispatcher
        {
            public function mode(): string
            {
                return AaeosExecutorMode::DEV;
            }

            public function liveDispatch(array $cyclePlan, array $options = []): array
            {
                return [
                    'status' => 'dispatch_failed',
                    'effects' => [],
                    'next_commands' => [],
                    'provider_calls' => 0,
                    'error' => 'test_dispatch_failed',
                ];
            }
        };
        $decision = new AiDualCoreRouteDecision;
        $decision->setAttribute('id', 1);
        $decision->setAttribute('uuid', 'test-dualcore-run');
        $dualCore = Mockery::mock(DualCoreRouteDecisionService::class);
        $dualCore->shouldReceive('record')->once()->andReturn($decision);
        $ledger = Mockery::mock(AtlasEvidenceLedger::class);
        $ledger->shouldReceive('record')->once()->andReturn(null);

        return new AaeosCycleRuntime(
            liveGateway: new AaeosLiveDispatchGateway(dev: $dispatcher, dualCore: $dualCore),
            ledger: $ledger,
        );
    }
}
