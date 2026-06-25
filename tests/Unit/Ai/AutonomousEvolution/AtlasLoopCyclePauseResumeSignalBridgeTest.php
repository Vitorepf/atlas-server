<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopCycleSignalEmitter;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseFlag;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseResumeSignalBridge;
use Tests\TestCase;

final class AtlasLoopCyclePauseResumeSignalBridgeTest extends TestCase
{
    private string $sandbox = '';

    private string $signalSinkDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-bridge-'.bin2hex(random_bytes(6));
        @mkdir($this->sandbox, 0o755, true);

        // Redirect storage_path() so the emitter writes JSONL into our sandbox
        // (the emitter's sinkPath() builds from storage_path()).
        $storageRoot = $this->sandbox.'/storage';
        @mkdir($storageRoot, 0o755, true);
        $this->app->useStoragePath($storageRoot);
        $this->signalSinkDir = $storageRoot.'/app/atlas-loop/signals';
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/{,.}*', GLOB_BRACE) as $entry) {
            $base = basename((string) $entry);
            if ($base === '.' || $base === '..') {
                continue;
            }
            is_dir($entry) ? $this->rrmdir((string) $entry) : @unlink((string) $entry);
        }
        @rmdir($dir);
    }

    private function flag(): AtlasLoopCyclePauseFlag
    {
        return new AtlasLoopCyclePauseFlag($this->sandbox.'/pause.json');
    }

    private function newBridge(bool $masterOn, AtlasLoopCyclePauseFlag $flag): AtlasLoopCyclePauseResumeSignalBridge
    {
        return new AtlasLoopCyclePauseResumeSignalBridge(
            new AtlasLoopCycleSignalEmitter(),
            $flag,
            static fn (): bool => $masterOn,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readEmittedSignals(): array
    {
        $rows = [];
        foreach ((array) glob($this->signalSinkDir.'/*.jsonl') as $path) {
            foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $decoded = json_decode((string) $line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }

        return $rows;
    }

    public function test_on_pause_raised_emits_signal_with_sentinel_sha256_from_disk(): void
    {
        $flag = $this->flag();
        $sentinel = $flag->raise('cyc-1', 'comprehend', 'operator-stop', '2026-06-25T00:00:00Z');

        $bridge = $this->newBridge(masterOn: true, flag: $flag);
        $bridge->onPauseRaised('cyc-1', 'comprehend', 'operator-stop');

        $signals = $this->readEmittedSignals();
        self::assertCount(1, $signals);
        $sig = $signals[0];
        self::assertSame(AtlasLoopCyclePauseResumeSignalBridge::STAGE_PAUSED, $sig['stage']);
        foreach (['cycle_id', 'phase_at_pause', 'reason', 'sentinel_sha256', 'paused_at'] as $k) {
            self::assertArrayHasKey($k, $sig['payload']);
        }

        // Recompute the sha256 from disk (the contract the bridge must honour).
        $diskRaw = (string) file_get_contents($this->sandbox.'/pause.json');
        $diskParsed = json_decode($diskRaw, true);
        self::assertIsArray($diskParsed);
        ksort($diskParsed, SORT_STRING);
        $expected = hash('sha256', (string) json_encode($diskParsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::assertSame($expected, $sig['payload']['sentinel_sha256']);
        self::assertSame($sentinel['raised_at'], $sig['payload']['paused_at']);
    }

    public function test_on_pause_lowered_emits_pause_lowered_signal(): void
    {
        $flag = $this->flag();
        $flag->raise('cyc-2', 'plan', 'op', '2026-06-25T00:00:01Z');

        $bridge = $this->newBridge(masterOn: true, flag: $flag);
        $bridge->onPauseLowered('cyc-2');

        $signals = $this->readEmittedSignals();
        self::assertCount(1, $signals);
        self::assertSame(AtlasLoopCyclePauseResumeSignalBridge::STAGE_PAUSE_LOWERED, $signals[0]['stage']);
        self::assertSame('cyc-2', $signals[0]['payload']['cycle_id']);
        self::assertArrayHasKey('lowered_at', $signals[0]['payload']);
    }

    public function test_on_resume_attempted_ok_and_on_resume_refused_drift_emit_distinct_types(): void
    {
        $flag = $this->flag();
        $flag->raise('cyc-3', 'verify', 'op', '2026-06-25T00:00:02Z');

        $bridge = $this->newBridge(masterOn: true, flag: $flag);
        $bridge->onResumeAttempted('cyc-3', [
            'outcome' => 'resumed',
            'resumed_phase' => 'verify',
        ]);
        $bridge->onResumeRefusedDueToDrift('cyc-3', [
            'outcome' => 'refused',
            'reason' => 'integrity_drift',
        ]);

        $signals = $this->readEmittedSignals();
        self::assertCount(2, $signals);
        $stages = array_map(static fn (array $s): string => (string) $s['stage'], $signals);
        self::assertContains(AtlasLoopCyclePauseResumeSignalBridge::STAGE_RESUMED, $stages);
        self::assertContains(AtlasLoopCyclePauseResumeSignalBridge::STAGE_RESUME_REFUSED, $stages);

        $resumed = $signals[array_search(AtlasLoopCyclePauseResumeSignalBridge::STAGE_RESUMED, $stages, true)];
        self::assertSame('verify', $resumed['payload']['resumed_phase']);
        self::assertTrue((bool) $resumed['payload']['integrity_ok']);

        $refused = $signals[array_search(AtlasLoopCyclePauseResumeSignalBridge::STAGE_RESUME_REFUSED, $stages, true)];
        self::assertSame('integrity_drift', $refused['payload']['reason']);
        self::assertSame('verify', $refused['payload']['paused_phase_recovered']);
    }

    public function test_master_off_is_byte_identical_noop_for_all_four_methods(): void
    {
        $flag = $this->flag();
        $flag->raise('cyc-9', 'plan', 'op', '2026-06-25T00:00:03Z');
        $beforeSentinel = (string) file_get_contents($this->sandbox.'/pause.json');

        @mkdir($this->signalSinkDir, 0o755, true);
        $sinkBefore = $this->snapshotSink();

        $bridge = $this->newBridge(masterOn: false, flag: $flag);
        $bridge->onPauseRaised('cyc-9', 'plan', 'op');
        $bridge->onPauseLowered('cyc-9');
        $bridge->onResumeAttempted('cyc-9', ['outcome' => 'resumed', 'resumed_phase' => 'plan']);
        $bridge->onResumeRefusedDueToDrift('cyc-9', ['outcome' => 'refused', 'reason' => 'integrity_drift']);

        $sinkAfter = $this->snapshotSink();
        self::assertSame($sinkBefore, $sinkAfter, 'master-off must produce zero emitter writes');
        $afterSentinel = (string) file_get_contents($this->sandbox.'/pause.json');
        self::assertSame($beforeSentinel, $afterSentinel, 'master-off must not mutate the sentinel');
        self::assertSame([], $this->readEmittedSignals(), 'no signals emitted under master-off');
    }

    public function test_payloads_carry_no_aggregate_scalar_keys_goodhart_guard(): void
    {
        $flag = $this->flag();
        $flag->raise('cyc-G', 'plan', 'op', '2026-06-25T00:00:04Z');

        $bridge = $this->newBridge(masterOn: true, flag: $flag);
        $bridge->onPauseRaised('cyc-G', 'plan', 'op');
        $bridge->onPauseLowered('cyc-G');
        $bridge->onResumeAttempted('cyc-G', ['outcome' => 'resumed', 'resumed_phase' => 'plan']);
        $bridge->onResumeRefusedDueToDrift('cyc-G', ['outcome' => 'refused', 'reason' => 'integrity_drift']);

        $forbidden = '/^(score|rank|health|composite|total)$/i';
        foreach ($this->readEmittedSignals() as $signal) {
            foreach (array_keys((array) $signal['payload']) as $key) {
                self::assertSame(
                    0,
                    preg_match($forbidden, (string) $key),
                    'forbidden aggregate scalar key in payload: '.$key,
                );
            }
        }
    }

    public function test_master_off_zero_emitter_invocations_counted_spy(): void
    {
        $flag = $this->flag();
        $flag->raise('cyc-S', 'plan', 'op', '2026-06-25T00:00:05Z');

        $bridge = $this->newBridge(masterOn: false, flag: $flag);
        $bridge->onPauseRaised('cyc-S', 'plan', 'op');
        $bridge->onPauseLowered('cyc-S');
        $bridge->onResumeAttempted('cyc-S', ['outcome' => 'resumed', 'resumed_phase' => 'plan']);
        $bridge->onResumeRefusedDueToDrift('cyc-S', ['outcome' => 'refused', 'reason' => 'integrity_drift']);

        // The emitter writes JSONL on every emit() call; zero rows ⇒ zero invocations.
        self::assertCount(0, $this->readEmittedSignals(), 'spy: zero emitter invocations expected');
    }

    /**
     * @return array<string,string>  basename → sha256
     */
    private function snapshotSink(): array
    {
        $snap = [];
        foreach ((array) glob($this->signalSinkDir.'/*') as $path) {
            $snap[basename((string) $path)] = (string) hash_file('sha256', (string) $path);
        }
        ksort($snap, SORT_STRING);

        return $snap;
    }
}
