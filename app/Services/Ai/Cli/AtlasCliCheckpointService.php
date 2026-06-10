<?php

namespace App\Services\Ai\Cli;

use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\Runtime\ToolResult;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;

class AtlasCliCheckpointService
{
    public function __construct(
        private readonly AiToolRuntime $runtime,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(string $workspace, int $limit = 20): array
    {
        $workspace = $this->workspace($workspace);
        $root = $this->root();
        if (! File::isDirectory($root)) {
            return [];
        }

        return collect(File::directories($root))
            ->map(fn (string $path): ?array => $this->readCheckpoint($path))
            ->filter()
            ->filter(fn (array $checkpoint): bool => $this->sameWorkspace($workspace, (string) ($checkpoint['workspace'] ?? '')))
            ->sortByDesc(fn (array $checkpoint): string => (string) ($checkpoint['created_at'] ?? ''))
            ->take(max(1, min($limit, 100)))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function show(string $workspace, string $reference): array
    {
        $workspace = $this->workspace($workspace);
        $path = $this->resolve($workspace, $reference);
        $checkpoint = $this->readCheckpoint($path);
        if (! $checkpoint) {
            throw new \RuntimeException("Checkpoint invalido: {$reference}.");
        }

        return $checkpoint;
    }

    /**
     * @return array<string,mixed>
     */
    public function restore(string $workspace, string $reference, bool $approved): array
    {
        $workspace = $this->workspace($workspace);
        $checkpoint = $this->show($workspace, $reference);
        $result = $this->runtime->execute(ToolInvocation::make('checkpoint.restore', $workspace, [
            'checkpoint' => $checkpoint['path'],
        ], [
            'permission_mode' => 'write',
            'metadata' => [
                'approved' => $approved,
                'approval_source' => $approved ? 'atlas_cli_checkpoint' : null,
            ],
        ]));

        return [
            'checkpoint' => $checkpoint,
            'result' => $result->toArray(),
        ];
    }

    public function restoreResult(string $workspace, string $reference, bool $approved): ToolResult
    {
        $workspace = $this->workspace($workspace);
        $checkpoint = $this->show($workspace, $reference);

        return $this->runtime->execute(ToolInvocation::make('checkpoint.restore', $workspace, [
            'checkpoint' => $checkpoint['path'],
        ], [
            'permission_mode' => 'write',
            'metadata' => [
                'approved' => $approved,
                'approval_source' => $approved ? 'atlas_cli_checkpoint' : null,
            ],
        ]));
    }

    private function resolve(string $workspace, string $reference): string
    {
        if ($reference === '') {
            throw new \InvalidArgumentException('Informe o checkpoint.');
        }

        if (str_starts_with($reference, DIRECTORY_SEPARATOR)) {
            $path = realpath($reference) ?: $reference;
            $checkpoint = $this->readCheckpoint($path);
            if ($checkpoint && $this->sameWorkspace($workspace, (string) $checkpoint['workspace'])) {
                return $path;
            }
        }

        $matches = collect($this->list($workspace, 100))
            ->filter(fn (array $checkpoint): bool => str_starts_with(basename((string) $checkpoint['path']), $reference)
                || str_starts_with((string) $checkpoint['id'], $reference))
            ->values();

        if ($matches->count() === 1) {
            return (string) $matches[0]['path'];
        }

        if ($matches->count() > 1) {
            throw new \RuntimeException("Referencia ambigua de checkpoint: {$reference}.");
        }

        throw new \RuntimeException("Checkpoint nao encontrado: {$reference}.");
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readCheckpoint(string $path): ?array
    {
        $metadataPath = rtrim($path, DIRECTORY_SEPARATOR).'/checkpoint.json';
        if (! File::exists($metadataPath)) {
            return null;
        }

        $decoded = JsonFileStore::readArray($metadataPath);
        if (! is_array($decoded)) {
            return null;
        }

        $files = collect((array) ($decoded['files'] ?? []))
            ->filter(fn (mixed $file): bool => is_array($file) && is_string($file['path'] ?? null))
            ->map(fn (array $file): array => [
                'path' => (string) $file['path'],
                'existed' => (bool) ($file['existed'] ?? false),
            ])
            ->values()
            ->all();

        return [
            'id' => basename($path),
            'path' => realpath($path) ?: $path,
            'workspace' => (string) ($decoded['workspace'] ?? ''),
            'reason' => (string) ($decoded['reason'] ?? ''),
            'created_at' => (string) ($decoded['created_at'] ?? ''),
            'files' => $files,
            'file_count' => count($files),
        ];
    }

    private function sameWorkspace(string $left, string $right): bool
    {
        return $this->workspace($left) === $this->workspace($right);
    }

    private function workspace(string $workspace): string
    {
        return realpath($workspace) ?: $workspace;
    }

    private function root(): string
    {
        return storage_path('app/ai/checkpoints');
    }
}
