<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\RunsCliProcesses;

class ClaudeCliProvider implements AiProvider
{
    use RunsCliProcesses;

    private string $streamJsonBuffer = '';

    public function key(): string
    {
        return 'claude_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        return $this->runStreaming($job, $prompt);
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $provider = config('atlas.ai.providers.claude_cli');
        $binary = (string) ($provider['binary'] ?? 'claude');
        $args = (array) ($provider['args'] ?? ['-p']);
        $this->streamJsonBuffer = '';

        if ($model = $this->invocationModel($job, $provider)) {
            $args[] = '--model';
            $args[] = $model;
        }

        $args = $this->withAtlasRuntimeArgs($args, $job);

        return $this->runProcessStreaming(
            command: array_values(array_merge([$binary], $args)),
            input: $prompt,
            timeoutSeconds: $job->timeout_seconds,
            cwd: $this->workdirForJob($job),
            onEvent: $onEvent,
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
            $single = $this->extractTextFromClaudePayload($decoded);
            if ($single !== '') {
                return $single;
            }
        }

        $result = '';
        $tokens = '';

        foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            $eventText = $this->extractTextFromClaudePayload($event);
            if ($eventText === '') {
                continue;
            }

            if (($event['type'] ?? null) === 'result' || isset($event['result'])) {
                $result = $eventText;
            } else {
                $tokens .= $eventText;
            }
        }

        if ($result !== '') {
            return trim($result);
        }

        if ($tokens !== '') {
            return trim($tokens);
        }

        return trim($stdout);
    }

    protected function streamOutputEvents(string $chunk): array
    {
        $this->streamJsonBuffer .= $chunk;
        $lines = preg_split('/\R/', $this->streamJsonBuffer) ?: [];
        $this->streamJsonBuffer = (string) array_pop($lines);
        $events = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $text = $this->extractClaudeDeltaText($decoded);
            if ($text === '') {
                continue;
            }

            $events[] = [
                'type' => 'token',
                'name' => 'claude_text_delta',
                'content' => $text,
                'metadata' => [
                    'parser' => 'claude_stream_json',
                    'event_type' => $decoded['type'] ?? null,
                ],
                'channel' => 'assistant',
            ];
        }

        return $events;
    }

    private function extractClaudeDeltaText(array $payload): string
    {
        $type = $payload['type'] ?? null;
        if ($type === 'content_block_delta') {
            $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];

            return isset($delta['text']) && is_string($delta['text']) ? $delta['text'] : '';
        }

        if (isset($payload['delta']) && is_array($payload['delta']) && is_string($payload['delta']['text'] ?? null)) {
            return $payload['delta']['text'];
        }

        return '';
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
        if ($model === '' || str_ends_with($model, '_default')) {
            return null;
        }

        return $model;
    }

    private function extractTextFromClaudePayload(array $payload): string
    {
        foreach (['result', 'content', 'message', 'text'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                return trim($payload[$key]);
            }
        }

        if (isset($payload['message']) && is_array($payload['message'])) {
            $nested = $this->extractTextFromClaudePayload($payload['message']);
            if ($nested !== '') {
                return $nested;
            }
        }

        $content = $payload['content'] ?? null;
        if (is_array($content)) {
            $text = '';
            foreach ($content as $block) {
                if (is_array($block) && is_string($block['text'] ?? null)) {
                    $text .= $block['text'];
                }
            }

            return trim($text);
        }

        return trim($this->extractClaudeDeltaText($payload));
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,mixed>
     */
    private function withAtlasRuntimeArgs(array $args, AiJob $job): array
    {
        $mode = $this->permissionModeForJob($job);

        if (in_array($mode, ['write', 'danger'], true)) {
            $args = $this->withClaudeAddDirs($args, $this->allowedRootsForJob($job));
        }

        if ($mode === 'danger') {
            $args = $this->withArgValue($args, '--permission-mode', 'bypassPermissions');
        }

        return $args;
    }

    /**
     * @param  array<int,mixed>  $args
     * @param  array<int,string>  $directories
     * @return array<int,mixed>
     */
    private function withClaudeAddDirs(array $args, array $directories): array
    {
        if ($directories === []) {
            return $args;
        }

        $normalized = array_values($args);
        $existing = [];

        foreach ($normalized as $index => $arg) {
            if ($arg !== '--add-dir') {
                continue;
            }

            for ($cursor = $index + 1; isset($normalized[$cursor]) && ! str_starts_with((string) $normalized[$cursor], '--'); $cursor++) {
                $existing[] = (string) $normalized[$cursor];
            }
        }

        $missing = array_values(array_diff($directories, $existing));
        if ($missing === []) {
            return $normalized;
        }

        $normalized[] = '--add-dir';

        return array_values(array_merge($normalized, $missing));
    }
}
