<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\AiStringListNormalizer;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dedicated MiniMax M3 CLI runtime bridge.
 *
 * This is intentionally separate from the CLI allowlist and from the generic
 * Python AI/Data runtime. It only runs the Atlas-owned adapter with explicit
 * argv and a manifest that was already authorized by the provider invocation
 * service.
 *
 * NOTE: auth env vars (ATLAS_MINIMAX_TOKEN_PLAN_KEY / ATLAS_MINIMAX_PAYGO_API_KEY)
 * are NEVER injected into the parent Laravel process. They are forwarded
 * exclusively as subprocess env vars via setEnv on the spawned Process so the
 * secret is scoped to the adapter lifecycle only.
 */
class AtlasMinimaxM27CliRuntimeExecutor
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_BLOCKED = 'blocked';
    public const BLOCKER_MODEL_NOT_M3 = 'minimax_m3_required';
    public const MODEL = 'MiniMax-M3';

    /**
     * Maximum tokens forwarded to the MiniMax model. Context that exceeds this
     * limit is truncated (never crashes). ~4 chars per token approximation.
     */
    public const MAX_TOKENS = 38000;
    private const MAX_CONTEXT_CHARS = self::MAX_TOKENS * 4;

    /** @var callable|null */
    private $processFactory;

    /**
     * @return array<string,mixed>
     */
    public function configured(): array
    {
        $config = $this->config();
        $blockers = [];

        if (! (bool) ($config['enabled'] ?? false)) {
            $blockers[] = 'minimax_m27_cli_disabled';
        }

        $python = $this->resolvePython((string) ($config['python'] ?? 'python3'));
        if ($python === null) {
            $blockers[] = 'minimax_m27_cli_python_missing';
        }

        $adapter = $this->adapterPath();
        if (! is_file($adapter)) {
            $blockers[] = 'minimax_m27_cli_adapter_missing';
        }

        $authResult = $this->authEnvState();
        if ($authResult['state'] === 'missing') {
            $blockers[] = 'missing_token_plan_key';
        } elseif ($authResult['state'] === 'paygo_only') {
            if (! (bool) ($config['allow_paygo'] ?? false)) {
                $blockers[] = 'paygo_not_authorized';
            }
        } elseif ($authResult['state'] === 'token_plan') {
            if (! (bool) ($config['allow_token_plan'] ?? true)) {
                $blockers[] = 'minimax_m27_highspeed_not_authorized';
            }
        }
        $model = $this->configuredModel($config);
        if (! $this->isMiniMaxM3($model)) {
            $blockers[] = self::BLOCKER_MODEL_NOT_M3;
        }

        $blockers = AiStringListNormalizer::uniqueStrings($blockers);

        return [
            'schema_version' => 'atlas.provider.minimax_m27_cli.status.v1',
            'provider' => AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
            'configured' => $blockers === [],
            'runtime_present' => $python !== null && is_file($adapter),
            'binary_path' => $python,
            'adapter_path' => $adapter,
            'auth_state' => $authResult['state'],
            'auth_mode' => $authResult['mode'],
            'model' => $model,
            'model_prefixes' => [self::MODEL, 'minimax-m3'],
            'billing_mode' => 'token_plan_request_based',
            'allowed_binaries' => [$python ?: (string) ($config['python'] ?? 'python3')],
            'blockers' => $blockers,
            'external_provider_call_possible' => true,
            'provider_tokens_may_be_spent' => true,
            'runtime_boundary' => [
                'owner' => 'laravel_kernel',
                'runtime_family' => 'python_minimax_m27_cli',
                'authority' => 'executor_only_after_decision_receipt',
                'forbidden_authorities' => [
                    'choose_provider_or_model',
                    'choose_domain_or_flow',
                    'write_memory_directly',
                    'mutate_policy',
                    'bypass_evidence_ledger',
                    'promote_completion_claim',
                ],
            ],
            'note' => 'Fail-closed CLI status; no external provider was contacted.',
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function plan(array $manifest): array
    {
        $config = $this->configured();
        $blockers = AiStringListNormalizer::uniqueMergedStrings(
            (array) ($config['blockers'] ?? []),
            $this->manifestBlockers($manifest),
        );

        return [
            'schema_version' => 'atlas.provider.minimax_m27_cli.invocation_request.v1',
            'provider' => AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? $this->configuredModel($this->config()),
            'configured' => (bool) ($config['configured'] ?? false),
            'plan_safe' => $blockers === [],
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'adapter_path' => $config['adapter_path'] ?? $this->adapterPath(),
            'argv_preview' => array_values(array_filter([
                $config['binary_path'] ?? null,
                $config['adapter_path'] ?? $this->adapterPath(),
                '<manifest.json>',
            ], 'is_string')),
            'manifest_hash' => $this->hashPayload($manifest),
            'allowed_files_hash' => $this->hashPayload((array) data_get($manifest, 'scope_contract.allowed_files', [])),
            'forbidden_files_hash' => $this->hashPayload((array) data_get($manifest, 'scope_contract.forbidden_files', [])),
            'blockers' => $blockers,
            'note' => 'Plan-only: MiniMax CLI adapter not spawned and no provider contacted.',
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function invoke(array $manifest): array
    {
        $plan = $this->plan($manifest);
        if (($plan['blockers'] ?? []) !== []) {
            return $this->blocked($manifest, (array) $plan['blockers'], 'MiniMax M3 CLI runtime stayed fail-closed.');
        }

        $config = $this->configured();
        $python = (string) ($config['binary_path'] ?? '');
        $adapter = (string) ($config['adapter_path'] ?? $this->adapterPath());
        $timeout = max(1, min(3600, (int) ($manifest['timeout_seconds'] ?? 120)));
        $maxOutputChars = max(200, min(200000, (int) ($manifest['max_output_chars'] ?? 12000)));

        // Enforce MAX_TOKENS contract — truncate context before writing manifest to disk.
        $manifest = $this->withDefaultModel($manifest);
        $manifest = $this->applyContextBudget($manifest);

        $manifestPath = $this->writeManifest($manifest);
        $argv = [$python, $adapter, $manifestPath];
        $started = microtime(true);

        try {
            $process = $this->makeProcess($argv, $this->workspacePath($manifest), $timeout);
            $process->run();
            $stdout = (string) $process->getOutput();
            $stderr = (string) $process->getErrorOutput();
            $exitCode = $process->getExitCode();
            $status = $process->isSuccessful() ? self::STATUS_COMPLETED : self::STATUS_FAILED;
        } catch (ProcessTimedOutException $e) {
            $stdout = '';
            $stderr = $e->getMessage();
            $exitCode = null;
            $status = self::STATUS_TIMED_OUT;
        } catch (Throwable $e) {
            $stdout = '';
            $stderr = $e->getMessage();
            $exitCode = null;
            $status = self::STATUS_FAILED;
        } finally {
            @unlink($manifestPath);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $decoded = json_decode($stdout, true);
        $adapterPayload = is_array($decoded) ? $decoded : [];
        $stderrExcerpt = $this->excerpt($this->redact($stderr), $maxOutputChars);
        $stdoutExcerpt = $this->excerpt($this->redact($stdout), $maxOutputChars);
        $blockers = array_values(array_filter((array) ($adapterPayload['blockers'] ?? []), 'is_string'));
        if ($status === self::STATUS_TIMED_OUT) {
            $blockers[] = 'timeout';
        } elseif ($status === self::STATUS_FAILED || (is_int($exitCode) && $exitCode !== 0)) {
            $blockers[] = (string) ($adapterPayload['failure_type'] ?? 'minimax_m27_cli_adapter_failed');
        }

        return [
            'schema_version' => 'atlas.provider.minimax_m27_cli.invocation_result.v1',
            'provider' => AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? null,
            'model_observed' => $adapterPayload['model_observed'] ?? ($manifest['model'] ?? null),
            'argv' => [$python, $adapter, '<manifest.json>'],
            'cwd' => $this->workspacePath($manifest),
            'configured' => true,
            'provider_called' => (bool) ($adapterPayload['provider_called'] ?? $status === self::STATUS_COMPLETED),
            'external_provider_call' => (bool) ($adapterPayload['external_provider_call'] ?? $status === self::STATUS_COMPLETED),
            'provider_tokens_spent' => $adapterPayload['provider_tokens_spent'] ?? 'unknown',
            'exit_code' => is_int($exitCode) ? $exitCode : null,
            'duration_ms' => $durationMs,
            'timeout_seconds' => $timeout,
            'timed_out' => $status === self::STATUS_TIMED_OUT,
            'stdout_hash' => hash('sha256', $stdout),
            'stderr_hash' => hash('sha256', $stderr),
            'stdout_excerpt' => $stdoutExcerpt,
            'stderr_excerpt' => $stderrExcerpt,
            'process_status' => $status,
            'artifacts' => is_array($adapterPayload['artifacts'] ?? null) ? $adapterPayload['artifacts'] : [],
            'changed_files' => is_array($adapterPayload['changed_files'] ?? null) ? $adapterPayload['changed_files'] : [],
            'performance_signal' => is_array($adapterPayload['performance_signal'] ?? null)
                ? $adapterPayload['performance_signal']
                : $this->performanceSignal($manifest, $status, $durationMs, $blockers),
            'classification' => $blockers === [] ? null : [
                'schema_version' => 'atlas.forge.provider_invocation_failure_classification.v1',
                'failure_type' => $blockers[0],
                'confidence' => 'high',
                'provider' => AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
            ],
            'failure_type' => $blockers[0] ?? null,
            'blockers' => AiStringListNormalizer::uniqueStrings($blockers),
            'note' => (string) ($adapterPayload['note'] ?? 'MiniMax M3 CLI adapter finished under Atlas governance.'),
        ];
    }

    /**
     * Run the MiniMax CLI adapter and return its RAW payload, the shape the
     * Codex→MiniMax worker (AtlasMinimaxFirstWorkerService) consumes:
     * {status, text, input_tokens, output_tokens, error}. This is exactly what the
     * python adapter emits. invoke() exists for the provider-driver-router consumer
     * and transforms the payload into an invocation-result envelope that DROPS `text`;
     * the worker needs the model text to extract code blocks, so it must use execute().
     * Fail-closed: a blocked plan or a non-JSON/crashed adapter returns status!='completed'
     * with an error, never fabricates text.
     *
     * @param  array<string,mixed>  $manifest
     * @return array{status:string,text:string,input_tokens:int,output_tokens:int,provider_called:bool,duration_ms:int,error:string}
     */
    public function execute(array $manifest): array
    {
        $plan = $this->plan($manifest);
        if (($plan['blockers'] ?? []) !== []) {
            return [
                'status' => 'blocked',
                'text' => '',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'provider_called' => false,
                'duration_ms' => 0,
                'error' => implode(',', array_values(array_filter((array) $plan['blockers'], 'is_string'))),
            ];
        }

        $config = $this->configured();
        $python = (string) ($config['binary_path'] ?? '');
        $adapter = (string) ($config['adapter_path'] ?? $this->adapterPath());
        $timeout = max(1, min(3600, (int) ($manifest['timeout_seconds'] ?? 120)));
        $manifest = $this->withDefaultModel($manifest);
        $manifest = $this->applyContextBudget($manifest);
        $manifestPath = $this->writeManifest($manifest);
        $argv = [$python, $adapter, $manifestPath];
        $started = microtime(true);

        $stdout = '';
        $stderr = '';
        $ok = false;
        try {
            $process = $this->makeProcess($argv, $this->workspacePath($manifest), $timeout);
            $process->run();
            $stdout = (string) $process->getOutput();
            $stderr = (string) $process->getErrorOutput();
            $ok = $process->isSuccessful();
        } catch (Throwable $e) {
            $stderr = $e->getMessage();
            $ok = false;
        } finally {
            @unlink($manifestPath);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $decoded = json_decode($stdout, true);
        $payload = is_array($decoded) ? $decoded : [];
        $status = (string) ($payload['status'] ?? ($ok ? 'completed' : 'failed'));

        return [
            'status' => $status,
            'text' => (string) ($payload['text'] ?? ''),
            'input_tokens' => (int) ($payload['input_tokens'] ?? 0),
            'output_tokens' => (int) ($payload['output_tokens'] ?? 0),
            'provider_called' => (bool) ($payload['provider_called'] ?? ($status === self::STATUS_COMPLETED)),
            'duration_ms' => $durationMs,
            'error' => (string) ($payload['error'] ?? ($ok ? '' : $this->redact($stderr))),
        ];
    }

    public function setProcessFactory(?callable $factory): void
    {
        $this->processFactory = $factory;
    }

    public function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasMinimaxM27CliRuntimeExecutorTest.php';
    }

    /**
     * Compile a context string for the model, enforcing MAX_TOKENS (38 000).
     *
     * Truncation is character-based (~4 chars per token). The string is never
     * crashed or thrown — oversized input is silently truncated with an ellipsis
     * marker so the manifest written to disk is always within budget.
     *
     * @param  string  $context  Raw context to compile.
     * @return array{text: string, truncated: bool, original_chars: int, compiled_chars: int}
     */
    public function compile(string $context): array
    {
        $originalChars = strlen($context);
        $truncated = false;

        if ($originalChars > self::MAX_CONTEXT_CHARS) {
            $context = substr($context, 0, self::MAX_CONTEXT_CHARS).'…';
            $truncated = true;
        }

        return [
            'text' => $context,
            'truncated' => $truncated,
            'original_chars' => $originalChars,
            'compiled_chars' => strlen($context),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function config(): array
    {
        return (array) config('atlas.ai.providers.minimax_m27_cli', []);
    }

    /**
     * Resolves which auth credential is available without injecting anything
     * into the parent process environment. Returns state + mode so configured()
     * can gate on both token_plan and paygo independently.
     *
     * @return array{state: string, mode: string|null}
     */
    private function authEnvState(): array
    {
        $tokenPlanKey = getenv('ATLAS_MINIMAX_TOKEN_PLAN_KEY');
        if (is_string($tokenPlanKey) && trim($tokenPlanKey) !== '') {
            return ['state' => 'token_plan', 'mode' => 'token_plan'];
        }

        $paygoKey = getenv('ATLAS_MINIMAX_PAYGO_API_KEY');
        if (is_string($paygoKey) && trim($paygoKey) !== '') {
            return ['state' => 'paygo_only', 'mode' => 'paygo'];
        }

        return ['state' => 'missing', 'mode' => null];
    }

    private function resolvePython(string $binary): ?string
    {
        return ProviderRuntimeEnvironment::resolveExecutable($binary);
    }

    private function adapterPath(): string
    {
        $configured = trim((string) ($this->config()['adapter_path'] ?? ''));
        if ($configured !== '') {
            return str_starts_with($configured, '/') ? $configured : base_path($configured);
        }

        return base_path('runtimes/python/minimax_m27/adapter.py');
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<string>
     */
    private function manifestBlockers(array $manifest): array
    {
        $blockers = [];
        if (trim((string) ($manifest['decision_receipt_id'] ?? '')) === ''
            || trim((string) ($manifest['decision_receipt_hash'] ?? '')) === '') {
            $blockers[] = 'decision_receipt_required';
        }
        if ($this->workspacePath($manifest) === null) {
            $blockers[] = 'minimax_m27_cli_workspace_required';
        }
        $allowed = (array) data_get($manifest, 'scope_contract.allowed_files', []);
        if (array_values(array_filter($allowed, 'is_string')) === []) {
            $blockers[] = 'minimax_m27_cli_allowed_files_required';
        }
        $model = trim((string) ($manifest['model'] ?? ''));
        if ($model !== '' && ! $this->isMiniMaxM3($model)) {
            $blockers[] = self::BLOCKER_MODEL_NOT_M3;
        }

        return AiStringListNormalizer::uniqueStrings($blockers);
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function workspacePath(array $manifest): ?string
    {
        return ProviderRuntimeEnvironment::workspacePath($manifest);
    }

    /**
     * Apply the MAX_TOKENS context budget to the manifest prompt context.
     * Truncates the context string in place using compile() — never crashes.
     *
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function applyContextBudget(array $manifest): array
    {
        $prompt = is_array($manifest['prompt'] ?? null) ? $manifest['prompt'] : [];
        $context = $prompt['context'] ?? null;
        if (is_string($context) && strlen($context) > self::MAX_CONTEXT_CHARS) {
            $compiled = $this->compile($context);
            $prompt['context'] = $compiled['text'];
            $manifest['prompt'] = $prompt;
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function writeManifest(array $manifest): string
    {
        return ProviderRuntimeManifestStore::write(storage_path('framework/cache/atlas-minimax-m27-cli'), $manifest);
    }

    private function withDefaultModel(array $manifest): array
    {
        if (trim((string) ($manifest['model'] ?? '')) === '') {
            $manifest['model'] = $this->configuredModel($this->config());
        }

        return $manifest;
    }

    private function configuredModel(array $config): string
    {
        $model = trim((string) ($config['model'] ?? self::MODEL));

        return $model !== '' ? $model : self::MODEL;
    }

    private function isMiniMaxM3(string $model): bool
    {
        return strtolower(trim($model)) === 'minimax-m3';
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $manifest, array $blockers, string $note): array
    {
        return [
            'schema_version' => 'atlas.provider.minimax_m27_cli.invocation_result.v1',
            'provider' => AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? null,
            'configured' => false,
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'timeout_seconds' => (int) ($manifest['timeout_seconds'] ?? 120),
            'timed_out' => false,
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
            'process_status' => self::STATUS_BLOCKED,
            'performance_signal' => $this->performanceSignal($manifest, self::STATUS_BLOCKED, 0, $blockers),
            'classification' => null,
            'failure_type' => null,
            'blockers' => AiStringListNormalizer::uniqueStrings($blockers),
            'note' => $note,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    private function performanceSignal(array $manifest, string $status, int $durationMs, array $blockers): array
    {
        return [
            'schema_version' => 'atlas.provider.minimax_m27_cli.performance_signal.v1',
            'provider' => AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
            'model_observed' => $manifest['model'] ?? null,
            'domain' => data_get($manifest, 'metadata.domain', 'programming'),
            'flow' => data_get($manifest, 'metadata.flow', 'programming.forge'),
            'task_type' => data_get($manifest, 'metadata.task_type'),
            'status' => $status === self::STATUS_COMPLETED && $blockers === [] ? 'succeeded' : 'failed',
            'duration_ms' => $durationMs,
            'changed_files_count' => 0,
            'required_gates_passed' => false,
            'completion_claim_promoted' => false,
            'routing_effect' => 'none',
            'advisory_only' => true,
            'blockers' => AiStringListNormalizer::uniqueStrings($blockers),
        ];
    }

    /**
     * Builds the subprocess env array. Auth credentials are forwarded ONLY to
     * the spawned Python process — they are never written to the global Laravel
     * environment or to any log.
     *
     * The adapter receives:
     *   ANTHROPIC_BASE_URL  = config base_url + /anthropic  (MiniMax OpenAI-compat endpoint)
     *   ANTHROPIC_API_KEY   = token_plan_key (preferred) or paygo key
     *
     * @return array<string,string>
     */
    private function buildSubprocessEnv(): array
    {
        $config = $this->config();
        $env = [];

        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        if ($baseUrl !== '') {
            $env['ANTHROPIC_BASE_URL'] = $baseUrl.'/anthropic';
        }

        $tokenPlanKey = getenv('ATLAS_MINIMAX_TOKEN_PLAN_KEY');
        if (is_string($tokenPlanKey) && trim($tokenPlanKey) !== '') {
            $env['ANTHROPIC_API_KEY'] = trim($tokenPlanKey);
        } else {
            $paygoKey = getenv('ATLAS_MINIMAX_PAYGO_API_KEY');
            if (is_string($paygoKey) && trim($paygoKey) !== '') {
                $env['ANTHROPIC_API_KEY'] = trim($paygoKey);
            }
        }

        return $env;
    }

    /**
     * @param  array<int,string>  $argv
     */
    private function makeProcess(array $argv, ?string $cwd, int $timeout): Process
    {
        return ProviderRuntimeProcessFactory::make($argv, $cwd, $this->buildSubprocessEnv(), $timeout, $this->processFactory);
    }

    private function hashPayload(mixed $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function redact(string $value): string
    {
        return ProviderRuntimeOutput::redact($value);
    }

    private function excerpt(string $value, int $maxLength): string
    {
        return ProviderRuntimeOutput::excerpt($value, $maxLength);
    }
}
