<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Runtime;

use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\SymfonyProcessCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\SymfonyClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Throwable;

final class AtlasDevReadinessService
{
    private const REQUIRED_HTTP_ROUTES = [
        'atlas-dev.readiness',
        'atlas-dev.plan',
        'atlas-dev.run',
        'atlas-dev.runs.index',
        'atlas-dev.runs.cancel',
        'atlas-dev.runs.show',
        'atlas-dev.runs.stream',
    ];

    /**
     * @return list<string>
     */
    public static function requiredHttpRoutes(): array
    {
        return self::REQUIRED_HTTP_ROUTES;
    }

    /**
     * @return array{
     *   schema_version:string,
     *   status:string,
     *   strict:bool,
     *   provider_safe:bool,
     *   checks:list<array<string,mixed>>,
     *   summary:array{passed:int,warnings:int,failed:int}
     * }
     */
    public function inspect(bool $strict = false, bool $providerSafe = false): array
    {
        $checks = [
            $this->checkConfigFlag('plan_enabled', (bool) config('atlas_dev.efficient.plan_enabled', false)),
            $this->checkConfigFlag('run_enabled', (bool) config('atlas_dev.efficient.run_enabled', false)),
            $this->checkConfigFlag('desktop_enabled', (bool) config('atlas_dev.efficient.desktop_enabled', false)),
            $this->checkDispatchMode(),
            $this->checkAppKey(),
            $this->checkReceiptsPath($providerSafe),
            $this->checkRoutes(),
            $this->checkWorkerRuntime(),
            $this->checkProviderRuntime($providerSafe),
        ];

        $failed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'failed'
                || ($strict && $check['status'] === 'warning'),
        ));

        return [
            'schema_version' => 'atlas.dev.readiness.v1',
            'status' => $failed === [] ? 'passed' : 'blocked',
            'strict' => $strict,
            'provider_safe' => $providerSafe,
            'checks' => $checks,
            'summary' => [
                'passed' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'passed')),
                'warnings' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'warning')),
                'failed' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'failed')),
            ],
        ];
    }

    /**
     * @return array{id:string,status:string,severity:string,message:string,details?:array<string,mixed>}
     */
    private function checkConfigFlag(string $flag, bool $enabled): array
    {
        return [
            'id' => 'config.'.$flag,
            'status' => $enabled ? 'passed' : 'failed',
            'severity' => 'blocker',
            'message' => $enabled
                ? "atlas_dev.efficient.{$flag} enabled."
                : "atlas_dev.efficient.{$flag} must be enabled for Desktop-ready Atlas Dev.",
        ];
    }

    /**
     * @return array{id:string,status:string,severity:string,message:string,details?:array<string,mixed>}
     */
    private function checkDispatchMode(): array
    {
        $mode = (string) config('atlas_dev.efficient.run_dispatch_mode', 'process');
        $allowed = ['process', 'inline', 'after_response'];
        if (! in_array($mode, $allowed, true)) {
            return [
                'id' => 'config.run_dispatch_mode',
                'status' => 'failed',
                'severity' => 'blocker',
                'message' => 'atlas_dev.efficient.run_dispatch_mode is invalid.',
                'details' => ['mode' => $mode, 'allowed' => $allowed],
            ];
        }

        return [
            'id' => 'config.run_dispatch_mode',
            'status' => $mode === 'process' ? 'passed' : 'warning',
            'severity' => $mode === 'process' ? 'info' : 'warning',
            'message' => $mode === 'process'
                ? 'Run dispatch mode is process.'
                : 'Process mode is recommended for responsive Desktop runs.',
            'details' => ['mode' => $mode],
        ];
    }

    /**
     * @return array{id:string,status:string,severity:string,message:string,details?:array<string,mixed>}
     */
    private function checkAppKey(): array
    {
        $key = (string) config('app.key', '');
        $material = $key;
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $material = is_string($decoded) ? $decoded : '';
        }

        return [
            'id' => 'security.app_key',
            'status' => strlen($material) >= 32 ? 'passed' : 'failed',
            'severity' => 'blocker',
            'message' => strlen($material) >= 32
                ? 'APP_KEY has enough material for confirmation_token HMAC.'
                : 'APP_KEY must provide at least 32 bytes for confirmation_token HMAC.',
        ];
    }

    /**
     * @return array{id:string,status:string,severity:string,message:string,details?:array<string,mixed>}
     */
    private function checkReceiptsPath(bool $providerSafe): array
    {
        $path = (string) config('atlas_dev.receipts_path', '');
        $ok = $path !== '';
        if ($ok && ! is_dir($path)) {
            $ok = @mkdir($path, 0o755, true) || is_dir($path);
        }

        return [
            'id' => 'storage.receipts_path',
            'status' => $ok && is_writable($path) ? 'passed' : 'failed',
            'severity' => 'blocker',
            'message' => $ok && is_writable($path)
                ? 'Atlas Dev receipts path is writable.'
                : 'Atlas Dev receipts path must be writable.',
            'details' => $providerSafe
                ? ['path_label' => $path !== '' ? basename($path) : null]
                : ['path' => $path],
        ];
    }

    /**
     * @return array{id:string,status:string,severity:string,message:string,details?:array<string,mixed>}
     */
    private function checkRoutes(): array
    {
        $required = self::requiredHttpRoutes();
        $missing = array_values(array_filter($required, static fn (string $route): bool => ! Route::has($route)));

        return [
            'id' => 'http.routes',
            'status' => $missing === [] ? 'passed' : 'failed',
            'severity' => 'blocker',
            'message' => $missing === [] ? 'All Atlas Dev HTTP routes are registered.' : 'Atlas Dev HTTP routes are missing.',
            'details' => ['missing' => $missing],
        ];
    }

    /**
     * @return array{id:string,status:string,severity:string,message:string,details?:array<string,mixed>}
     */
    private function checkWorkerRuntime(): array
    {
        $mode = (string) config('atlas_dev.efficient.run_dispatch_mode', 'process');
        if ($mode !== 'process') {
            return [
                'id' => 'worker.runtime',
                'status' => 'warning',
                'severity' => 'warning',
                'message' => 'Worker process runtime is not required outside process dispatch mode.',
                'details' => ['mode' => $mode],
            ];
        }

        $missing = [];
        if (! function_exists('proc_open')) {
            $missing[] = 'proc_open';
        }
        if (! is_file(base_path('artisan'))) {
            $missing[] = 'artisan';
        }

        return [
            'id' => 'worker.runtime',
            'status' => $missing === [] ? 'passed' : 'failed',
            'severity' => 'blocker',
            'message' => $missing === []
                ? 'Process worker runtime is available.'
                : 'Process worker runtime dependencies are missing.',
            'details' => [
                'missing' => $missing,
                'posix_kill_available' => function_exists('posix_kill'),
            ],
        ];
    }

    /**
     * @return array{id:string,status:string,severity:string,message:string,details?:array<string,mixed>}
     */
    private function checkProviderRuntime(bool $providerSafe): array
    {
        $missing = [];

        $gateway = app(ClaudeCliGateway::class);
        if (! $gateway instanceof SymfonyClaudeCliGateway) {
            $missing[] = 'ClaudeCliGateway production binding';
        }

        $runner = app(VerificationCommandRunner::class);
        if (! $runner instanceof SymfonyProcessCommandRunner) {
            $missing[] = 'VerificationCommandRunner production binding';
        }

        $configuredBinary = (string) config('atlas.ai.providers.claude_cli.binary', 'claude');
        $basename = basename(str_replace('\\', '/', $configuredBinary));
        if (! in_array($basename, ['claude', 'claude-code', 'claude.exe'], true)) {
            $missing[] = 'allowed claude_cli binary';
        }

        $args = config('atlas.ai.providers.claude_cli.args', []);
        $args = AtlasDevStringListNormalizer::strings($args);
        if (! $this->providerToolsAreReadOnly($args)) {
            $missing[] = 'claude_cli read-only tools';
        }

        $resolvedBinary = $this->resolveBinary($configuredBinary);
        if ($resolvedBinary === null) {
            $missing[] = 'executable claude_cli binary';
        }
        $versionProbe = $resolvedBinary !== null
            ? $this->probeProviderVersion($resolvedBinary)
            : ['ok' => false, 'exit_code' => null, 'version' => null, 'error_excerpt' => 'binary_not_resolved'];
        if ($versionProbe['ok'] !== true) {
            $missing[] = 'claude_cli version command';
        }

        return [
            'id' => 'provider.runtime',
            'status' => $missing === [] ? 'passed' : 'failed',
            'severity' => 'blocker',
            'message' => $missing === []
                ? 'Provider runtime is configured for Atlas Dev runs.'
                : 'Provider runtime is not ready for Atlas Dev runs.',
            'details' => [
                $providerSafe ? 'binary_label' : 'binary' => $providerSafe ? $basename : $configuredBinary,
                $providerSafe ? 'resolved_binary_label' : 'resolved_binary' => $providerSafe && is_string($resolvedBinary) ? basename($resolvedBinary) : $resolvedBinary,
                'args' => $args,
                'missing' => $missing,
                'version_probe' => $providerSafe
                    ? [
                        'ok' => $versionProbe['ok'],
                        'exit_code' => $versionProbe['exit_code'],
                        'version_label' => $versionProbe['version'] !== null ? strtok((string) $versionProbe['version'], ' ') : null,
                    ]
                    : $versionProbe,
            ],
        ];
    }

    /**
     * @param  list<string>  $args
     */
    private function providerToolsAreReadOnly(array $args): bool
    {
        foreach ($args as $index => $arg) {
            if (! in_array($arg, ['--allowedTools', '--allowed-tools'], true)) {
                continue;
            }

            $allowed = (string) ($args[$index + 1] ?? '');
            $tools = AtlasDevStringListNormalizer::nonEmptyStrings(preg_split('/[\s,]+/', $allowed) ?: []);

            return $tools === ['Read'];
        }

        return false;
    }

    private function resolveBinary(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        $path = getenv('PATH');
        if (! is_string($path) || trim($path) === '') {
            $path = (string) ($_SERVER['PATH'] ?? $_ENV['PATH'] ?? '');
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{ok:bool,exit_code:int|null,version:string|null,error_excerpt:string|null}
     */
    private function probeProviderVersion(string $binary): array
    {
        $process = new Process([$binary, '--version'], timeout: 5.0);

        try {
            $process->run();
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => null,
                'version' => null,
                'error_excerpt' => $this->shortExcerpt($e->getMessage()),
            ];
        }

        $stdout = trim((string) $process->getOutput());
        $stderr = trim((string) $process->getErrorOutput());
        $exitCode = $process->getExitCode();

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'version' => $stdout !== '' ? $this->shortExcerpt($stdout) : null,
            'error_excerpt' => $exitCode === 0 ? null : $this->shortExcerpt($stderr !== '' ? $stderr : $stdout),
        ];
    }

    private function shortExcerpt(string $value): string
    {
        $clean = AtlasSecurity::redactString($value);
        $clean = preg_replace('#/(?:Users|private/var|var/folders|tmp)/[^\s"\']+#', '[path-redacted]', $clean);
        $clean = is_string($clean) ? trim(preg_replace('/\s+/', ' ', $clean) ?? $clean) : trim($value);

        return mb_substr($clean, 0, 180);
    }
}
