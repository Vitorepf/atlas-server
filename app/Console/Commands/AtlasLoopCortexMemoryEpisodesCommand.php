<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasCortexMemoryEpisodicLedger::iterate()} at the operator surface: streams the recent
 * cross-cycle Cortex memory episodes in deterministic (captured_at, cycle_id) order and emits them as facts.
 *
 * Read-only: it iterates the append-only ledger and NEVER appends, mutates, or scores — episodes are surfaced
 * as-is (FACT-only). A missing ledger file yields zero episodes.
 */
final class AtlasLoopCortexMemoryEpisodesCommand extends Command
{
    protected $signature = 'atlas:loop:cortex-memory-episodes {--limit=} {--json}';

    protected $description = 'Read-only stream of recent Cortex memory episodes (deterministic order, FACT-only).';

    public function handle(): int
    {
        $limit = $this->option('limit') !== null && trim((string) $this->option('limit')) !== ''
            ? max(1, (int) $this->option('limit'))
            : null;

        $episodes = iterator_to_array($this->ledger()->iterate($limit), false);

        $facts = [
            'schema' => 'atlas.loop.cortex_memory_episodes.v1',
            'limit' => $limit,
            'episode_count' => count($episodes),
            'episodes' => $episodes,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('episode_count: '.$facts['episode_count']);
            foreach ($episodes as $e) {
                $this->line(($e['captured_at'] ?? '?').'  '.($e['cycle_id'] ?? '?').'  '.($e['scope_root'] ?? ''));
            }
        }

        return self::SUCCESS;
    }

    private function ledger(): AtlasCortexMemoryEpisodicLedger
    {
        if (app()->bound(AtlasCortexMemoryEpisodicLedger::class)) {
            return app(AtlasCortexMemoryEpisodicLedger::class);
        }

        // Constructor needs a concrete path (not autowireable): config with a workspace-storage fallback.
        $path = (string) config(
            'atlas.loop.cortex.memory.episodic_ledger_path',
            storage_path('atlas/loop/cortex/memory/episodic-ledger.ndjson'),
        );

        return new AtlasCortexMemoryEpisodicLedger($path);
    }
}
