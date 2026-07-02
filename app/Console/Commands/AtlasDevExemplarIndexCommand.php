<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Discovery\DevGreenRunExemplarRetriever;
use Illuminate\Console\Command;

/**
 * One-time (idempotent) backfill of the green-run exemplar index over the whole
 * receipts store. After this, retrievals serve the FULL proven history at
 * O(index) while per-call disk scans stay capped; new runs self-index lazily on
 * every retrieval, so the command only needs re-running after bulk imports.
 */
class AtlasDevExemplarIndexCommand extends Command
{
    protected $signature = 'atlas:dev:exemplar-index {--json : Print machine-readable JSON}';

    protected $description = 'Backfill the exemplar index (exemplar_index.jsonl) over the whole Dev receipts store.';

    public function handle(): int
    {
        $summary = (new DevGreenRunExemplarRetriever)->indexAll();

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('  exemplar index backfill');
        $this->line('  indexed_green='.$summary['indexed_green'].'  indexed_other='.$summary['indexed_other']
            .'  already_indexed='.$summary['already_indexed'].'  unreadable='.$summary['unreadable']);

        return self::SUCCESS;
    }
}
