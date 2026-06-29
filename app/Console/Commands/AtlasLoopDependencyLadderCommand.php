<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricDependencyLadder;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasTaskFabricDependencyLadder::ladder()} at the operator surface: orders a set of
 * packet specs (by produces/consumes symbols) into dependency WAVES — each wave is safe to run in parallel —
 * and emits the ladder, the per-packet depends_on edges and any blockers (missing producers, cycles,
 * within-wave file conflicts) as deterministic facts. Pure and read-only.
 *
 * --packets accepts inline JSON or a path to a JSON file (a list of packet specs).
 */
final class AtlasLoopDependencyLadderCommand extends Command
{
    protected $signature = 'atlas:loop:dependency-ladder {--packets=} {--json}';

    protected $description = 'Read-only: order packet specs into dependency waves (produces/consumes topology).';

    public function handle(AtlasTaskFabricDependencyLadder $ladder): int
    {
        $packetsOption = $this->option('packets');
        if ($packetsOption === null || trim((string) $packetsOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'packets_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $packetsOption) ? (string) file_get_contents((string) $packetsOption) : (string) $packetsOption;
        $packets = json_decode($raw, true);
        if (! is_array($packets)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'packets' => (string) $packetsOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $result = $ladder->ladder(array_values(array_filter($packets, 'is_array')));

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.dependency_ladder.v1'] + $result,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
