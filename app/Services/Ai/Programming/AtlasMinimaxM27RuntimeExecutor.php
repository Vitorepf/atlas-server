<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MiniMax M3 HTTP runtime executor.
 *
 * Calls the MiniMax Anthropic-compatible endpoint directly from Laravel
 * after upstream Forge invocation gates have been verified. Token Plan Key
 * is the canonical auth mode; pay-as-you-go is blocked by default.
 */
class AtlasMinimaxM27RuntimeExecutor
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_BLOCKED = 'blocked';

    public const AUTH_MODE_TOKEN_PLAN = 'token_plan_key';
    public const AUTH_MODE_PAYGO = 'paygo';

    /**
     * Maximum tokens sent to the model. Context that exceeds this limit is
     * truncated (never crashes). ~4 chars per token approximation.
     */
    public const MAX_TOKENS = 38000;
    private const MAX_CONTEXT_CHARS = self::MAX_TOKENS * 4;

    public const BLOCKER_DISABLED = 'minimax_m27_disabled';
    public const BLOCKER_MISSING_TOKEN_PLAN_KEY = 'missing_token_plan_key';
    public const BLOCKER_PAYGO_NOT_AUTHORIZED = 'paygo_not_authorized';
    public const BLOCKER_HIGHSPEED_NOT_AUTHORIZED = 'minimax_m27_highspeed_not_authorized';
    public const BLOCKER_MODEL_NOT_M3 = 'minimax_m3_required';
    public const BLOCKER_WORKSPACE_REQUIRED = 'workspace_path_required';
    public const MODEL = 'MiniMax-M3';

    /** @var callable|null */
    private $httpFactory;

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasMinimaxM27RuntimeExecutorTest.php';
    }

    public function setHttpFactory(?callable $factory): void
    {
        $this->httpFactory = $factory;
    }

    /**
     * Compile a context string for the model, enforcing MAX_TOKENS (38 000).
     *
     * Truncation is character-based (~4 chars per token). The string is never
     * crashed or thrown — oversized input is silently truncated with an ellipsis
     * marker so downstream callers always receive a usable string.
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
    public function configured(): array
    {
        $config = $this->config();
        $blockers = [];

        if (! (bool) ($config['enabled'] ?? false)) {
            $blockers[] = self::BLOCKER_DISABLED;
        }

        $authMode = (string) ($config['auth_mode'] ?? self::AUTH_MODE_TOKEN_PLAN);
        $tokenPlanKey = AiValueNormalizer::trimmedStringOrNull($config['token_plan_key'] ?? null);
        $paygoEnabled = (bool) ($config['paygo_enabled'] ?? false);
        $paygoKey = AiValueNormalizer::trimmedStringOrNull($config['paygo_api_key'] ?? null);

        if ($authMode === self::AUTH_MODE_TOKEN_PLAN) {
            if ($tokenPlanKey === null) {
                $blockers[] = self::BLOCKER_MISSING_TOKEN_PLAN_KEY;
            }
        } elseif ($authMode === self::AUTH_MODE_PAYGO) {
            if (! $paygoEnabled) {
                $blockers[] = self::BLOCKER_PAYGO_NOT_AUTHORIZED;
            } elseif ($paygoKey === null) {
                $blockers[] = 'missing_paygo_api_key';
            }
        } else {
            if ($tokenPlanKey === null) {
                $blockers[] = self::BLOCKER_MISSING_TOKEN_PLAN_KEY;
            }
        }

        $model = $this->configuredModel($config);
        $allowHighspeed = (bool) ($config['allow_highspeed'] ?? false);
        if (! $this->isMiniMaxM3($model)) {
            $blockers[] = self::BLOCKER_MODEL_NOT_M3;
        }
        if ($this->isHighspeedModel($model) && ! $allowHighspeed) {
            $blockers[] = self::BLOCKER_HIGHSPEED_NOT_AUTHORIZED;
        }

        $blockers = AiStringListNormalizer::uniqueStrings($blockers);
        $configured = $blockers === [];

        return [
            'schema_version' => 'atlas.provider.minimax_m27.status.v1',
            'provider' => AtlasForgeMinimaxM27InvocationDriver::PROVIDER,
            'configured' => $configured,
            'runtime_present' => true,
            'binary_path' => null,
            'auth_mode' => $authMode,
            'auth_state' => $this->authState($authMode, $tokenPlanKey, $paygoEnabled, $paygoKey),
            'token_plan_key_present' => $tokenPlanKey !== null,
            'paygo_enabled' => $paygoEnabled,
            'paygo_key_present' => $paygoKey !== null,
            'model' => $model,
            'allow_highspeed' => $allowHighspeed,
            'base_url' => (string) ($config['base_url'] ?? 'https://api.minimax.io'),
            'model_prefixes' => [self::MODEL, 'minimax-m3'],
            'allowed_binaries' => [],
            'blockers' => $blockers,
            'external_provider_call_possible' => $configured,
            'provider_tokens_may_be_spent' => $configured,
            'runtime_boundary' => [
                'owner' => 'laravel_kernel',
                'runtime_family' => 'minimax_m27_http_api',
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
            'note' => 'Fail-closed MiniMax M3 HTTP status; no external provider contacted.',
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
            'schema_version' => 'atlas.provider.minimax_m27.invocation_request.v1',
            'provider' => AtlasForgeMinimaxM27InvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? null,
            'configured' => (bool) ($config['configured'] ?? false),
            'plan_safe' => $blockers === [],
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auth_mode' => $config['auth_mode'] ?? self::AUTH_MODE_TOKEN_PLAN,
            'billing_mode' => 'token_plan_request_based',
            'quota_bucket' => 'minimax_token_plan',
            'argv_preview' => ['https://api.minimax.io/anthropic/v1/messages', '<manifest_hash>'],
            'manifest_hash' => $this->hashPayload($manifest),
            'allowed_files_hash' => $this->hashPayload((array) data_get($manifest, 'scope_contract.allowed_files', [])),
            'forbidden_files_hash' => $this->hashPayload((array) data_get($manifest, 'scope_contract.forbidden_files', [])),
            'blockers' => $blockers,
            'note' => 'Plan-only: MiniMax M3 HTTP executor not contacted, no provider call made.',
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
            return $this->blocked($manifest, (array) $plan['blockers'], 'MiniMax M3 runtime stayed fail-closed.');
        }

        $config = $this->config();
        $authMode = (string) ($config['auth_mode'] ?? self::AUTH_MODE_TOKEN_PLAN);
        $apiKey = $authMode === self::AUTH_MODE_TOKEN_PLAN
            ? (string) ($config['token_plan_key'] ?? '')
            : (string) ($config['paygo_api_key'] ?? '');

        $model = $this->manifestModel($manifest, $config);
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.minimax.io'), '/');
        $endpoint = $baseUrl.'/anthropic/v1/messages';
        $timeout = max(1, min(3600, (int) ($manifest['timeout_seconds'] ?? $config['timeout_seconds'] ?? 120)));
        $maxTokens = max(1, min(65536, (int) ($config['max_output_tokens'] ?? 8192)));
        $maxOutputChars = max(200, min(200000, (int) ($manifest['max_output_chars'] ?? 12000)));

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $this->buildSystemPrompt($manifest),
            'messages' => $this->buildMessages($manifest, self::MAX_CONTEXT_CHARS),
        ];

        $started = microtime(true);

        try {
            $response = $this->makeHttpRequest($endpoint, $apiKey, $payload, $timeout);
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            if (! $response->successful()) {
                return $this->httpError($manifest, $response->status(), $response->body(), $durationMs);
            }

            $data = $response->json() ?? [];
            $stdout = $this->extractText($data);

            if ($maxOutputChars > 0 && strlen($stdout) > $maxOutputChars) {
                $stdout = substr($stdout, 0, $maxOutputChars).'…';
            }

            return [
                'schema_version' => 'atlas.provider.minimax_m27.invocation_result.v1',
                'provider' => AtlasForgeMinimaxM27InvocationDriver::PROVIDER,
                'model' => $model,
                'model_observed' => (string) ($data['model'] ?? $model),
                'configured' => true,
                'provider_called' => true,
                'external_provider_call' => true,
                'provider_tokens_spent' => true,
                'auth_mode' => $authMode,
                'billing_mode' => 'token_plan_request_based',
                'exit_code' => 0,
                'duration_ms' => $durationMs,
                'timeout_seconds' => $timeout,
                'timed_out' => false,
                'stdout_hash' => hash('sha256', $stdout),
                'stderr_hash' => hash('sha256', ''),
                'stdout_excerpt' => $this->excerpt($stdout, 2000),
                'stderr_excerpt' => '',
                'process_status' => self::STATUS_COMPLETED,
                'artifacts' => [],
                'changed_files' => [],
                'performance_signal' => $this->performanceSignal($data, $durationMs, $model),
                'classification' => null,
                'failure_type' => null,
                'blockers' => [],
                'note' => 'MiniMax M3 HTTP API call completed.',
                'request_id' => (string) ($data['id'] ?? ''),
                'stop_reason' => (string) ($data['stop_reason'] ?? ''),
                'usage' => $data['usage'] ?? null,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            return $this->blockedWithFailure($manifest, ['timeout'],
                AtlasForgeProviderFallbackPolicyService::FAILURE_TIMEOUT,
                'MiniMax M3 connection failed: '.$this->sanitizeMsg($e->getMessage()), $durationMs);
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            return $this->blockedWithFailure($manifest, ['provider_error'],
                AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR,
                'MiniMax M3 error: '.$this->sanitizeMsg($e->getMessage()), $durationMs);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function makeHttpRequest(string $endpoint, string $apiKey, array $payload, int $timeout): \Illuminate\Http\Client\Response
    {
        if ($this->httpFactory !== null) {
            return ($this->httpFactory)($endpoint, $apiKey, $payload, $timeout);
        }

        return Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])
            ->timeout($timeout)
            ->post($endpoint, $payload);
    }

    /** @param  array<string,mixed>  $data */
    private function extractText(array $data): string
    {
        $parts = [];
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'thinking') {
                $parts[] = '<thinking>'.($block['thinking'] ?? '').'</thinking>';
            } elseif (($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return implode("\n", array_filter($parts));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  positive-int  $maxChars  Character budget for the user message content.
     */
    private function buildMessages(array $manifest, int $maxChars = self::MAX_CONTEXT_CHARS): array
    {
        $prompt = is_array($manifest['prompt'] ?? null) ? $manifest['prompt'] : [];
        $task = (string) data_get($prompt, 'task_contract.task_description', '');
        $context = (string) data_get($prompt, 'context', '');
        $content = trim(implode("\n\n", array_filter([$context, $task])));

        if ($content === '') {
            $content = 'Execute the task as defined in the manifest.';
        }

        // Enforce MAX_TOKENS contract via compile() — truncate, never crash.
        $compiled = $this->compile($content);
        $content = $compiled['text'];

        return [['role' => 'user', 'content' => $content]];
    }

    /** @param  array<string,mixed>  $manifest */
    private function buildSystemPrompt(array $manifest): string
    {
        $prompt = is_array($manifest['prompt'] ?? null) ? $manifest['prompt'] : [];
        $role = (string) data_get($prompt, 'role', 'assistant');
        $allowedFiles = (array) data_get($manifest, 'scope_contract.allowed_files', []);
        $forbiddenFiles = (array) data_get($manifest, 'scope_contract.forbidden_files', []);

        $parts = [
            "You are a governed Atlas Forge worker. Role: {$role}.",
            'Completion claims are NEVER allowed — only Atlas Decide may promote.',
        ];
        if ($allowedFiles !== []) {
            $parts[] = 'Allowed files: '.implode(', ', array_slice($allowedFiles, 0, 20));
        }
        if ($forbiddenFiles !== []) {
            $parts[] = 'FORBIDDEN files (never touch): '.implode(', ', array_slice($forbiddenFiles, 0, 20));
        }

        return implode(' ', $parts);
    }

    /** @param  array<string,mixed>  $manifest */
    private function manifestBlockers(array $manifest): array
    {
        $blockers = [];
        $path = data_get($manifest, 'workspace.path');
        if (! is_string($path) || trim($path) === '') {
            $blockers[] = self::BLOCKER_WORKSPACE_REQUIRED;
        }
        if (! $this->isMiniMaxM3($this->manifestModel($manifest))) {
            $blockers[] = self::BLOCKER_MODEL_NOT_M3;
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function performanceSignal(array $data, int $durationMs, string $model): array
    {
        return [
            'provider' => AtlasForgeMinimaxM27InvocationDriver::PROVIDER,
            'model' => (string) ($data['model'] ?? $model),
            'duration_ms' => $durationMs,
            'input_tokens' => (int) data_get($data, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($data, 'usage.output_tokens', 0),
            'stop_reason' => (string) ($data['stop_reason'] ?? ''),
            'request_id' => (string) ($data['id'] ?? ''),
        ];
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function classifyHttpError(int $status, string $body): array
    {
        $low = strtolower($body);
        if ($status === 401 || $status === 403) {
            return [['auth_failed'], AtlasForgeProviderFallbackPolicyService::FAILURE_AUTH_FAILED];
        }
        if ($status === 429) {
            if (str_contains($low, 'quota') || str_contains($low, 'credits')) {
                return [['quota_exhausted'], AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED];
            }
            return [['rate_limit'], AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT];
        }
        if ($status === 400 && str_contains($low, 'context')) {
            return [['context_limit'], AtlasForgeProviderFallbackPolicyService::FAILURE_CONTEXT_LIMIT];
        }
        if ($status >= 503) {
            return [['model_unavailable'], AtlasForgeProviderFallbackPolicyService::FAILURE_MODEL_UNAVAILABLE];
        }
        return [['provider_error'], AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR];
    }

    private function httpError(array $manifest, int $status, string $body, int $durationMs): array
    {
        [$blockers, $failureType] = $this->classifyHttpError($status, $body);
        return $this->blockedWithFailure($manifest, $blockers, $failureType,
            "MiniMax M3 HTTP {$status}: ".$this->sanitizeBody($body), $durationMs);
    }

    private function authState(string $mode, ?string $tpKey, bool $paygoEnabled, ?string $pgKey): string
    {
        if ($mode === self::AUTH_MODE_TOKEN_PLAN) {
            return $tpKey !== null ? 'token_plan_key_present' : 'missing';
        }
        if ($mode === self::AUTH_MODE_PAYGO && $paygoEnabled) {
            return $pgKey !== null ? 'paygo_key_present' : 'missing';
        }
        return 'unknown';
    }

    private function isHighspeedModel(string $model): bool
    {
        return str_contains(strtolower($model), 'highspeed');
    }

    private function configuredModel(array $config): string
    {
        $model = trim((string) ($config['model'] ?? self::MODEL));

        return $model !== '' ? $model : self::MODEL;
    }

    private function manifestModel(array $manifest, ?array $config = null): string
    {
        $model = trim((string) ($manifest['model'] ?? ''));
        if ($model !== '') {
            return $model;
        }

        return $this->configuredModel($config ?? $this->config());
    }

    private function isMiniMaxM3(string $model): bool
    {
        return strtolower(trim($model)) === 'minimax-m3';
    }

    private function sanitizeMsg(string $msg): string
    {
        return preg_replace('/sk-[a-zA-Z0-9_\-]{8,}/', '[REDACTED]', $msg) ?? $msg;
    }

    private function sanitizeBody(string $body): string
    {
        $s = $this->sanitizeMsg($body);
        return strlen($s) > 300 ? substr($s, 0, 300).'…' : $s;
    }

    private function excerpt(string $v, int $max): string
    {
        return strlen($v) <= $max ? $v : substr($v, 0, $max).'…';
    }

    private function hashPayload(mixed $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $manifest, array $blockers, string $note): array
    {
        return [
            'schema_version' => 'atlas.provider.minimax_m27.invocation_result.v1',
            'provider' => AtlasForgeMinimaxM27InvocationDriver::PROVIDER,
            'model' => $manifest['model'] ?? null,
            'configured' => false,
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'timed_out' => false,
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
            'process_status' => self::STATUS_BLOCKED,
            'artifacts' => [],
            'changed_files' => [],
            'performance_signal' => null,
            'classification' => null,
            'failure_type' => null,
            'blockers' => AiStringListNormalizer::uniqueStrings($blockers),
            'note' => $note,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blockedWithFailure(array $manifest, array $blockers, string $failureType, string $note, int $durationMs): array
    {
        return array_merge($this->blocked($manifest, $blockers, $note), [
            'provider_called' => true,
            'external_provider_call' => true,
            'duration_ms' => $durationMs,
            'process_status' => self::STATUS_FAILED,
            'failure_type' => $failureType,
        ]);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return (array) config('atlas.ai.providers.minimax_m27', []);
    }
}
