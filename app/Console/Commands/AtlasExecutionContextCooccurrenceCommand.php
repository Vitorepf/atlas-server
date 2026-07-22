<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\ExecutionContextCooccurrenceService;
use Illuminate\Console\Command;

final class AtlasExecutionContextCooccurrenceCommand extends Command
{
    protected $signature = 'atlas:context:execution-cooccurrence
        {--runs= : Measured run artifact path}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless measured runs exist}';

    protected $description = 'MAXL-08 report-only delivered refs x measured used refs co-occurrence.';

    public function handle(ExecutionContextCooccurrenceService $service): int
    {
        $runs = trim((string) ($this->option('runs') ?: ''));
        $report = $service->report($runs !== '' ? $runs : null);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Execution co-occurrence</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Measured share', (string) data_get($report, 'denominator.measured_share', 'n/a'));
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'ok') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
