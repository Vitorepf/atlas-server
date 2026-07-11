<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduler;

use App\Console\Commands\AtlasSchedulerInstallWatchdogCommand;
use Tests\TestCase;

/**
 * EVI-01 — external scheduler watchdog (standalone script + installer).
 *
 * Authority: docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md §EVI-01
 */
class SchedulerWatchdogScriptTest extends TestCase
{
    private string $tmp;

    private string $script;

    private string $okArtisan;

    private string $fatalArtisan;

    private string $kickLog;

    private string $notifyLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas-watchdog-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/storage', 0775, true);
        mkdir($this->tmp.'/LaunchAgents', 0775, true);
        $this->script = base_path('scripts/scheduler-watchdog.php');
        $this->assertFileExists($this->script);

        $this->okArtisan = $this->tmp.'/artisan-ok.php';
        file_put_contents($this->okArtisan, "<?php\n// fake artisan — boot smoke ok\nexit(0);\n");

        $this->fatalArtisan = $this->tmp.'/artisan-fatal.php';
        file_put_contents($this->fatalArtisan, "<?php\nfwrite(STDERR, \"PHP Fatal error: boom\\n\");\nexit(1);\n");

        $this->kickLog = $this->tmp.'/kick.log';
        $this->notifyLog = $this->tmp.'/notify.log';
        @unlink($this->kickLog);
        @unlink($this->notifyLog);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmp);
        parent::tearDown();
    }

    public function test_heartbeat_stale_alarms_and_kickstarts(): void
    {
        $this->writeHeartbeat(gmdate('c', time() - 600));
        $out = $this->runWatchdog([
            'ATLAS_WATCHDOG_ARTISAN' => $this->okArtisan,
            'ATLAS_WATCHDOG_THRESHOLD_SECONDS' => '300',
            'ATLAS_WATCHDOG_COOLDOWN_SECONDS' => '0',
        ]);
        $this->assertSame(0, $out['exit']);
        $payload = json_decode($out['stdout'], true);
        $this->assertIsArray($payload);
        $this->assertSame('unhealthy', $payload['status']);
        $this->assertContains('heartbeat_stale', array_column($payload['failures'], 'check'));
        $this->assertFileExists($this->tmp.'/storage/watchdog-alarm.jsonl');
        $this->assertFileExists($this->kickLog);
        $this->assertGreaterThan(0, filesize($this->kickLog));
    }

    public function test_boot_fatal_alarms(): void
    {
        $this->writeHeartbeat(gmdate('c'));
        // Explicit boot cmd avoids proc_open env quirks with PHP_BINARY+script.
        $bootCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->fatalArtisan);
        $out = $this->runWatchdog([
            'ATLAS_WATCHDOG_ARTISAN' => $this->fatalArtisan,
            'ATLAS_WATCHDOG_BOOT_CMD' => $bootCmd,
            'ATLAS_WATCHDOG_THRESHOLD_SECONDS' => '300',
            'ATLAS_WATCHDOG_COOLDOWN_SECONDS' => '0',
        ]);
        $payload = json_decode($out['stdout'], true);
        $this->assertIsArray($payload, 'stdout='.$out['stdout'].' stderr='.$out['stderr']);
        $this->assertSame('unhealthy', $payload['status'], 'stdout='.$out['stdout']);
        $this->assertContains('artisan_boot_fatal', array_column($payload['failures'], 'check'));
    }

    public function test_healthy_is_silent(): void
    {
        $this->writeHeartbeat(gmdate('c'));
        file_put_contents($this->tmp.'/storage/launchd.err.log', "info only\n");
        $out = $this->runWatchdog([
            'ATLAS_WATCHDOG_ARTISAN' => $this->okArtisan,
            'ATLAS_WATCHDOG_THRESHOLD_SECONDS' => '300',
            'ATLAS_WATCHDOG_COOLDOWN_SECONDS' => '0',
        ]);
        $payload = json_decode($out['stdout'], true);
        $this->assertIsArray($payload);
        $this->assertSame('healthy', $payload['status']);
        $this->assertSame([], $payload['failures']);
        $this->assertSame([], $payload['warnings'] ?? []);
        $this->assertFileDoesNotExist($this->tmp.'/storage/watchdog-alarm.jsonl');
        $this->assertFileDoesNotExist($this->kickLog);
    }

    public function test_cooldown_suppresses_repeat_alarm(): void
    {
        $this->writeHeartbeat(gmdate('c', time() - 900));
        $env = [
            'ATLAS_WATCHDOG_ARTISAN' => $this->okArtisan,
            'ATLAS_WATCHDOG_THRESHOLD_SECONDS' => '300',
            'ATLAS_WATCHDOG_COOLDOWN_SECONDS' => '3600',
        ];
        $first = $this->runWatchdog($env);
        $this->assertSame('unhealthy', json_decode($first['stdout'], true)['status'] ?? null);
        @unlink($this->kickLog);

        $second = $this->runWatchdog($env);
        $payload = json_decode($second['stdout'], true);
        $this->assertIsArray($payload);
        $this->assertSame('unhealthy_cooldown', $payload['status']);
        $this->assertSame('suppressed_cooldown', $payload['action']);
        $this->assertFileDoesNotExist($this->kickLog);
    }

    public function test_kill_switch_exits_zero_silently(): void
    {
        file_put_contents($this->tmp.'/storage/watchdog-disabled', "1\n");
        $this->writeHeartbeat(gmdate('c', time() - 9999));
        $out = $this->runWatchdog([
            'ATLAS_WATCHDOG_ARTISAN' => $this->fatalArtisan,
        ]);
        $this->assertSame(0, $out['exit']);
        $payload = json_decode($out['stdout'], true);
        $this->assertSame('disabled', $payload['status'] ?? null);
        $this->assertFileDoesNotExist($this->kickLog);
    }

    public function test_new_php_fatal_block_in_err_log_alarms(): void
    {
        $this->writeHeartbeat(gmdate('c'));
        // Seed state as if we already consumed an empty log.
        file_put_contents($this->tmp.'/storage/watchdog-state.json', json_encode([
            'err_log_offset' => 0,
            'err_log_fatal_blocks' => 0,
        ]));
        file_put_contents($this->tmp.'/storage/launchd.err.log', "PHP Fatal error: Class X not found\n");
        $out = $this->runWatchdog([
            'ATLAS_WATCHDOG_ARTISAN' => $this->okArtisan,
            'ATLAS_WATCHDOG_THRESHOLD_SECONDS' => '300',
            'ATLAS_WATCHDOG_COOLDOWN_SECONDS' => '0',
        ]);
        $payload = json_decode($out['stdout'], true);
        $this->assertIsArray($payload);
        $this->assertContains('php_fatal_in_err_log', array_column($payload['failures'] ?? [], 'check'));
    }

    public function test_volume_janela_faminta_emits_warning_without_kickstart(): void
    {
        $this->writeHeartbeat(gmdate('c'));
        $volumeFixture = json_encode([
            'schema_version' => 'atlas.acos.operational_volume.v1',
            'status' => 'alert',
            'alert' => true,
            'alert_code' => 'janela_faminta',
            'windows' => [
                'dev' => ['count' => 0, 'threshold' => 3],
                'forge' => ['count' => 0, 'threshold' => 5],
            ],
        ], JSON_UNESCAPED_SLASHES);
        $volumeCmd = 'printf %s '.escapeshellarg($volumeFixture);

        $out = $this->runWatchdog([
            'ATLAS_WATCHDOG_ARTISAN' => $this->okArtisan,
            'ATLAS_WATCHDOG_THRESHOLD_SECONDS' => '300',
            'ATLAS_WATCHDOG_COOLDOWN_SECONDS' => '0',
            'ATLAS_WATCHDOG_VOLUME_CMD' => $volumeCmd,
            'ATLAS_WATCHDOG_ROLLBACK_CMD' => 'printf %s '.escapeshellarg(json_encode(['alert' => false], JSON_UNESCAPED_SLASHES)),
        ]);

        $payload = json_decode($out['stdout'], true);
        $this->assertIsArray($payload);
        $this->assertSame('warning', $payload['status']);
        $this->assertContains('operational_volume_janela_faminta', array_column($payload['warnings'] ?? [], 'check'));
        $this->assertFileDoesNotExist($this->kickLog);
        $this->assertFileExists($this->tmp.'/storage/watchdog-alarm.jsonl');
    }

    public function test_rollback_trigger_simulated_emits_warning_without_kickstart(): void
    {
        $this->writeHeartbeat(gmdate('c'));
        $rollbackFixture = json_encode([
            'schema_version' => 'atlas.acos.rollback_triggers.v1',
            'status' => 'alert',
            'alert' => true,
            'alert_code' => 'rollback_trigger_fired',
            'alerts' => [[
                'trigger_id' => 'eng_13_governance_enforce',
                'slices' => ['ENG-13'],
                'rollback_action' => [
                    'ATLAS_AI_GOVERNANCE_ENFORCE' => 'false',
                    'ATLAS_AI_CALL_COST_GUARD_HARD_UNITS' => '0',
                ],
                'simulated' => true,
            ]],
        ], JSON_UNESCAPED_SLASHES);
        $rollbackCmd = 'printf %s '.escapeshellarg($rollbackFixture);
        $healthyVolume = json_encode(['alert' => false], JSON_UNESCAPED_SLASHES);

        $out = $this->runWatchdog([
            'ATLAS_WATCHDOG_ARTISAN' => $this->okArtisan,
            'ATLAS_WATCHDOG_THRESHOLD_SECONDS' => '300',
            'ATLAS_WATCHDOG_COOLDOWN_SECONDS' => '0',
            'ATLAS_WATCHDOG_VOLUME_CMD' => 'printf %s '.escapeshellarg($healthyVolume),
            'ATLAS_WATCHDOG_ROLLBACK_CMD' => $rollbackCmd,
        ]);

        $payload = json_decode($out['stdout'], true);
        $this->assertIsArray($payload);
        $this->assertSame('warning', $payload['status']);
        $this->assertContains('rollback_trigger_fired', array_column($payload['warnings'] ?? [], 'check'));
        $this->assertSame('eng_13_governance_enforce', $payload['warnings'][0]['trigger_id'] ?? null);
        $this->assertFileDoesNotExist($this->kickLog);
        $this->assertFileExists($this->tmp.'/storage/watchdog-alarm.jsonl');
    }

    public function test_uninstall_removes_agent_and_plist(): void
    {
        $home = $this->tmp;
        $plistDir = $home.'/Library/LaunchAgents';
        mkdir($plistDir, 0775, true);
        $plistPath = $plistDir.'/'.AtlasSchedulerInstallWatchdogCommand::LABEL.'.plist';
        file_put_contents($plistPath, "<plist/>\n");

        $previousHome = getenv('HOME');
        putenv('HOME='.$home);
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;

        try {
            $this->artisan('atlas:scheduler:install-watchdog', [
                '--uninstall' => true,
                '--json' => true,
                '--skip-legacy-bootout' => true,
            ])->assertSuccessful();
            $this->assertFileDoesNotExist($plistPath);
        } finally {
            if ($previousHome === false) {
                putenv('HOME');
                unset($_ENV['HOME'], $_SERVER['HOME']);
            } else {
                putenv('HOME='.$previousHome);
                $_ENV['HOME'] = $previousHome;
                $_SERVER['HOME'] = $previousHome;
            }
        }
    }

    public function test_install_dry_run_renders_plist_with_absolute_paths(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:scheduler:install-watchdog', [
            '--dry-run' => true,
            '--json' => true,
            '--skip-legacy-bootout' => true,
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('install', $payload['action']);
        $this->assertTrue($payload['dry_run']);
        $this->assertStringContainsString('scheduler-watchdog.php', $payload['plist']);
        $this->assertStringContainsString('<integer>300</integer>', $payload['plist']);
        $this->assertStringContainsString(base_path('scripts/scheduler-watchdog.php'), $payload['plist']);
    }

    /**
     * @param  array<string,string>  $env
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runWatchdog(array $env): array
    {
        $kickCmd = 'echo kick >> '.escapeshellarg($this->kickLog);
        $notifyCmd = 'echo notify >> '.escapeshellarg($this->notifyLog);
        $baseEnv = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $baseEnv[$k] = (string) $v;
            }
        }
        $fullEnv = array_merge($baseEnv, [
            'ATLAS_WATCHDOG_ROOT' => $this->tmp,
            'ATLAS_WATCHDOG_STORAGE' => $this->tmp.'/storage',
            'ATLAS_WATCHDOG_PHP' => PHP_BINARY,
            'ATLAS_WATCHDOG_KICKSTART_CMD' => $kickCmd,
            'ATLAS_WATCHDOG_NOTIFY_CMD' => $notifyCmd,
            'ATLAS_WATCHDOG_BOOT_TIMEOUT_SECONDS' => '5',
        ], $env);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->script);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->tmp, $fullEnv);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
    }

    private function writeHeartbeat(string $timestamp): void
    {
        $row = [
            'schema_version' => 'atlas.scheduler.heartbeat.v1',
            'timestamp' => $timestamp,
            'actor' => 'test',
        ];
        file_put_contents(
            $this->tmp.'/storage/heartbeat.jsonl',
            json_encode($row, JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
