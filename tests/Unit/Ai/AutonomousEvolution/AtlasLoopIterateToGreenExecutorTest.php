<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIterateToGreenExecutor;
use PHPUnit\Framework\TestCase;

/**
 * The ADEP keystone: the loop runs the acceptance and, on red, re-invokes the provider WITH the exact
 * failure until green or budget — the test-fix-retest loop the single-shot lane never had.
 */
final class AtlasLoopIterateToGreenExecutorTest extends TestCase
{
    public function test_already_green_does_not_reinvoke_the_provider(): void
    {
        $reinvokes = 0;
        $out = (new AtlasLoopIterateToGreenExecutor())->pursue(
            fn (): array => ['passed' => true, 'output' => ''],
            function () use (&$reinvokes): void { $reinvokes++; },
            3,
        );

        $this->assertTrue($out['passed']);
        $this->assertTrue($out['initially_green']);
        $this->assertSame(0, $out['iterations']);
        $this->assertSame(0, $reinvokes, 'green on first run => the provider is never re-invoked');
    }

    public function test_iterates_until_green_feeding_the_failure_back(): void
    {
        // RED, RED, then GREEN — converges in 2 re-invocations; each gets the failure output.
        $sequence = [
            ['passed' => false, 'output' => 'class-not-found: FooSupport'],
            ['passed' => false, 'output' => 'undefined method bar()'],
            ['passed' => true, 'output' => ''],
        ];
        $i = 0;
        $failuresSeen = [];

        $out = (new AtlasLoopIterateToGreenExecutor())->pursue(
            function () use (&$i, $sequence): array { return $sequence[$i++]; },
            function (string $failure) use (&$failuresSeen): void { $failuresSeen[] = $failure; },
            5,
        );

        $this->assertTrue($out['passed']);
        $this->assertFalse($out['initially_green']);
        $this->assertSame(2, $out['iterations']);
        $this->assertSame(['class-not-found: FooSupport', 'undefined method bar()'], $failuresSeen, 'the EXACT failure is fed back each round');
    }

    public function test_gives_up_at_the_iteration_budget_without_green(): void
    {
        $reinvokes = 0;
        $out = (new AtlasLoopIterateToGreenExecutor())->pursue(
            fn (): array => ['passed' => false, 'output' => 'still red'],
            function () use (&$reinvokes): void { $reinvokes++; },
            3,
        );

        $this->assertFalse($out['passed']);
        $this->assertSame(3, $out['iterations'], 'bounded by the budget');
        $this->assertSame(3, $reinvokes);
    }
}
