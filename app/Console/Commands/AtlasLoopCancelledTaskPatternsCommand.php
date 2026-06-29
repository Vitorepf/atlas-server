<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop\AtlasLoopCancelledTaskMiner;
use App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop\AtlasLoopCancelledTaskServingReader;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCancelledTaskMiner} at the operator surface: wires the
 * {@see AtlasLoopCancelledTaskServingReader} (cancelled / given-back records from the live serving store) into
 * the miner and emits the clustered failure patterns (cluster_id, member_count, reason, shared_gate,
 * shared_prefix) as JSON — so recurring doomed-packet patterns become visible instead of being re-seeded blind.
 * Read-only: no queue mutation, no provider/process.
 */
final class AtlasLoopCancelledTaskPatternsCommand extends Command
{
    protected $signature = 'atlas:loop:cancelled-task-patterns {--limit=200} {--json}';

    protected $description = 'Read-only miner of recurring cancelled/given-back task patterns (clustered facts).';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $reader = $this->getLaravel()->make(AtlasLoopCancelledTaskServingReader::class);
        $patterns = (new AtlasLoopCancelledTaskMiner($reader))->mine($limit);

        $this->line((string) json_encode(['patterns' => $patterns], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
