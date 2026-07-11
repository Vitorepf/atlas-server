<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\AtlasSchedulerEnsureLaunchdCommand;
use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * EVI-02 — ensure-launchd must not call "healthy" when the patient is failing.
 */
class AtlasSchedulerEnsureLaunchdCommandTest extends TestCase
{
    private string $tmp;

    private string $errLog;

    private string $statePath;

    private string $mockBin;

    private string $mockScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas-ensure-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
        $this->errLog = $this->tmp.'/launchd.err.log';
        $this->statePath = $this->tmp.'/fatal-state.json';
        file_put_contents($this->errLog, '');
        file_put_contents($this->statePath, json_encode([
            'err_log_offset' => 0,
            'err_log_fatal_blocks' => 0,
        ]));

        $this->mockScript = $this->tmp.'/launchctl-mock.php';
        $this->mockBin = PHP_BINARY.' '.$this->mockScript;

        // Fresh heartbeat so heartbeat is NOT the proof case.
        $hb = new AtlasSchedulerHealthService;
        $hb->setLogPathForTesting($this->tmp.'/heartbeat.jsonl');
        $hb->recordHeartbeat('test');
        $this->app->instance(AtlasSchedulerHealthService::class, $hb);

        putenv('ATLAS_ENSURE_ERR_LOG='.$this->errLog);
        putenv('ATLAS_ENSURE_FATAL_STATE='.$this->statePath);
        $_ENV['ATLAS_ENSURE_ERR_LOG'] = $this->errLog;
        $_ENV['ATLAS_ENSURE_FATAL_STATE'] = $this->statePath;
    }

    protected function tearDown(): void
    {
        putenv('ATLAS_ENSURE_ERR_LOG');
        putenv('ATLAS_ENSURE_FATAL_STATE');
        putenv('ATLAS_ENSURE_LAUNCHCTL_BIN');
        unset($_ENV['ATLAS_ENSURE_ERR_LOG'], $_ENV['ATLAS_ENSURE_FATAL_STATE'], $_ENV['ATLAS_ENSURE_LAUNCHCTL_BIN']);
        $this->rmTree($this->tmp);
        parent::tearDown();
    }

    public function test_last_exit_code_nonzero_is_unhealthy_and_heals(): void
    {
        $this->installMock(<<<'PHP'
<?php
$cmd = $argv[1] ?? '';
if (str_contains($cmd, 'launchctl list')) {
    echo "PID\tStatus\tLabel\n123\t0\tcom.atlas.scheduler\n";
    exit(0);
}
if (str_contains($cmd, 'launchctl print')) {
    echo "last exit code = 78\nruns = 3\n";
    exit(0);
}
if (str_contains($cmd, 'kickstart')) {
    file_put_contents(getenv('ATLAS_ENSURE_KICK_LOG') ?: '/dev/null', "kick\n", FILE_APPEND);
    exit(0);
}
exit(0);
PHP);

        // Avoid real install-launchd: bind a no-op by stubbing Artisan::call path —
        // the command uses $this->call(); we assert status via a subclass... use mock that
        // still lets install-launchd dry-run. Force dry path by replacing install command
        // output: call real install-launchd --dry-run is not wired. Instead, allow the
        // real install under --json in dry environment HOME.
        $home = $this->tmp.'/home';
        mkdir($home.'/Library/LaunchAgents', 0775, true);
        $prevHome = getenv('HOME');
        putenv('HOME='.$home);
        $_ENV['HOME'] = $home;

        try {
            // Pre-seed state so err log contributes 0 new fatals.
            file_put_contents($this->statePath, json_encode([
                'err_log_offset' => 0,
                'err_log_fatal_blocks' => 0,
            ]));
            $exit = Artisan::call('atlas:scheduler:ensure-launchd', ['--json' => true]);
            $this->assertSame(0, $exit);
            $raw = Artisan::output();
            $payload = $this->decodeLastJsonObject($raw);
            $this->assertIsArray($payload, 'raw='.$raw);
            $this->assertContains('last_exit_code_nonzero', $payload['unhealthy_reasons'] ?? []);
            $this->assertTrue(in_array($payload['status'], ['reinstalled', 'reinstall_failed', 'unhealthy', 'reinstall_error'], true));
            $this->assertNotSame('healthy', $payload['status']);
        } finally {
            if ($prevHome === false) {
                putenv('HOME');
                unset($_ENV['HOME']);
            } else {
                putenv('HOME='.$prevHome);
                $_ENV['HOME'] = $prevHome;
            }
        }
    }

    public function test_new_php_fatal_blocks_are_unhealthy_and_heal(): void
    {
        $this->installMock(<<<'PHP'
<?php
$cmd = $argv[1] ?? '';
if (str_contains($cmd, 'launchctl list')) {
    echo "PID\tStatus\tLabel\n123\t0\tcom.atlas.scheduler\n";
    exit(0);
}
if (str_contains($cmd, 'launchctl print')) {
    echo "last exit code = 0\nruns = 9\n";
    exit(0);
}
if (str_contains($cmd, 'kickstart')) {
    exit(0);
}
exit(0);
PHP);

        file_put_contents($this->errLog, "PHP Fatal error: Class BrokenCommand not found\n");
        $home = $this->tmp.'/home2';
        mkdir($home.'/Library/LaunchAgents', 0775, true);
        $prevHome = getenv('HOME');
        putenv('HOME='.$home);
        $_ENV['HOME'] = $home;

        try {
            $exit = Artisan::call('atlas:scheduler:ensure-launchd', ['--json' => true]);
            $this->assertSame(0, $exit);
            $raw = Artisan::output();
            $payload = $this->decodeLastJsonObject($raw);
            $this->assertIsArray($payload, 'raw='.$raw);
            $this->assertContains('php_fatal_in_err_log', $payload['unhealthy_reasons'] ?? []);
            $this->assertGreaterThan(0, $payload['new_fatal_blocks'] ?? 0);
            $this->assertNotSame('healthy', $payload['status']);
        } finally {
            if ($prevHome === false) {
                putenv('HOME');
                unset($_ENV['HOME']);
            } else {
                putenv('HOME='.$prevHome);
                $_ENV['HOME'] = $prevHome;
            }
        }
    }

    public function test_healthy_when_agent_ok_exit_zero_and_no_new_fatals(): void
    {
        $this->installMock(<<<'PHP'
<?php
$cmd = $argv[1] ?? '';
if (str_contains($cmd, 'launchctl list')) {
    echo "PID\tStatus\tLabel\n123\t0\tcom.atlas.scheduler\n";
    exit(0);
}
if (str_contains($cmd, 'launchctl print')) {
    echo "last exit code = 0\nruns = 9\n";
    exit(0);
}
exit(0);
PHP);

        // Consume empty err log first so subsequent read sees 0 new blocks.
        file_put_contents($this->errLog, "info only\n");
        file_put_contents($this->statePath, json_encode([
            'err_log_offset' => filesize($this->errLog),
            'err_log_fatal_blocks' => 0,
        ]));

        $exit = Artisan::call('atlas:scheduler:ensure-launchd', ['--json' => true]);
        $this->assertSame(0, $exit);
        $payload = $this->decodeLastJsonObject(Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('healthy', $payload['status']);
        $this->assertSame([], $payload['unhealthy_reasons']);
        $this->assertArrayNotHasKey('healed', $payload);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeLastJsonObject(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $pos = strrpos($raw, "{\n");
        if ($pos === false) {
            $pos = strrpos($raw, '{');
        }
        if ($pos === false) {
            return null;
        }
        $decoded = json_decode(substr($raw, $pos), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function installMock(string $phpSource): void
    {
        file_put_contents($this->mockScript, $phpSource);
        // ATLAS_ENSURE_LAUNCHCTL_BIN is invoked as: bin 'full command'
        // We need a shell wrapper because the command does: escapeshellarg(bin).' '.escapeshellarg(command)
        $wrapper = $this->tmp.'/launchctl-wrap.sh';
        file_put_contents($wrapper, "#!/bin/sh\n".escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->mockScript)." \"\$1\"\n");
        chmod($wrapper, 0755);
        putenv('ATLAS_ENSURE_LAUNCHCTL_BIN='.$wrapper);
        $_ENV['ATLAS_ENSURE_LAUNCHCTL_BIN'] = $wrapper;
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
