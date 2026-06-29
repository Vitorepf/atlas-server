<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopLeverageScorer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopLeverageScorer::score()} at the operator surface: reads a candidate signal
 * packet from a JSON file and emits its leverage score breakdown (leverage + the impact/breadth/compounding/
 * cost/risk components + rationale + verifiable flag) as deterministic facts. Read-only and pure — missing
 * signals fail-open to conservative defaults; it never enqueues.
 */
final class AtlasLoopLeverageScoreCommand extends Command
{
    protected $signature = 'atlas:loop:leverage-score {--input=} {--json}';

    protected $description = 'Read-only leverage score breakdown for a candidate signal packet (JSON file).';

    public function handle(AtlasLoopLeverageScorer $scorer): int
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

        $candidate = json_decode((string) file_get_contents($input), true);
        if (! is_array($candidate)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.leverage_score.v1'] + $scorer->score($candidate),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
