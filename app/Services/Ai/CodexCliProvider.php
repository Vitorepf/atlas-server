<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;

class CodexCliProvider implements AiProvider
{
    use RunsCliProcesses;

    public function key(): string
    {
        return 'codex_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        return $this->runStreaming($job, $prompt);
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $provider = config('atlas.ai.providers.codex_cli');
        $binary = (string) ($provider['binary'] ?? 'codex');
        $args = (array) ($provider['args'] ?? ['exec']);
        $tmp = storage_path('app/ai/codex-last-'.bin2hex(random_bytes(6)).'.txt');
        File::ensureDirectoryExists(dirname($tmp));

        $args = $this->withArgValue(
            $args,
            '--sandbox',
            (string) (data_get($job->payload, 'tool_permissions.codex_sandbox') ?: ($provider['sandbox'] ?? 'read-only')),
        );

        if (! empty($job->model ?? $provider['model'] ?? null)) {
            $args[] = '--model';
            $args[] = (string) ($job->model ?? $provider['model']);
        }

        $args[] = '--output-last-message';
        $args[] = $tmp;
        $args[] = '-';

        $result = $this->runProcessStreaming(
            command: array_values(array_merge([$binary], $args)),
            input: $prompt,
            timeoutSeconds: $job->timeout_seconds,
            cwd: $this->workdirForJob($job),
            onEvent: $onEvent,
        );

        if (File::exists($tmp)) {
            $lastMessage = trim(AtlasSecurity::redactString(File::get($tmp)));
            File::delete($tmp);
            if ($lastMessage !== '') {
                $onEvent?->__invoke([
                    'type' => 'response',
                    'name' => 'codex_last_message',
                    'content' => $lastMessage,
                    'channel' => 'assistant',
                    'metadata' => ['source' => 'codex_output_last_message'],
                    'occurred_at' => now()->toJSON(),
                ]);

                return new AiProviderResult(
                    ok: $result->ok,
                    output: $lastMessage,
                    command: $result->command,
                    exitCode: $result->exitCode,
                    durationMs: $result->durationMs,
                    stdout: $result->stdout,
                    stderr: $result->stderr,
                    errorCode: $result->errorCode,
                    errorMessage: $result->errorMessage,
                    metadata: $result->metadata,
                );
            }
        }

        return $result;
    }

    public function health(): AiProviderHealthCheck
    {
        return $this->checkBinary($this->key(), (string) config('atlas.ai.providers.codex_cli.binary', 'codex'));
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,mixed>
     */
    private function withArgValue(array $args, string $name, string $value): array
    {
        $normalized = array_values($args);
        $index = array_search($name, $normalized, true);

        if ($index === false) {
            $normalized[] = $name;
            $normalized[] = $value;

            return $normalized;
        }

        $normalized[$index + 1] = $value;

        return $normalized;
    }
}
