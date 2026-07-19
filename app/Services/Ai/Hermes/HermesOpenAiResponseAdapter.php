<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use Throwable;

/**
 * Narrow OpenAI chat-completions adapter for preregistered Rivals units.
 *
 * The adapter is deliberately not a second Atlas runtime. Every request is
 * compiled into an AiJob and executed by the canonical HermesCliProvider,
 * which produces the ExecutiveMission and HermesResultPacket recorded here.
 */
final class HermesOpenAiResponseAdapter
{
    public function __construct(private readonly HermesCliProvider $provider) {}

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $headers
     * @return array{status:int,body:array<string,mixed>}
     */
    public function complete(array $body, array $headers): array
    {
        if (! is_array($body['messages'] ?? null)) {
            return $this->error(400, 'messages_required', 'invalid_request_error');
        }
        if (($body['stream'] ?? false) === true) {
            return $this->error(400, 'streaming_not_supported', 'invalid_request_error');
        }

        $binding = $this->binding($headers);
        if ($binding === null) {
            return $this->error(400, 'rivals_execution_binding_invalid', 'invalid_request_error');
        }

        $entry = $binding['entry'];
        $model = basename(trim((string) ($body['model'] ?? $entry['atlas_cli_model'] ?? '')));
        if ($model === '' || $model !== (string) ($entry['atlas_cli_model'] ?? '')) {
            return $this->error(400, 'rivals_execution_model_mismatch', 'invalid_request_error');
        }

        $prompt = $this->renderMessages($body['messages']);
        $tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];
        if ($tools !== []) {
            $prompt .= "\n\n[available tools]\n".json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ."\n\nThe benchmark harness owns the tool loop. If and only if a tool is needed, reply with ONLY this JSON:\n"
                .'{"tool_calls":[{"name":"<tool name>","arguments":{}}]}'
                ."\nOtherwise reply with the plain assistant message.";
        }

        $scratch = $binding['scratch'];
        RunPaths::ensureDir($scratch);
        $usageFile = tempnam($scratch, 'hermes-usage-');
        if (! is_string($usageFile)) {
            return $this->error(500, 'atlas_runtime_usage_file_unavailable', 'server_error');
        }

        $job = $this->job($entry, $model, $prompt, $usageFile, $binding['run_id']);
        try {
            $result = $this->provider->runStreaming($job, $prompt);
        } catch (Throwable $exception) {
            $message = AtlasSecurity::redactString($exception->getMessage());
            $result = new AiProviderResult(
                ok: false,
                output: '',
                command: ['hermes', '[invocation_failed_before_command_receipt]'],
                exitCode: null,
                durationMs: 0,
                stdout: '',
                stderr: $message,
                errorCode: 'provider_exception',
                errorMessage: $message,
            );
        } finally {
            @unlink($usageFile);
        }

        $usage = $this->usage($result);
        $governed = $this->governed($result);
        $failureReason = $this->failureReason($result, $usage, $governed);
        $passed = $failureReason === null;
        $this->recordCall(
            runId: $binding['run_id'],
            entry: $entry,
            scratch: $scratch,
            result: $result,
            usage: $usage,
            passed: $passed,
            failureReason: $failureReason,
        );

        if (! $passed) {
            return $this->error(502, (string) $failureReason, 'server_error');
        }

        $content = trim($result->output);
        $message = ['role' => 'assistant', 'content' => $content];
        $finishReason = 'stop';
        if ($tools !== [] && ($toolCalls = $this->parseToolCalls($content)) !== null) {
            $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls];
            $finishReason = 'tool_calls';
        }

        return [
            'status' => 200,
            'body' => [
                'id' => 'chatcmpl-atlas-'.bin2hex(random_bytes(8)),
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $model,
                'choices' => [[
                    'index' => 0,
                    'message' => $message,
                    'finish_reason' => $finishReason,
                ]],
                'usage' => [
                    'prompt_tokens' => $usage['input_tokens'],
                    'completion_tokens' => $usage['output_tokens'],
                    'total_tokens' => $usage['input_tokens'] + $usage['output_tokens'],
                ],
            ],
        ];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function error(int $status, string $message, string $type): array
    {
        return [
            'status' => $status,
            'body' => ['error' => ['message' => $message, 'type' => $type]],
        ];
    }

    /**
     * @param  array<string,string>  $headers
     * @return array{run_id:string,entry:array<string,mixed>,scratch:string}|null
     */
    private function binding(array $headers): ?array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim((string) $name))] = trim((string) $value);
        }
        $runId = $normalized['x-atlas-rivals-run-id'] ?? '';
        $executionId = $normalized['x-atlas-rivals-execution-id'] ?? '';
        if ($runId === '' || $executionId === '') {
            return null;
        }

        try {
            $manifest = NativeExecutionManifest::load($runId);
        } catch (Throwable) {
            return null;
        }
        if (! in_array((string) ($manifest->data['suite_id'] ?? ''), ['inspect_evals', 'tau2_bench'], true)) {
            return null;
        }
        $entry = collect($manifest->entries())->first(
            fn (array $candidate): bool => ($candidate['execution_id'] ?? null) === $executionId,
        );
        if (! is_array($entry) || ($entry['runtime'] ?? null) !== 'atlas_dev') {
            return null;
        }

        $expectedScratch = RunPaths::runDir($runId).'/native_scratch/'.$executionId;
        $declaredScratch = (string) data_get($entry, 'normalization.scratch_dir', '');
        if ($declaredScratch !== $expectedScratch) {
            return null;
        }

        return ['run_id' => $runId, 'entry' => $entry, 'scratch' => $expectedScratch];
    }

    /** @param array<string,mixed> $entry */
    private function job(array $entry, string $model, string $prompt, string $usageFile, string $runId): AiJob
    {
        $job = new AiJob([
            'trace_id' => (string) Str::uuid(),
            'kind' => 'rivals_openai_response',
            'provider' => 'hermes_cli',
            'model' => $model,
            'input_text' => $prompt,
            'timeout_seconds' => max(1, (int) ($entry['max_seconds'] ?? 900)),
            'metadata' => [
                'purpose' => 'rivals_openai_compatible_response',
                'permission_mode' => 'read',
                'run_id' => $runId,
                'execution_id' => $entry['execution_id'],
            ],
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'read',
                    'workspace' => base_path(),
                    'allowed_roots' => [base_path()],
                ],
                // Presence forces the canonical provider onto its auditable
                // per-call CLI transport rather than a warm shared ACP session.
                'forge_provider_invocation_env' => ['TERMINAL_ENV' => 'local'],
                'hermes' => [
                    'provider' => 'verboo',
                    'source' => 'rivals',
                    'cli_oneshot' => true,
                    'safe_mode' => true,
                    'usage_file' => $usageFile,
                    'memory_policy' => 'off',
                    'schedule_policy' => 'off',
                    'procedure_policy' => 'off',
                    'runtime_router_reason' => 'rivals_preregistered_single_provider_arm',
                    'response_format' => 'openai_chat_completion',
                    'network_policy' => 'limited',
                ],
                'task_request' => [
                    'task_type' => 'rivals_benchmark_response',
                    'objective' => 'Return one bounded model response to the preregistered external benchmark harness.',
                    'success_criteria' => [
                        'Use only the fixed Verboo model declared by the native manifest.',
                        'Return an OpenAI-compatible assistant response.',
                        'Persist usage and governed runtime evidence.',
                    ],
                ],
                'decision_receipt' => [
                    'schema_version' => 'atlas.rivals2.fixed_arm_decision.v1',
                    'decision' => 'use_preregistered_single_provider_arm',
                    'run_id' => $runId,
                    'execution_id' => $entry['execution_id'],
                    'provider' => 'hermes_cli',
                    'model' => $model,
                    'fallback_allowed' => false,
                ],
            ],
        ]);
        $job->setAttribute('id', (string) Str::uuid());

        return $job;
    }

    /** @return array{input_tokens:int,output_tokens:int,present:bool} */
    private function usage(AiProviderResult $result): array
    {
        $input = (int) (data_get($result->metadata, 'hermes_usage.input_tokens')
            ?? data_get($result->metadata, 'hermes_usage.prompt_tokens')
            ?? 0);
        $output = (int) (data_get($result->metadata, 'hermes_usage.output_tokens')
            ?? data_get($result->metadata, 'hermes_usage.completion_tokens')
            ?? 0);

        return [
            'input_tokens' => max(0, $input),
            'output_tokens' => max(0, $output),
            'present' => ($input + $output) > 0,
        ];
    }

    private function governed(AiProviderResult $result): bool
    {
        return data_get($result->metadata, 'executive_mission.schema_version') === 'atlas.hermes.executive_mission.v1'
            && is_string(data_get($result->metadata, 'executive_mission.mission_hash'))
            && data_get($result->metadata, 'hermes_result_packet.schema_version') === 'atlas.hermes.result_packet.v1'
            && is_string(data_get($result->metadata, 'hermes_result_packet.result_hash'))
            && data_get($result->metadata, 'hermes_runtime.atlas_is_sovereign') === true;
    }

    /** @param array{input_tokens:int,output_tokens:int,present:bool} $usage */
    private function failureReason(AiProviderResult $result, array $usage, bool $governed): ?string
    {
        if (! $result->ok) {
            return 'atlas_runtime_failed:'.($result->errorCode ?: 'cli_error');
        }
        if (trim($result->output) === '') {
            return 'atlas_runtime_empty_output';
        }
        if (! $governed) {
            return 'atlas_runtime_governance_proof_missing';
        }
        if (! $usage['present']) {
            return 'atlas_runtime_usage_missing';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array{input_tokens:int,output_tokens:int,present:bool}  $usage
     */
    private function recordCall(
        string $runId,
        array $entry,
        string $scratch,
        AiProviderResult $result,
        array $usage,
        bool $passed,
        ?string $failureReason,
    ): void {
        $lockPath = $scratch.'/.atlas-response-proof.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('rivals_response_proof_lock_failed');
        }

        try {
            $proofPath = $scratch.'/.rivals_atlas_dev_bridge.json';
            $existing = is_file($proofPath)
                ? (json_decode((string) file_get_contents($proofPath), true) ?: [])
                : [];
            $calls = is_array($existing['calls'] ?? null) ? $existing['calls'] : [];
            $sequence = count($calls) + 1;
            $callDir = $scratch.'/atlas-response-calls';
            RunPaths::ensureDir($callDir);
            $prefix = sprintf('call-%04d', $sequence);
            $stdoutPath = $callDir.'/'.$prefix.'.stdout.log';
            $stderrPath = $callDir.'/'.$prefix.'.stderr.log';
            AtomicWriter::write($stdoutPath, $this->capturedStream('stdout', $result->stdout));
            AtomicWriter::write($stderrPath, $this->capturedStream('stderr', $result->stderr));

            $runDir = RunPaths::runDir($runId);
            $relativeStdout = ltrim(substr($stdoutPath, strlen($runDir)), '/');
            $relativeStderr = ltrim(substr($stderrPath, strlen($runDir)), '/');
            $errorCode = $passed ? null : ($result->errorCode ?: $failureReason);
            $calls[] = [
                'sequence' => $sequence,
                'at' => now()->toIso8601String(),
                'status' => $passed ? 'passed' : 'failed',
                'failure_reason' => $failureReason,
                'error_code' => $errorCode,
                'duration_ms' => $result->durationMs,
                'exit_code' => $result->exitCode,
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'stdout_path' => $relativeStdout,
                'stdout_sha256' => hash('sha256', $result->stdout),
                'stderr_path' => $relativeStderr,
                'stderr_sha256' => hash('sha256', $result->stderr),
                'executive_mission_hash' => data_get($result->metadata, 'executive_mission.mission_hash'),
                'result_packet_hash' => data_get($result->metadata, 'hermes_result_packet.result_hash'),
                'command_hash' => hash('sha256', json_encode($result->command, JSON_UNESCAPED_SLASHES)),
            ];

            $inputTokens = array_sum(array_column($calls, 'input_tokens'));
            $outputTokens = array_sum(array_column($calls, 'output_tokens'));
            $successful = count(array_filter($calls, fn (array $call): bool => $call['status'] === 'passed'));
            $errorCodes = array_values(array_unique(array_filter(array_column($calls, 'error_code'))));
            $priorFailure = is_string($existing['failure_reason'] ?? null) ? $existing['failure_reason'] : null;
            $aggregatePassed = $passed && ($existing['status'] ?? 'passed') !== 'failed';
            $aggregateFailure = $aggregatePassed ? null : ($priorFailure ?: $failureReason ?: 'atlas_runtime_call_failed');

            $proof = [
                'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v2',
                'status' => $aggregatePassed ? 'passed' : 'failed',
                'failure_reason' => $aggregateFailure,
                'real_provider' => $successful > 0 && ($inputTokens + $outputTokens) > 0,
                'provider' => 'hermes_cli',
                'model' => (string) $entry['atlas_cli_model'],
                'execution_id' => (string) $entry['execution_id'],
                'runtime_contract' => 'atlas.hermes_cli_provider.v1',
                'fair_mode' => [
                    'single_provider' => true,
                    'decide_disabled' => true,
                    'fallback_disabled' => true,
                ],
                'governance' => [
                    'atlas_is_sovereign' => true,
                    'executive_mission_schema' => 'atlas.hermes.executive_mission.v1',
                    'result_packet_schema' => 'atlas.hermes.result_packet.v1',
                    'safe_mode' => true,
                ],
                'provider_call' => [
                    'provider_calls' => count($calls),
                    'successful_responses' => $successful,
                    'error_codes' => $errorCodes,
                ],
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cost_usd' => 0.0,
                    'present' => $successful > 0 && ($inputTokens + $outputTokens) > 0,
                ],
                'calls' => $calls,
                'updated_at' => now()->toIso8601String(),
            ];
            AtomicWriter::write(
                $proofPath,
                json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            );

            file_put_contents(
                RunPaths::root().'/endpoint_receipts.jsonl',
                json_encode([
                    'schema_version' => 'atlas.rivals2.endpoint_receipt.v2',
                    'at' => now()->toIso8601String(),
                    'run_id' => $runId,
                    'execution_id' => $entry['execution_id'],
                    'runtime' => 'hermes_cli',
                    'provider' => 'verboo',
                    'model' => $entry['atlas_cli_model'],
                    'status' => $passed ? 'passed' : 'failed',
                    'failure_reason' => $failureReason,
                    'input_tokens' => $usage['input_tokens'],
                    'output_tokens' => $usage['output_tokens'],
                    'stdout_path' => $relativeStdout,
                    'stderr_path' => $relativeStderr,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
                FILE_APPEND | LOCK_EX,
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function capturedStream(string $stream, string $contents): string
    {
        return "atlas.rivals2.captured_stream.v1\nstream={$stream}\nbytes=".strlen($contents)."\n\n".$contents;
    }

    /** @param list<array<string,mixed>> $messages */
    private function renderMessages(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = implode("\n", array_map(
                    static fn (mixed $block): string => (string) (is_array($block) ? ($block['text'] ?? '') : $block),
                    $content,
                ));
            }
            if (is_array($message['tool_calls'] ?? null)) {
                foreach ($message['tool_calls'] as $call) {
                    $function = is_array($call['function'] ?? null) ? $call['function'] : [];
                    $content .= sprintf(
                        "\n[assistant called tool %s(%s)]",
                        (string) ($function['name'] ?? '?'),
                        (string) ($function['arguments'] ?? '{}'),
                    );
                }
            }
            $parts[] = "[{$role}]\n".trim((string) $content);
        }

        return implode("\n\n", $parts);
    }

    /** @return list<array<string,mixed>>|null */
    private function parseToolCalls(string $content): ?array
    {
        $json = trim($content);
        if (! str_starts_with($json, '{')) {
            $start = strpos($json, '{');
            $end = strrpos($json, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $json = substr($json, $start, $end - $start + 1);
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! is_array($decoded['tool_calls'] ?? null)) {
            return null;
        }

        $calls = [];
        foreach ($decoded['tool_calls'] as $index => $call) {
            if (! is_array($call) || ! is_string($call['name'] ?? null)) {
                return null;
            }
            $calls[] = [
                'id' => 'call_atlas_'.$index.'_'.bin2hex(random_bytes(4)),
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => json_encode($call['arguments'] ?? new \stdClass, JSON_UNESCAPED_SLASHES),
                ],
            ];
        }

        return $calls === [] ? null : $calls;
    }
}
