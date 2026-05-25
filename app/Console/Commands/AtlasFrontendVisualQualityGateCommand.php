<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendVisualQualityGateService;
use Illuminate\Console\Command;

class AtlasFrontendVisualQualityGateCommand extends Command
{
    protected $signature = 'atlas:frontend:visual-quality
        {action=inspect : inspect or template}
        {--report= : Visual quality report path for inspect action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect or scaffold Atlas Frontend visual quality gate reports.';

    public function handle(AtlasFrontendVisualQualityGateService $gate): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $gate->inspect((string) ($this->option('report') ?: '')),
            'template' => $gate->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-visual-quality'))),
            default => [
                'schema_version' => AtlasFrontendVisualQualityGateService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Visual Quality Gate: '.$payload['status']);
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
