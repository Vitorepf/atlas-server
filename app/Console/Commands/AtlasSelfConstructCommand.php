<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionLoopService;
use Illuminate\Console\Command;

/**
 * Self-construction loop — "Atlas builds Atlas". Detects improvement signals in the
 * Atlas codebase (or takes operator-supplied --request gaps) and runs each through
 * the Mission e2e pipe, producing BRANCHES for the operator to review + merge.
 * NEVER merges, NEVER touches main. Bounded by --max.
 *
 * NOTE: each delivery makes a real provider call (operator spend) — run with intent.
 */
class AtlasSelfConstructCommand extends Command
{
    protected $signature = 'atlas:self-construct
        {--max=1 : maximum improvement signals to act on this run}
        {--request=* : explicit operator improvement request(s), merged ahead of code markers}
        {--repo= : repo dir to scan + materialize into (default: app base path)}
        {--provider=codex_cli : provider for the generation step}
        {--watch : run continuously (one bounded cycle per --interval) until the kill-switch}
        {--interval=3600 : seconds between cycles in --watch mode (min 60)}
        {--json : machine-readable output}';

    protected $description = 'Self-construction: Atlas detects + delivers its own improvements as branches (you merge).';

    public function handle(AtlasSelfConstructionLoopService $loop): int
    {
        if (! (bool) $this->option('watch')) {
            $this->runCycle($loop);

            return self::SUCCESS;
        }

        // --watch: bounded continuous mode. ONE cycle per interval, until the operator
        // trips the kill-switch (a stop file) — never a firehose, always killable.
        $interval = max(60, (int) $this->option('interval'));
        $killFile = storage_path('app/atlas-self-construct.stop');
        @unlink($killFile);
        $this->info('watch mode — one cycle every '.$interval.'s. KILL ANYTIME:  touch '.$killFile);
        $cycle = 0;
        while (true) {
            if (is_file($killFile)) {
                $this->info('kill-switch tripped — stopping after '.$cycle.' cycle(s).');
                @unlink($killFile);
                break;
            }
            $this->line('— cycle '.(++$cycle).' ('.now()->toTimeString().') —');
            $this->runCycle($loop);
            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function runCycle(AtlasSelfConstructionLoopService $loop): void
    {
        $result = $loop->run([
            'max' => (int) $this->option('max'),
            'requests' => (array) $this->option('request'),
            'repo_dir' => $this->stringOption('repo') ?: base_path(),
            'delivery' => array_filter(['provider' => $this->stringOption('provider')], static fn ($v): bool => $v !== null && $v !== ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info('Self-construction — Atlas improves Atlas (brain-anchored; relevance-gated; branches only, you merge).');
        $precision = $result['relevance_precision'] ?? null;
        $this->line(sprintf(
            '  detected: %d  |  delivered: %d  |  accepted (on-target): %d  |  rejected (off-target): %d  |  on-target rate: %s',
            (int) ($result['detected'] ?? 0),
            (int) ($result['delivered_count'] ?? 0),
            (int) ($result['accepted_count'] ?? 0),
            (int) ($result['rejected_count'] ?? 0),
            $precision === null ? 'n/a' : (string) $precision,
        ));
        foreach ((array) ($result['outcomes'] ?? []) as $o) {
            $sig = $o['signal']['signal'] ?? '?';
            if ($o['accepted'] ?? false) {
                $this->line('  ✓ '.($o['branch'] ?? '?').'  ←  '.$sig);
            } elseif ($o['delivered'] ?? false) {
                $this->line('  ⊘ REJECTED off-target ('.($o['relevance_reason'] ?? '?').') — branch discarded  ←  '.$sig);
            } else {
                $this->line('  ✗ ('.($o['stage'] ?? '?').'/'.($o['reason'] ?? '?').')  ←  '.$sig);
            }
        }
        $this->line('');
        $this->line('  '.($result['operator_action'] ?? 'review the branches'));
    }

    private function stringOption(string $key): ?string
    {
        $v = $this->option($key);

        return is_string($v) ? $v : null;
    }
}
