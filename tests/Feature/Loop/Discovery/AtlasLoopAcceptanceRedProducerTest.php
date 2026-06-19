<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopAcceptanceRedProducer;
use Tests\TestCase;

/**
 * §11.3 — obra earned-RED bridge. Each test runs REAL shell commands (no mocks) so the earned-RED
 * verdict is proven against an actual process exit code, and pins EXACT booleans + the null
 * fail-closed so the suite fails if the producer were broken (e.g. inverted red, or accepting a
 * node with no runnable acceptance). Commands are fixed (true / false / php -r exit) so the test
 * is deterministic, not flaky.
 */
final class AtlasLoopAcceptanceRedProducerTest extends TestCase
{
    private AtlasLoopAcceptanceRedProducer $producer;

    /** A real, writable directory to run the acceptance command in. */
    private string $cwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producer = new AtlasLoopAcceptanceRedProducer();
        $this->cwd = sys_get_temp_dir();
    }

    public function test_failing_command_is_earned_red(): void
    {
        // `false` always exits non-zero ⇒ the acceptance currently FAILS ⇒ earned RED.
        $out = $this->producer->verifyRed('false', $this->cwd);

        $this->assertTrue($out['ran']);
        $this->assertTrue($out['red']);
        $this->assertNotSame(0, $out['exit']);
        $this->assertSame('false', $out['command']);
    }

    public function test_failing_php_command_is_earned_red_with_exact_exit(): void
    {
        // Deterministic non-zero exit via a fixed PHP one-liner.
        $out = $this->producer->verifyRed('php -r "exit(1);"', $this->cwd);

        $this->assertTrue($out['red']);
        $this->assertSame(1, $out['exit']);
    }

    public function test_passing_command_is_not_red(): void
    {
        // `true` always exits 0 ⇒ already GREEN ⇒ NOT a valid acceptance-RED.
        $out = $this->producer->verifyRed('true', $this->cwd);

        $this->assertTrue($out['ran']);
        $this->assertFalse($out['red']);
        $this->assertSame(0, $out['exit']);
        $this->assertSame('true', $out['command']);
    }

    public function test_passing_php_command_is_not_red_with_exact_exit(): void
    {
        $out = $this->producer->verifyRed('php -r "exit(0);"', $this->cwd);

        $this->assertFalse($out['red']);
        $this->assertSame(0, $out['exit']);
    }

    public function test_failing_criterion_builds_earned_red_acceptance_artifact(): void
    {
        $out = $this->producer->toEarnedRedAcceptance([
            'objective' => 'Calculator divides without an off-by-one',
            'acceptance_command' => 'php -r "exit(1);"',
        ], $this->cwd);

        $this->assertNotNull($out);
        $this->assertSame('obra_node', $out['shape']);
        $this->assertSame('Calculator divides without an off-by-one', $out['objective']);

        // Exact acceptance artifact shape: the command fused into a single-command set, red required.
        $this->assertSame(['php -r "exit(1);"'], $out['acceptance']['commands']);
        $this->assertTrue($out['acceptance']['red_required']);

        // The command currently FAILS ⇒ earned_red true (verified, not asserted).
        $this->assertTrue($out['earned_red']);
    }

    public function test_passing_criterion_is_not_earned_red(): void
    {
        $out = $this->producer->toEarnedRedAcceptance([
            'objective' => 'Already-green criterion',
            'acceptance_command' => 'php -r "exit(0);"',
        ], $this->cwd);

        $this->assertNotNull($out);
        $this->assertSame(['php -r "exit(0);"'], $out['acceptance']['commands']);
        $this->assertTrue($out['acceptance']['red_required']);

        // The command already PASSES ⇒ NOT a valid acceptance-RED ⇒ earned_red false.
        $this->assertFalse($out['earned_red']);
    }

    public function test_criterion_with_no_acceptance_command_is_fail_closed_null(): void
    {
        // Fail-closed: a node with no runnable acceptance is never accepted (field presence ≠ contract).
        $out = $this->producer->toEarnedRedAcceptance([
            'objective' => 'No runnable acceptance handle',
        ], $this->cwd);

        $this->assertNull($out);
    }

    public function test_criterion_with_blank_acceptance_command_is_fail_closed_null(): void
    {
        // Whitespace-only command is treated as no command — still fail-closed.
        $out = $this->producer->toEarnedRedAcceptance([
            'objective' => 'Blank acceptance',
            'acceptance_command' => '   ',
        ], $this->cwd);

        $this->assertNull($out);
    }
}
