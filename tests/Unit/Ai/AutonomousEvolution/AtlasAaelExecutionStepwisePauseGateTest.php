<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\AtlasAaelExecutionStepwisePauseGate;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\PauseGateDecision;
use Tests\TestCase;

final class AtlasAaelExecutionStepwisePauseGateTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-pause-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_no_pause_armed_means_should_pause_is_false_for_all_steps(): void
    {
        $gate = new AtlasAaelExecutionStepwisePauseGate($this->root);

        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($gate->shouldPause('R', $i));
        }
    }

    public function test_arm_then_timeout_then_disarm_resumes(): void
    {
        $gate = new AtlasAaelExecutionStepwisePauseGate($this->root, pollIntervalMs: 1);
        $gate->arm('R', atStepIndex: 5);

        $this->assertTrue($gate->shouldPause('R', 5));
        $this->assertFalse($gate->shouldPause('R', 4));

        $decision = $gate->awaitResume('R', 5, timeoutMs: 50);
        $this->assertSame(PauseGateDecision::timed_out, $decision);

        $gate->disarm('R');
        $decision = $gate->awaitResume('R', 5, timeoutMs: 50);
        $this->assertSame(PauseGateDecision::resumed, $decision);
    }

    public function test_storage_error_returns_should_pause_false_and_emits_canonical_log(): void
    {
        $captured = [];
        $gate = new AtlasAaelExecutionStepwisePauseGate(
            storageRoot: $this->root,
            factLogger: function (string $line) use (&$captured): void { $captured[] = $line; },
            pollIntervalMs: 1,
        );

        // Plant a malformed payload — readState will throw inside shouldPause and fall back to false.
        file_put_contents($this->root.'/R.pause.json', 'not-json');

        $this->assertFalse($gate->shouldPause('R', 0));
        $this->assertNotEmpty($captured);
        $this->assertStringContainsString('pause_gate.fail_open', $captured[0]);
        $this->assertStringContainsString('run_id=R', $captured[0]);
        $this->assertStringContainsString('error_class=', $captured[0]);
    }
}
