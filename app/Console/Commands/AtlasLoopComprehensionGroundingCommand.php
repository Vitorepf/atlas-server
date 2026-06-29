<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopComprehensionGroundingGate::ground()} at the operator surface: reads the
 * symbols a stated objective cites from a JSON file and, against the repo root, emits whether the objective
 * is grounded (every citation resolves) or ungrounded (a citation resolves nowhere — possibly hallucinated),
 * as deterministic facts. Read-only — it only resolves citations; it blocks nothing.
 */
final class AtlasLoopComprehensionGroundingCommand extends Command
{
    protected $signature = 'atlas:loop:comprehension-grounding-gate {--objective=} {--input=} {--json}';

    protected $description = 'Read-only: is a stated objective grounded (do its cited symbols resolve in the repo)?';

    public function handle(AtlasLoopComprehensionGroundingGate $gate): int
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

        $citedSymbols = json_decode((string) file_get_contents($input), true);
        if (! is_array($citedSymbols)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $gate->ground((string) $this->option('objective'), $citedSymbols, base_path()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
