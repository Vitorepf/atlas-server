<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendQualityBudgetGateService;
use Illuminate\Console\Command;

class AtlasFrontendQualityBudgetCommand extends Command
{
    protected $signature = 'atlas:frontend:quality-budget
        {action=inspect : inspect or template}
        {--report= : Quality budget report JSON for inspect action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless budget gate passed or warning}';

    protected $description = 'Inspect objective Atlas Frontend quality budgets for accessibility, performance and visual stability.';

    public function handle(AtlasFrontendQualityBudgetGateService $gate): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $gate->inspect((string) ($this->option('report') ?: '')),
            'template' => $gate->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-quality-budget'))),
            default => [
                'schema_version' => AtlasFrontendQualityBudgetGateService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Quality Budget: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ! in_array($payload['status'] ?? null, ['passed', 'warning', 'ready'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
