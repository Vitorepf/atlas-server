<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAbstainAndAsk;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAbstainAndAsk::evaluate()} at the operator surface: reads a decision payload
 * from a JSON file and emits whether the loop should ABSTAIN-and-ask (with the clarifying operator question and
 * the honesty reasons) or PROCEED, as deterministic facts. Read-only — it only scores the payload; it never
 * acts on the decision.
 */
final class AtlasLoopAbstainAndAskCommand extends Command
{
    protected $signature = 'atlas:loop:abstain-and-ask {--input=} {--json}';

    protected $description = 'Read-only abstain-and-ask verdict for a decision payload (proceed vs abstain + question).';

    public function handle(AtlasLoopAbstainAndAsk $organ): int
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

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.abstain_and_ask.v1'] + $organ->evaluate($decoded),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
