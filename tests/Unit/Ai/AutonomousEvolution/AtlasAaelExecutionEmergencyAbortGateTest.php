<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionEmergencyAbortGate;
use Tests\TestCase;

class AtlasAaelExecutionEmergencyAbortGateTest extends TestCase
{
    private string $dir = '';

    private string $sentinel = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-aael-abort-'.bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o755, true);
        $this->sentinel = $this->dir.'/abort.flag';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_raise_then_check_reports_aborted_facts(): void
    {
        $gate = new AtlasAaelExecutionEmergencyAbortGate($this->sentinel);
        $gate->raise('operator_panic');

        $verdict = $gate->check();
        self::assertTrue($verdict['aborted']);
        self::assertSame('operator_panic', $verdict['reason']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $verdict['raised_at_iso']);
    }

    public function test_clear_removes_sentinel_and_check_reports_not_aborted(): void
    {
        $gate = new AtlasAaelExecutionEmergencyAbortGate($this->sentinel);
        $gate->raise('test');

        // Seed an "external safe-state checkpoint" fixture file that clear() must NOT touch.
        $checkpointPath = $this->dir.'/safe-state-checkpoint.bin';
        file_put_contents($checkpointPath, 'opaque-checkpoint-blob');
        $hashBefore = sha1_file($checkpointPath);

        self::assertTrue($gate->clear());
        self::assertFileDoesNotExist($this->sentinel);

        $verdict = $gate->check();
        self::assertFalse($verdict['aborted']);
        self::assertNull($verdict['reason']);
        self::assertNull($verdict['raised_at_iso']);

        // Safe-state checkpoint must be byte-identical.
        self::assertFileExists($checkpointPath);
        self::assertSame($hashBefore, sha1_file($checkpointPath), 'clear() must NOT touch the safe-state checkpoint file');
    }

    public function test_raise_uses_atomic_tmp_rename_and_leaves_no_tmp_leftover(): void
    {
        $gate = new AtlasAaelExecutionEmergencyAbortGate($this->sentinel);
        $gate->raise('panic-2');

        $leftovers = glob($this->sentinel.'.tmp.*') ?: [];
        self::assertSame([], $leftovers, 'no .tmp leftover may remain after atomic rename');
        self::assertFileExists($this->sentinel);
    }

    public function test_check_on_pristine_state_returns_not_aborted_without_throwing(): void
    {
        $verdict = (new AtlasAaelExecutionEmergencyAbortGate($this->sentinel))->check();

        self::assertFalse($verdict['aborted']);
        self::assertNull($verdict['reason']);
    }

    public function test_safe_state_checkpoint_id_is_round_tripped(): void
    {
        $gate = new AtlasAaelExecutionEmergencyAbortGate($this->sentinel);
        $gate->raise('panic-with-checkpoint', 'cp-123');
        $verdict = $gate->check();

        self::assertSame('cp-123', $verdict['safe_state_checkpoint_id']);
    }
}
