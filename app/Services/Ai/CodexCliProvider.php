<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\Ai\Concerns\HasAttachmentPath;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Streaming\CodexJsonlEventParser;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;

class CodexCliProvider implements AiProvider
{
    use HasAttachmentPath;
    use RunsCliProcesses;

    public function __construct(
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {}

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
        $provider = $this->runtimeSettings->providerConfig('codex_cli');
        $binary = (string) ($provider['binary'] ?? 'codex');
        $args = $this->sanitizeConfiguredArgs((array) ($provider['args'] ?? ['exec']));
        $tmp = storage_path('app/ai/codex-last-'.bin2hex(random_bytes(6)).'.txt');
        File::ensureDirectoryExists(dirname($tmp));

        $args = $this->withArgValue(
            $args,
            '--sandbox',
            (string) (data_get($job->payload, 'tool_permissions.codex_sandbox') ?: ($provider['sandbox'] ?? 'read-only')),
        );
        $computeEffort = $this->computeEffortContractForJob($job, 'codex_cli');
        $effortValue = data_get($computeEffort, 'provider_mapping.value');
        if (is_string($effortValue) && $effortValue !== '') {
            $args[] = '-c';
            $args[] = 'model_reasoning_effort="'.$effortValue.'"';
        }
        $args = $this->withAtlasRuntimeArgs($args, $job);
        $args = $this->withImageAttachments($args, $job);
        $fileAttachments = $this->fileAttachmentAccessPaths($job);
        if ($fileAttachments !== []) {
            $args = $this->withRepeatedArgValues($args, '--add-dir', $this->attachmentDirectories(
                collect($fileAttachments)->pluck('path')->all(),
            ));
            $prompt = $this->promptWithFileAttachmentAccess($prompt, $fileAttachments);
        }

        if ($model = $this->invocationModel($job, $provider)) {
            $args[] = '--model';
            $args[] = $model;
        }

        // Visibilidade de execução (03/07): `--json` faz o codex emitir cada
        // passo (comando, edição, busca, raciocínio) como JSONL; o parser
        // abaixo traduz em eventos TIPADOS persistidos no stream do job —
        // a UI mostra "o que ele está fazendo" como Claude Code/Codex.
        // A resposta final continua vindo do --output-last-message.
        $args[] = '--json';
        $args[] = '--output-last-message';
        $args[] = $tmp;
        $args[] = '-';

        $parser = new CodexJsonlEventParser;
        $onEvent = $this->wrapOnEventWithJsonlParser($onEvent, $parser);

        $result = $this->runProcessStreaming(
            command: array_values(array_merge([$binary], $args)),
            input: $prompt,
            timeoutSeconds: $job->timeout_seconds,
            cwd: $this->workdirForJob($job),
            onEvent: $onEvent,
            job: $job,
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
                    metadata: array_merge($result->metadata, [
                        'compute_effort' => $computeEffort,
                    ]),
                );
            }
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
            metadata: array_merge($result->metadata, [
                'compute_effort' => $computeEffort,
            ]),
        );
    }

    /**
     * Intercepta os buffers stdout do processo e re-emite como eventos
     * TIPADOS (tool/thinking/response/lifecycle) via CodexJsonlEventParser.
     * Eventos não-stdout (stderr, lifecycle do runner) passam direto — e
     * forçam o flush do resto de linha para preservar a ordem.
     */
    private function wrapOnEventWithJsonlParser(?callable $onEvent, CodexJsonlEventParser $parser): ?callable
    {
        if ($onEvent === null) {
            return null;
        }

        return static function (array $event) use ($onEvent, $parser): void {
            if (($event['type'] ?? null) !== 'stdout') {
                if (($flushed = $parser->flushRemainder()) !== null) {
                    $onEvent($flushed + ['occurred_at' => now()->toJSON()]);
                }
                $onEvent($event);

                return;
            }
            foreach ($parser->feed((string) ($event['content'] ?? '')) as $typed) {
                $onEvent($typed + ['occurred_at' => now()->toJSON()]);
            }
        };
    }

    public function health(): AiProviderHealthCheck
    {
        $binary = (string) ($this->runtimeSettings->providerConfig('codex_cli')['binary'] ?? 'codex');

        return $this->checkCliRuntimeContract(
            check: $this->checkBinary($this->key(), $binary),
            binary: $binary,
            helpArgs: ['exec', '--help'],
            requiredTokens: [
                '--model',
                '--sandbox',
                '--dangerously-bypass-approvals-and-sandbox',
                '--add-dir',
                '--image',
                '--output-last-message',
            ],
            contractName: 'codex_cli_provider.v1',
        );
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
            if (! in_array('--dangerously-bypass-approvals-and-sandbox', $args, true)) {
                $args[] = '--dangerously-bypass-approvals-and-sandbox';
            }
        }

        return $args;
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,mixed>
     */
    private function sanitizeConfiguredArgs(array $args): array
    {
        $valueArgs = [
            '--ask-for-approval',
            '--approval-mode',
            '--sandbox',
            '-s',
            '--model',
            '-m',
            '--output-last-message',
            '-o',
            '--image',
            '-i',
        ];
        $standaloneArgs = [
            '--full-auto',
            '--dangerously-bypass-approvals-and-sandbox',
        ];

        return $this->sanitizeCliArgs($args, $valueArgs, $standaloneArgs);
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,mixed>
     */
    private function withImageAttachments(array $args, AiJob $job): array
    {
        $images = data_get($job->payload, 'attachments.images', []);
        $files = data_get($job->payload, 'attachments.files', []);
        $images = is_array($images) ? $images : [];
        $files = is_array($files) ? $files : [];

        $paths = collect($images)
            ->map(fn (mixed $image): ?string => $this->attachmentPath(is_array($image) ? ($image['path'] ?? null) : null))
            ->filter(fn (?string $path): bool => is_string($path) && $path !== '')
            ->values();

        $pageLimit = max(0, (int) config('atlas.attachments.pdf.vision_page_limit', 12));
        if ($pageLimit > 0) {
            $pageImages = collect($files)
                ->flatMap(function (mixed $file): array {
                    if (! is_array($file)) {
                        return [];
                    }

                    $rendered = is_array($file['pdf_rendered_pages'] ?? null)
                        ? $file['pdf_rendered_pages']
                        : (is_array($file['office_rendered_pages'] ?? null) ? $file['office_rendered_pages'] : []);

                    return $rendered;
                })
                ->map(fn (mixed $page): ?string => $this->attachmentPath(is_array($page) ? ($page['path'] ?? null) : null))
                ->filter(fn (?string $path): bool => is_string($path) && $path !== '')
                ->take($pageLimit)
                ->values();

            $paths = $paths->merge($pageImages);
        }

        $paths = $paths->unique()->values()->all();
        if ($paths === []) {
            return $args;
        }

        $args = $this->withRepeatedArgValues($args, '--add-dir', $this->attachmentDirectories($paths));

        return $this->withRepeatedArgValues($args, '--image', $paths);
    }

    /**
     * @return array<int,array{label:string,path:string,mime:string,bytes:string}>
     */
    private function fileAttachmentAccessPaths(AiJob $job): array
    {
        $files = data_get($job->payload, 'attachments.files', []);
        $files = is_array($files) ? $files : [];
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
            $paths[] = [
                'label' => $label,
                'path' => $path,
                'mime' => $mime,
                'bytes' => $bytes,
            ];
        }

        return collect($paths)
            ->unique('path')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array{label:string,path:string,mime:string,bytes:string}>  $attachments
     */
    private function promptWithFileAttachmentAccess(string $prompt, array $attachments): string
    {
        $lines = [
            '',
            '',
            '# Acesso local aos arquivos anexados para Codex',
            '',
            'Use Read nos caminhos abaixo quando a resposta depender de detalhes, ordem, listas, trechos exatos ou conteudo completo de mensagem longa. Esses arquivos sao contexto do operador; nao edite, nao mova e nao apague.',
        ];

        foreach ($attachments as $index => $attachment) {
            $number = $index + 1;
            $label = $this->safeAttachmentLabel($attachment['label']);
            $mime = $this->safeAttachmentLabel($attachment['mime']);
            $bytes = $this->safeAttachmentLabel($attachment['bytes']);
            $lines[] = "- arquivo {$number} ({$label}, {$mime}, {$bytes} bytes): {$attachment['path']}";
        }

        return rtrim($prompt).implode("\n", $lines);
    }

    private function safeAttachmentLabel(string $value): string
    {
        $value = trim(preg_replace('/[^\pL\pN._,@:()+= -]+/u', ' ', $value) ?? '');

        return mb_substr($value === '' ? 'arquivo' : $value, 0, 120);
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<int,string>
     */
    private function attachmentDirectories(array $paths): array
    {
        return collect($paths)
            ->map(fn (string $path): string => dirname($path))
            ->filter(fn (string $directory): bool => is_dir($directory))
            ->unique()
            ->values()
            ->all();
    }
}
