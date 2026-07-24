<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosLiveDispatchGateway;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosModeLiveDispatcher;
use App\Services\Ai\DualCore\DualCoreRouteDecisionService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Models\AiDualCoreRouteDecision;
use Mockery;
use Tests\TestCase;

final class AaeosDryOutcomeRecorderAbsenceTest extends TestCase
{
    public function test_run_dry_does_not_call_outcome_recorder(): void
    {
        $recorder = Mockery::mock(AaeosCycleOutcomeRecorder::class);
        $recorder->shouldNotReceive('record');
        $this->app->instance(AaeosCycleOutcomeRecorder::class, $recorder);

        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p0 dry run',
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful();
    }

    public function test_cycle_dry_does_not_call_outcome_recorder(): void
    {
        $recorder = Mockery::mock(AaeosCycleOutcomeRecorder::class);
        $recorder->shouldNotReceive('record');
        $this->app->instance(AaeosCycleOutcomeRecorder::class, $recorder);

        $this->artisan('atlas:aaeos:cycle', [
            'intent' => 'p0 dry cycle',
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful();
    }

    public function test_dry_runtime_never_enters_gateway_that_can_record_a_dual_core_decision(): void
    {
        $dualCore = Mockery::mock(DualCoreRouteDecisionService::class);
        $dualCore->shouldNotReceive('record');
        $runtime = new AaeosCycleRuntime(
            liveGateway: new AaeosLiveDispatchGateway(dualCore: $dualCore),
        );

        $receipt = $runtime->runCycle('p0 pure dry runtime', [], [], true);

        $this->assertSame('plan_only', $receipt['dispatch']['live']['status']);
        $this->assertFalse($receipt['dispatch']['dualcore']['recorded']);
        $this->assertSame('not_attempted_dry_run', $receipt['dispatch']['dualcore']['status']);
    }

    public function test_live_preparation_with_effects_is_not_called_mutated_without_durable_proof(): void
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
                    'status' => 'dispatched_live',
                    'effects' => [['kind' => 'session_pack_prepared']],
                    'next_commands' => [],
                    'provider_calls' => 0,
                ];
            }
        };
        $decision = new AiDualCoreRouteDecision;
        $decision->setAttribute('id', 1);
        $decision->setAttribute('uuid', 'test-dualcore-decision');
        $dualCore = Mockery::mock(DualCoreRouteDecisionService::class);
        $dualCore->shouldReceive('record')->once()->andReturn($decision);
        $ledger = Mockery::mock(AtlasEvidenceLedger::class);
        $ledger->shouldReceive('record')->once()->andReturn(null);
        $runtime = new AaeosCycleRuntime(
            liveGateway: new AaeosLiveDispatchGateway(dev: $dispatcher, dualCore: $dualCore),
            ledger: $ledger,
        );

        $receipt = $runtime->runCycle('p0 live preparation proof', [
            'source' => 'human',
            'interactive' => true,
            'live_dispatch' => true,
        ]);

        $this->assertSame('dispatched_live', $receipt['status']);
        $this->assertSame('prepared', $receipt['effect_level']);
    }
}
