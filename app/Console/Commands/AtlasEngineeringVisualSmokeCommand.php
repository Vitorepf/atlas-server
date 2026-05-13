<?php

namespace App\Console\Commands;

use App\Services\Tools\AtlasToolEvidenceStore;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AtlasEngineeringVisualSmokeCommand extends Command
{
    protected $signature = 'atlas:engineering:visual-smoke
        {--workspace= : Target workspace path. Defaults to current directory}
        {--start-command= : Command that starts the local web app; port, host and workspace placeholders are expanded}
        {--url= : Base URL to probe; port and host placeholders are expanded}
        {--route=* : Route path to probe. Defaults to /}
        {--port= : Local port. Defaults to a deterministic high port}
        {--timeout=45 : Seconds to wait for the app to become reachable}
        {--artifact-dir=atlas-visual-report : Relative workspace directory for report artifacts}
        {--baseline=observe : off, observe or strict DOM baseline comparison}
        {--screenshot-baseline=auto : auto, off, observe or strict pixel screenshot baseline comparison}
        {--screenshot-driver= : auto, workspace, atlas or off. Defaults to config/auto}
        {--run-context-type= : Optional Atlas Tool Runtime context type}
        {--run-context-id= : Optional Atlas Tool Runtime context id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas-managed local visual smoke checks and persist route snapshots.';

    public function handle(AtlasToolEvidenceStore $toolEvidence): int
    {
        $workspace = $this->workspace();
        $host = '127.0.0.1';
        $port = $this->port($workspace);
        $artifactDir = $this->artifactDir();
        $artifactRoot = $workspace.'/'.$artifactDir;
        File::deleteDirectory($artifactRoot);
        File::ensureDirectoryExists($artifactRoot.'/routes');

        $startCommand = $this->expandedOption('start-command', $workspace, $host, $port)
            ?: $this->detectStartCommand($workspace, $host, $port);
        $baseUrl = rtrim($this->expandedOption('url', $workspace, $host, $port) ?: "http://{$host}:{$port}", '/');
        $routes = $this->routes($workspace);
        $baselineMode = $this->baselineMode();
        $screenshotBaselineMode = $this->screenshotBaselineMode($baselineMode);
        $screenshotDriver = $this->screenshotDriver($workspace);
        $timeout = max(5, (int) $this->option('timeout'));
        $startedAt = hrtime(true);
        $process = null;
        $routeResults = [];
        $ready = false;
        $failure = null;

        if ($startCommand === null) {
            $failure = 'no_start_command_detected';
        } elseif (! $this->isLocalUrl($baseUrl)) {
            $failure = 'non_local_visual_smoke_url';
        } else {
            $process = Process::fromShellCommandline($startCommand, $workspace, AtlasSecurity::processEnv([
                'CI' => '1',
                'BROWSER' => 'none',
                'HOST' => $host,
                'PORT' => (string) $port,
            ], 'tool'));
            $process->setTimeout(null);
            $process->start();

            $ready = $this->waitUntilReady($baseUrl, $timeout, $process);
            if (! $ready) {
                $failure = $process->isRunning() ? 'visual_smoke_timeout' : 'visual_smoke_process_exited';
            } else {
                foreach ($routes as $route) {
                    $routeResults[] = $this->captureRoute($workspace, $artifactRoot, $baseUrl, $route, $baselineMode);
                }

                $routeResults = $this->captureScreenshotsIfAvailable(
                    $workspace,
                    $artifactRoot,
                    $baseUrl,
                    $routeResults,
                    $screenshotBaselineMode,
                    $timeout,
                    $screenshotDriver,
                );
            }
        }

        $screenshot = $ready && $routeResults !== []
            ? (array) ($routeResults[0]['screenshot'] ?? ['status' => 'skipped', 'reason' => 'screenshot_missing'])
            : ['status' => 'skipped', 'reason' => $ready ? 'no_routes' : 'app_not_ready'];

        if ($process instanceof Process && $process->isRunning()) {
            $process->stop(2);
        }

        $failedRoutes = collect($routeResults)->filter(fn (array $route): bool => ! (bool) ($route['ok'] ?? false))->values();
        $strictBaselineChanges = collect($routeResults)->filter(fn (array $route): bool => (string) data_get($route, 'baseline.status') === 'changed' && $baselineMode === 'strict')->values();
        $strictScreenshotFailures = collect($routeResults)->filter(fn (array $route): bool => (string) data_get($route, 'screenshot.status') === 'failed' && $screenshotBaselineMode === 'strict')->values();
        $strictScreenshotChanges = collect($routeResults)->filter(fn (array $route): bool => (string) data_get($route, 'screenshot.baseline.status') === 'changed' && $screenshotBaselineMode === 'strict')->values();
        $requiredScreenshotFailures = collect($routeResults)->filter(fn (array $route): bool => (string) data_get($route, 'screenshot.status') === 'failed' && (bool) data_get($route, 'screenshot.driver.required', false))->values();
        $ok = $failure === null && $ready && $failedRoutes->isEmpty() && $strictBaselineChanges->isEmpty() && $strictScreenshotFailures->isEmpty() && $strictScreenshotChanges->isEmpty() && $requiredScreenshotFailures->isEmpty();
        $manifest = [
            'status' => $ok ? 'passed' : 'failed',
            'failure' => $failure,
            'workspace_hash' => hash('sha256', $workspace),
            'base_url' => $baseUrl,
            'routes' => $routeResults,
            'screenshot' => $screenshot,
            'screenshot_driver' => $this->compactScreenshotDriver($screenshotDriver),
            'baseline_mode' => $baselineMode,
            'screenshot_baseline_mode' => $screenshotBaselineMode,
            'strict_failure_summary' => [
                'route_failed_count' => $failedRoutes->count(),
                'dom_baseline_changed_count' => $strictBaselineChanges->count(),
                'screenshot_failed_count' => $strictScreenshotFailures->count(),
                'screenshot_baseline_changed_count' => $strictScreenshotChanges->count(),
                'screenshot_required_failed_count' => $requiredScreenshotFailures->count(),
            ],
            'artifact_dir' => $artifactDir,
            'started_command' => $startCommand ? AtlasSecurity::redactString($startCommand) : null,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'process' => [
                'exit_code' => $process instanceof Process && ! $process->isRunning() ? $process->getExitCode() : null,
                'stdout_excerpt' => $process instanceof Process ? Str::limit(AtlasSecurity::redactString($process->getOutput()), 2000, "\n...[truncated]") : null,
                'stderr_excerpt' => $process instanceof Process ? Str::limit(AtlasSecurity::redactString($process->getErrorOutput()), 2000, "\n...[truncated]") : null,
            ],
        ];

        File::put($artifactRoot.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->recordToolRuntimeEvidence($toolEvidence, $workspace, $artifactRoot, $manifest);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($ok) {
            $this->info('Atlas visual smoke passed: '.$baseUrl);
        } else {
            $this->error('Atlas visual smoke failed: '.($failure ?: 'route_or_baseline_failed'));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function recordToolRuntimeEvidence(AtlasToolEvidenceStore $toolEvidence, string $workspace, string $artifactRoot, array $manifest): void
    {
        try {
            $toolEvidence->recordExternalToolResult('atlas_visual_smoke', $workspace, [
                'status' => (string) ($manifest['status'] ?? 'unknown'),
                'required' => $this->baselineMode() === 'strict' || $this->screenshotBaselineMode($this->baselineMode()) === 'strict',
                'failure_policy' => 'blocks_release',
                'policy_decision' => 'allowed',
                'duration_ms' => (int) ($manifest['duration_ms'] ?? 0),
                'exit_code' => ($manifest['status'] ?? null) === 'passed' ? 0 : 1,
                'category' => 'browser_automation',
                'metrics' => [
                    'route_count' => count((array) ($manifest['routes'] ?? [])),
                    'route_failed_count' => data_get($manifest, 'strict_failure_summary.route_failed_count', 0),
                    'dom_baseline_changed_count' => data_get($manifest, 'strict_failure_summary.dom_baseline_changed_count', 0),
                    'screenshot_failed_count' => data_get($manifest, 'strict_failure_summary.screenshot_failed_count', 0),
                    'screenshot_baseline_changed_count' => data_get($manifest, 'strict_failure_summary.screenshot_baseline_changed_count', 0),
                ],
                'findings' => $this->toolRuntimeFindings($manifest),
                'recommendations' => $this->toolRuntimeRecommendations($manifest),
                'artifact_paths' => $this->toolRuntimeArtifactPaths($artifactRoot, $manifest),
            ], [
                'surface' => 'engineering_visual_smoke',
                'source' => 'atlas_engineering_visual_smoke_command',
                'run_context_type' => $this->stringOption('run-context-type'),
                'run_context_id' => $this->stringOption('run-context-id'),
                'metadata' => [
                    'artifact_dir' => $manifest['artifact_dir'] ?? null,
                    'artifact_root_hash' => hash('sha256', $artifactRoot),
                    'base_url_hash' => isset($manifest['base_url']) ? hash('sha256', (string) $manifest['base_url']) : null,
                    'baseline_mode' => $manifest['baseline_mode'] ?? null,
                    'screenshot_baseline_mode' => $manifest['screenshot_baseline_mode'] ?? null,
                    'screenshot_driver' => $manifest['screenshot_driver'] ?? null,
                    'failure' => $manifest['failure'] ?? null,
                ],
            ]);
        } catch (\Throwable) {
            // Visual smoke must keep its historical behavior even if generic evidence is unavailable.
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,array<string,mixed>>
     */
    private function toolRuntimeFindings(array $manifest): array
    {
        $findings = [];
        if (is_string($manifest['failure'] ?? null) && $manifest['failure'] !== '') {
            $findings[] = [
                'rule_id' => 'atlas_visual_smoke.failure',
                'title' => 'Visual smoke failed before route capture',
                'message' => (string) $manifest['failure'],
                'severity' => 'high',
                'blocks_resolved' => true,
            ];
        }

        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            if (! is_array($route)) {
                continue;
            }

            $routePath = (string) ($route['route'] ?? '/');
            if (! (bool) ($route['ok'] ?? false)) {
                $findings[] = [
                    'rule_id' => 'atlas_visual_smoke.route_failed',
                    'title' => 'Visual route did not return a successful response',
                    'message' => $routePath.' returned '.(string) ($route['status_code'] ?? $route['error'] ?? 'unknown'),
                    'severity' => 'high',
                    'file_path' => $route['artifact'] ?? null,
                    'blocks_resolved' => true,
                    'metadata' => ['route' => $routePath],
                ];
            }

            if ((string) data_get($route, 'baseline.status') === 'changed' && (bool) data_get($route, 'baseline.strict', false)) {
                $findings[] = [
                    'rule_id' => 'atlas_visual_smoke.dom_baseline_changed',
                    'title' => 'Strict DOM baseline changed',
                    'message' => $routePath.' differs from the promoted DOM baseline.',
                    'severity' => 'medium',
                    'file_path' => $route['artifact'] ?? null,
                    'blocks_resolved' => true,
                    'metadata' => ['route' => $routePath],
                ];
            }

            if ((string) data_get($route, 'screenshot.status') === 'failed' && (bool) data_get($route, 'screenshot.driver.required', false)) {
                $findings[] = [
                    'rule_id' => 'atlas_visual_smoke.screenshot_failed',
                    'title' => 'Required screenshot capture failed',
                    'message' => (string) (data_get($route, 'screenshot.reason') ?? data_get($route, 'screenshot.stderr_excerpt') ?? 'screenshot_failed'),
                    'severity' => 'high',
                    'blocks_resolved' => true,
                    'metadata' => ['route' => $routePath],
                ];
            }

            if ((string) data_get($route, 'screenshot.baseline.status') === 'changed' && (bool) data_get($route, 'screenshot.baseline.strict', false)) {
                $findings[] = [
                    'rule_id' => 'atlas_visual_smoke.screenshot_baseline_changed',
                    'title' => 'Strict screenshot baseline changed',
                    'message' => $routePath.' differs from the promoted screenshot baseline.',
                    'severity' => 'medium',
                    'file_path' => data_get($route, 'screenshot.artifact'),
                    'blocks_resolved' => true,
                    'metadata' => [
                        'route' => $routePath,
                        'changed_pixels' => data_get($route, 'screenshot.baseline.changed_pixels'),
                        'diff_ratio' => data_get($route, 'screenshot.baseline.diff_ratio'),
                    ],
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function toolRuntimeRecommendations(array $manifest): array
    {
        if (($manifest['status'] ?? null) === 'passed') {
            return [];
        }

        return [
            'Open the visual smoke manifest and inspect failed routes, DOM baselines and screenshot baselines before promoting new baselines.',
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,string>
     */
    private function toolRuntimeArtifactPaths(string $artifactRoot, array $manifest): array
    {
        $paths = [
            'manifest' => $artifactRoot.'/manifest.json',
        ];

        foreach ((array) ($manifest['routes'] ?? []) as $index => $route) {
            if (! is_array($route)) {
                continue;
            }

            foreach (['artifact', 'headers_artifact'] as $key) {
                $relative = $route[$key] ?? null;
                if (is_string($relative) && $relative !== '') {
                    $paths['route_'.$index.'_'.$key] = $artifactRoot.'/'.$relative;
                }
            }
        }

        return $paths;
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        if (! $resolved || ! is_dir($resolved)) {
            throw new \InvalidArgumentException("Workspace invalido: {$workspace}");
        }

        return $resolved;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function port(string $workspace): int
    {
        $configured = $this->option('port');
        if (is_numeric($configured) && (int) $configured >= 1024 && (int) $configured <= 65535) {
            return (int) $configured;
        }

        return 45_000 + (hexdec(substr(hash('crc32b', $workspace), 0, 4)) % 10_000);
    }

    private function artifactDir(): string
    {
        $dir = trim((string) ($this->option('artifact-dir') ?: 'atlas-visual-report'), "/ \t\n\r\0\x0B");
        if ($dir === '' || str_contains($dir, '..')) {
            throw new \InvalidArgumentException('--artifact-dir deve ser relativo e nao pode conter ..');
        }

        return $dir;
    }

    private function expandedOption(string $name, string $workspace, string $host, int $port): ?string
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace(
            ['{workspace}', '{host}', '{port}'],
            [$workspace, $host, (string) $port],
            trim($value),
        );
    }

    /**
     * @return array<int,string>
     */
    private function routes(string $workspace): array
    {
        $routes = array_values(array_filter((array) $this->option('route'), fn (mixed $route): bool => is_scalar($route) && trim((string) $route) !== ''));

        if ($routes !== []) {
            return array_map(fn (mixed $route): string => (string) $route, $routes);
        }

        if (File::isFile($workspace.'/routes/api.php') && ! File::isFile($workspace.'/routes/web.php')) {
            return [$this->defaultApiHealthRoute($workspace)];
        }

        return ['/'];
    }

    private function defaultApiHealthRoute(string $workspace): string
    {
        $bootstrap = $workspace.'/bootstrap/app.php';
        if (File::isFile($bootstrap) && str_contains((string) File::get($bootstrap), "apiPrefix: ''")) {
            return '/health';
        }

        return '/api/health';
    }

    private function baselineMode(): string
    {
        $mode = trim((string) ($this->option('baseline') ?: config('atlas.engineering.visual_e2e.baseline_mode', 'observe')));

        return in_array($mode, ['off', 'observe', 'strict'], true) ? $mode : 'observe';
    }

    private function screenshotBaselineMode(string $baselineMode): string
    {
        $mode = trim((string) ($this->option('screenshot-baseline') ?: 'auto'));
        if ($mode === 'auto') {
            return $baselineMode === 'off' ? 'off' : $baselineMode;
        }

        return in_array($mode, ['off', 'observe', 'strict'], true)
            ? $mode
            : ($baselineMode === 'off' ? 'off' : $baselineMode);
    }

    private function detectStartCommand(string $workspace, string $host, int $port): ?string
    {
        if (File::isFile($workspace.'/artisan')) {
            return escapeshellarg(AtlasPhpBinary::path()).' artisan serve --host='.escapeshellarg($host).' --port='.escapeshellarg((string) $port);
        }

        if (File::isFile($workspace.'/public/index.php')) {
            return escapeshellarg(AtlasPhpBinary::path()).' -S '.escapeshellarg($host.':'.$port).' -t public';
        }

        $package = $this->jsonFile($workspace.'/package.json');
        $scripts = (array) ($package['scripts'] ?? []);
        $packageManager = $this->packageManager($workspace);
        $deps = array_merge((array) ($package['dependencies'] ?? []), (array) ($package['devDependencies'] ?? []));

        if (isset($scripts['dev']) && (File::exists($workspace.'/vite.config.ts') || File::exists($workspace.'/vite.config.js') || isset($deps['vite']))) {
            return $packageManager.' run dev -- --host '.escapeshellarg($host).' --port '.escapeshellarg((string) $port);
        }

        if (isset($scripts['web'])) {
            return 'PORT='.escapeshellarg((string) $port).' HOST='.escapeshellarg($host).' '.$packageManager.' run web -- --host '.escapeshellarg($host).' --port '.escapeshellarg((string) $port);
        }

        if (isset($scripts['start'])) {
            return 'PORT='.escapeshellarg((string) $port).' HOST='.escapeshellarg($host).' BROWSER=none '.$packageManager.' run start';
        }

        return null;
    }

    private function waitUntilReady(string $baseUrl, int $timeout, ?Process $process): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            if ($process instanceof Process && ! $process->isRunning()) {
                return false;
            }

            try {
                $response = Http::timeout(2)->get($baseUrl);
                if ($response->status() >= 200 && $response->status() < 500) {
                    return true;
                }
            } catch (\Throwable) {
                usleep(250_000);
            }
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function captureRoute(string $workspace, string $artifactRoot, string $baseUrl, string $route, string $baselineMode): array
    {
        $routePath = $this->normalizeRoute($route);
        $url = $baseUrl.$routePath;
        $slug = $this->routeSlug($routePath);
        $started = hrtime(true);

        try {
            $response = Http::timeout(10)->get($url);
            $body = AtlasSecurity::redactString($response->body());
            $headers = $response->headers();
            $bodyHash = hash('sha256', $body);
            File::put($artifactRoot.'/routes/'.$slug.'.html', $body);
            File::put($artifactRoot.'/routes/'.$slug.'.headers.json', json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return [
                'route' => $routePath,
                'url' => $url,
                'ok' => $response->successful(),
                'status_code' => $response->status(),
                'content_type' => $response->header('content-type'),
                'body_hash' => $bodyHash,
                'bytes' => strlen($body),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'slug' => $slug,
                'artifact' => 'routes/'.$slug.'.html',
                'headers_artifact' => 'routes/'.$slug.'.headers.json',
                'baseline' => $this->baselineStatus($workspace, $routePath, $bodyHash, $baselineMode),
            ];
        } catch (\Throwable $exception) {
            return [
                'route' => $routePath,
                'url' => $url,
                'ok' => false,
                'error' => AtlasSecurity::redactString($exception->getMessage()),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'baseline' => ['status' => 'skipped', 'reason' => 'route_capture_failed'],
            ];
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $routeResults
     * @return array<int,array<string,mixed>>
     */
    private function captureScreenshotsIfAvailable(
        string $workspace,
        string $artifactRoot,
        string $baseUrl,
        array $routeResults,
        string $baselineMode,
        int $timeout,
        array $driver,
    ): array {
        if (($driver['status'] ?? null) !== 'ready') {
            return array_map(function (array $route) use ($driver): array {
                $route['screenshot'] = [
                    'status' => (bool) ($driver['required'] ?? false) ? 'failed' : 'skipped',
                    'reason' => $driver['reason'] ?? 'playwright_dependency_not_installed',
                    'driver' => $this->compactScreenshotDriver($driver),
                ];

                return $route;
            }, $routeResults);
        }

        File::ensureDirectoryExists($artifactRoot.'/screenshots');
        File::ensureDirectoryExists($artifactRoot.'/traces');

        $script = $workspace.'/.atlas-visual-smoke-screenshot.cjs';
        File::put($script, <<<'JS'
const pathModule = require('path');
const { createRequire } = require('module');

const [, , url, screenshotPath, timeoutArg, tracePath] = process.argv;
const timeout = Number(timeoutArg || 30000);
const requireFromRoot = process.env.ATLAS_PLAYWRIGHT_REQUIRE_FROM || process.cwd();
const requireFrom = createRequire(requireFromRoot.endsWith('.json')
  ? requireFromRoot
  : pathModule.join(requireFromRoot, 'package.json'));
let runtime;
try {
  runtime = requireFrom('playwright');
} catch (error) {
  runtime = requireFrom('@playwright/test');
}
const chromium = runtime.chromium;
if (!chromium) {
  throw new Error('Playwright chromium runtime not available.');
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
    try {
      if (tracePath) {
        await context.tracing.start({ screenshots: true, snapshots: true, sources: false });
      }
      const page = await context.newPage();
      await page.goto(url, { waitUntil: 'networkidle', timeout });
      await page.screenshot({ path: screenshotPath, fullPage: true });
      if (tracePath) {
        await context.tracing.stop({ path: tracePath });
      }
    } finally {
      await context.close();
    }
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error && error.stack ? error.stack : String(error));
  process.exit(1);
});
JS);

        try {
            foreach ($routeResults as $index => $route) {
                if (! (bool) ($route['ok'] ?? false)) {
                    $routeResults[$index]['screenshot'] = [
                        'status' => 'skipped',
                        'reason' => 'route_not_ok',
                        'driver' => $this->compactScreenshotDriver($driver),
                    ];

                    continue;
                }

                $routePath = $this->normalizeRoute((string) ($route['route'] ?? '/'));
                $slug = (string) ($route['slug'] ?? $this->routeSlug($routePath));
                $artifact = 'screenshots/'.$slug.'.png';
                $screenshot = $artifactRoot.'/'.$artifact;
                $traceArtifact = 'traces/'.$slug.'.trace.zip';
                $tracePath = $artifactRoot.'/'.$traceArtifact;
                $url = $baseUrl.$routePath;
                $driverEnv = [
                    'ATLAS_PLAYWRIGHT_REQUIRE_FROM' => (string) ($driver['require_from'] ?? $workspace),
                    'NODE_PATH' => (string) ($driver['node_modules'] ?? ''),
                ];
                if (is_string($driver['browsers_path'] ?? null) && $driver['browsers_path'] !== '') {
                    $driverEnv['PLAYWRIGHT_BROWSERS_PATH'] = (string) $driver['browsers_path'];
                }

                $process = new Process(['node', $script, $url, $screenshot, (string) ($timeout * 1000), $tracePath], $workspace, AtlasSecurity::processEnv($driverEnv, 'tool'));
                $process->setTimeout($timeout + 10);
                $process->run();

                if (($process->getExitCode() ?? 1) !== 0 || ! File::isFile($screenshot)) {
                    $routeResults[$index]['screenshot'] = [
                        'status' => 'failed',
                        'exit_code' => $process->getExitCode() ?? 1,
                        'stderr_excerpt' => Str::limit(AtlasSecurity::redactString($process->getErrorOutput()), 1200),
                        'driver' => $this->compactScreenshotDriver($driver),
                    ];

                    continue;
                }

                $hash = hash_file('sha256', $screenshot);
                $routeResults[$index]['screenshot'] = [
                    'status' => 'captured',
                    'artifact' => $artifact,
                    'bytes' => File::size($screenshot),
                    'sha256' => $hash,
                    'driver' => $this->compactScreenshotDriver($driver),
                    'trace' => $this->traceArtifactPayload($tracePath, $traceArtifact),
                    'baseline' => $this->screenshotBaselineStatus($workspace, $artifactRoot, $routePath, $slug, $screenshot, $hash, $baselineMode),
                ];
            }

            return $routeResults;
        } catch (\Throwable $exception) {
            return array_map(function (array $route) use ($exception): array {
                $route['screenshot'] = [
                    'status' => 'failed',
                    'error' => AtlasSecurity::redactString($exception->getMessage()),
                    'driver' => $this->compactScreenshotDriver($driver),
                ];

                return $route;
            }, $routeResults);
        } finally {
            File::delete($script);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function traceArtifactPayload(string $tracePath, string $traceArtifact): array
    {
        if (! File::isFile($tracePath)) {
            return [
                'status' => 'skipped',
                'reason' => 'trace_not_emitted',
            ];
        }

        return [
            'status' => 'captured',
            'artifact' => $traceArtifact,
            'bytes' => File::size($tracePath),
            'sha256' => hash_file('sha256', $tracePath),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function screenshotDriver(string $workspace): array
    {
        $mode = $this->screenshotDriverMode();
        if ($mode === 'off') {
            return [
                'status' => 'disabled',
                'mode' => $mode,
                'source' => 'off',
                'required' => false,
                'reason' => 'screenshot_driver_disabled',
            ];
        }

        if (in_array($mode, ['auto', 'workspace'], true)) {
            $workspaceDriver = $this->workspacePlaywrightDriver($workspace, $mode === 'workspace');
            if ($workspaceDriver !== null) {
                return $workspaceDriver;
            }
        }

        if (in_array($mode, ['auto', 'atlas'], true)) {
            $atlasDriver = $this->atlasPlaywrightDriver($mode === 'atlas');
            if ($atlasDriver !== null) {
                return $atlasDriver;
            }
        }

        return [
            'status' => 'missing',
            'mode' => $mode,
            'source' => $mode === 'atlas' ? 'atlas_managed' : ($mode === 'workspace' ? 'workspace' : 'auto'),
            'required' => in_array($mode, ['workspace', 'atlas'], true),
            'reason' => match ($mode) {
                'workspace' => 'workspace_playwright_dependency_not_installed',
                'atlas' => 'atlas_playwright_runtime_not_configured',
                default => 'playwright_dependency_not_installed',
            },
            'searched_node_modules' => $mode === 'workspace'
                ? [$workspace.'/node_modules']
                : $this->configuredPlaywrightNodeModules(),
        ];
    }

    private function screenshotDriverMode(): string
    {
        $configured = $this->option('screenshot-driver');
        $mode = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : (string) config('atlas.engineering.visual_e2e.screenshot_driver', 'auto');

        return in_array($mode, ['auto', 'workspace', 'atlas', 'off'], true) ? $mode : 'auto';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function workspacePlaywrightDriver(string $workspace, bool $required): ?array
    {
        return $this->driverFromNodeModules($workspace.'/node_modules', 'workspace', 'workspace', $required);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function atlasPlaywrightDriver(bool $required): ?array
    {
        foreach ($this->configuredPlaywrightNodeModules() as $nodeModules) {
            $driver = $this->driverFromNodeModules($nodeModules, 'atlas', 'atlas_managed', $required);
            if ($driver !== null) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function configuredPlaywrightNodeModules(): array
    {
        $configured = config('atlas.engineering.visual_e2e.playwright_node_modules', []);
        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }

        return collect((array) $configured)
            ->filter(fn (mixed $path): bool => is_scalar($path) && trim((string) $path) !== '')
            ->map(fn (mixed $path): string => rtrim((string) $path, DIRECTORY_SEPARATOR))
            ->push(base_path('node_modules'))
            ->push(storage_path('app/engineering-playwright/node_modules'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function driverFromNodeModules(string $nodeModules, string $mode, string $source, bool $required): ?array
    {
        $nodeModules = rtrim($nodeModules, DIRECTORY_SEPARATOR);
        if (basename($nodeModules) !== 'node_modules' && File::isDirectory($nodeModules.'/node_modules')) {
            $nodeModules .= '/node_modules';
        }

        if (! File::isDirectory($nodeModules)) {
            return null;
        }

        $hasPlaywright = File::isDirectory($nodeModules.'/playwright')
            || File::isDirectory($nodeModules.'/@playwright/test');
        if (! $hasPlaywright) {
            return null;
        }

        $browsersPath = $source === 'atlas_managed'
            ? dirname($nodeModules).'/browsers'
            : null;

        return [
            'status' => 'ready',
            'mode' => $mode,
            'source' => $source,
            'required' => $required,
            'node_modules' => $nodeModules,
            'node_modules_hash' => hash('sha256', $nodeModules),
            'require_from' => dirname($nodeModules),
            'require_from_hash' => hash('sha256', dirname($nodeModules)),
            'browsers_path' => $browsersPath,
            'browsers_path_hash' => is_string($browsersPath) ? hash('sha256', $browsersPath) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $driver
     * @return array<string,mixed>
     */
    private function compactScreenshotDriver(array $driver): array
    {
        return [
            'status' => $driver['status'] ?? 'unknown',
            'mode' => $driver['mode'] ?? null,
            'source' => $driver['source'] ?? null,
            'required' => (bool) ($driver['required'] ?? false),
            'reason' => $driver['reason'] ?? null,
            'node_modules_hash' => $driver['node_modules_hash'] ?? null,
            'require_from_hash' => $driver['require_from_hash'] ?? null,
            'browsers_path_hash' => $driver['browsers_path_hash'] ?? null,
            'searched_count' => count((array) ($driver['searched_node_modules'] ?? [])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function screenshotBaselineStatus(
        string $workspace,
        string $artifactRoot,
        string $route,
        string $slug,
        string $screenshotPath,
        string $screenshotHash,
        string $mode,
    ): array {
        if ($mode === 'off') {
            return ['status' => 'skipped', 'reason' => 'baseline_disabled'];
        }

        $root = storage_path('app/engineering-visual-baselines/'.substr(hash('sha256', $workspace), 0, 16));
        $baselinePath = $root.'/'.$slug.'.json';
        $baselineImage = $root.'/'.$slug.'.png';
        $base = [
            'current_hash' => $screenshotHash,
            'baseline_file_hash' => hash('sha256', $baselinePath),
            'baseline_image_hash' => hash('sha256', $baselineImage),
            'strict' => $mode === 'strict',
        ];
        $baseline = File::isFile($baselinePath) ? json_decode(File::get($baselinePath), true) : null;
        $previousHash = is_array($baseline) ? (string) ($baseline['screenshot_sha256'] ?? '') : '';

        if ($previousHash === '' || ! File::isFile($baselineImage)) {
            File::ensureDirectoryExists($root);
            File::copy($screenshotPath, $baselineImage);
            $nextBaseline = array_merge(is_array($baseline) ? $baseline : [], [
                'route' => $route,
                'screenshot_sha256' => $screenshotHash,
                'screenshot_baseline_file' => basename($baselineImage),
                'screenshot_artifact' => 'screenshots/'.$slug.'.png',
                'workspace_hash' => hash('sha256', $workspace),
                'created_at' => is_array($baseline) ? ($baseline['created_at'] ?? now()->toJSON()) : now()->toJSON(),
            ]);
            File::put($baselinePath, json_encode($nextBaseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return array_merge($base, [
                'status' => 'first_baseline',
                'previous_hash' => null,
            ]);
        }

        $diffArtifact = 'screenshots/'.$slug.'.diff.png';
        $comparison = $this->compareScreenshots($baselineImage, $screenshotPath, $artifactRoot.'/'.$diffArtifact);
        $changed = ! hash_equals($previousHash, $screenshotHash);

        return array_merge($base, $comparison, [
            'status' => $changed ? 'changed' : 'stable',
            'previous_hash' => $previousHash,
            'diff_artifact' => $changed && File::isFile($artifactRoot.'/'.$diffArtifact) ? $diffArtifact : null,
            'baseline_promoted_at' => is_array($baseline) ? ($baseline['promoted_at'] ?? $baseline['created_at'] ?? null) : null,
            'source_manifest_hash' => is_array($baseline) ? ($baseline['source_manifest_hash'] ?? null) : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function compareScreenshots(string $baselinePath, string $currentPath, string $diffPath): array
    {
        if (! function_exists('imagecreatefrompng')) {
            return ['pixel_comparison' => 'unavailable', 'reason' => 'gd_png_not_available'];
        }

        $baseline = @imagecreatefrompng($baselinePath);
        $current = @imagecreatefrompng($currentPath);
        if (! $baseline || ! $current) {
            return ['pixel_comparison' => 'unavailable', 'reason' => 'png_decode_failed'];
        }

        try {
            $baselineWidth = imagesx($baseline);
            $baselineHeight = imagesy($baseline);
            $currentWidth = imagesx($current);
            $currentHeight = imagesy($current);

            if ($baselineWidth !== $currentWidth || $baselineHeight !== $currentHeight) {
                return [
                    'pixel_comparison' => 'dimensions_mismatch',
                    'baseline_dimensions' => ['width' => $baselineWidth, 'height' => $baselineHeight],
                    'current_dimensions' => ['width' => $currentWidth, 'height' => $currentHeight],
                    'changed_pixels' => null,
                    'total_pixels' => $currentWidth * $currentHeight,
                    'diff_ratio' => 1.0,
                ];
            }

            $total = $currentWidth * $currentHeight;
            $changed = 0;
            $diff = imagecreatetruecolor($currentWidth, $currentHeight);
            $red = imagecolorallocate($diff, 255, 0, 0);

            for ($y = 0; $y < $currentHeight; $y++) {
                for ($x = 0; $x < $currentWidth; $x++) {
                    $baselinePixel = imagecolorat($baseline, $x, $y);
                    $currentPixel = imagecolorat($current, $x, $y);
                    if ($baselinePixel === $currentPixel) {
                        imagesetpixel($diff, $x, $y, $currentPixel);

                        continue;
                    }

                    $changed++;
                    imagesetpixel($diff, $x, $y, $red);
                }
            }

            if ($changed > 0) {
                imagepng($diff, $diffPath);
            }

            return [
                'pixel_comparison' => 'completed',
                'dimensions' => ['width' => $currentWidth, 'height' => $currentHeight],
                'changed_pixels' => $changed,
                'total_pixels' => $total,
                'diff_ratio' => $total > 0 ? round($changed / $total, 6) : 0.0,
            ];
        } finally {
            imagedestroy($baseline);
            imagedestroy($current);
            if (isset($diff) && $diff) {
                imagedestroy($diff);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function baselineStatus(string $workspace, string $route, string $bodyHash, string $mode): array
    {
        if ($mode === 'off') {
            return ['status' => 'skipped', 'reason' => 'baseline_disabled'];
        }

        $root = storage_path('app/engineering-visual-baselines/'.substr(hash('sha256', $workspace), 0, 16));
        $path = $root.'/'.$this->routeSlug($route).'.json';
        $base = [
            'current_hash' => $bodyHash,
            'baseline_file_hash' => hash('sha256', $path),
            'baseline_root_hash' => hash('sha256', $root),
        ];
        if (! File::isFile($path)) {
            File::ensureDirectoryExists($root);
            File::put($path, json_encode([
                'route' => $route,
                'body_hash' => $bodyHash,
                'created_at' => now()->toJSON(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return array_merge($base, [
                'status' => 'first_baseline',
                'strict' => $mode === 'strict',
            ]);
        }

        $baseline = json_decode(File::get($path), true);
        $previous = is_array($baseline) ? (string) ($baseline['body_hash'] ?? '') : '';

        return array_merge($base, [
            'status' => hash_equals($previous, $bodyHash) ? 'stable' : 'changed',
            'strict' => $mode === 'strict',
            'previous_hash' => $previous !== '' ? $previous : null,
            'baseline_promoted_at' => is_array($baseline) ? ($baseline['promoted_at'] ?? $baseline['created_at'] ?? null) : null,
            'source_manifest_hash' => is_array($baseline) ? ($baseline['source_manifest_hash'] ?? null) : null,
        ]);
    }

    private function normalizeRoute(string $route): string
    {
        $route = trim($route);
        if ($route === '') {
            return '/';
        }

        return str_starts_with($route, '/') ? $route : '/'.$route;
    }

    private function routeSlug(string $route): string
    {
        $slug = Str::slug(trim($route, '/') ?: 'root');

        return $slug !== '' ? $slug : 'root';
    }

    private function isLocalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        if (! File::isFile($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function packageManager(string $workspace): string
    {
        return match (true) {
            File::isFile($workspace.'/pnpm-lock.yaml') => 'pnpm',
            File::isFile($workspace.'/yarn.lock') => 'yarn',
            default => 'npm',
        };
    }
}
