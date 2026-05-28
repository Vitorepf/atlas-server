<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Antigravity SDK governed provider driver.
 *
 * Provider: `antigravity_sdk`. This is not a CLI wrapper. It delegates to a
 * dedicated Atlas-owned Python adapter only after the upstream invocation
 * service has enforced Decision Receipt, dispatch, confirmations and budget.
 */
class AtlasForgeAntigravitySdkInvocationDriver implements AtlasForgeProviderInvocationDriver
{
    public const PROVIDER = 'antigravity_sdk';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasForgeAntigravitySdkInvocationDriverTest.php';
    }

    public function __construct(
        private readonly AtlasAntigravitySdkRuntimeExecutor $runtime,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function supports(string $provider, ?string $model): bool
    {
        if ($provider !== self::PROVIDER) {
            return false;
        }
        if ($model === null || trim($model) === '') {
            return true;
        }

        $model = strtolower($model);
        foreach (['antigravity', 'gemini-', 'claude-', 'gpt-', 'oss'] as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function configured(): array
    {
        return $this->runtime->configured();
    }

    public function plan(array $request): array
    {
        $manifest = $this->manifest($request);
        $plan = $this->runtime->plan($manifest);

        return [
            'schema_version' => 'atlas.forge.provider_driver_plan.v1',
            'provider' => self::PROVIDER,
            'model' => $request['model'] ?? null,
            'argv_preview' => $plan['argv_preview'] ?? [],
            'cwd' => $manifest['workspace']['path'] ?? null,
            'configured' => (bool) ($plan['configured'] ?? false),
            'allowlist_passed' => true,
            'allowlist_blockers' => [],
            'config_blockers' => array_values(array_filter((array) ($this->configured()['blockers'] ?? []), 'is_string')),
            'blockers' => array_values(array_unique((array) ($plan['blockers'] ?? []))),
            'plan_safe' => (bool) ($plan['plan_safe'] ?? false),
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'sdk_request_schema' => 'atlas.provider.antigravity_sdk.invocation_request.v1',
            'manifest_hash' => $plan['manifest_hash'] ?? null,
            'allowed_files_hash' => $plan['allowed_files_hash'] ?? null,
            'forbidden_files_hash' => $plan['forbidden_files_hash'] ?? null,
            'note' => 'Plan-only: Antigravity SDK adapter was not spawned.',
        ];
    }

    public function invoke(array $request): array
    {
        $manifest = $this->manifest($request);
        $result = $this->runtime->invoke($manifest);

        return [
            'schema_version' => 'atlas.forge.provider_driver_result.v1',
            'provider' => self::PROVIDER,
            'model' => $request['model'] ?? null,
            'model_observed' => $result['model_observed'] ?? ($request['model'] ?? null),
            'argv' => $result['argv'] ?? [],
            'cwd' => $result['cwd'] ?? ($manifest['workspace']['path'] ?? null),
            'configured' => (bool) ($result['configured'] ?? false),
            'provider_called' => (bool) ($result['provider_called'] ?? false),
            'external_provider_call' => (bool) ($result['external_provider_call'] ?? false),
            'provider_tokens_spent' => $result['provider_tokens_spent'] ?? false,
            'exit_code' => $result['exit_code'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'timeout_seconds' => $result['timeout_seconds'] ?? ($request['timeout_seconds'] ?? 120),
            'timed_out' => (bool) ($result['timed_out'] ?? false),
            'stdout_hash' => $result['stdout_hash'] ?? hash('sha256', ''),
            'stderr_hash' => $result['stderr_hash'] ?? hash('sha256', ''),
            'stdout_excerpt' => $result['stdout_excerpt'] ?? '',
            'stderr_excerpt' => $result['stderr_excerpt'] ?? '',
            'process_status' => $result['process_status'] ?? null,
            'artifacts' => $result['artifacts'] ?? [],
            'changed_files' => $result['changed_files'] ?? [],
            'performance_signal' => $result['performance_signal'] ?? null,
            'classification' => $result['classification'] ?? null,
            'failure_type' => $result['failure_type'] ?? null,
            'blockers' => array_values(array_unique((array) ($result['blockers'] ?? []))),
            'note' => (string) ($result['note'] ?? 'Antigravity SDK driver finished.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function manifest(array $request): array
    {
        $prompt = is_array($request['prompt'] ?? null) ? $request['prompt'] : [];
        $allowedFiles = array_values(array_filter((array) data_get($prompt, 'scope_contract.allowed_files', []), 'is_string'));
        $forbiddenFiles = array_values(array_filter((array) data_get($prompt, 'scope_contract.forbidden_files', []), 'is_string'));
        $workspacePath = $this->stringOrNull($request['cwd'] ?? null)
            ?? $this->stringOrNull(data_get($request, 'workspace.path'));

        return [
            'schema_version' => 'atlas.provider.antigravity_sdk.invocation_request.v1',
            'provider' => self::PROVIDER,
            'model' => $request['model'] ?? null,
            'prompt' => $prompt,
            'workspace' => [
                'path' => $workspacePath,
            ],
            'scope_contract' => [
                'allowed_files' => $allowedFiles,
                'forbidden_files' => $forbiddenFiles,
                'forbidden_actions' => array_values(array_filter((array) data_get($prompt, 'scope_contract.forbidden_actions', []), 'is_string')),
            ],
            'decision_receipt_id' => $request['decision_receipt_id'] ?? data_get($prompt, 'decision_receipt_id'),
            'decision_receipt_hash' => $request['decision_receipt_hash'] ?? data_get($prompt, 'decision_receipt_hash'),
            'dispatch_id' => $request['dispatch_id'] ?? data_get($prompt, 'dispatch_id'),
            'timeout_seconds' => $request['timeout_seconds'] ?? 120,
            'max_output_chars' => $request['max_output_chars'] ?? 12000,
            'completion_criteria' => data_get($prompt, 'task_contract.acceptance_criteria', []),
            'metadata' => [
                'domain' => 'programming',
                'flow' => 'programming.forge',
                'role' => $request['role'] ?? data_get($prompt, 'role'),
                'obra_id' => $request['obra_id'] ?? data_get($prompt, 'obra_id'),
                'provider_authority' => 'atlas_decide',
                'routing_effect' => 'none',
                'completion_claim_allowed' => false,
            ],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
