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
        $args = $this->withAtlasRuntimeArgs($args, $job);
        $args = $this->withImageAttachments($args, $job);

        if ($model = $this->invocationModel($job, $provider)) {
            $args[] = '--model';
            $args[] = $model;
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

    private function invocationModel(AiJob $job, array $provider): ?string
    {
        $source = data_get($job->payload, 'model_identity_source') ?? data_get($job->metadata, 'model_identity_source');
        if (in_array($source, ['provider_default_identity', 'configured_model_identity'], true)) {
            return null;
        }

        $model = $job->model ?: ($provider['model'] ?? null);
        if (! is_string($model) && ! is_numeric($model)) {
            return null;
        }

        $model = trim((string) $model);

        return $model === '' ? null : $model;
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,mixed>
     */
    private function withAtlasRuntimeArgs(array $args, AiJob $job): array
    {
        $mode = $this->permissionModeForJob($job);

        if (in_array($mode, ['write', 'danger'], true)) {
            $args = $this->withRepeatedArgValues($args, '--add-dir', $this->allowedRootsForJob($job));
        }

        if ($mode === 'danger') {
            $args = $this->withArgValue($args, '--ask-for-approval', 'never');
        }

        return $args;
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,mixed>
     */
    private function withImageAttachments(array $args, AiJob $job): array
    {
        $images = data_get($job->payload, 'attachments.images', []);
        if (! is_array($images) || $images === []) {
            return $args;
        }

        $paths = collect($images)
            ->map(fn (mixed $image): ?string => is_array($image) && is_string($image['path'] ?? null) ? $image['path'] : null)
            ->filter(fn (?string $path): bool => is_string($path) && File::isFile($path))
            ->values()
            ->all();

        return $this->withRepeatedArgValues($args, '--image', $paths);
    }
}
