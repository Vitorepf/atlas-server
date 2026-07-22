<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopClosePhaseRunner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopFullCycleConductor;
use Tests\TestCase;

// AtlasLoopPhaseRunner + UnifiedReceiptChain are sibling interfaces declared inside the conductor
// file. Force-load the conductor so PSR-4 doesn't try (and fail) to resolve those interfaces
// to their own files.
\class_exists(AtlasLoopFullCycleConductor::class);

final class AtlasLoopClosePhaseRunnerWiringWiredTest extends TestCase
{
    private function trivialPhase(array $extra = []): \App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopPhaseRunner
    {
        return new class($extra) implements \App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopPhaseRunner {
            public function __construct(private readonly array $extra) {}

            public function run(array $scope): array
            {
                return ['status' => 'ok'] + $this->extra;
            }
        };
    }

    private function chain(): \App\Services\Ai\AutonomousEvolution\LiveCycle\UnifiedReceiptChain
    {
        return new class implements \App\Services\Ai\AutonomousEvolution\LiveCycle\UnifiedReceiptChain {
            /** @var list<array{cycle:string,phase:string,receipt:array<string,mixed>}> */
            public array $recorded = [];

            public function record(string $cycleId, string $phase, array $receipt): void
            {
                $this->recorded[] = ['cycle' => $cycleId, 'phase' => $phase, 'receipt' => $receipt];
            }
        };
    }

    public function test_default_close_runner_factory_returns_orphan_class_and_runs(): void
    {
        $chain = $this->chain();
        $runnersWithoutClose = [
            'orient' => $this->trivialPhase(['task_packet_id' => 'P1', 'base_sha' => 'sha-base']),
            'comprehend' => $this->trivialPhase(),
            'leverage' => $this->trivialPhase(),
            'architect' => $this->trivialPhase(),
            'decompose' => $this->trivialPhase(),
            'implement' => $this->trivialPhase(),
            'certify' => $this->trivialPhase(['task_packet_id' => 'P1', 'base_sha' => 'sha-base', 'status' => 'certified']),
        ];

        $autoMergeCalled = 0;
        $autoMerge = function (array $certifyReceipt) use (&$autoMergeCalled): array {
            $autoMergeCalled++;
            return ['merged' => true, 'merge_result' => ['merge_sha' => 'sha-merged']];
        };
        $chainAppender = function (array $receipt): void { /* no-op for runner-internal append */ };

        $conductor = AtlasLoopFullCycleConductor::withDefaultClosePhase(
            $runnersWithoutClose,
            $chain,
            $autoMerge,
            $chainAppender,
            masterSwitchOverride: static fn (): bool => true,
        );

        $summary = $conductor->runCycle([
            'cycle_id' => 'C1',
            'task_packet_id' => 'P1',
            'base_sha' => 'sha-base',
            'status' => 'certified',
        ]);
        $this->assertSame(AtlasLoopFullCycleConductor::STATUS_COMPLETED, $summary['final_status']);
        $this->assertSame('sha-merged', $summary['merged_sha']);
        $this->assertSame(1, $autoMergeCalled, 'auto-merge delegate must have run from AtlasLoopClosePhaseRunner');

        $closeRecord = array_values(array_filter($chain->recorded, static fn (array $r): bool => $r['phase'] === 'close'));
        $this->assertCount(1, $closeRecord);
        $this->assertSame(AtlasLoopClosePhaseRunner::STATUS_MERGED, $closeRecord[0]['receipt']['status']);
        $this->assertSame('sha-merged', $closeRecord[0]['receipt']['merged_sha']);
    }

    public function test_master_switch_off_short_circuits_close_phase_via_orphan_runner(): void
    {
        $chain = $this->chain();
        $runnersWithoutClose = [
            'orient' => $this->trivialPhase(),
            'comprehend' => $this->trivialPhase(),
            'leverage' => $this->trivialPhase(),
            'architect' => $this->trivialPhase(),
            'decompose' => $this->trivialPhase(),
            'implement' => $this->trivialPhase(),
            'certify' => $this->trivialPhase(['status' => 'certified']),
        ];

        $autoMergeCalled = 0;
        $autoMerge = function () use (&$autoMergeCalled): array { $autoMergeCalled++; return ['merged' => true]; };

        $conductor = AtlasLoopFullCycleConductor::withDefaultClosePhase(
            $runnersWithoutClose,
            $chain,
            $autoMerge,
            static fn (array $r): null => null,
            masterSwitchOverride: static fn (): bool => false,
        );

        $summary = $conductor->runCycle(['cycle_id' => 'C2']);
        $this->assertSame(AtlasLoopFullCycleConductor::STATUS_ABORTED, $summary['final_status']);
        $this->assertSame(0, $autoMergeCalled, 'auto-merge delegate must NEVER fire when master switch is off');
    }
}
