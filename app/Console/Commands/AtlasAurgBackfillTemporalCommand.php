<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reality\AtlasGitHistoryTemporalProducerService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Illuminate\Console\Command;

/**
 * T4-S1 (Obra #17) — the bi-temporal PRODUCER's operator surface.
 *
 * `atlas:aurg:backfill-temporal --limit=N` walks git history and emits AURG-4D
 * ticks with valid-time (commit date). Idempotent — re-running skips commits
 * already ticked. Read the state it produces with `atlas:aurg:temporal --valid-at=<iso>`.
 */
class AtlasAurgBackfillTemporalCommand extends Command
{
    protected $signature = 'atlas:aurg:backfill-temporal
        {--limit=500 : max HEAD-reachable commits (newest) to tick}
        {--json : JSON output}';

    protected $description = 'AURG · backfill bi-temporal ticks from git history (valid-time producer, T4-S1).';

    public function handle(AtlasUnifiedRealityGraphTemporalService $temporal): int
    {
        // Write the code-truth axis to its DEDICATED log, never mixed with the
        // reality-graph tick log (whose ticks span the same dates).
        $temporal->setLogPath($temporal->codeTruthLogPath());
        $producer = new AtlasGitHistoryTemporalProducerService($temporal);

        $summary = $producer->backfill((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => true] + $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[atlas:aurg:backfill-temporal] scanned=%d emitted=%d skipped=%d valid=[%s .. %s]',
            $summary['scanned'],
            $summary['emitted'],
            $summary['skipped'],
            (string) ($summary['first_valid_at'] ?? '-'),
            (string) ($summary['last_valid_at'] ?? '-'),
        ));

        return self::SUCCESS;
    }
}
