<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;
use Illuminate\Console\Command;

class AtlasAiSelfImproveCommand extends Command
{
    protected $signature = 'atlas:ai:self-improve
        {--hours=24 : Evidence Ledger lookback window}
        {--limit=5 : Maximum findings/proposals}
        {--emit : Emit safe review proposals to the Inbox}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the Atlas AI self-improvement review over Evidence Ledger events.';

    public function handle(AtlasSelfImprovementOrchestrator $orchestrator): int
    {
        $payload = $orchestrator->executeNightlyReview([
            'emit' => (bool) $this->option('emit'),
            'hours' => (int) $this->option('hours'),
            'limit' => (int) $this->option('limit'),
        ]);
        $runtime = (array) ($payload['runtime'] ?? []);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Self-Improvement</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Run', (string) ($runtime['run_id'] ?? '-'));
        $this->components->twoColumnDetail('Dry run', ($runtime['dry_run'] ?? true) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Findings', (string) count((array) ($runtime['findings'] ?? [])));
        $this->components->twoColumnDetail('Emitted', (string) ($runtime['emitted_count'] ?? 0));

        foreach ((array) ($runtime['findings'] ?? []) as $finding) {
            $this->line('- '.($finding['title'] ?? 'Finding'));
        }

        return self::SUCCESS;
    }
}
