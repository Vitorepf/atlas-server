<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliCheckpointService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasCliCheckpointCommand extends Command
{
    protected $signature = 'atlas:cli:checkpoint
        {action=list : list, show or restore}
        {checkpoint? : Checkpoint id prefix or absolute path}
        {--workspace= : Workspace path. Defaults to current directory}
        {--limit=20 : Maximum checkpoints to list}
        {--yes : Approve restore}
        {--json : Print machine-readable JSON}';

    protected $description = 'List, inspect and restore Atlas runtime checkpoints.';

    public function handle(AtlasCliCheckpointService $checkpoints): int
    {
        $workspace = $this->workspace();
        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();
        $reference = (string) ($this->argument('checkpoint') ?: '');

        try {
            if ($action === 'list') {
                $payload = ['checkpoints' => $checkpoints->list($workspace, (int) $this->option('limit'))];
                $this->printPayload($payload, 'list');

                return self::SUCCESS;
            }

            if ($action === 'show') {
                $payload = ['checkpoint' => $checkpoints->show($workspace, $reference)];
                $this->printPayload($payload, 'show');

                return self::SUCCESS;
            }

            if ($action === 'restore') {
                $approved = (bool) $this->option('yes');
                if (! $approved && ! (bool) $this->option('json') && $this->input->isInteractive()) {
                    $approved = $this->confirm('Restaurar este checkpoint agora?', false);
                }

                $payload = $checkpoints->restore($workspace, $reference, $approved);
                $this->printPayload($payload, 'restore');

                return (bool) data_get($payload, 'result.ok') ? self::SUCCESS : self::FAILURE;
            }

            throw new \InvalidArgumentException("Acao invalida: {$action}.");
        } catch (\Throwable $exception) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload, string $action): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_merge(['ok' => true], $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($action === 'list') {
            $rows = collect((array) ($payload['checkpoints'] ?? []))
                ->map(fn (array $checkpoint): array => [
                    $checkpoint['id'],
                    $checkpoint['reason'],
                    $checkpoint['file_count'],
                    $checkpoint['created_at'],
                ])
                ->all();

            if ($rows === []) {
                $this->line('Nenhum checkpoint para este workspace.');

                return;
            }

            $this->table(['id', 'reason', 'files', 'created_at'], $rows);

            return;
        }

        if ($action === 'show') {
            $this->renderCheckpoint((array) $payload['checkpoint']);

            return;
        }

        $checkpoint = (array) $payload['checkpoint'];
        $result = (array) $payload['result'];
        $this->renderCheckpoint($checkpoint);
        $this->newLine();
        $this->line(((bool) ($result['ok'] ?? false)) ? '<info>Checkpoint restaurado.</info>' : '<error>Restore bloqueado ou falhou.</error>');
        if (! empty($result['error_message'])) {
            $this->line((string) $result['error_message']);
        }
        if (! empty($result['changed_files'])) {
            $this->line('Arquivos afetados: '.implode(', ', (array) $result['changed_files']));
        }
        if (! empty($result['diff'])) {
            $this->newLine();
            $this->line((string) $result['diff']);
        }
    }

    /**
     * @param  array<string,mixed>  $checkpoint
     */
    private function renderCheckpoint(array $checkpoint): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Checkpoint', (string) ($checkpoint['id'] ?? '-'));
        $this->components->twoColumnDetail('Reason', (string) ($checkpoint['reason'] ?? '-'));
        $this->components->twoColumnDetail('Created', (string) ($checkpoint['created_at'] ?? '-'));
        $this->components->twoColumnDetail('Path', (string) ($checkpoint['path'] ?? '-'));
        $this->line('Files:');
        foreach ((array) ($checkpoint['files'] ?? []) as $file) {
            $this->line('  - '.($file['path'] ?? '-').' '.(($file['existed'] ?? false) ? '(restore)' : '(delete on restore)'));
        }
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
