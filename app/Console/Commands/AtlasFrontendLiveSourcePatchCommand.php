<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendLiveSourcePatchRuntimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AtlasFrontendLiveSourcePatchCommand extends Command
{
    protected $signature = 'atlas:frontend:live
        {action : prepare, accept, discard, recover or status}
        {--workspace= : Workspace root}
        {--file= : Relative file for prepare}
        {--target= : Exact target source for prepare}
        {--variant=* : Variant as id=content or id:path relative to workspace, repeatable}
        {--session= : Existing session id}
        {--accept-variant= : Variant id to accept}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Run Atlas Frontend live source patch prepare/accept/discard/recover operations.';

    public function handle(AtlasFrontendLiveSourcePatchRuntimeService $runtime): int
    {
        $workspace = (string) ($this->option('workspace') ?: base_path());

        try {
            $payload = match ((string) $this->argument('action')) {
                'prepare' => $runtime->prepare(
                    $workspace,
                    (string) $this->option('file'),
                    (string) $this->option('target'),
                    $this->variants($workspace),
                    is_string($this->option('session')) ? trim((string) $this->option('session')) ?: null : null,
                ),
                'accept' => $runtime->accept(
                    $workspace,
                    (string) $this->option('session'),
                    (string) $this->option('accept-variant'),
                ),
                'discard' => $runtime->discard($workspace, (string) $this->option('session')),
                'recover' => $runtime->recover($workspace, (string) $this->option('session')),
                'status' => $runtime->status($workspace, (string) $this->option('session')),
                default => throw new RuntimeException('invalid_action'),
            };
        } catch (RuntimeException $exception) {
            $payload = [
                'schema_version' => AtlasFrontendLiveSourcePatchRuntimeService::RESULT_SCHEMA_VERSION,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Live Source Patch: '.($payload['status'] ?? 'unknown'));
        }

        return ($payload['status'] ?? 'failed') === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int,array{id:string,content:string}>
     */
    private function variants(string $workspace): array
    {
        $variants = [];
        foreach ((array) $this->option('variant') as $index => $raw) {
            $raw = (string) $raw;
            if (str_contains($raw, '=')) {
                [$id, $content] = explode('=', $raw, 2);
            } elseif (str_contains($raw, ':')) {
                [$id, $path] = explode(':', $raw, 2);
                $content = $this->variantFileContent($workspace, trim($path));
            } else {
                [$id, $content] = ['variant-'.($index + 1), $raw];
            }
            $variants[] = ['id' => trim($id), 'content' => $content];
        }

        return $variants;
    }

    private function variantFileContent(string $workspace, string $path): string
    {
        $workspaceReal = realpath($workspace);
        if ($workspaceReal === false || ! File::isDirectory($workspaceReal)) {
            throw new RuntimeException('workspace_not_found');
        }

        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $workspaceReal.'/'.ltrim($path, DIRECTORY_SEPARATOR);
        $real = realpath($candidate);
        if ($real === false || ! File::isFile($real)) {
            throw new RuntimeException('variant_file_not_found');
        }

        $workspacePrefix = rtrim($workspaceReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($real, $workspacePrefix)) {
            throw new RuntimeException('variant_file_outside_workspace');
        }

        return File::get($real);
    }
}
