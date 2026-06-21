<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Parallel;

use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerHandle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LoopWorkerHandleTest extends TestCase
{
    public function test_is_finished_returns_true_when_worker_is_no_longer_running(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getPid')->willReturn(1234);
        $process->expects($this->once())
            ->method('isRunning')
            ->willReturn(false);
        $process->expects($this->never())
            ->method('checkTimeout');

        $handle = new LoopWorkerHandle($process, 'task-1', 'worker-1');

        $this->assertTrue($handle->isFinished());
    }

    public function test_is_finished_returns_false_when_worker_keeps_running_after_timeout_check(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getPid')->willReturn(1234);
        $process->expects($this->exactly(2))
            ->method('isRunning')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('checkTimeout');

        $handle = new LoopWorkerHandle($process, 'task-1', 'worker-1');

        $this->assertFalse($handle->isFinished());
    }
}
