<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\RunsCliProcesses;

class ClaudeCliProvider implements AiProvider
{
    use RunsCliProcesses;

    public function key(): string
    {
        return 'claude_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        $provider = config('atlas.ai.providers.claude_cli');
        $binary = (string) ($provider['binary'] ?? 'claude');
        $args = (array) ($provider['args'] ?? ['-p']);

        if (! empty($job->model ?? $provider['model'] ?? null)) {
            $args[] = '--model';
            $args[] = (string) ($job->model ?? $provider['model']);
        }

        return $this->runProcess(
            command: array_values(array_merge([$binary], $args)),
            input: $prompt,
            timeoutSeconds: $job->timeout_seconds,
            cwd: (string) config('atlas.ai.workdir'),
        );
    }

    public function health(): AiProviderHealthCheck
    {
        return $this->checkBinary($this->key(), (string) config('atlas.ai.providers.claude_cli.binary', 'claude'));
    }

    protected function extractOutput(string $stdout): string
    {
        $decoded = json_decode(trim($stdout), true);
        if (is_array($decoded)) {
            foreach (['result', 'content', 'message', 'text'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    return trim($decoded[$key]);
                }
            }
        }

        return trim($stdout);
    }
}
