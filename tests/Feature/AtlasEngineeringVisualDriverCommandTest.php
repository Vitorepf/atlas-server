<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasEngineeringVisualDriverCommandTest extends TestCase
{
    private string $runtimeDir;

    private string $binDir;

    private string|false $originalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = getenv('PATH');
        $this->runtimeDir = sys_get_temp_dir().'/atlas-visual-driver-'.bin2hex(random_bytes(4));
        $this->binDir = sys_get_temp_dir().'/atlas-visual-driver-bin-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->binDir);
        $this->installFakeNodeRuntime();
        putenv('PATH='.$this->binDir.':'.($this->originalPath !== false ? $this->originalPath : ''));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeDir);
        File::deleteDirectory($this->binDir);
        if ($this->originalPath !== false) {
            putenv('PATH='.$this->originalPath);
        }

        parent::tearDown();
    }

    public function test_status_reports_missing_package_without_failing(): void
    {
        $exit = Artisan::call('atlas:engineering:visual-driver', [
            'action' => 'status',
            '--runtime-dir' => $this->runtimeDir,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('status', $payload['action'] ?? null);
        $this->assertFalse((bool) data_get($payload, 'before.ready'));
        $this->assertSame('package_missing', data_get($payload, 'before.status'));
        $this->assertFalse((bool) ($payload['paid_tool_required'] ?? true));
    }

    public function test_install_bootstraps_free_atlas_managed_playwright_runtime(): void
    {
        $exit = Artisan::call('atlas:engineering:visual-driver', [
            'action' => 'install',
            '--runtime-dir' => $this->runtimeDir,
            '--timeout' => 30,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('install', $payload['action'] ?? null);
        $this->assertSame('free_local', $payload['cost_posture'] ?? null);
        $this->assertFalse((bool) ($payload['paid_tool_required'] ?? true));
        $this->assertSame(2, data_get($payload, 'install.command_count'));
        $this->assertSame('ready', data_get($payload, 'after.status'));
        $this->assertTrue((bool) data_get($payload, 'after.ready'));
        $this->assertSame('playwright', data_get($payload, 'after.installed_package'));
        $this->assertTrue((bool) data_get($payload, 'after.verification.chromium_installed'));
        $this->assertFileExists($this->runtimeDir.'/package.json');
        $this->assertDirectoryExists($this->runtimeDir.'/node_modules/playwright');
        $this->assertFileExists($this->runtimeDir.'/browsers/chromium/chrome');
    }

    private function installFakeNodeRuntime(): void
    {
        $php = PHP_BINARY;
        File::put($this->binDir.'/npm', <<<PHP
#!{$php}
<?php
\$root = getcwd();
@mkdir(\$root.'/node_modules/playwright', 0777, true);
file_put_contents(\$root.'/node_modules/playwright/package.json', json_encode(['version' => '1.0.0']));
echo "fake npm install complete\\n";
PHP);
        File::put($this->binDir.'/npx', <<<PHP
#!{$php}
<?php
\$browserRoot = getenv('PLAYWRIGHT_BROWSERS_PATH') ?: getcwd().'/browsers';
@mkdir(\$browserRoot.'/chromium', 0777, true);
file_put_contents(\$browserRoot.'/chromium/chrome', 'fake chromium');
echo "fake playwright chromium install complete\\n";
PHP);
        File::put($this->binDir.'/node', <<<PHP
#!{$php}
<?php
\$root = \$argv[count(\$argv) - 1] ?? getcwd();
\$browserRoot = getenv('PLAYWRIGHT_BROWSERS_PATH') ?: \$root.'/browsers';
\$browser = \$browserRoot.'/chromium/chrome';
echo json_encode([
    'package' => is_dir(\$root.'/node_modules/playwright') ? 'playwright' : '@playwright/test',
    'version' => '1.0.0',
    'chromium_installed' => file_exists(\$browser),
    'chromium_executable_hash' => hash('sha256', \$browser),
    'chromium_error' => null,
])."\\n";
PHP);

        chmod($this->binDir.'/npm', 0755);
        chmod($this->binDir.'/npx', 0755);
        chmod($this->binDir.'/node', 0755);
    }
}
