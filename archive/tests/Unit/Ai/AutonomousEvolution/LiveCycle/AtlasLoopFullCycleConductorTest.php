<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopFullCycleConductor;
use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopPhaseRunner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\CertificationFailedException;
use App\Services\Ai\AutonomousEvolution\LiveCycle\UnifiedReceiptChain;
use Tests\TestCase;

final class AtlasLoopFullCycleConductorTest extends TestCase
{
    private function happyRunner(string $phase, ?string $mergedSha = null): AtlasLoopPhaseRunner
    {
        return new class($phase, $mergedSha) implements AtlasLoopPhaseRunner
        {
            public int $calls = 0;

            public function __construct(private readonly string $phase, private readonly ?string $mergedSha) {}

            public function run(array $scope): array
            {
                $this->calls++;

                return $this->mergedSha === null
                    ? ['status' => 'ok', 'phase' => $this->phase]
                    : ['status' => 'ok', 'phase' => $this->phase, 'merged_sha' => $this->mergedSha];
            }
        };
    }

    private function failingRunner(): AtlasLoopPhaseRunner
    {
        return new class implements AtlasLoopPhaseRunner
        {
            public int $calls = 0;

            public function run(array $scope): array
            {
                $this->calls++;

                throw new CertificationFailedException('synthetic certify failure');
            }
        };
    }

    private function spyRunner(): AtlasLoopPhaseRunner
    {
        return new class implements AtlasLoopPhaseRunner
        {
            public int $calls = 0;

            public function run(array $scope): array
            {
                $this->calls++;

                return ['status' => 'ok'];
            }
        };
    }

    private function fakeChain(): UnifiedReceiptChain
    {
        return new class implements UnifiedReceiptChain
        {
            /** @var list<array{cycle_id:string, phase:string, receipt:array<string,mixed>}> */
            public array $records = [];

            public function record(string $cycleId, string $phase, array $receipt): void
            {
                $this->records[] = ['cycle_id' => $cycleId, 'phase' => $phase, 'receipt' => $receipt];
            }
        };
    }

    public function test_all_happy_phases_with_merged_sha_yield_completed_cycle(): void
    {
        $runners = [];
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $runners[$phase] = $this->happyRunner($phase, $phase === 'close' ? 'sha-abc123' : null);
        }
        $chain = $this->fakeChain();

        $result = (new AtlasLoopFullCycleConductor($runners, $chain))->runCycle(['cycle_id' => 'cycle-1']);

        $this->assertSame(AtlasLoopFullCycleConductor::STATUS_COMPLETED, $result['final_status']);
        $this->assertNull($result['aborted_at_phase']);
        $this->assertSame(AtlasLoopFullCycleConductor::PHASES, $result['phases_completed']);
        $this->assertSame('sha-abc123', $result['merged_sha']);
        $this->assertCount(8, $chain->records);
    }

    public function test_certify_failure_short_circuits_close_runner_is_never_invoked(): void
    {
        $runners = [];
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $runners[$phase] = match ($phase) {
                'certify' => $this->failingRunner(),
                'close' => $this->spyRunner(), // must NOT be called
                default => $this->happyRunner($phase),
            };
        }
        $chain = $this->fakeChain();

        $result = (new AtlasLoopFullCycleConductor($runners, $chain))->runCycle(['cycle_id' => 'cycle-2']);

        $this->assertSame(AtlasLoopFullCycleConductor::STATUS_ABORTED, $result['final_status']);
        $this->assertSame('certify', $result['aborted_at_phase']);
        $this->assertStringContainsString('synthetic certify failure', $result['abort_reason']);
        $this->assertSame(0, $runners['close']->calls, 'close runner must NEVER be invoked after certify failure');
        $this->assertNull($result['merged_sha']);
    }

    public function test_close_phase_without_merged_sha_is_an_aborted_cycle_no_proxy_success(): void
    {
        $runners = [];
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $runners[$phase] = $this->happyRunner($phase, null); // close emits no merged_sha
        }

        $result = (new AtlasLoopFullCycleConductor($runners, $this->fakeChain()))->runCycle(['cycle_id' => 'cycle-3']);

        $this->assertSame(AtlasLoopFullCycleConductor::STATUS_ABORTED, $result['final_status']);
        $this->assertSame('close', $result['aborted_at_phase']);
        $this->assertSame('close_phase_missing_merged_sha', $result['abort_reason']);
    }

    public function test_phase_runner_reporting_failed_status_aborts_without_exception(): void
    {
        $runners = [];
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $runners[$phase] = $phase === 'implement'
                ? new class implements AtlasLoopPhaseRunner
                {
                    public function run(array $scope): array
                    {
                        return ['status' => 'failed', 'reason' => 'no_diff_earned'];
                    }
                }
                : $this->happyRunner($phase, $phase === 'close' ? 'sha-x' : null);
        }

        $result = (new AtlasLoopFullCycleConductor($runners, $this->fakeChain()))->runCycle(['cycle_id' => 'cycle-4']);

        $this->assertSame(AtlasLoopFullCycleConductor::STATUS_ABORTED, $result['final_status']);
        $this->assertSame('implement', $result['aborted_at_phase']);
        $this->assertSame('no_diff_earned', $result['abort_reason']);
    }
}
