<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasCoRecallCompositionDetector;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * MAXJ-07 — read-only co-recall composition report (optional --enqueue behind flag).
 */
class AtlasAiCoRecallCompositionCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:co-recall-composition
        {--floor= : Override co_case_count floor (hard-min 3)}
        {--enqueue : Materialise held ASI-02 candidates when enqueue flag ON}
        {--json : Machine-readable JSON}';

    protected $description = 'MAXJ-07 · detect co-recalled memory pairs in measured passing outcomes (shadow-first).';

    public function handle(AtlasCoRecallCompositionDetector $detector): int
    {
        $floorOpt = $this->option('floor');
        $floor = is_numeric($floorOpt) ? (int) $floorOpt : null;
        $report = $detector->detect($floor);

        $enqueued = [];
        if ((bool) $this->option('enqueue')) {
            foreach ((array) ($report['proposals'] ?? []) as $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $enqueued[] = $detector->enqueueHeld($proposal);
            }
            $report['enqueued'] = $enqueued;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->info('status='.$report['status'].' proposals='.(int) data_get($report, 'totals.proposals', 0));

        return self::SUCCESS;
    }
}
