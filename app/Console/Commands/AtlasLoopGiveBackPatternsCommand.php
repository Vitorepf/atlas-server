<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackPatternMiner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopGiveBackPatternMiner::mine()} at the operator surface: mines the serving-store
 * give-back records and emits the clustered doomed-packet patterns (top reasons, give-back rate by class, top
 * reason by class, worker concentration) as deterministic facts — so the loop stops re-seeding doomed shapes.
 * Read-only.
 */
final class AtlasLoopGiveBackPatternsCommand extends Command
{
    protected $signature = 'atlas:loop:give-back-patterns {--limit=200} {--json}';

    protected $description = 'Read-only give-back pattern miner: clustered doomed-packet facts from the serving store.';

    public function handle(): int
    {
        $patterns = (new AtlasLoopGiveBackPatternMiner)->mine(max(1, (int) $this->option('limit')));

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.give_back_patterns.v1',
            'patterns' => $patterns,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
