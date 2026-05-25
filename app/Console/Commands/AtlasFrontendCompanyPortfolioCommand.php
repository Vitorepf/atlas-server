<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyPortfolioService;
use Illuminate\Console\Command;

class AtlasFrontendCompanyPortfolioCommand extends Command
{
    protected $signature = 'atlas:frontend:portfolio
        {--root= : Parent directory containing local company repositories}
        {--max-depth=2 : Maximum directory depth to search}
        {--max-repos=30 : Maximum repositories to inspect}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless at least one repository candidate is found}';

    protected $description = 'Scan a local company repo portfolio for Atlas Frontend readiness.';

    public function handle(AtlasFrontendCompanyPortfolioService $portfolio): int
    {
        $payload = $portfolio->scan([
            'root' => (string) ($this->option('root') ?: ''),
            'max_depth' => (int) ($this->option('max-depth') ?: 2),
            'max_repos' => (int) ($this->option('max-repos') ?: 30),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Portfolio: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
