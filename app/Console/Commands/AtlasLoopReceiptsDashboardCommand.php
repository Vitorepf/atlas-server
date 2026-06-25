<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only TTY dashboard for the last N receipts across the three Loop ledgers: attempt, impact, projection
 * outcome. Three columns per row; the linkage column shows `attempt_id -> impact_id -> outcome_id` where the
 * canonical receipt id chains; missing legs render as `-`. Ordered by canonical timestamp DESC.
 *
 * Reads through container-bound CLOSURE adapters (one per ledger surface) so the command:
 *   - never calls raw SQL,
 *   - never writes,
 *   - is byte-identical no-op under ATLAS_LOOP_MASTER_ENABLED=false (the adapters are never invoked).
 *
 * Tests bind fakes; production binds adapters over the real ledger read APIs.
 */
final class AtlasLoopReceiptsDashboardCommand extends Command
{
    public const ATTEMPT_READER_KEY = 'atlas.loop.dashboard.attempt_reader';

    public const IMPACT_READER_KEY = 'atlas.loop.dashboard.impact_reader';

    public const OUTCOME_READER_KEY = 'atlas.loop.dashboard.outcome_reader';

    public const MASTER_OFF_BANNER = '[atlas:loop:dashboard] master_switch_off';

    public const DEFAULT_TAIL = 10;

    public const MAX_TAIL = 50;

    public const FRAME_WIDTH = 96;

    public const MISSING_LEG = '-';

    protected $signature = 'atlas:loop:dashboard:receipts {--tail=10}';

    protected $description = 'Read-only last-N receipts dashboard across attempt / impact / projection-outcome ledgers.';

    public function handle(): int
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->line(self::MASTER_OFF_BANNER);

            return self::SUCCESS;
        }

        $tail = $this->clampedTail();
        $snapshot = [
            'attempts' => $this->readRows(self::ATTEMPT_READER_KEY, $tail),
            'impacts' => $this->readRows(self::IMPACT_READER_KEY, $tail),
            'outcomes' => $this->readRows(self::OUTCOME_READER_KEY, $tail),
        ];

        $this->line($this->render($snapshot));

        return self::SUCCESS;
    }

    /**
     * Public pure renderer reused by handle() and the overview composer. Accepts a single composite
     * snapshot {attempts, impacts, outcomes} and delegates to renderFrame.
     *
     * @param  array{attempts?:list<array<string,mixed>>, impacts?:list<array<string,mixed>>, outcomes?:list<array<string,mixed>>}  $snapshot
     */
    public function render(array $snapshot): string
    {
        return $this->renderFrame(
            (array) ($snapshot['attempts'] ?? []),
            (array) ($snapshot['impacts'] ?? []),
            (array) ($snapshot['outcomes'] ?? []),
        );
    }

    private function clampedTail(): int
    {
        $tail = (int) $this->option('tail');
        if ($tail < 1) {
            return 1;
        }
        if ($tail > self::MAX_TAIL) {
            return self::MAX_TAIL;
        }

        return $tail;
    }

    /**
     * @return list<array{id:string, at:string, parent_id:string, summary:string}>
     */
    private function readRows(string $key, int $tail): array
    {
        if (! app()->bound($key)) {
            return [];
        }
        try {
            $reader = app($key);
            $rows = is_callable($reader) ? $reader($tail) : [];
        } catch (Throwable) {
            $rows = [];
        }

        $shaped = array_map(static fn (array $r): array => [
            'id' => (string) ($r['id'] ?? ''),
            'at' => (string) ($r['at'] ?? ''),
            'parent_id' => (string) ($r['parent_id'] ?? ''),
            'summary' => (string) ($r['summary'] ?? ''),
        ], array_values((array) $rows));

        // Canonical timestamp DESC.
        usort($shaped, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($shaped, 0, $tail);
    }

    /**
     * @param  list<array{id:string, at:string, parent_id:string, summary:string}>  $attempts
     * @param  list<array{id:string, at:string, parent_id:string, summary:string}>  $impacts
     * @param  list<array{id:string, at:string, parent_id:string, summary:string}>  $outcomes
     */
    private function renderFrame(array $attempts, array $impacts, array $outcomes): string
    {
        $impactByAttempt = $this->indexByParent($impacts);
        $outcomeByImpact = $this->indexByParent($outcomes);

        $bar = str_repeat('-', self::FRAME_WIDTH);
        $lines = [$bar, $this->pad('ATLAS LOOP — RECEIPTS DASHBOARD'), $bar];
        $lines[] = $this->pad('attempt_id | impact_id | outcome_id | summary');
        $lines[] = $bar;

        if ($attempts === [] && $impacts === [] && $outcomes === []) {
            $lines[] = $this->pad('  (no rows)');
            $lines[] = $bar;

            return implode("\n", $lines);
        }

        foreach ($attempts as $attempt) {
            $impactId = $impactByAttempt[$attempt['id']]['id'] ?? self::MISSING_LEG;
            $outcomeId = ($impactId !== self::MISSING_LEG && isset($outcomeByImpact[$impactId])) ? $outcomeByImpact[$impactId]['id'] : self::MISSING_LEG;
            $lines[] = $this->pad('  '.$attempt['id'].' -> '.$impactId.' -> '.$outcomeId.' | '.$attempt['summary']);
        }
        $lines[] = $bar;

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{id:string, at:string, parent_id:string, summary:string}>  $rows
     * @return array<string, array{id:string, at:string, parent_id:string, summary:string}>
     */
    private function indexByParent(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== '') {
                $index[$row['parent_id']] = $row;
            }
        }

        return $index;
    }

    private function pad(string $text): string
    {
        if (mb_strlen($text) >= self::FRAME_WIDTH) {
            return mb_substr($text, 0, self::FRAME_WIDTH);
        }

        return str_pad($text, self::FRAME_WIDTH);
    }
}
