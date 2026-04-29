<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\RunsCliProcesses;
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
        $provider = config('atlas.ai.providers.codex_cli');
        $binary = (string) ($provider['binary'] ?? 'codex');
        $args = (array) ($provider['args'] ?? ['exec']);
        $tmp = storage_path('app/ai/codex-last-'.bin2hex(random_bytes(6)).'.txt');
        File::ensureDirectoryExists(dirname($tmp));

        if (! in_array('--sandbox', $args, true)) {
            $args[] = '--sandbox';
            $args[] = (string) ($provider['sandbox'] ?? 'read-only');
        }

        if (! empty($job->model ?? $provider['model'] ?? null)) {
            $args[] = '--model';
            $args[] = (string) ($job->model ?? $provider['model']);
        }

        $args[] = '--output-last-message';
        $args[] = $tmp;
        $args[] = '-';

        $result = $this->runProcess(
            command: array_values(array_merge([$binary], $args)),
            input: $prompt,
            timeoutSeconds: $job->timeout_seconds,
            cwd: (string) config('atlas.ai.workdir'),
        );

        if (File::exists($tmp)) {
            $lastMessage = trim(File::get($tmp));
            File::delete($tmp);
            if ($lastMessage !== '') {
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
}
