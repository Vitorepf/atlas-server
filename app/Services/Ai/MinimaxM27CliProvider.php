<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Programming\AtlasMinimaxM27CliRuntimeExecutor;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Support\AtlasSecurity;

class MinimaxM27CliProvider implements AiProvider
{
    use RunsCliProcesses;

    public function __construct(
        private readonly AtlasMinimaxM27CliRuntimeExecutor $runtime,
    ) {}

    public function key(): string
    {
        return 'minimax_m27_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        return $this->runStreaming($job, $prompt);
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $manifest = $this->manifest($job, $prompt);
        $result = $this->runtime->execute($manifest);
        $ok = (string) ($result['status'] ?? '') === AtlasMinimaxM27CliRuntimeExecutor::STATUS_COMPLETED
            && trim((string) ($result['text'] ?? '')) !== '';
        $output = AtlasSecurity::redactString(trim((string) ($result['text'] ?? '')));
        $error = AtlasSecurity::redactString(trim((string) ($result['error'] ?? '')));

        if ($output !== '') {
            $onEvent?->__invoke([
                'type' => 'response',
                'name' => 'minimax_m3_response',
                'content' => $output,
                'channel' => 'assistant',
                'metadata' => ['source' => 'minimax_m27_cli_provider'],
                'occurred_at' => now()->toJSON(),
            ]);
        }

        return new AiProviderResult(
            ok: $ok,
            output: $output,
            command: ['minimax_m27_cli', '<atlas-governed-python-adapter>'],
            exitCode: $ok ? 0 : 1,
            durationMs: (int) ($result['duration_ms'] ?? 0),
            stdout: $output,
            stderr: $error,
            errorCode: $ok ? null : ((string) ($result['status'] ?? 'blocked') ?: 'blocked'),
            errorMessage: $ok ? null : ($error !== '' ? $error : 'MiniMax M3 CLI runtime blocked or returned no text.'),
            metadata: [
                'provider' => $this->key(),
                'model' => AtlasMinimaxM27CliRuntimeExecutor::MODEL,
                'input_tokens' => (int) ($result['input_tokens'] ?? 0),
                'output_tokens' => (int) ($result['output_tokens'] ?? 0),
                'provider_called' => (bool) ($result['provider_called'] ?? false),
                'runtime_status' => $result['status'] ?? null,
            ],
        );
    }

    public function health(): AiProviderHealthCheck
    {
        $configured = $this->runtime->configured();
        $ok = (bool) ($configured['configured'] ?? false);

        return new AiProviderHealthCheck(
            provider: $this->key(),
            status: $ok ? 'online' : 'offline',
            message: $ok
                ? 'MiniMax M3 CLI runtime configured.'
                : 'MiniMax M3 CLI runtime is not configured: '.implode(', ', (array) ($configured['blockers'] ?? [])),
            metadata: $configured,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function manifest(AiJob $job, string $prompt): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $receipt = is_array($payload['decision_receipt'] ?? null) ? $payload['decision_receipt'] : [];
        $workspace = $this->workdirForJob($job);

        return [
            'schema_version' => 'atlas.provider.minimax_m27_cli.ai_provider_manifest.v1',
            'provider' => $this->key(),
            'model' => AtlasMinimaxM27CliRuntimeExecutor::MODEL,
            'decision_receipt_id' => $this->firstString(
                data_get($receipt, 'decision_id'),
                data_get($receipt, 'receipt_id'),
                $job->trace_id,
            ),
            'decision_receipt_hash' => $this->firstString(
                data_get($receipt, 'receipt_hash'),
                data_get($receipt, 'contract_hash'),
                $this->payloadHash($payload['provider_governance'] ?? $payload),
            ),
            'workspace' => ['path' => $workspace],
            'workspace_path' => $workspace,
            'timeout_seconds' => $job->timeout_seconds,
            'max_output_chars' => (int) config('atlas.ai.providers.minimax_m27_cli.max_output_chars', 12000),
            'scope_contract' => [
                'permission_mode' => 'read',
                'allowed_files' => $this->allowedFiles($job),
                'forbidden_files' => AiStringListNormalizer::trimmedStrings(data_get($payload, 'tool_permissions.forbidden_files', [])),
                'provider_is_executor_only' => true,
                'atlas_is_sovereign' => true,
            ],
            'task_contract' => [
                'task_description' => $job->input_text,
                'context' => $prompt,
            ],
            'metadata' => [
                'role' => 'atlas_ai_minimax_direct',
                'surface_id' => data_get($payload, 'surface_id') ?: data_get($payload, 'app_surface'),
                'trace_id' => $job->trace_id,
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function allowedFiles(AiJob $job): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $files = array_values(array_unique(array_merge(
            AiStringListNormalizer::trimmedStrings(data_get($payload, 'tool_permissions.allowed_files', [])),
            AiStringListNormalizer::trimmedStrings($payload['expected_files'] ?? []),
            AiStringListNormalizer::trimmedStrings($payload['context_refs'] ?? []),
        )));

        return $files !== [] ? $files : ['.'];
    }

    private function firstString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return hash('sha256', spl_object_id($this).microtime(true));
    }

    private function payloadHash(mixed $payload): string
    {
        $encoded = json_encode($payload);

        return hash('sha256', is_string($encoded) ? $encoded : serialize($payload));
    }
}
