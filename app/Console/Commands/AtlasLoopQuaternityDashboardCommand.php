<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only TTY dashboard for the Quaternity layer. Three panes (each capped at 10 rows, ordered by canonical
 * timestamp ascending):
 *
 *   operator_intent_stream      Last operator intents persisted upstream.
 *   recent_facts                The FACT stream from the comprehend path.
 *   cortex_grounding_state      Persisted grounded-projection roles surface.
 *
 * INVARIANT: no writes, no external HTTP, no scalar grounding-quality / intent-priority score (R1/R2). Reads
 * a single persisted snapshot via a container-bound closure ({@see self::SNAPSHOT_SOURCE_KEY}) — tests bind a
 * frozen fixture; production binds a snapshot reader.
 *
 * Same composition contract as the other dashboards: master switch OFF ⇒ emits ONLY the banner + exit 0.
 */
final class AtlasLoopQuaternityDashboardCommand extends Command
{
    public const SNAPSHOT_SOURCE_KEY = 'atlas.loop.dashboard.quaternity_source';

    public const MASTER_OFF_BANNER = '[atlas:loop:dashboard] master_switch_off';

    public const NO_ROWS_MARKER = '(no rows)';

    public const FRAME_WIDTH = 80;

    public const PANE_ROW_CAP = 10;

    protected $signature = 'atlas:loop:dashboard:quaternity';

    protected $description = 'Read-only Quaternity dashboard: operator intents, recent FACTs, cortex grounding (FACTs only).';

    public function handle(): int
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->line(self::MASTER_OFF_BANNER);

            return self::SUCCESS;
        }

        $snapshot = $this->loadSnapshot();
        $this->line($this->renderFrame($snapshot));

        return self::SUCCESS;
    }

    /**
     * @return array{operator_intent_stream:list<array<string,mixed>>, recent_facts:list<array<string,mixed>>, cortex_grounding_state:list<array<string,mixed>>}
     */
    private function loadSnapshot(): array
    {
        $defaults = ['operator_intent_stream' => [], 'recent_facts' => [], 'cortex_grounding_state' => []];
        if (! app()->bound(self::SNAPSHOT_SOURCE_KEY)) {
            return $defaults;
        }
        try {
            $source = app(self::SNAPSHOT_SOURCE_KEY);
            $payload = is_callable($source) ? $source() : null;
        } catch (Throwable) {
            $payload = null;
        }

        return is_array($payload) ? array_replace($defaults, $payload) : $defaults;
    }

    /**
     * @param  array{operator_intent_stream:list<array<string,mixed>>, recent_facts:list<array<string,mixed>>, cortex_grounding_state:list<array<string,mixed>>}  $snapshot
     */
    private function renderFrame(array $snapshot): string
    {
        $bar = str_repeat('-', self::FRAME_WIDTH);
        $lines = [$bar, $this->pad('ATLAS LOOP — QUATERNITY DASHBOARD'), $bar];
        foreach (['operator_intent_stream', 'recent_facts', 'cortex_grounding_state'] as $paneKey) {
            $lines[] = $this->pad('['.$paneKey.']');
            $rows = $this->normalize((array) ($snapshot[$paneKey] ?? []));
            if ($rows === []) {
                $lines[] = $this->pad('  '.self::NO_ROWS_MARKER);
            } else {
                foreach ($rows as $row) {
                    $lines[] = $this->pad('  '.((string) $row['at']).' | '.((string) $row['kind']).' | '.((string) $row['detail']));
                }
            }
            $lines[] = $bar;
        }

        return implode("\n", $lines);
    }

    /**
     * Sort rows ascending by canonical `at` timestamp and cap at the pane row cap.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array{at:string,kind:string,detail:string}>
     */
    private function normalize(array $rows): array
    {
        $shaped = array_map(static fn (array $r): array => [
            'at' => (string) ($r['at'] ?? ''),
            'kind' => (string) ($r['kind'] ?? ''),
            'detail' => (string) ($r['detail'] ?? ''),
        ], $rows);
        usort($shaped, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_slice($shaped, 0, self::PANE_ROW_CAP);
    }

    private function pad(string $text): string
    {
        if (mb_strlen($text) >= self::FRAME_WIDTH) {
            return mb_substr($text, 0, self::FRAME_WIDTH);
        }

        return str_pad($text, self::FRAME_WIDTH);
    }
}
