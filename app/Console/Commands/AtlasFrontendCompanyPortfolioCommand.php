<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyPortfolioService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendCompanyPortfolioCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:portfolio
        {--root= : Parent directory containing local company repositories}
        {--task= : Optional frontend task or operator intent used only to rank repository candidates}
        {--max-depth=2 : Maximum directory depth to search}
        {--max-repos=30 : Maximum repositories to inspect}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless at least one repository candidate is found}';

    protected $description = 'Scan a local company repo portfolio for Atlas Frontend readiness.';

    public function handle(AtlasFrontendCompanyPortfolioService $portfolio): int
    {
        $payload = $portfolio->scan([
            'root' => (string) ($this->option('root') ?: ''),
            'task' => (string) ($this->option('task') ?: ''),
            'max_depth' => (int) ($this->option('max-depth') ?: 2),
            'max_repos' => (int) ($this->option('max-repos') ?: 30),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Portfolio: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
