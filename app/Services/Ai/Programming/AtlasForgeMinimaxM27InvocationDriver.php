<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * MiniMax M2.7 HTTP API governed provider driver.
 *
 * Provider: `minimax_m27`. This is not a CLI wrapper. It delegates to a
 * dedicated Atlas-owned HTTP executor only after the upstream invocation
 * service has enforced Decision Receipt, dispatch, confirmations and budget.
 */
class AtlasForgeMinimaxM27InvocationDriver implements AtlasForgeProviderInvocationDriver
{
    public const PROVIDER = 'minimax_m27';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasForgeMinimaxM27InvocationDriverTest.php';
    }

    public function __construct(
        private readonly AtlasMinimaxM27RuntimeExecutor $runtime,
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

        $modelLower = strtolower($model);

        return str_starts_with($modelLower, 'minimax-m2');
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
            'sdk_request_schema' => 'atlas.provider.minimax_m27.invocation_request.v1',
            'manifest_hash' => $plan['manifest_hash'] ?? null,
            'allowed_files_hash' => $plan['allowed_files_hash'] ?? null,
            'forbidden_files_hash' => $plan['forbidden_files_hash'] ?? null,
            'billing_mode' => $plan['billing_mode'] ?? 'token_plan_request_based',
            'quota_bucket' => $plan['quota_bucket'] ?? null,
            'note' => 'Plan-only: MiniMax M2.7 HTTP executor was not contacted.',
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
            'billing_mode' => $result['billing_mode'] ?? 'token_plan_request_based',
            'quota_bucket' => $result['quota_bucket'] ?? null,
            'auth_mode' => $result['auth_mode'] ?? 'token_plan_key',
            'note' => (string) ($result['note'] ?? 'MiniMax M2.7 HTTP driver finished.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function manifest(array $request): array
    {
        $prompt = is_array($request['prompt'] ?? null) ? $request['prompt'] : [];
        $workspacePath = $this->stringOrNull($request['cwd'] ?? null)
            ?? $this->stringOrNull(data_get($request, 'workspace.path'));

        return [
            'schema_version' => 'atlas.provider.minimax_m27.invocation_request.v1',
            'provider' => self::PROVIDER,
            'model' => $request['model'] ?? null,
            'prompt' => $prompt,
            'workspace' => [
                'path' => $workspacePath,
            ],
            'scope_contract' => [
                'allowed_files' => array_values(array_filter((array) data_get($prompt, 'scope_contract.allowed_files', []), 'is_string')),
                'forbidden_files' => array_values(array_filter((array) data_get($prompt, 'scope_contract.forbidden_files', []), 'is_string')),
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
                'auth_mode' => 'token_plan_key',
                'billing_mode' => 'token_plan_request_based',
                'paygo_enabled' => false,
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
