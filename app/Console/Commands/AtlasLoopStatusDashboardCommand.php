<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only one-screen TTY dashboard of the Loop substrate health. Reads from a single persisted snapshot
 * (the Part-1 read model), composing four sections — queue_depth / in_flight / recent_completion_rate /
 * last_10_facts — in deterministic order. FACTs ONLY: no scalar score, no ranking (R1/R2 anti-Goodhart).
 *
 * INVARIANT: never writes state, never joins live DB during render. Byte-identical no-op when the master
 * switch is OFF (only the master-off banner is emitted, then exit 0).
 *
 * Snapshot source: the container binding {@see self::SNAPSHOT_SOURCE_KEY} is a Closure returning a stable
 * payload. Production binds a reader over the persisted snapshot file; tests bind a frozen fixture.
 */
final class AtlasLoopStatusDashboardCommand extends Command
{
    public const SNAPSHOT_SOURCE_KEY = 'atlas.loop.dashboard.snapshot_source';

    public const MASTER_OFF_BANNER = '[atlas:loop:dashboard] master_switch_off';

    public const FRAME_WIDTH = 80;

    protected $signature = 'atlas:loop:dashboard:status';

    protected $description = 'One-screen read-only operator dashboard for the Loop substrate (FACTs only).';

    public function handle(): int
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->line(self::MASTER_OFF_BANNER);

            return self::SUCCESS;
        }

        $snapshot = $this->loadSnapshot();
        $this->line($this->render($snapshot));

        return self::SUCCESS;
    }

    /**
     * Public, pure renderer reused by handle() and by the composing overview command. Same underlying
     * format as renderFrame() — handle() and the overview share this single code path.
     *
     * @param  array<string,mixed>  $snapshot
     */
    public function render(array $snapshot): string
    {
        return $this->renderFrame($snapshot);
    }

    /**
     * @return array{queue_depth:int, in_flight:int, recent_completion_rate:string, last_10_facts:list<array<string,mixed>>}
     */
    private function loadSnapshot(): array
    {
        $defaults = [
            'queue_depth' => 0,
            'in_flight' => 0,
            'recent_completion_rate' => '0/0',
            'last_10_facts' => [],
        ];
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
     * @param  array{queue_depth:int, in_flight:int, recent_completion_rate:string, last_10_facts:list<array<string,mixed>>}  $snapshot
     */
    private function renderFrame(array $snapshot): string
    {
        $bar = str_repeat('-', self::FRAME_WIDTH);
        $lines = [];
        $lines[] = $bar;
        $lines[] = $this->pad('ATLAS LOOP — SUBSTRATE STATUS');
        $lines[] = $bar;
        $lines[] = $this->pad('queue_depth: '.(int) $snapshot['queue_depth']);
        $lines[] = $this->pad('in_flight: '.(int) $snapshot['in_flight']);
        $lines[] = $this->pad('recent_completion_rate: '.(string) $snapshot['recent_completion_rate']);
        $lines[] = $bar;
        $lines[] = $this->pad('last 10 FACTs:');

        // Stable order: each fact rendered as `at | kind | detail` with overflow truncation.
        $facts = array_slice(array_values((array) $snapshot['last_10_facts']), 0, 10);
        foreach ($facts as $fact) {
            $at = (string) ($fact['at'] ?? '');
            $kind = (string) ($fact['kind'] ?? '');
            $detail = (string) ($fact['detail'] ?? '');
            $lines[] = $this->pad('  '.$at.' | '.$kind.' | '.$detail);
        }
        if ($facts === []) {
            $lines[] = $this->pad('  (none)');
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
