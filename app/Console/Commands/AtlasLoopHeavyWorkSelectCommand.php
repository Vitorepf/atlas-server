<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHeavyWorkSelector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopHeavyWorkSelector::select()} at the operator surface: reads heavy-work
 * candidates (and optional context) from a JSON file and emits the selected candidate — the biggest leap
 * worth attempting, with its trust gate and ranking — as deterministic facts. Read-only and pure: it ranks
 * the candidates by measured evidence; it merges nothing. Under-evidenced candidates are excluded by the panel.
 *
 * Input JSON may be a bare candidate list, or an object {candidates:[...], context:{...}}.
 */
final class AtlasLoopHeavyWorkSelectCommand extends Command
{
    protected $signature = 'atlas:loop:heavy-work-select {--input=} {--json}';

    protected $description = 'Read-only: select the biggest worth-attempting heavy-work candidate from measured evidence.';

    public function handle(AtlasLoopHeavyWorkSelector $selector): int
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

        $payload = json_decode((string) file_get_contents($input), true);
        if (! is_array($payload)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $candidates = array_key_exists('candidates', $payload) ? (array) $payload['candidates'] : $payload;
        $context = is_array($payload['context'] ?? null) ? (array) $payload['context'] : [];

        $result = $selector->select(array_values(array_filter($candidates, 'is_array')), $context);

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
