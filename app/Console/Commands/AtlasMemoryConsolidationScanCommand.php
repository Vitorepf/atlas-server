<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Memory\MemoryConsolidationScanner;
use Illuminate\Console\Command;

/**
 * MAXH-03 — read-only autonomous producer of memory-pair proposals.
 *
 *   php artisan atlas:memory:consolidation-scan --observe --json
 *
 * `--observe` remains the default (0 relation rows written). MAXH-04 adds
 * `--enforce` for high-confidence, reversible auto-application.
 */
final class AtlasMemoryConsolidationScanCommand extends Command
{
    protected $signature = 'atlas:memory:consolidation-scan
        {--observe : Observe mode (default). No relation rows are ever persisted.}
        {--enforce : Enforce high-confidence non-high-risk supersedence proposals.}
        {--json : Print the machine-readable JSON report.}';

    protected $description = 'MAXH-03/MAXH-04 memory pair scanner and reversible consolidation actuator.';

    public function handle(MemoryConsolidationScanner $scanner): int
    {
        $mode = (bool) $this->option('enforce')
            ? MemoryConsolidationScanner::MODE_ENFORCE
            : MemoryConsolidationScanner::MODE_OBSERVE;
        $report = $scanner->scan($mode);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['memory_consolidation_scan' => $report],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>MAXH-03 Consolidation Scanner</>', (string) $report['status']);
        $this->components->twoColumnDetail('Mode', $mode);
        $this->components->twoColumnDetail('Active count', (string) ($report['active_count'] ?? 0));
        $this->components->twoColumnDetail('Pairs evaluated', (string) ($report['pairs_evaluated'] ?? 0));
        $this->components->twoColumnDetail('Similarity source', (string) ($report['similarity_source'] ?? 'unavailable'));
        $this->components->twoColumnDetail('Relations written', (string) ($report['relations_written'] ?? 0));
        foreach ((array) ($report['verdict_distribution'] ?? []) as $verb => $count) {
            $this->components->twoColumnDetail((string) $verb, (string) $count);
        }
        $this->components->twoColumnDetail('Ledger', (string) ($report['ledger_path'] ?? ''));

        return self::SUCCESS;
    }
}
