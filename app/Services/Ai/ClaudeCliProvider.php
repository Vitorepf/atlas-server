<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\HasAttachmentPath;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Support\AiStringListNormalizer;

class ClaudeCliProvider implements AiProvider
{
    use HasAttachmentPath;
    use RunsCliProcesses;

    private string $streamJsonBuffer = '';

    public function __construct(
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {}

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
        $provider = $this->runtimeSettings->providerConfig('claude_cli');
        $binary = (string) ($provider['binary'] ?? 'claude');
        $args = $this->sanitizeConfiguredArgs((array) ($provider['args'] ?? ['-p']));
        $this->streamJsonBuffer = '';

        if ($model = $this->invocationModel($job, $provider)) {
            $args[] = '--model';
            $args[] = $model;
        }

        $computeEffort = $this->computeEffortContractForJob($job, 'claude_cli');
        $effortValue = data_get($computeEffort, 'provider_mapping.value');
        if (is_string($effortValue) && $effortValue !== '') {
            $args = $this->withArgValue($args, '--effort', $effortValue);
        }

        $args = $this->withAtlasRuntimeArgs($args, $job);
        $command = array_values(array_merge([$binary], $args));
        $cwd = $this->workdirForJob($job);
        $promptForProvider = $this->withFileAttachmentInstructions(
            $this->withImageAttachmentInstructions($prompt, $job),
            $job,
        );
        $fingerprint = $this->cliInvocationFingerprint($command, $promptForProvider, $job->timeout_seconds, $cwd, $job, [
            'provider_key' => $this->key(),
            'requested_model' => $this->invocationModel($job, $provider),
            'output_format' => $this->argValue($args, '--output-format'),
            'permission_mode' => $this->argValue($args, '--permission-mode'),
            'compute_effort' => $computeEffort,
            'add_dirs' => $this->argValuesAfter($args, '--add-dir'),
            'session_policy' => in_array('--no-session-persistence', $args, true) ? 'no_session_persistence' : 'provider_default',
        ]);

        $result = $this->runProcessStreaming(
            command: $command,
            input: $promptForProvider,
            timeoutSeconds: $job->timeout_seconds,
            cwd: $cwd,
            onEvent: $onEvent,
            job: $job,
        );

        return new AiProviderResult(
            ok: $result->ok,
            output: $result->output,
            command: $result->command,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            stdout: $result->stdout,
            stderr: $result->stderr,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            metadata: array_merge($result->metadata, [
                'claude_invocation_fingerprint' => $fingerprint,
            ]),
        );
    }

    public function health(): AiProviderHealthCheck
    {
        $binary = (string) ($this->runtimeSettings->providerConfig('claude_cli')['binary'] ?? 'claude');

        return $this->checkCliRuntimeContract(
            check: $this->checkBinary($this->key(), $binary),
            binary: $binary,
            helpArgs: ['--help'],
            requiredTokens: [
                '--model',
                '--effort',
                '--permission-mode',
                '--add-dir',
                '--output-format',
                'stream-json',
                '--no-session-persistence',
            ],
            contractName: 'claude_cli_provider.v1',
        );
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

            // Extended thinking: emit dedicated event so the Atlas Code
            // Live Cockpit can show what the model is reasoning about.
            $thinking = $this->extractClaudeDeltaThinking($decoded);
            if ($thinking !== '') {
                $events[] = [
                    'type' => 'token',
                    'name' => 'claude_thinking_block',
                    'content' => $thinking,
                    'metadata' => [
                        'parser' => 'claude_stream_json',
                        'event_type' => $decoded['type'] ?? null,
                        'checkpoint' => 'provider_thinking',
                    ],
                    'channel' => 'assistant',
                ];

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
                    'checkpoint' => 'provider',
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

    /**
     * Extended thinking blocks from Claude. Payload may shape as either:
     *   { type: 'content_block_delta', delta: { type: 'thinking_delta', thinking: '...' } }
     *   { type: 'content_block_delta', delta: { thinking: '...' } }
     */
    private function extractClaudeDeltaThinking(array $payload): string
    {
        if (($payload['type'] ?? null) !== 'content_block_delta') {
            return '';
        }
        $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
        $kind = $delta['type'] ?? null;
        if ($kind === 'thinking_delta' && is_string($delta['thinking'] ?? null)) {
            return $delta['thinking'];
        }
        if ($kind !== 'thinking_delta' && is_string($delta['thinking'] ?? null)) {
            return $delta['thinking'];
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

    private function argValue(array $args, string $name): ?string
    {
        foreach (array_values($args) as $index => $arg) {
            if ($arg === $name && isset($args[$index + 1]) && is_scalar($args[$index + 1])) {
                return (string) $args[$index + 1];
            }
            if (is_string($arg) && str_starts_with($arg, $name.'=')) {
                return substr($arg, strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function argValuesAfter(array $args, string $name): array
    {
        $values = [];
        $normalized = array_values($args);
        foreach ($normalized as $index => $arg) {
            if ($arg !== $name) {
                continue;
            }
            for ($cursor = $index + 1; isset($normalized[$cursor]) && ! str_starts_with((string) $normalized[$cursor], '--'); $cursor++) {
                if (is_scalar($normalized[$cursor])) {
                    $values[] = (string) $normalized[$cursor];
                }
            }
        }

        return AiStringListNormalizer::uniqueStrings($values);
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

        $args = $this->withClaudeAddDirs($args, $this->imageAttachmentDirectoriesForJob($job));
        $args = $this->withClaudeAddDirs($args, $this->fileAttachmentDirectoriesForJob($job));

        if ($mode === 'danger') {
            $args = $this->withArgValue($args, '--permission-mode', 'bypassPermissions');
        }

        return $args;
    }

    /**
     * Diretorios contendo imagens anexadas, liberados via --add-dir para que
     * o Claude Code consiga abri-los com a Read tool.
     *
     * @return array<int,string>
     */
    private function imageAttachmentDirectoriesForJob(AiJob $job): array
    {
        $directories = [];
        foreach ($this->imageAttachmentPathsForJob($job) as $path) {
            $directory = dirname($path);
            if ($directory === '' || $directory === '.' || $directory === '/') {
                continue;
            }
            $directories[$directory] = true;
        }

        return array_keys($directories);
    }

    /**
     * Paths absolutos das imagens anexadas, validados como existentes no host.
     *
     * @return array<int,string>
     */
    private function imageAttachmentPathsForJob(AiJob $job): array
    {
        $images = data_get($job->payload, 'attachments.images', []);
        if (! is_array($images)) {
            return [];
        }

        $paths = [];
        foreach ($images as $image) {
            $path = is_array($image) ? ($image['path'] ?? null) : null;
            if (! is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path === '' || ! is_file($path)) {
                continue;
            }
            $paths[$path] = true;
        }

        return array_keys($paths);
    }

    /**
     * @return array<int,string>
     */
    private function fileAttachmentDirectoriesForJob(AiJob $job): array
    {
        return collect($this->fileAttachmentPathsForJob($job))
            ->map(fn (array $file): string => dirname($file['path']))
            ->filter(fn (string $directory): bool => $directory !== '' && $directory !== '.' && $directory !== '/' && is_dir($directory))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{label:string,path:string,mime:string,bytes:string}>
     */
    private function fileAttachmentPathsForJob(AiJob $job): array
    {
        $files = data_get($job->payload, 'attachments.files', []);
        if (! is_array($files)) {
            return [];
        }

        $paths = [];
        foreach (array_slice($files, 0, 4) as $index => $file) {
            if (! is_array($file)) {
                continue;
            }

            $path = $this->attachmentPath($file['path'] ?? null);
            if ($path === null) {
                continue;
            }

            $label = is_string($file['original_name'] ?? null) && trim($file['original_name']) !== ''
                ? trim($file['original_name'])
                : 'arquivo '.($index + 1);
            $mime = is_scalar($file['mime_type'] ?? null) ? (string) $file['mime_type'] : 'application/octet-stream';
            $bytes = is_scalar($file['bytes'] ?? null) ? (string) $file['bytes'] : 'desconhecido';
            $paths[$path] = [
                'label' => $label,
                'path' => $path,
                'mime' => $mime,
                'bytes' => $bytes,
            ];
        }

        return array_values($paths);
    }

    private function withImageAttachmentInstructions(string $prompt, AiJob $job): string
    {
        $paths = $this->imageAttachmentPathsForJob($job);
        if ($paths === []) {
            return $prompt;
        }

        $lines = ['', '# Imagens anexadas (paths absolutos para Read tool)'];
        $lines[] = 'O Atlas anexou as imagens a seguir. Use a Read tool com o path absoluto para abrir cada uma e analisar visualmente; nao trate como referencia textual.';
        foreach ($paths as $index => $path) {
            $number = $index + 1;
            $lines[] = "- imagem {$number}: {$path}";
        }

        return rtrim($prompt)."\n".implode("\n", $lines)."\n";
    }

    private function withFileAttachmentInstructions(string $prompt, AiJob $job): string
    {
        $files = $this->fileAttachmentPathsForJob($job);
        if ($files === []) {
            return $prompt;
        }

        $lines = ['', '# Arquivos anexados (paths absolutos para Read tool)'];
        $lines[] = 'Use a Read tool nos caminhos abaixo quando a resposta depender de detalhes, ordem, listas, trechos exatos ou conteudo completo de mensagem longa. Esses arquivos sao contexto do operador; nao edite, nao mova e nao apague.';
        foreach ($files as $index => $file) {
            $number = $index + 1;
            $label = $this->safeAttachmentLabel($file['label']);
            $mime = $this->safeAttachmentLabel($file['mime']);
            $bytes = $this->safeAttachmentLabel($file['bytes']);
            $lines[] = "- arquivo {$number} ({$label}, {$mime}, {$bytes} bytes): {$file['path']}";
        }

        return rtrim($prompt)."\n".implode("\n", $lines)."\n";
    }

    private function safeAttachmentLabel(string $value): string
    {
        $value = trim(preg_replace('/[^\pL\pN._,@:()+= -]+/u', ' ', $value) ?? '');

        return mb_substr($value === '' ? 'arquivo' : $value, 0, 120);
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,string>
     */
    private function sanitizeConfiguredArgs(array $args): array
    {
        return $this->sanitizeCliArgs(
            args: $args,
            valueArgs: [
                '--model',
                '-m',
                '--effort',
                '--permission-mode',
            ],
            standaloneArgs: [
                '--dangerously-skip-permissions',
                '--allow-dangerously-skip-permissions',
            ],
            multiValueArgs: [
                '--add-dir',
            ],
        );
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
