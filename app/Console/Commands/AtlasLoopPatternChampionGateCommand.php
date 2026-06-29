<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternChampionGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopPatternChampionGate::evaluate()} at the operator surface: reads a
 * champion-challenge from a JSON file and emits whether the challenger unseats the champion — plus the
 * per-lane checks (independent approval / fresh eval / beats champion by margin / no guardrail regression) —
 * as deterministic facts. Read-only: the gate is constructed with NO ledger, so it records nothing.
 */
final class AtlasLoopPatternChampionGateCommand extends Command
{
    protected $signature = 'atlas:loop:pattern-champion-gate {--input=} {--json}';

    protected $description = 'Read-only champion-challenger promotion verdict (does the challenger unseat the champion?).';

    public function handle(): int
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

        $challenge = json_decode((string) file_get_contents($input), true);
        if (! is_array($challenge)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        // Null ledger ⇒ pure, no I/O: a preview must never record a decision.
        $verdict = (new AtlasLoopPatternChampionGate)->evaluate($challenge);

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.pattern_champion_gate.v1'] + $verdict,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
