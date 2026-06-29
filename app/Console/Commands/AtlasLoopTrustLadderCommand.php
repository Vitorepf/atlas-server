<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTrustLadder;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopTrustLadder::assess()} at the operator surface: reads a work-class's
 * {successes, failures} from a JSON file and emits the trust-ladder rung verdict (park_only / trusted_review /
 * autonomous_merge + can_auto_merge + the Wilson lower bound) as deterministic facts. Read-only and pure —
 * it only scores the stats; it never advances a ladder or merges.
 */
final class AtlasLoopTrustLadderCommand extends Command
{
    protected $signature = 'atlas:loop:trust-ladder {--input=} {--json}';

    protected $description = 'Read-only trust-ladder rung verdict for a work-class (successes/failures JSON).';

    public function handle(AtlasLoopTrustLadder $ladder): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'input_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($input)) {
            $this->line((string) json_encode(['status' => 'input_not_found', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $stats = json_decode((string) file_get_contents($input), true);
        if (! is_array($stats)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.trust_ladder.v1'] + $ladder->assess($stats),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
