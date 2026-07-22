<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\Retrieval\GoldenCounterfactualReplayService;
use Illuminate\Console\Command;

final class AtlasGoldenCounterfactualReplayCommand extends Command
{
    protected $signature = 'atlas:context:golden-counterfactual
        {--runs= : Paired golden run artifact path}
        {--decision-id= : Optional decision id filter}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless paired runs are available}';

    protected $description = 'MAXL-07 read-only paired golden counterfactual replay report.';

    public function handle(GoldenCounterfactualReplayService $service): int
    {
        $runs = trim((string) ($this->option('runs') ?: ''));
        $decisionId = trim((string) ($this->option('decision-id') ?: ''));
        $report = $service->report(
            runsPath: $runs !== '' ? $runs : null,
            decisionId: $decisionId !== '' ? $decisionId : null,
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Golden counterfactual</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Reason', (string) ($report['reason'] ?? 'n/a'));
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'ok') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
