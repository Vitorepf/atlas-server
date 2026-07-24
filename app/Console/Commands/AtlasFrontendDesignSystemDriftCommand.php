<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemDriftGateService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendDesignSystemDriftCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:design-system-drift
        {action=inspect : inspect or template}
        {--report= : Design system drift report path for inspect action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect Atlas Frontend design-system drift reports or write governed templates.';

    public function handle(AtlasFrontendDesignSystemDriftGateService $gate): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $gate->inspect((string) ($this->option('report') ?: '')),
            'template' => $gate->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-design-system-drift'))),
            default => [
                'schema_version' => AtlasFrontendDesignSystemDriftGateService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Design-System Drift Gate: '.$payload['status']);
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
