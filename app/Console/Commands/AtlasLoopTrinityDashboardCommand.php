<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only TTY dashboard for the Trinity coupling state across the three substrate organs (loop / cortex /
 * maestro). One row per organ, columns are RAW FACT INTEGERS:
 *
 *   emit_rate_60s | consume_rate_60s | emit_minus_consume | last_emit_age_s | last_consume_age_s
 *
 * NEVER renders a coupling/drift "score" or "rating" — anti-Goodhart per the substrate sentinels. Reads a
 * single persisted snapshot via a container-bound CLOSURE source ({@see self::SNAPSHOT_SOURCE_KEY}) so the
 * command does no raw SQL and no live joins.
 *
 * Composition contract: same banner as the other dashboards. Master switch OFF or missing snapshot ⇒ exit 0
 * with an explicit 'no data' state and no further reads.
 */
final class AtlasLoopTrinityDashboardCommand extends Command
{
    public const SNAPSHOT_SOURCE_KEY = 'atlas.loop.dashboard.trinity_source';

    public const MASTER_OFF_BANNER = '[atlas:loop:dashboard] master_switch_off';

    public const NO_DATA_MARKER = '(no data)';

    public const ORGANS = ['loop', 'cortex', 'maestro'];

    public const COLUMNS = ['emit_rate_60s', 'consume_rate_60s', 'emit_minus_consume', 'last_emit_age_s', 'last_consume_age_s'];

    public const FRAME_WIDTH = 120;

    protected $signature = 'atlas:loop:dashboard:trinity';

    protected $description = 'Read-only Trinity coupling dashboard (raw FACT integers per organ).';

    public function handle(): int
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->line(self::MASTER_OFF_BANNER);

            return self::SUCCESS;
        }

        $snapshot = $this->loadSnapshot();
        $this->line($this->render($snapshot ?? []));

        return self::SUCCESS;
    }

    /**
     * Public pure renderer reused by handle() and the overview composer. An empty snapshot returns the
     * NO_DATA_MARKER (the same byte sequence handle() would have emitted for a null snapshot).
     *
     * @param  array<string,mixed>  $snapshot
     */
    public function render(array $snapshot): string
    {
        if ($snapshot === []) {
            return self::NO_DATA_MARKER;
        }

        return $this->renderFrame($snapshot);
    }

    /**
     * @return array<string, array<string,int>>|null  organ => {col=>int}, or null when snapshot is absent
     */
    private function loadSnapshot(): ?array
    {
        if (! app()->bound(self::SNAPSHOT_SOURCE_KEY)) {
            return null;
        }
        try {
            $source = app(self::SNAPSHOT_SOURCE_KEY);
            $payload = is_callable($source) ? $source() : null;
        } catch (Throwable) {
            $payload = null;
        }
        if ($payload === null) {
            return null;
        }
        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, array<string,int>>  $snapshot
     */
    private function renderFrame(array $snapshot): string
    {
        $bar = str_repeat('-', self::FRAME_WIDTH);
        $lines = [$bar, $this->pad('ATLAS LOOP — TRINITY COUPLING'), $bar];
        $lines[] = $this->pad('organ | emit_rate_60s | consume_rate_60s | emit_minus_consume | last_emit_age_s | last_consume_age_s');
        $lines[] = $bar;
        foreach (self::ORGANS as $organ) {
            $row = (array) ($snapshot[$organ] ?? []);
            $cells = [$organ];
            foreach (self::COLUMNS as $col) {
                $cells[] = (string) (int) ($row[$col] ?? 0);
            }
            $lines[] = $this->pad('  '.implode(' | ', $cells));
        }
        $lines[] = $bar;

        return implode("\n", $lines);
    }

    private function pad(string $text): string
    {
        if (mb_strlen($text) >= self::FRAME_WIDTH) {
            return mb_substr($text, 0, self::FRAME_WIDTH);
        }

        return str_pad($text, self::FRAME_WIDTH);
    }
}
