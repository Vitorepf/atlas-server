<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopPauseResumeCommand;
use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopCycleSignalEmitter;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseFlag;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseResumeSignalBridge;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class AtlasLoopPauseResumeCommandTest extends TestCase
{
    private string $sentinelPath = '';

    private string $storageDir = '';

    private AtlasLoopCyclePauseFlag $flag;

    protected function setUp(): void
    {
        parent::setUp();
        // Redirect storage so the cycle-signal sink (storage_path('app/atlas-loop/signals/..')) is isolated.
        $this->storageDir = sys_get_temp_dir().'/atlas-pause-storage-'.bin2hex(random_bytes(6));
        @mkdir($this->storageDir, 0o755, true);
        $this->app->useStoragePath($this->storageDir);

        $this->sentinelPath = sys_get_temp_dir().'/atlas-pause-cli-'.bin2hex(random_bytes(6)).'.json';
        $this->flag = new AtlasLoopCyclePauseFlag($this->sentinelPath);
        $this->app->instance(AtlasLoopPauseResumeCommand::PAUSE_FLAG_BINDING, $this->flag);
    }

    protected function tearDown(): void
    {
        @unlink($this->sentinelPath);
        $this->rmrf($this->storageDir);
        parent::tearDown();
    }

    /** Bind a signal bridge sharing the test pause flag, with a controllable master gate. */
    private function bindBridge(bool $masterOn): void
    {
        $this->app->instance(
            AtlasLoopCyclePauseResumeSignalBridge::class,
            new AtlasLoopCyclePauseResumeSignalBridge(new AtlasLoopCycleSignalEmitter, $this->flag, fn (): bool => $masterOn),
        );
    }

    /** @return list<array<string,mixed>> all emitted cycle signals from the redirected sink */
    private function readSignals(): array
    {
        $out = [];
        foreach (glob($this->storageDir.'/app/atlas-loop/signals/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }

        return $out;
    }

    private function signalsForStage(string $stage): array
    {
        return array_values(array_filter($this->readSignals(), static fn (array $s): bool => ($s['stage'] ?? '') === $stage));
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function runCli(string $action, array $options = []): array
    {
        $opts = ['action' => $action, '--json' => true];
        foreach ($options as $k => $v) {
            $opts['--'.$k] = $v;
        }
        $exit = Artisan::call('atlas:loop:pause-resume', $opts);
        $payload = json_decode(trim(Artisan::output()), true);

        return ['exit' => $exit, 'payload' => is_array($payload) ? $payload : []];
    }

    public function test_pause_status_resume_round_trip(): void
    {
        $paused = $this->runCli('pause', ['cycle' => 'cyc-X', 'phase' => 'verify', 'reason' => 'operator']);
        self::assertSame(0, $paused['exit']);
        self::assertSame('raised', $paused['payload']['outcome']);
        self::assertSame('cyc-X', $paused['payload']['sentinel']['cycle_id']);

        $status = $this->runCli('status');
        self::assertSame(0, $status['exit']);
        self::assertSame('raised', $status['payload']['outcome']);
        self::assertSame('cyc-X', $status['payload']['sentinel']['cycle_id']);

        $resumed = $this->runCli('resume', ['cycle' => 'cyc-X', 'phase' => 'verify']);
        self::assertSame(0, $resumed['exit']);
        self::assertSame('resumed', $resumed['payload']['outcome']);
        self::assertSame('verify', $resumed['payload']['resumed_phase']);

        // After resume, sentinel is lowered.
        $statusAfter = $this->runCli('status');
        self::assertSame('not_raised', $statusAfter['payload']['outcome']);
    }

    public function test_clear_lowers_existing_sentinel_and_noop_when_absent(): void
    {
        $this->runCli('pause', ['cycle' => 'cyc-Y', 'phase' => 'plan', 'reason' => 'r']);
        $cleared = $this->runCli('clear');
        self::assertSame(0, $cleared['exit']);
        self::assertSame('cleared', $cleared['payload']['outcome']);

        $noop = $this->runCli('clear');
        self::assertSame(0, $noop['exit']);
        self::assertSame('noop', $noop['payload']['outcome']);
    }

    public function test_pause_refuses_missing_options(): void
    {
        $r = $this->runCli('pause', ['cycle' => 'cyc-Z']);
        self::assertSame(1, $r['exit']);
        self::assertSame('refused', $r['payload']['outcome']);
        self::assertSame('missing_required_options', $r['payload']['reason']);
    }

    public function test_resume_refuses_when_sentinel_absent(): void
    {
        $r = $this->runCli('resume', ['cycle' => 'cyc-W', 'phase' => 'verify']);
        self::assertSame(1, $r['exit']);
        self::assertSame('refused', $r['payload']['outcome']);
        self::assertSame('missing_pause_sentinel', $r['payload']['reason']);
    }

    public function test_unknown_action_returns_structured_refusal(): void
    {
        $r = $this->runCli('bogus');
        self::assertSame(1, $r['exit']);
        self::assertSame('refused', $r['payload']['outcome']);
        self::assertSame('unknown_action', $r['payload']['reason']);
    }

    public function test_pause_emits_cycle_paused_signal_when_master_on(): void
    {
        $this->bindBridge(true);

        $paused = $this->runCli('pause', ['cycle' => 'cyc-S', 'phase' => 'verify', 'reason' => 'operator']);

        // The command's own JSON/outcome is unchanged by the signal wiring.
        self::assertSame(0, $paused['exit']);
        self::assertSame('raised', $paused['payload']['outcome']);
        self::assertSame('cyc-S', $paused['payload']['sentinel']['cycle_id']);

        $signals = $this->signalsForStage(AtlasLoopCyclePauseResumeSignalBridge::STAGE_PAUSED);
        self::assertCount(1, $signals, (string) json_encode($this->readSignals()));
        self::assertSame('cyc-S', $signals[0]['cycle_id']);
        self::assertSame('cyc-S', $signals[0]['payload']['cycle_id']);
        self::assertSame('verify', $signals[0]['payload']['phase_at_pause']);
        self::assertSame('operator', $signals[0]['payload']['reason']);
    }

    public function test_resume_emits_resumed_and_clear_emits_pause_lowered(): void
    {
        $this->bindBridge(true);

        // resume on a resumed outcome ⇒ cycle.resumed
        $this->runCli('pause', ['cycle' => 'cyc-R', 'phase' => 'verify', 'reason' => 'operator']);
        $this->runCli('resume', ['cycle' => 'cyc-R', 'phase' => 'verify']);
        $resumed = $this->signalsForStage(AtlasLoopCyclePauseResumeSignalBridge::STAGE_RESUMED);
        self::assertCount(1, $resumed, (string) json_encode($this->readSignals()));
        self::assertSame('cyc-R', $resumed[0]['cycle_id']);
        self::assertTrue($resumed[0]['payload']['integrity_ok']);

        // clear that lowers a sentinel ⇒ cycle.pause_lowered naming the cleared cycle
        $this->runCli('pause', ['cycle' => 'cyc-C', 'phase' => 'plan', 'reason' => 'operator']);
        $this->runCli('clear');
        $lowered = $this->signalsForStage(AtlasLoopCyclePauseResumeSignalBridge::STAGE_PAUSE_LOWERED);
        self::assertCount(1, $lowered, (string) json_encode($this->readSignals()));
        self::assertSame('cyc-C', $lowered[0]['payload']['cycle_id']);
    }

    public function test_master_off_emits_no_signal_byte_identical(): void
    {
        $this->bindBridge(false);

        $paused = $this->runCli('pause', ['cycle' => 'cyc-OFF', 'phase' => 'verify', 'reason' => 'operator']);

        // command behaviour unchanged...
        self::assertSame(0, $paused['exit']);
        self::assertSame('raised', $paused['payload']['outcome']);
        // ...but with the master switch OFF the bridge is a byte-identical no-op: nothing emitted.
        self::assertSame([], $this->readSignals());
    }
}
