<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLearningAbstractionLadderService;
use Illuminate\Console\Command;

/**
 * MULTJ-06 read-only surface (default): emits the abstraction ladder report.
 * `--enqueue` only materialises level-3 candidates when
 * `atlas.ai.abstraction_ladder.enqueue_enabled` is ON (default-OFF).
 */
final class AtlasAiAbstractionLadderCommand extends Command
{
    protected $signature = 'atlas:ai:abstraction-ladder
        {--k= : Minimum distinct signatures sharing a primary_cause for level-3 (freeze default 3)}
        {--enqueue : Materialise accepted level-3 principles into ai_learning_candidates when enqueue flag is ON}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when no accepted principles were produced}';

    protected $description = 'MULTJ-06 — abstraction ladder tactical→pattern→principle with case_count per level; same ASI-02 queue when enqueue flag ON.';

    public function handle(AtlasLearningAbstractionLadderService $service): int
    {
        $kOpt = $this->option('k');
        $k = is_numeric($kOpt) ? (int) $kOpt : null;
        $enqueue = (bool) $this->option('enqueue');

        $report = $service->propose($k, $enqueue);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Abstraction Ladder</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('K (effective)', (string) ($report['k'] ?? '?'));
            $this->components->twoColumnDetail('Patterns', (string) data_get($report, 'totals.patterns', 0));
            $this->components->twoColumnDetail('Principles accepted', (string) data_get($report, 'totals.principles_accepted', 0));
            $this->components->twoColumnDetail('Enqueued', (string) data_get($report, 'totals.enqueued', 0));
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'ok') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
