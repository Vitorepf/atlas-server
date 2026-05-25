<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendLiveSourcePatchRuntimeService;
use Illuminate\Console\Command;
use RuntimeException;

class AtlasFrontendLiveSourcePatchCommand extends Command
{
    protected $signature = 'atlas:frontend:live
        {action : prepare, accept, discard, recover or status}
        {--workspace= : Workspace root}
        {--file= : Relative file for prepare}
        {--target= : Exact target source for prepare}
        {--variant=* : Variant as id=content, repeatable}
        {--session= : Existing session id}
        {--accept-variant= : Variant id to accept}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Run Atlas Frontend live source patch prepare/accept/discard/recover operations.';

    public function handle(AtlasFrontendLiveSourcePatchRuntimeService $runtime): int
    {
        try {
            $payload = match ((string) $this->argument('action')) {
                'prepare' => $runtime->prepare(
                    (string) ($this->option('workspace') ?: base_path()),
                    (string) $this->option('file'),
                    (string) $this->option('target'),
                    $this->variants(),
                    is_string($this->option('session')) ? trim((string) $this->option('session')) ?: null : null,
                ),
                'accept' => $runtime->accept(
                    (string) ($this->option('workspace') ?: base_path()),
                    (string) $this->option('session'),
                    (string) $this->option('accept-variant'),
                ),
                'discard' => $runtime->discard((string) ($this->option('workspace') ?: base_path()), (string) $this->option('session')),
                'recover' => $runtime->recover((string) ($this->option('workspace') ?: base_path()), (string) $this->option('session')),
                'status' => $runtime->status((string) ($this->option('workspace') ?: base_path()), (string) $this->option('session')),
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
    private function variants(): array
    {
        $variants = [];
        foreach ((array) $this->option('variant') as $index => $raw) {
            $raw = (string) $raw;
            [$id, $content] = str_contains($raw, '=') ? explode('=', $raw, 2) : ['variant-'.($index + 1), $raw];
            $variants[] = ['id' => trim($id), 'content' => $content];
        }

        return $variants;
    }
}
