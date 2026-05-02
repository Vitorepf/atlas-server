<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;

class GeminiCliProvider implements AiProvider
{
    use RunsCliProcesses;

    private const REQUIRED_MODEL = 'gemini-3.1-pro-preview';

    private string $streamJsonBuffer = '';

    public function key(): string
    {
        return 'gemini_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        return $this->runStreaming($job, $prompt);
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $provider = config('atlas.ai.providers.gemini_cli');
        $binary = (string) ($provider['binary'] ?? 'gemini');
        $args = $this->sanitizeConfiguredArgs((array) ($provider['args'] ?? []));
        $this->streamJsonBuffer = '';

        $args = $this->withArgValue($args, '--model', self::REQUIRED_MODEL);
        $args = $this->withArgValue($args, '--prompt', '');
        $args = $this->withArgValue($args, '--output-format', 'stream-json');
        $args[] = '--approval-mode=yolo';
        $args[] = '--skip-trust';

        $attachmentWorkspace = null;
        $attachments = $this->attachmentAccessPaths($job);
        if ($attachments !== []) {
            [$attachments, $attachmentWorkspace] = $this->prepareAttachmentWorkspace($job, $attachments);
        }

        if ($attachments !== []) {
            $args = $this->withRepeatedArgValues($args, '--include-directories', $this->attachmentIncludeDirectories($attachments));
            $prompt = $this->promptWithAttachmentAccess($prompt, $attachments);
        }

        try {
            $result = $this->runProcessStreaming(
                command: array_values(array_merge([$binary], $args)),
                input: $prompt,
                timeoutSeconds: $job->timeout_seconds,
                cwd: $this->workdirForJob($job),
                onEvent: $onEvent,
                job: $job,
            );
        } finally {
            if (is_string($attachmentWorkspace) && is_dir($attachmentWorkspace)) {
                File::deleteDirectory($attachmentWorkspace);
            }
        }

        return $this->withModelPolicyValidation($result);
    }

    public function health(): AiProviderHealthCheck
    {
        $provider = (array) config('atlas.ai.providers.gemini_cli', []);
        $check = $this->checkBinary($this->key(), (string) ($provider['binary'] ?? 'gemini'));

        return new AiProviderHealthCheck(
            provider: $check->provider,
            status: $check->status,
            message: $check->message,
            metadata: array_merge($check->metadata, [
                'required_model' => self::REQUIRED_MODEL,
                'runtime_policy' => 'native_yolo',
            ]),
        );
    }

    protected function extractOutput(string $stdout): string
    {
        $decoded = json_decode(trim($stdout), true);
        if (is_array($decoded) && $this->payloadHasAssistantText($decoded)) {
            $text = $this->textFromPayload($decoded);
            if ($text !== '') {
                return $text;
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

            if (! $this->payloadHasAssistantText($event)) {
                continue;
            }

            $text = $this->textFromPayload($event);
            if ($text === '') {
                continue;
            }

            if (($event['type'] ?? null) === 'result' || isset($event['result'])) {
                $result = $text;
            } else {
                $tokens .= $text;
            }
        }

        return trim($result !== '' ? $result : ($tokens !== '' ? $tokens : $stdout));
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

            if (! $this->payloadHasAssistantText($decoded)) {
                continue;
            }

            $text = $this->textFromPayload($decoded);
            if ($text === '') {
                continue;
            }

            $events[] = [
                'type' => ($decoded['type'] ?? null) === 'result' ? 'response' : 'token',
                'name' => 'gemini_stream_json',
                'content' => $text,
                'metadata' => [
                    'parser' => 'gemini_stream_json',
                    'event_type' => $decoded['type'] ?? null,
                ],
                'channel' => 'assistant',
            ];
        }

        return $events;
    }

    /**
     * @return array<string,string|false>
     */
    protected function cliProcessEnv(): array
    {
        $extra = [
            'TERM' => (string) ($_SERVER['TERM'] ?? getenv('TERM') ?: 'xterm-256color'),
        ];
        $home = config('atlas.ai.providers.gemini_cli.home');
        if (! is_string($home) || trim($home) === '') {
            return AtlasSecurity::processEnv($extra, 'provider');
        }

        $home = trim($home);
        File::ensureDirectoryExists($home);

        return AtlasSecurity::processEnv(array_merge($extra, [
            'GEMINI_CLI_HOME' => $home,
        ]), 'provider');
    }

    private function withModelPolicyValidation(AiProviderResult $result): AiProviderResult
    {
        $models = $this->modelsFromStdout($result->stdout);
        $metadata = array_merge($result->metadata, [
            'required_model' => self::REQUIRED_MODEL,
            'observed_models' => $models,
        ]);

        $unexpected = array_values(array_filter(
            $models,
            fn (string $model): bool => $model !== self::REQUIRED_MODEL,
        ));

        if ($unexpected !== []) {
            return new AiProviderResult(
                ok: false,
                output: '',
                command: $result->command,
                exitCode: $result->exitCode,
                durationMs: $result->durationMs,
                stdout: $result->stdout,
                stderr: $result->stderr,
                errorCode: 'policy_violation',
                errorMessage: 'Gemini CLI returned or routed to a model outside the Atlas allowlist.',
                metadata: array_merge($metadata, [
                    'policy_violation' => 'gemini_model_mismatch',
                    'fallback_provider' => 'claude_cli',
                ]),
            );
        }

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
            metadata: $metadata,
        );
    }

    /**
     * @return array<int,string>
     */
    private function modelsFromStdout(string $stdout): array
    {
        $models = [];
        $payloads = [];
        $single = json_decode(trim($stdout), true);
        if (is_array($single)) {
            $payloads[] = $single;
        }

        foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $payloads[] = $decoded;
            }
        }

        foreach ($payloads as $payload) {
            foreach ([
                data_get($payload, 'model'),
                data_get($payload, 'modelVersion'),
                data_get($payload, 'response.model'),
                data_get($payload, 'result.model'),
                data_get($payload, 'stats.model'),
                data_get($payload, 'metadata.model'),
            ] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $models[] = trim($candidate);
                }
            }

            $statsModels = data_get($payload, 'stats.models');
            if (is_array($statsModels)) {
                foreach ($statsModels as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $models[] = trim($candidate);
                    }
                }
            }
        }

        return array_values(array_unique($models));
    }

    private function textFromPayload(array $payload): string
    {
        foreach (['result', 'response', 'text', 'content', 'message'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value)) {
                return trim($value);
            }
            if (is_array($value)) {
                $nested = $this->textFromPayload($value);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        $candidates = data_get($payload, 'candidates');
        if (is_array($candidates)) {
            $text = '';
            foreach ($candidates as $candidate) {
                if (is_array($candidate)) {
                    $text .= $this->textFromPayload($candidate);
                }
            }

            return trim($text);
        }

        $parts = data_get($payload, 'parts') ?: data_get($payload, 'content.parts');
        if (is_array($parts)) {
            $text = '';
            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }

            return trim($text);
        }

        return '';
    }

    private function payloadHasAssistantText(array $payload): bool
    {
        $role = $payload['role'] ?? data_get($payload, 'message.role') ?? data_get($payload, 'content.role');
        if (is_string($role) && ! in_array(strtolower($role), ['assistant', 'model'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,string>
     */
    private function sanitizeConfiguredArgs(array $args): array
    {
        $valueArgs = [
            '--model', '-m',
            '--prompt', '-p',
            '--output-format',
            '--approval-mode',
            '--admin-policy',
            '--policy',
            '--include-directories',
            '--permission-mode',
            '--allowed-tools',
            '--allowedTools',
        ];
        $standaloneArgs = [
            '--sandbox',
            '--no-sandbox',
            '--skip-trust',
            '--yolo',
            '-y',
            '--all-files',
            '--skip-permissions',
            '--dangerously-skip-permissions',
            '--allow-dangerously-skip-permissions',
        ];
        $sanitized = [];
        $skipNext = false;

        foreach (array_values($args) as $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            if (! is_string($arg) && ! is_numeric($arg)) {
                continue;
            }

            $arg = trim((string) $arg);
            if ($arg === '') {
                continue;
            }

            if (in_array($arg, $valueArgs, true)) {
                $skipNext = true;
                continue;
            }

            if (in_array($arg, $standaloneArgs, true)) {
                continue;
            }

            $blockedWithValue = collect($valueArgs)
                ->contains(fn (string $name): bool => str_starts_with($arg, $name.'='));
            if ($blockedWithValue) {
                continue;
            }

            $sanitized[] = $arg;
        }

        return $sanitized;
    }

    /**
     * @return array<int,array{kind:string,label:string,path:string}>
     */
    private function attachmentAccessPaths(AiJob $job): array
    {
        $paths = [];
        $images = data_get($job->payload, 'attachments.images', []);
        $files = data_get($job->payload, 'attachments.files', []);
        $images = is_array($images) ? $images : [];
        $files = is_array($files) ? $files : [];

        foreach (array_slice($images, 0, 8) as $index => $image) {
            $path = $this->realFilePath(is_array($image) ? ($image['path'] ?? null) : null);
            if ($path !== null) {
                $paths[] = [
                    'kind' => 'image',
                    'label' => 'imagem '.($index + 1),
                    'path' => $path,
                ];
            }
        }

        foreach (array_slice($files, 0, 4) as $index => $file) {
            if (! is_array($file)) {
                continue;
            }

            $label = is_string($file['original_name'] ?? null) && trim($file['original_name']) !== ''
                ? trim($file['original_name'])
                : 'arquivo '.($index + 1);
            $path = $this->realFilePath($file['path'] ?? null);
            if ($path !== null) {
                $paths[] = [
                    'kind' => 'file',
                    'label' => $label,
                    'path' => $path,
                ];
            }

            $pageLimit = max(0, (int) config('atlas.attachments.pdf.vision_page_limit', 12));
            if ($pageLimit === 0) {
                continue;
            }

            $rendered = is_array($file['pdf_rendered_pages'] ?? null)
                ? $file['pdf_rendered_pages']
                : (is_array($file['office_rendered_pages'] ?? null) ? $file['office_rendered_pages'] : []);

            foreach (array_slice($rendered, 0, $pageLimit) as $page) {
                if (! is_array($page)) {
                    continue;
                }

                $pagePath = $this->realFilePath($page['path'] ?? null);
                if ($pagePath === null) {
                    continue;
                }

                $pageNumber = is_scalar($page['page'] ?? null) ? (string) $page['page'] : '?';
                $paths[] = [
                    'kind' => 'rendered_page',
                    'label' => $label.' p. '.$pageNumber,
                    'path' => $pagePath,
                ];
            }
        }

        return collect($paths)
            ->unique('path')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array{kind:string,label:string,path:string}>  $attachments
     * @return array<int,string>
     */
    private function attachmentIncludeDirectories(array $attachments): array
    {
        $directories = [];

        foreach ($attachments as $attachment) {
            $directories[] = dirname($attachment['path']);
        }

        return collect($directories)
            ->filter(fn (mixed $directory): bool => is_string($directory) && is_dir($directory))
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array{kind:string,label:string,path:string}>  $attachments
     */
    private function promptWithAttachmentAccess(string $prompt, array $attachments): string
    {
        $lines = [
            '',
            '',
            '# Acesso local aos anexos para Gemini',
            '',
            'Use a ferramenta read_file nos caminhos abaixo quando a resposta depender de imagem, PDF, audio ou documento anexado. Esses arquivos sao contexto do operador; nao edite, nao mova e nao apague.',
        ];

        foreach ($attachments as $index => $attachment) {
            $number = $index + 1;
            $label = $this->safeAttachmentLabel($attachment['label']);
            $lines[] = "- anexo {$number} ({$attachment['kind']}, {$label}): {$attachment['path']}";
        }

        return rtrim($prompt).implode("\n", $lines);
    }

    private function realFilePath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $real = realpath($path);

        if (! is_string($real) || ! File::isFile($real)) {
            return null;
        }

        $root = realpath(storage_path('app/ai/attachments'));
        if (! is_string($root) || ! AtlasSecurity::pathIsInside($real, $root)) {
            return null;
        }

        return $real;
    }

    private function safeAttachmentLabel(string $label): string
    {
        $label = trim($label);
        $label = str_replace(["\r", "\n"], ' ', $label);

        return $label !== '' ? $label : 'sem nome';
    }

    /**
     * @param  array<int,array{kind:string,label:string,path:string}>  $attachments
     * @return array{0:array<int,array{kind:string,label:string,path:string}>,1:?string}
     */
    private function prepareAttachmentWorkspace(AiJob $job, array $attachments): array
    {
        $base = storage_path('app/ai/gemini-attachments');
        File::ensureDirectoryExists($base);

        $jobId = $job->id ?: 'unsaved';
        $runDirectory = $base.'/job-'.$jobId.'-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($runDirectory);

        $prepared = [];
        foreach ($attachments as $index => $attachment) {
            $extension = pathinfo($attachment['path'], PATHINFO_EXTENSION);
            $target = $runDirectory.'/attachment-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            if ($extension !== '') {
                $target .= '.'.preg_replace('/[^A-Za-z0-9]+/', '', $extension);
            }

            if (! @copy($attachment['path'], $target)) {
                continue;
            }

            $prepared[] = [
                ...$attachment,
                'path' => realpath($target) ?: $target,
            ];
        }

        if ($prepared === []) {
            File::deleteDirectory($runDirectory);

            return [[], null];
        }

        return [$prepared, $runDirectory];
    }
}
