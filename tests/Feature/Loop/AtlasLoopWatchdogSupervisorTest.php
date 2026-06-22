<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * bin/atlas-loop-watchdog-supervised.sh — the OS-less crash-resilience layer for a watched soak. These
 * tests drive the REAL bash script via env seams (ATLAS_LOOP_REPO_DIR / _ENV_FILE / _WATCHDOG_BIN / …) so
 * it never touches the real .env or the real watchdog. The load-bearing guarantees: (1) MASTER OFF ⇒ it
 * respawns NOTHING and exits (honors the §0 switch), (2) a watchdog that DIES while master is still ON is
 * respawned, (3) once master flips OFF the supervisor exits clean (does not treat the intended stop as a
 * crash), (4) its STOP file short-circuits.
 */
final class AtlasLoopWatchdogSupervisorTest extends TestCase
{
    private string $dir;

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = base_path('bin/atlas-loop-watchdog-supervised.sh');
        $this->dir = sys_get_temp_dir().'/atlas-loop-sup-'.bin2hex(random_bytes(5));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string,string> $env */
    private function runSupervisor(array $env): Process
    {
        $base = [
            'ATLAS_LOOP_REPO_DIR' => $this->dir,
            'ATLAS_LOOP_ENV_FILE' => $this->dir.'/.env',
            'ATLAS_LOOP_SUPERVISOR_STOP' => $this->dir.'/SUP_STOP',
            'ATLAS_LOOP_SUPERVISOR_LOG' => $this->dir.'/sup.log',
            'ATLAS_LOOP_SUPERVISOR_BACKOFF' => '0',
        ];
        $p = new Process(['/bin/bash', $this->script, 'test-cid'], $this->dir, array_merge($base, $env));
        $p->setTimeout(20);
        $p->run();

        return $p;
    }

    private function writeStub(string $body): string
    {
        $path = $this->dir.'/stub.sh';
        file_put_contents($path, "#!/bin/bash\n".$body."\n");
        chmod($path, 0755);

        return $path;
    }

    public function test_master_off_exits_without_ever_running_the_watchdog(): void
    {
        file_put_contents($this->dir.'/.env', "ATLAS_LOOP_MASTER_ENABLED=false\n");
        $this->writeStub('echo ran >> "$RAN_MARKER"');

        $p = $this->runSupervisor([
            'ATLAS_LOOP_WATCHDOG_BIN' => $this->dir.'/stub.sh',
            'RAN_MARKER' => $this->dir.'/ran',
        ]);

        $this->assertSame(0, $p->getExitCode(), 'supervisor exits clean when master OFF');
        $this->assertFileDoesNotExist($this->dir.'/ran', 'the watchdog is NEVER run while master is OFF (respawns nothing)');
    }

    public function test_respawns_a_crashed_watchdog_then_exits_when_master_flips_off(): void
    {
        file_put_contents($this->dir.'/.env', "ATLAS_LOOP_MASTER_ENABLED=true\n");
        // 1st run: "crash" (exit 1) with master still ON => the supervisor MUST respawn. 2nd run: flip
        // master OFF (the canonical intended stop) + exit 0 => the supervisor MUST exit. The counter
        // proving exactly 2 runs is the proof of respawn-on-crash AND intended-stop-exit.
        $this->writeStub(
            'n=$(cat "$STUB_COUNTER" 2>/dev/null || echo 0); n=$((n+1)); echo "$n" > "$STUB_COUNTER"'."\n".
            'if [ "$n" -ge 2 ]; then echo "ATLAS_LOOP_MASTER_ENABLED=false" > "$ATLAS_LOOP_ENV_FILE"; exit 0; fi'."\n".
            'exit 1'
        );

        $p = $this->runSupervisor([
            'ATLAS_LOOP_WATCHDOG_BIN' => $this->dir.'/stub.sh',
            'STUB_COUNTER' => $this->dir.'/count',
        ]);

        $this->assertSame(0, $p->getExitCode(), 'supervisor exits clean once master flips OFF');
        $this->assertSame('2', trim((string) @file_get_contents($this->dir.'/count')), 'watchdog ran twice: crashed→respawned, then intended-stop');
    }

    public function test_supervisor_stop_file_short_circuits(): void
    {
        file_put_contents($this->dir.'/.env', "ATLAS_LOOP_MASTER_ENABLED=true\n");
        file_put_contents($this->dir.'/SUP_STOP', '1');
        $this->writeStub('echo ran >> "$RAN_MARKER"');

        $p = $this->runSupervisor([
            'ATLAS_LOOP_WATCHDOG_BIN' => $this->dir.'/stub.sh',
            'RAN_MARKER' => $this->dir.'/ran',
        ]);

        $this->assertSame(0, $p->getExitCode(), 'supervisor exits on its STOP file');
        $this->assertFileDoesNotExist($this->dir.'/ran', 'STOP present => watchdog not run');
    }
}
