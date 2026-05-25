<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProductBlueprintService;
use Illuminate\Console\Command;

class AtlasFrontendBlueprintCommand extends Command
{
    protected $signature = 'atlas:frontend:blueprint
        {action=generate : generate or write}
        {--task= : Frontend product/design task}
        {--workspace= : Local company/product repository path}
        {--frontend-app= : Optional frontend app subdirectory inside the selected repository, e.g. apps/web}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero when blueprint is blocked}';

    protected $description = 'Generate or write the Atlas Frontend product/UX/design blueprint for premium company frontend work.';

    public function handle(AtlasFrontendProductBlueprintService $blueprint): int
    {
        $input = [
            'task' => (string) ($this->option('task') ?: ''),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
            'surface' => (string) ($this->option('surface') ?: 'programming.frontend'),
            'asset_context' => (bool) $this->option('asset-context'),
            'prototype' => (bool) $this->option('prototype'),
            'live' => (bool) $this->option('live'),
        ];
        $payload = match ((string) $this->argument('action')) {
            'generate' => $blueprint->generate($input),
            'write' => $blueprint->writeDocument($input),
            default => [
                'schema_version' => AtlasFrontendProductBlueprintService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Blueprint: '.$payload['status']);
        }

        return (bool) $this->option('strict') && in_array($payload['status'] ?? null, ['blocked', 'failed'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
