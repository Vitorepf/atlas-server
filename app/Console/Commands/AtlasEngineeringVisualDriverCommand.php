<?php

namespace App\Console\Commands;

use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class AtlasEngineeringVisualDriverCommand extends Command
{
    protected $signature = 'atlas:engineering:visual-driver
        {action=status : status, doctor or install}
        {--runtime-dir= : Atlas-managed Playwright runtime root. Defaults to storage/app/engineering-playwright}
        {--package=playwright : npm package to install. Defaults to playwright}
        {--skip-browser-install : Install only the npm runtime package, without Chromium binaries}
        {--force : Run npm install even when the runtime package is already present}
        {--timeout=900 : Seconds allowed for each install command}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect or bootstrap the Atlas-managed Playwright runtime used by engineering visual smoke.';

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        if (! in_array($action, ['status', 'doctor', 'install'], true)) {
            $this->error('Action invalida. Use status, doctor ou install.');

            return self::FAILURE;
        }

        $runtimeDir = $this->runtimeDir();
        $result = [
            'action' => $action,
            'runtime_dir' => $runtimeDir,
            'node_modules' => $this->nodeModulesPath($runtimeDir),
            'cost_posture' => 'free_local',
            'paid_tool_required' => false,
            'before' => $this->inspectRuntime($runtimeDir),
        ];

        if ($action === 'install') {
            $result['install'] = $this->installRuntime($runtimeDir, $result['before']);
            $result['after'] = $this->inspectRuntime($runtimeDir);
        }

        $status = (array) ($result['after'] ?? $result['before']);
        $ready = (bool) ($status['ready'] ?? false);
        $exitCode = match ($action) {
            'status' => self::SUCCESS,
            'doctor', 'install' => $ready ? self::SUCCESS : self::FAILURE,
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->render($result, $status, $ready);

        return $exitCode;
    }

    private function runtimeDir(): string
    {
        $configured = trim((string) ($this->option('runtime-dir') ?: ''));
        $path = $configured !== '' ? $configured : storage_path('app/engineering-playwright');

        return AtlasSecurity::canonicalPath($path, base_path(), allowMissing: true);
    }

    private function nodeModulesPath(string $runtimeDir): string
    {
        return rtrim($runtimeDir, DIRECTORY_SEPARATOR).'/node_modules';
    }

    /**
     * @param  array<string,mixed>  $before
     * @return array<string,mixed>
     */
    private function installRuntime(string $runtimeDir, array $before): array
    {
        $package = $this->packageName();
        $timeout = $this->timeoutSeconds();
        $commands = [];
        File::ensureDirectoryExists($runtimeDir);
        File::ensureDirectoryExists($runtimeDir.'/browsers');
        $this->ensurePackageJson($runtimeDir);

        if ((bool) $this->option('force') || ! (bool) ($before['package_installed'] ?? false)) {
            $commands[] = $this->runProcess(
                [$this->npmBinary(), 'install', '--no-audit', '--fund=false', '--save-exact', $package],
                $runtimeDir,
                $timeout,
            );
        }

        if (! (bool) $this->option('skip-browser-install')) {
            $commands[] = $this->runProcess(
                [$this->npxBinary(), 'playwright', 'install', 'chromium'],
                $runtimeDir,
                $timeout,
                ['PLAYWRIGHT_BROWSERS_PATH' => $runtimeDir.'/browsers'],
            );
        }

        return [
            'package' => $package,
            'browser_install_skipped' => (bool) $this->option('skip-browser-install'),
            'command_count' => count($commands),
            'commands' => $commands,
        ];
    }

    private function ensurePackageJson(string $runtimeDir): void
    {
        $packageJson = $runtimeDir.'/package.json';
        if (File::isFile($packageJson)) {
            return;
        }

        File::put($packageJson, json_encode([
            'private' => true,
            'name' => 'atlas-engineering-playwright-runtime',
            'version' => '1.0.0',
            'description' => 'Atlas-managed Playwright runtime for engineering visual smoke.',
            'license' => 'UNLICENSED',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectRuntime(string $runtimeDir): array
    {
        $nodeModules = $this->nodeModulesPath($runtimeDir);
        $finder = new ExecutableFinder;
        $node = $finder->find('node');
        $npm = $finder->find('npm');
        $npx = $finder->find('npx');
        $package = $this->detectInstalledPackage($nodeModules);
        $configured = $this->configuredNodeModules();
        $verification = null;

        if ($package !== null && $node !== null && File::isFile($runtimeDir.'/package.json')) {
            $verification = $this->verifyRuntime($runtimeDir, $node);
        }

        $chromiumInstalled = (bool) data_get($verification, 'chromium_installed', false);
        $status = match (true) {
            $node === null => 'node_missing',
            $package === null => 'package_missing',
            is_array($verification) && ($verification['status'] ?? null) === 'failed' => 'package_installed_unverified',
            ! $chromiumInstalled => 'browser_missing',
            default => 'ready',
        };

        return [
            'status' => $status,
            'ready' => $status === 'ready',
            'runtime_dir_hash' => hash('sha256', $runtimeDir),
            'node_modules_hash' => hash('sha256', $nodeModules),
            'package_installed' => $package !== null,
            'installed_package' => $package,
            'node_available' => $node !== null,
            'npm_available' => $npm !== null,
            'npx_available' => $npx !== null,
            'browser_path' => $runtimeDir.'/browsers',
            'browser_path_hash' => hash('sha256', $runtimeDir.'/browsers'),
            'configured_for_visual_smoke' => in_array($nodeModules, $configured, true),
            'configured_node_modules_count' => count($configured),
            'recommended_env' => in_array($nodeModules, $configured, true)
                ? null
                : 'ATLAS_ENGINEERING_VISUAL_PLAYWRIGHT_NODE_MODULES='.$nodeModules,
            'verification' => $verification,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function configuredNodeModules(): array
    {
        $configured = config('atlas.engineering.visual_e2e.playwright_node_modules', []);
        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }

        return collect((array) $configured)
            ->filter(fn (mixed $path): bool => is_scalar($path) && trim((string) $path) !== '')
            ->map(function (mixed $path): string {
                $path = rtrim((string) $path, DIRECTORY_SEPARATOR);

                return basename($path) === 'node_modules' ? $path : $path.'/node_modules';
            })
            ->push(base_path('node_modules'))
            ->push(storage_path('app/engineering-playwright/node_modules'))
            ->map(fn (string $path): string => AtlasSecurity::canonicalPath($path, base_path(), allowMissing: true))
            ->unique()
            ->values()
            ->all();
    }

    private function detectInstalledPackage(string $nodeModules): ?string
    {
        if (File::isDirectory($nodeModules.'/playwright')) {
            return 'playwright';
        }

        if (File::isDirectory($nodeModules.'/@playwright/test')) {
            return '@playwright/test';
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function verifyRuntime(string $runtimeDir, string $node): array
    {
        $script = <<<'JS'
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { createRequire } = require('module');
const root = process.argv[1] || process.cwd();
const requireFrom = createRequire(path.join(root, 'package.json'));
let packageName = 'playwright';
let runtime;
try {
  runtime = requireFrom('playwright');
} catch (error) {
  packageName = '@playwright/test';
  runtime = requireFrom('@playwright/test');
}
let version = null;
try {
  version = requireFrom(packageName + '/package.json').version || null;
} catch (error) {}
let executable = null;
let chromiumInstalled = false;
let chromiumError = null;
try {
  executable = runtime.chromium && runtime.chromium.executablePath ? runtime.chromium.executablePath() : null;
  chromiumInstalled = executable ? fs.existsSync(executable) : false;
} catch (error) {
  chromiumError = error && error.message ? error.message : String(error);
}
const hash = (value) => value ? crypto.createHash('sha256').update(String(value)).digest('hex') : null;
console.log(JSON.stringify({
  package: packageName,
  version,
  chromium_installed: chromiumInstalled,
  chromium_executable_hash: hash(executable),
  chromium_error: chromiumError
}));
JS;

        $process = new Process([$node, '-e', $script, $runtimeDir], $runtimeDir, AtlasSecurity::processEnv([
            'PLAYWRIGHT_BROWSERS_PATH' => $runtimeDir.'/browsers',
        ], 'tool'));
        $process->setTimeout(30);
        $process->run();

        if (($process->getExitCode() ?? 1) !== 0) {
            return [
                'status' => 'failed',
                'exit_code' => $process->getExitCode() ?? 1,
                'stderr_excerpt' => Str::limit(AtlasSecurity::redactString($process->getErrorOutput()), 1200),
            ];
        }

        $payload = json_decode(trim($process->getOutput()), true);
        if (! is_array($payload)) {
            return [
                'status' => 'failed',
                'reason' => 'invalid_node_verification_output',
                'stdout_excerpt' => Str::limit(AtlasSecurity::redactString($process->getOutput()), 1200),
            ];
        }

        return array_merge(['status' => 'passed'], $payload);
    }

    /**
     * @param  array<int,string>  $command
     * @param  array<string,string>  $extraEnv
     * @return array<string,mixed>
     */
    private function runProcess(array $command, string $cwd, int $timeout, array $extraEnv = []): array
    {
        $process = new Process($command, $cwd, AtlasSecurity::processEnv(array_merge([
            'CI' => '1',
        ], $extraEnv), 'tool'));
        $process->setTimeout($timeout);
        $process->run();

        return [
            'command' => AtlasSecurity::redactCommand($command),
            'exit_code' => $process->getExitCode() ?? 1,
            'duration_status' => $process->isSuccessful() ? 'completed' : 'failed',
            'stdout_excerpt' => Str::limit(AtlasSecurity::redactString($process->getOutput()), 2000, "\n...[truncated]"),
            'stderr_excerpt' => Str::limit(AtlasSecurity::redactString($process->getErrorOutput()), 2000, "\n...[truncated]"),
        ];
    }

    private function packageName(): string
    {
        $package = trim((string) ($this->option('package') ?: 'playwright'));
        if (! in_array($package, ['playwright', '@playwright/test'], true)) {
            throw new \InvalidArgumentException('--package aceita apenas playwright ou @playwright/test');
        }

        return $package;
    }

    private function timeoutSeconds(): int
    {
        $timeout = $this->option('timeout');

        return is_numeric($timeout) ? max(30, (int) $timeout) : 900;
    }

    private function npmBinary(): string
    {
        $npm = (new ExecutableFinder)->find('npm');
        if ($npm === null) {
            throw new \RuntimeException('npm nao encontrado no PATH. Instale Node.js/npm para preparar o runtime Playwright.');
        }

        return $npm;
    }

    private function npxBinary(): string
    {
        $npx = (new ExecutableFinder)->find('npx');
        if ($npx === null) {
            throw new \RuntimeException('npx nao encontrado no PATH. Instale Node.js/npm para preparar o runtime Playwright.');
        }

        return $npx;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $status
     */
    private function render(array $result, array $status, bool $ready): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Visual Driver</>', $ready ? 'ready' : (string) ($status['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Runtime', (string) ($result['runtime_dir'] ?? '-'));
        $this->components->twoColumnDetail('Package', (string) ($status['installed_package'] ?? '-'));
        $this->components->twoColumnDetail('Node', (bool) ($status['node_available'] ?? false) ? 'available' : 'missing');
        $this->components->twoColumnDetail('npm/npx', ((bool) ($status['npm_available'] ?? false) ? 'npm' : 'npm missing').' / '.((bool) ($status['npx_available'] ?? false) ? 'npx' : 'npx missing'));
        $this->components->twoColumnDetail('Chromium', (bool) data_get($status, 'verification.chromium_installed', false) ? 'installed' : 'missing');
        $this->components->twoColumnDetail('Visual smoke config', (bool) ($status['configured_for_visual_smoke'] ?? false) ? 'configured' : 'not configured');

        if (($status['recommended_env'] ?? null) !== null) {
            $this->warn('Configure este runtime com: '.(string) $status['recommended_env']);
        }
    }
}
