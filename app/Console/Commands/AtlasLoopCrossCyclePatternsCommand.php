<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternMiner;
use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternMinerInsufficientEpisodesException;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCrossCyclePatternMiner::mine()} at the operator surface: mines the recent
 * cycle episodes and emits the tokens recurring across cycles (count + source episodes) as deterministic facts.
 * When fewer episodes than the window are available, it reports `insufficient_episodes` rather than throwing.
 * Read-only. Episodes come from an injectable source seam (empty by default until a cycle-episode source is wired).
 */
final class AtlasLoopCrossCyclePatternsCommand extends Command
{
    /** Container key for an injected episode source (test/integration seam): list<array>|callable():list<array>. */
    private const EPISODES_BINDING = 'atlas.loop.cross_cycle.episodes';

    protected $signature = 'atlas:loop:cross-cycle-patterns {--window=3} {--json}';

    protected $description = 'Read-only cross-cycle pattern miner: tokens recurring across recent cycle episodes.';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('window'));
        $episodes = $this->episodes();

        try {
            $result = (new AtlasLoopCrossCyclePatternMiner($episodes, $window))->mine();
        } catch (AtlasLoopCrossCyclePatternMinerInsufficientEpisodesException $e) {
            $this->line((string) json_encode([
                'schema_version' => 'atlas.loop.cross_cycle_patterns.v1',
                'status' => 'insufficient_episodes',
                'window' => $window,
                'episodes_available' => count($episodes),
                'patterns' => [],
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.cross_cycle_patterns.v1',
            'status' => 'ok',
            'window' => $result['window'],
            'patterns_count' => count($result['patterns']),
            'patterns' => $result['patterns'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function episodes(): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::EPISODES_BINDING)) {
            $bound = $app->make(self::EPISODES_BINDING);
            if (is_callable($bound)) {
                $bound = $bound();
            }
            if (is_array($bound)) {
                return array_values(array_filter($bound, 'is_array'));
            }
        }

        return [];
    }
}
