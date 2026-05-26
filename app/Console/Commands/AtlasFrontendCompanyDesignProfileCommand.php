<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyDesignProfileService;
use Illuminate\Console\Command;

class AtlasFrontendCompanyDesignProfileCommand extends Command
{
    protected $signature = 'atlas:frontend:company-profile
        {action=inspect : inspect or template}
        {--profile= : Company design profile JSON path for inspect action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect Atlas Frontend company design profiles or write governed templates.';

    public function handle(AtlasFrontendCompanyDesignProfileService $profiles): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $profiles->inspect((string) ($this->option('profile') ?: '')),
            'template' => $profiles->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-company-profile'))),
            default => [
                'schema_version' => AtlasFrontendCompanyDesignProfileService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Company Profile: '.$payload['status']);
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
