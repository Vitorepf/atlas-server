<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasEngineeringVisualSmokeCommandTest extends TestCase
{
    private string $workspace;

    private string|false $originalPath;

    /**
     * @var array<int,string>
     */
    private array $managedRuntimeRoots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = getenv('PATH');
        $this->workspace = sys_get_temp_dir().'/atlas-visual-smoke-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/public');
        File::put($this->workspace.'/public/index.php', '<?php echo "<html><body>Atlas visual ok</body></html>";');

        config()->set('atlas.ai.tool_permissions.allowed_roots', [$this->workspace]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->baselineRoot());
        foreach ($this->managedRuntimeRoots as $root) {
            File::deleteDirectory($root);
        }
        File::deleteDirectory($this->workspace);
        if ($this->originalPath !== false) {
            putenv('PATH='.$this->originalPath);
        }

        parent::tearDown();
    }

    public function test_visual_smoke_starts_local_app_and_writes_artifacts(): void
    {
        $port = 48_000 + random_int(0, 999);
        $exit = Artisan::call('atlas:engineering:visual-smoke', [
            '--workspace' => $this->workspace,
            '--start-command' => escapeshellarg(PHP_BINARY).' -S 127.0.0.1:{port} -t public',
            '--url' => 'http://127.0.0.1:{port}',
            '--port' => $port,
            '--timeout' => 10,
            '--artifact-dir' => 'atlas-visual-report',
            '--baseline' => 'off',
            '--screenshot-driver' => 'off',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, $this->visualManifestForFailure());
        $this->assertIsArray($payload);
        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertSame(200, data_get($payload, 'routes.0.status_code'));
        $this->assertSame('skipped', data_get($payload, 'screenshot.status'));
        $this->assertFileExists($this->workspace.'/atlas-visual-report/manifest.json');
        $this->assertFileExists($this->workspace.'/atlas-visual-report/routes/root.html');
        $this->assertStringContainsString('Atlas visual ok', File::get($this->workspace.'/atlas-visual-report/routes/root.html'));
    }

    public function test_visual_smoke_defaults_to_api_health_for_api_only_laravel_workspace(): void
    {
        File::ensureDirectoryExists($this->workspace.'/routes');
        File::put($this->workspace.'/routes/api.php', '<?php // api only');
        File::put($this->workspace.'/artisan', '#!/usr/bin/env php');
        File::put($this->workspace.'/public/index.php', <<<'PHP'
<?php
if (($_SERVER['REQUEST_URI'] ?? '/') === '/api/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    return;
}
http_response_code(404);
echo 'missing';
PHP);

        $port = 48_000 + random_int(1000, 1999);
        $exit = Artisan::call('atlas:engineering:visual-smoke', [
            '--workspace' => $this->workspace,
            '--start-command' => escapeshellarg(PHP_BINARY).' -S 127.0.0.1:{port} -t public',
            '--url' => 'http://127.0.0.1:{port}',
            '--port' => $port,
            '--timeout' => 10,
            '--artifact-dir' => 'atlas-visual-report',
            '--baseline' => 'off',
            '--screenshot-driver' => 'off',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, $this->visualManifestForFailure());
        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertSame('/api/health', data_get($payload, 'routes.0.route'));
        $this->assertSame(200, data_get($payload, 'routes.0.status_code'));
    }

    public function test_visual_baseline_promote_lists_and_unblocks_strict_changes(): void
    {
        $port = 49_000 + random_int(0, 499);
        $this->runVisualSmoke($port, 'observe');

        File::put($this->workspace.'/public/index.php', '<?php echo "<html><body>Atlas visual changed</body></html>";');
        $strictExit = $this->runVisualSmoke($port + 500, 'strict');
        $strictPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $strictExit, Artisan::output());
        $this->assertSame('failed', $strictPayload['status'] ?? null);
        $this->assertSame('changed', data_get($strictPayload, 'routes.0.baseline.status'));

        $dryRunExit = Artisan::call('atlas:engineering:visual-baseline', [
            'action' => 'promote',
            '--workspace' => $this->workspace,
            '--manifest' => 'atlas-visual-report/manifest.json',
            '--json' => true,
        ]);
        $dryRunPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $dryRunExit);
        $this->assertTrue((bool) ($dryRunPayload['dry_run'] ?? false));
        $this->assertSame('updated', data_get($dryRunPayload, 'promotions.0.status'));

        $applyExit = Artisan::call('atlas:engineering:visual-baseline', [
            'action' => 'promote',
            '--workspace' => $this->workspace,
            '--manifest' => 'atlas-visual-report/manifest.json',
            '--apply' => true,
            '--json' => true,
        ]);
        $applyPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $applyExit);
        $this->assertFalse((bool) ($applyPayload['dry_run'] ?? true));
        $this->assertSame(1, $applyPayload['promoted_count'] ?? null);

        $historyExit = Artisan::call('atlas:engineering:visual-baseline', [
            'action' => 'history',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $historyPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $historyExit);
        $this->assertSame(1, $historyPayload['count'] ?? null);
        $this->assertSame('promoted', data_get($historyPayload, 'events.0.event'));
        $this->assertSame('/', data_get($historyPayload, 'events.0.route'));

        $listExit = Artisan::call('atlas:engineering:visual-baseline', [
            'action' => 'list',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $listPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $listExit);
        $this->assertSame(1, $listPayload['count'] ?? null);
        $this->assertSame('/', data_get($listPayload, 'baselines.0.route'));

        $strictAfterPromoteExit = $this->runVisualSmoke($port + 1000, 'strict');
        $strictAfterPromotePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $strictAfterPromoteExit);
        $this->assertSame('stable', data_get($strictAfterPromotePayload, 'routes.0.baseline.status'));
    }

    public function test_visual_smoke_compares_screenshot_baseline_when_playwright_exists(): void
    {
        $this->installFakePlaywrightRuntime();
        File::put($this->workspace.'/visual-color.txt', 'red');

        $port = 50_000 + random_int(0, 299);
        $firstExit = $this->runVisualSmoke($port, 'observe');
        $firstPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $firstExit, $this->visualManifestForFailure());
        $this->assertSame('captured', data_get($firstPayload, 'routes.0.screenshot.status'));
        $this->assertSame('captured', data_get($firstPayload, 'routes.0.screenshot.trace.status'));
        $this->assertSame('workspace', data_get($firstPayload, 'routes.0.screenshot.driver.source'));
        $this->assertSame('first_baseline', data_get($firstPayload, 'routes.0.screenshot.baseline.status'));
        $this->assertFileExists($this->workspace.'/atlas-visual-report/screenshots/root.png');
        $this->assertFileExists($this->workspace.'/atlas-visual-report/traces/root.trace.zip');
        $this->assertFileExists($this->baselineRoot().'/root.png');

        File::put($this->workspace.'/visual-color.txt', 'blue');
        $strictExit = $this->runVisualSmoke($port + 300, 'strict');
        $strictPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $strictExit);
        $this->assertSame('failed', $strictPayload['status'] ?? null);
        $this->assertSame('changed', data_get($strictPayload, 'routes.0.screenshot.baseline.status'));
        $this->assertSame(4, data_get($strictPayload, 'routes.0.screenshot.baseline.changed_pixels'));
        $this->assertEquals(1.0, data_get($strictPayload, 'routes.0.screenshot.baseline.diff_ratio'));
        $this->assertSame(1, data_get($strictPayload, 'strict_failure_summary.screenshot_baseline_changed_count'));
        $this->assertFileExists($this->workspace.'/atlas-visual-report/screenshots/root.diff.png');

        $applyExit = Artisan::call('atlas:engineering:visual-baseline', [
            'action' => 'promote',
            '--workspace' => $this->workspace,
            '--manifest' => 'atlas-visual-report/manifest.json',
            '--apply' => true,
            '--json' => true,
        ]);
        $applyPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $applyExit);
        $this->assertSame('updated', data_get($applyPayload, 'promotions.0.status'));
        $this->assertNotEmpty(data_get($applyPayload, 'promotions.0.screenshot_sha256'));

        $strictAfterPromoteExit = $this->runVisualSmoke($port + 600, 'strict');
        $strictAfterPromotePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $strictAfterPromoteExit);
        $this->assertSame('stable', data_get($strictAfterPromotePayload, 'routes.0.screenshot.baseline.status'));
        $promotedScreenshotHash = data_get($strictAfterPromotePayload, 'routes.0.screenshot.sha256');

        File::deleteDirectory($this->workspace.'/node_modules');
        $noScreenshotExit = $this->runVisualSmoke($port + 900, 'observe', [
            '--screenshot-driver' => 'off',
        ]);
        $noScreenshotPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $noScreenshotExit);
        $this->assertSame('skipped', data_get($noScreenshotPayload, 'routes.0.screenshot.status'));

        $promoteDomOnlyExit = Artisan::call('atlas:engineering:visual-baseline', [
            'action' => 'promote',
            '--workspace' => $this->workspace,
            '--manifest' => 'atlas-visual-report/manifest.json',
            '--apply' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $promoteDomOnlyExit);

        $listExit = Artisan::call('atlas:engineering:visual-baseline', [
            'action' => 'list',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $listPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $listExit);
        $this->assertSame($promotedScreenshotHash, data_get($listPayload, 'baselines.0.screenshot_sha256'));
    }

    public function test_visual_smoke_can_use_atlas_managed_playwright_runtime_outside_workspace(): void
    {
        $managedRoot = sys_get_temp_dir().'/atlas-managed-playwright-'.bin2hex(random_bytes(4));
        $this->managedRuntimeRoots[] = $managedRoot;
        $managedNodeModules = $managedRoot.'/node_modules';
        $this->installFakePlaywrightRuntime($managedNodeModules);
        config()->set('atlas.engineering.visual_e2e.playwright_node_modules', [$managedNodeModules]);
        File::deleteDirectory($this->workspace.'/node_modules');
        File::put($this->workspace.'/visual-color.txt', 'red');

        $port = 51_000 + random_int(0, 299);
        $exit = $this->runVisualSmoke($port, 'observe', [
            '--screenshot-driver' => 'atlas',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, $this->visualManifestForFailure());
        $this->assertSame('ready', data_get($payload, 'screenshot_driver.status'));
        $this->assertSame('atlas_managed', data_get($payload, 'screenshot_driver.source'));
        $this->assertNotEmpty(data_get($payload, 'screenshot_driver.browsers_path_hash'));
        $this->assertSame('captured', data_get($payload, 'routes.0.screenshot.status'));
        $this->assertSame('atlas_managed', data_get($payload, 'routes.0.screenshot.driver.source'));
        $this->assertSame('captured', data_get($payload, 'routes.0.screenshot.trace.status'));
        $this->assertFileExists($this->workspace.'/atlas-visual-report/screenshots/root.png');
        $this->assertFileExists($this->workspace.'/atlas-visual-report/traces/root.trace.zip');
    }

    private function runVisualSmoke(int $port, string $baseline, array $extraOptions = []): int
    {
        return Artisan::call('atlas:engineering:visual-smoke', array_merge([
            '--workspace' => $this->workspace,
            '--start-command' => escapeshellarg(PHP_BINARY).' -S 127.0.0.1:{port} -t public',
            '--url' => 'http://127.0.0.1:{port}',
            '--port' => $port,
            '--timeout' => 10,
            '--artifact-dir' => 'atlas-visual-report',
            '--baseline' => $baseline,
            '--json' => true,
        ], $extraOptions));
    }

    private function installFakePlaywrightRuntime(?string $nodeModules = null): void
    {
        $nodeModules ??= $this->workspace.'/node_modules';
        File::ensureDirectoryExists($nodeModules.'/playwright');
        File::ensureDirectoryExists($this->workspace.'/bin');
        $node = $this->workspace.'/bin/node';
        $php = PHP_BINARY;
        File::put($node, <<<PHP
#!{$php}
<?php
\$screenshotPath = \$argv[3] ?? null;
\$tracePath = \$argv[5] ?? null;
if (! is_string(\$screenshotPath) || \$screenshotPath === '') {
    fwrite(STDERR, "missing screenshot path\\n");
    exit(1);
}
if (is_string(\$tracePath) && \$tracePath !== '') {
    @mkdir(dirname(\$tracePath), 0777, true);
    file_put_contents(\$tracePath, 'fake-playwright-trace');
}
\$colorName = trim((string) @file_get_contents(getcwd().'/visual-color.txt'));
\$image = imagecreatetruecolor(2, 2);
\$color = \$colorName === 'blue'
    ? imagecolorallocate(\$image, 0, 0, 255)
    : imagecolorallocate(\$image, 255, 0, 0);
imagefilledrectangle(\$image, 0, 0, 1, 1, \$color);
imagepng(\$image, \$screenshotPath);
imagedestroy(\$image);
PHP);
        chmod($node, 0755);
        putenv('PATH='.$this->workspace.'/bin:'.($this->originalPath !== false ? $this->originalPath : ''));
    }

    private function baselineRoot(): string
    {
        $workspace = realpath($this->workspace) ?: $this->workspace;

        return storage_path('app/engineering-visual-baselines/'.substr(hash('sha256', $workspace), 0, 16));
    }

    private function visualManifestForFailure(): string
    {
        $manifest = $this->workspace.'/atlas-visual-report/manifest.json';

        return File::exists($manifest) ? File::get($manifest) : Artisan::output();
    }
}
