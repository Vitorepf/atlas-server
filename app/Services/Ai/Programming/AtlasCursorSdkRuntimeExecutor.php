<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dedicated Cursor SDK runtime bridge.
 *
 * Laravel remains the authority for provider/model selection, scope, receipts,
 * evidence and completion. This executor only launches an Atlas-owned Node
 * adapter for @cursor/sdk after upstream gates have already passed.
 */
class AtlasCursorSdkRuntimeExecutor
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_BLOCKED = 'blocked';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasCursorSdkRuntimeExecutorTest.php';
    }

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
            $blockers[] = 'cursor_sdk_disabled';
        }

        $node = $this->resolveNode((string) ($config['node'] ?? 'node'));
        if ($node === null) {
            $blockers[] = 'cursor_sdk_node_missing';
        }

        $adapter = $this->adapterPath();
        if (! is_file($adapter)) {
            $blockers[] = 'cursor_sdk_adapter_missing';
        }

        $module = trim((string) ($config['module'] ?? '@cursor/sdk'));
        $modulePresent = false;
        if ($node !== null && $module !== '') {
            $modulePresent = $this->nodeModulePresent($node, $module);
            if (! $modulePresent) {
                $blockers[] = 'cursor_sdk_module_missing';
            }
        } else {
            $blockers[] = 'cursor_sdk_module_missing';
        }

        $authState = $this->authState($this->authEnvVars());
        if ($authState === 'missing') {
            $blockers[] = 'cursor_sdk_auth_required';
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'schema_version' => 'atlas.provider.cursor_sdk.status.v1',
            'provider' => AtlasForgeCursorSdkInvocationDriver::PROVIDER,
            'configured' => $blockers === [],
            'runtime_present' => $node !== null && is_file($adapter),
            'binary_path' => $node,
            'adapter_path' => $adapter,
            'auth_state' => $authState,
            'module' => $module,
            'module_present' => $modulePresent,
            'runtime_mode' => (string) ($config['runtime_mode'] ?? 'local'),
            'billing_mode' => (string) ($config['billing_mode'] ?? 'cursor_account_usage_bucket'),
            'quota_bucket' => (string) ($config['quota_bucket'] ?? 'cursor_account_default'),
            'model_prefixes' => ['cursor', 'composer', 'gpt-', 'claude-', 'gemini-', 'opus', 'sonnet'],
            'allowed_binaries' => [$node ?: (string) ($config['node'] ?? 'node')],
            'blockers' => $blockers,
            'external_provider_call_possible' => true,
            'provider_tokens_may_be_spent' => true,
            'runtime_boundary' => [
                'owner' => 'laravel_kernel',
                'runtime_family' => 'node_cursor_sdk',
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
            'note' => 'Fail-closed SDK status; no external provider was contacted.',
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function plan(array $manifest): array
    {
        $config = $this->configured();
        $blockers = array_values(array_unique(array_merge(
            (array) ($config['blockers'] ?? []),
            $this->manifestBlockers($manifest),
        )));

        return [
            'schema_version' => 'atlas.provider.cursor_sdk.invocation_request.v1',
            'provider' => AtlasForgeCursorSdkInvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? null,
            'configured' => (bool) ($config['configured'] ?? false),
            'plan_safe' => $blockers === [],
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'billing_mode' => $config['billing_mode'] ?? null,
            'quota_bucket' => $config['quota_bucket'] ?? null,
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
            'note' => 'Plan-only: Cursor SDK adapter not spawned and no provider contacted.',
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
            return $this->blocked($manifest, (array) $plan['blockers'], 'Cursor SDK runtime stayed fail-closed.');
        }

        $config = $this->configured();
        $node = (string) ($config['binary_path'] ?? '');
        $adapter = (string) ($config['adapter_path'] ?? $this->adapterPath());
        $timeout = max(1, min(3600, (int) ($manifest['timeout_seconds'] ?? 120)));
        $maxOutputChars = max(200, min(200000, (int) ($manifest['max_output_chars'] ?? 12000)));

        $manifestPath = $this->writeManifest($manifest);
        $argv = [$node, $adapter, $manifestPath];
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
            $blockers[] = (string) ($adapterPayload['failure_type'] ?? 'cursor_sdk_adapter_failed');
        }

        return [
            'schema_version' => 'atlas.provider.cursor_sdk.invocation_result.v1',
            'provider' => AtlasForgeCursorSdkInvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? null,
            'model_observed' => $adapterPayload['model_observed'] ?? ($manifest['model'] ?? null),
            'runtime_mode' => data_get($manifest, 'cursor.runtime_mode', $config['runtime_mode'] ?? 'local'),
            'billing_mode' => $adapterPayload['billing_mode'] ?? ($config['billing_mode'] ?? 'cursor_account_usage_bucket'),
            'quota_bucket' => $adapterPayload['quota_bucket'] ?? ($config['quota_bucket'] ?? 'cursor_account_default'),
            'argv' => [$node, $adapter, '<manifest.json>'],
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
            'tool_events' => is_array($adapterPayload['tool_events'] ?? null) ? $adapterPayload['tool_events'] : [],
            'performance_signal' => is_array($adapterPayload['performance_signal'] ?? null)
                ? $adapterPayload['performance_signal']
                : $this->performanceSignal($manifest, $status, $durationMs, $blockers),
            'classification' => $blockers === [] ? null : [
                'schema_version' => 'atlas.forge.provider_invocation_failure_classification.v1',
                'failure_type' => $blockers[0],
                'confidence' => 'high',
                'provider' => AtlasForgeCursorSdkInvocationDriver::PROVIDER,
            ],
            'failure_type' => $blockers[0] ?? null,
            'blockers' => array_values(array_unique($blockers)),
            'note' => (string) ($adapterPayload['note'] ?? 'Cursor SDK adapter finished under Atlas governance.'),
        ];
    }

    public function setProcessFactory(?callable $factory): void
    {
        $this->processFactory = $factory;
    }

    /**
     * @return array<string,mixed>
     */
    private function config(): array
    {
        return (array) config('atlas.ai.providers.cursor_sdk', []);
    }

    /**
     * @return list<string>
     */
    private function authEnvVars(): array
    {
        $vars = (array) (($this->config()['auth_env'] ?? null) ?: ['CURSOR_API_KEY']);

        return array_values(array_filter($vars, 'is_string'));
    }

    private function authState(array $vars): string
    {
        return ProviderRuntimeEnvironment::authState($vars);
    }

    private function resolveNode(string $binary): ?string
    {
        return ProviderRuntimeEnvironment::resolveExecutable($binary);
    }

    private function nodeModulePresent(string $node, string $module): bool
    {
        $process = new Process([
            $node,
            '--input-type=module',
            '-e',
            'import(process.argv[1]).then(()=>process.exit(0)).catch(()=>process.exit(42));',
            $module,
        ], dirname($this->adapterPath()), null, null, 5.0);

        try {
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function adapterPath(): string
    {
        $configured = trim((string) ($this->config()['adapter_path'] ?? ''));
        if ($configured !== '') {
            return str_starts_with($configured, '/') ? $configured : base_path($configured);
        }

        return base_path('runtimes/node/cursor_sdk/adapter.mjs');
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
            $blockers[] = 'cursor_sdk_workspace_required';
        }
        if (trim((string) ($manifest['model'] ?? '')) === '') {
            $blockers[] = 'cursor_sdk_model_required';
        }
        $allowed = (array) data_get($manifest, 'scope_contract.allowed_files', []);
        if (array_values(array_filter($allowed, 'is_string')) === []) {
            $blockers[] = 'cursor_sdk_allowed_files_required';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function workspacePath(array $manifest): ?string
    {
        return ProviderRuntimeEnvironment::workspacePath($manifest);
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function writeManifest(array $manifest): string
    {
        return ProviderRuntimeManifestStore::write(storage_path('framework/cache/atlas-cursor-sdk'), $manifest);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $manifest, array $blockers, string $note): array
    {
        return [
            'schema_version' => 'atlas.provider.cursor_sdk.invocation_result.v1',
            'provider' => AtlasForgeCursorSdkInvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? null,
            'configured' => false,
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'billing_mode' => data_get($manifest, 'billing.billing_mode', 'cursor_account_usage_bucket'),
            'quota_bucket' => data_get($manifest, 'billing.quota_bucket', 'cursor_account_default'),
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
            'blockers' => array_values(array_unique($blockers)),
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
            'schema_version' => 'atlas.provider.cursor_sdk.performance_signal.v1',
            'provider' => AtlasForgeCursorSdkInvocationDriver::PROVIDER,
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
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  array<int,string>  $argv
     */
    private function makeProcess(array $argv, ?string $cwd, int $timeout): Process
    {
        return ProviderRuntimeProcessFactory::make($argv, $cwd, [
            'ATLAS_CURSOR_SDK_MODULE' => (string) ($this->config()['module'] ?? '@cursor/sdk'),
            'ATLAS_CURSOR_SDK_BILLING_MODE' => (string) ($this->config()['billing_mode'] ?? 'cursor_account_usage_bucket'),
            'ATLAS_CURSOR_SDK_QUOTA_BUCKET' => (string) ($this->config()['quota_bucket'] ?? 'cursor_account_default'),
        ], $timeout, $this->processFactory);
    }

    private function hashPayload(mixed $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function redact(string $value): string
    {
        return ProviderRuntimeOutput::redact($value, [
            '/(cursor[_-]?(?:api[_-]?)?key["\']?\s*[:=]\s*["\']?)[^"\'\s,]+/i',
        ]);
    }

    private function excerpt(string $value, int $maxLength): string
    {
        return ProviderRuntimeOutput::excerpt($value, $maxLength);
    }
}
