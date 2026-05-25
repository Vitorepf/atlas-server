<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendSkillPackService;
use Illuminate\Console\Command;

class AtlasFrontendSkillPackCommand extends Command
{
    protected $signature = 'atlas:frontend:skill-pack
        {action=export : export or install the provider-safe Atlas Frontend skill pack}
        {--workspace= : Local company frontend repository path for install action}
        {--output= : Output directory for SKILL.md and reference files}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless the skill pack is ready}';

    protected $description = 'Export or install the provider-safe Atlas Frontend SKILL.md operating pack.';

    public function handle(AtlasFrontendSkillPackService $skillPack): int
    {
        $payload = match ((string) $this->argument('action')) {
            'export' => $skillPack->export([
                'output' => (string) ($this->option('output') ?: ''),
            ]),
            'install' => $skillPack->install([
                'workspace' => (string) ($this->option('workspace') ?: ''),
            ]),
            default => [
                'schema_version' => AtlasFrontendSkillPackService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Skill Pack: '.$payload['status']);
        }

        if (($payload['status'] ?? null) === 'failed') {
            return self::FAILURE;
        }

        $readyStatus = in_array(($payload['status'] ?? null), ['ready', 'installed'], true);

        return (bool) $this->option('strict') && ! $readyStatus
            ? self::FAILURE
            : self::SUCCESS;
    }
}
