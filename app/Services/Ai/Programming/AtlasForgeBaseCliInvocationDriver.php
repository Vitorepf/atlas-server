<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Base implementation shared by the governed CLI provider drivers
 * (claude_cli, codex_cli, gemini_cli). Concrete drivers only declare:
 *
 *   - canonical provider id (`claude_cli`/`codex_cli`/`gemini_cli`);
 *   - candidate binary names (allowlist applies);
 *   - the model namespace they accept;
 *   - env vars that signal auth.
 *
 * Everything else (config detection, plan, invoke through the safe process
 * runner, output capture, blocker resolution) lives here.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
 */
abstract class AtlasForgeBaseCliInvocationDriver implements AtlasForgeProviderInvocationDriver
{
    public function __construct(
        protected readonly AtlasForgeProviderCommandAllowlistService $allowlist,
        protected readonly AtlasForgeProviderProcessRunner $runner,
        protected readonly AtlasForgeProviderInvocationFailureClassifier $classifier,
    ) {}

    /** @return list<string> */
    abstract protected function candidateBinaries(): array;

    /** @return list<string> Env vars whose presence signals auth is configured. */
    abstract protected function authEnvVars(): array;

    /** @return list<string> Model prefixes this driver claims to handle. */
    abstract protected function modelPrefixes(): array;

    public function supports(string $provider, ?string $model): bool
    {
        if ($provider !== $this->provider()) {
            return false;
        }
        if ($model === null || $model === '') {
            return true;
        }
        $needle = strtolower($model);
        foreach ($this->modelPrefixes() as $prefix) {
            if ($prefix === '' || str_starts_with($needle, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    public function configured(): array
    {
        $binaryPath = $this->resolveBinaryPath();
        $authState = $this->resolveAuthState();
        $blockers = [];
        if ($binaryPath === null) {
            $blockers[] = 'provider_runtime_binary_missing';
        }
        if ($authState === 'missing') {
            $blockers[] = 'provider_auth_required';
        }
        $configured = $binaryPath !== null && $authState !== 'missing';

        return [
            'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
            'provider' => $this->provider(),
            'configured' => $configured,
            'runtime_present' => $binaryPath !== null,
            'binary_path' => $binaryPath,
            'auth_state' => $authState,
            'model_prefixes' => $this->modelPrefixes(),
            'allowed_binaries' => $this->candidateBinaries(),
            'blockers' => $blockers,
            'external_provider_call_possible' => true,
            'provider_tokens_may_be_spent' => true,
            'note' => 'Config status: nunca chama provider externo; apenas inspeciona ambiente local.',
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function plan(array $request): array
    {
        $config = $this->configured();
        $argv = $this->buildArgv($request);
        $allowlist = $this->allowlist->evaluate($argv, is_string($request['cwd'] ?? null) ? $request['cwd'] : null);
        $blockers = array_merge((array) $config['blockers'], (array) $allowlist['blockers']);

        return [
            'schema_version' => 'atlas.forge.provider_driver_plan.v1',
            'provider' => $this->provider(),
            'model' => $request['model'] ?? null,
            'argv_preview' => $argv,
            'cwd' => $request['cwd'] ?? null,
            'configured' => (bool) $config['configured'],
            'allowlist_passed' => (bool) $allowlist['allowed'],
            'allowlist_blockers' => array_values((array) $allowlist['blockers']),
            'config_blockers' => array_values((array) $config['blockers']),
            'blockers' => array_values(array_unique($blockers)),
            'plan_safe' => $blockers === [],
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'note' => 'Plan-only: nenhum binary foi spawnado.',
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function invoke(array $request): array
    {
        $config = $this->configured();
        if (! (bool) $config['configured']) {
            return $this->blocked(
                $request,
                blockers: array_values(array_unique(array_merge(
                    ['provider_driver_not_configured'],
                    (array) $config['blockers'],
                ))),
                note: 'Driver '.$this->provider().' nao esta configurado neste host. Mantido fail-closed.',
            );
        }

        $argv = $this->buildArgv($request);
        $cwd = is_string($request['cwd'] ?? null) ? $request['cwd'] : null;
        $allowlist = $this->allowlist->evaluate($argv, $cwd);
        if (! (bool) $allowlist['allowed']) {
            return $this->blocked(
                $request,
                blockers: array_merge(['provider_command_not_allowed'], (array) $allowlist['blockers']),
                note: 'Command allowlist bloqueou execucao.',
            );
        }

        $prompt = $this->encodePrompt($request['prompt'] ?? null);
        $timeout = (int) ($request['timeout_seconds'] ?? 120);
        $maxOutputChars = (int) ($request['max_output_chars'] ?? 12000);

        $result = $this->runner->run([
            'argv' => $argv,
            'cwd' => $cwd,
            'stdin' => $prompt,
            'timeout_seconds' => $timeout,
            'max_output_chars' => $maxOutputChars,
        ]);

        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $externalCall = (bool) ($result['external_provider_call'] ?? false);
        $exitCode = $result['exit_code'] ?? null;
        $timedOut = ($result['status'] ?? null) === AtlasForgeProviderProcessRunner::STATUS_TIMED_OUT;
        $stdout = (string) ($result['stdout_excerpt'] ?? '');
        $stderr = (string) ($result['stderr_excerpt'] ?? '');

        $classification = null;
        if ($timedOut || (is_int($exitCode) && $exitCode !== 0) || ($result['status'] ?? null) === AtlasForgeProviderProcessRunner::STATUS_FAILED) {
            $classification = $this->classifier->classify([
                'provider' => $this->provider(),
                'model' => $request['model'] ?? null,
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'timed_out' => $timedOut,
            ]);
        }

        return [
            'schema_version' => 'atlas.forge.provider_driver_result.v1',
            'provider' => $this->provider(),
            'model' => $request['model'] ?? null,
            'argv' => $argv,
            'cwd' => $cwd,
            'configured' => true,
            'provider_called' => $providerCalled,
            'external_provider_call' => $externalCall,
            'provider_tokens_spent' => $providerCalled ? 'unknown' : false,
            'exit_code' => $exitCode,
            'duration_ms' => $result['duration_ms'] ?? null,
            'timeout_seconds' => $timeout,
            'timed_out' => $timedOut,
            'stdout_hash' => $result['stdout_hash'] ?? null,
            'stderr_hash' => $result['stderr_hash'] ?? null,
            'stdout_excerpt' => $stdout,
            'stderr_excerpt' => $stderr,
            'process_status' => $result['status'] ?? null,
            'classification' => $classification,
            'failure_type' => $classification['failure_type'] ?? null,
            'blockers' => $classification !== null ? [(string) $classification['failure_type']] : [],
            'note' => 'Driver real: provider externo pode ter sido invocado conforme allowlist + binary disponivel + auth presente.',
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<int,string>
     */
    protected function buildArgv(array $request): array
    {
        $binary = $this->resolveBinaryPath() ?? ($this->candidateBinaries()[0] ?? '');
        $model = is_string($request['model'] ?? null) ? (string) $request['model'] : '';
        $argv = [$binary];
        if ($model !== '') {
            $argv[] = '--model';
            $argv[] = $model;
        }
        // The prompt is fed through stdin. The CLI typically supports a
        // `--prompt-stdin` toggle, but the safest cross-CLI convention is to
        // require explicit stdin via the runner — drivers should not embed
        // raw prompt content in argv.
        $argv[] = '--non-interactive';

        return array_values(array_filter($argv, static fn (string $v): bool => $v !== ''));
    }

    protected function resolveBinaryPath(): ?string
    {
        foreach ($this->candidateBinaries() as $candidate) {
            $path = $this->which($candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    protected function resolveAuthState(): string
    {
        $vars = $this->authEnvVars();
        if ($vars === []) {
            return 'unknown';
        }
        foreach ($vars as $var) {
            $value = getenv($var);
            if (is_string($value) && trim($value) !== '') {
                return 'configured';
            }
        }

        return 'missing';
    }

    protected function which(string $binary): ?string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return null;
        }
        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(':', $path) as $dir) {
            $candidate = rtrim($dir, '/').'/'.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function encodePrompt(mixed $prompt): ?string
    {
        if (is_string($prompt) && $prompt !== '') {
            return $prompt;
        }
        if (is_array($prompt)) {
            return (string) json_encode($prompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    protected function blocked(array $request, array $blockers, string $note): array
    {
        return [
            'schema_version' => 'atlas.forge.provider_driver_result.v1',
            'provider' => $this->provider(),
            'model' => $request['model'] ?? null,
            'argv' => [],
            'cwd' => $request['cwd'] ?? null,
            'configured' => false,
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'timeout_seconds' => (int) ($request['timeout_seconds'] ?? 120),
            'timed_out' => false,
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
            'process_status' => AtlasForgeProviderProcessRunner::STATUS_BLOCKED,
            'classification' => null,
            'failure_type' => null,
            'blockers' => array_values(array_unique($blockers)),
            'note' => $note,
        ];
    }
}
