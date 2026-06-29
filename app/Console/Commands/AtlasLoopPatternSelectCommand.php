<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSelector;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopPatternSelector::select()} at the operator surface: reads an objective
 * descriptor from a JSON file, resolves the pattern registry, and emits the selected loop pattern (or the
 * refusal + ranking) as deterministic facts. Read-only — it only matches a pattern; it executes nothing.
 * Cosmetic / negligible-impact objectives are refused (zero leverage per the canonical Loop definition).
 */
final class AtlasLoopPatternSelectCommand extends Command
{
    protected $signature = 'atlas:loop:pattern-select {--input=} {--json}';

    protected $description = 'Read-only: select the strongest loop pattern for an objective (or refuse cosmetic work).';

    public function handle(AtlasLoopPatternSelector $selector): int
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

        $objective = json_decode((string) file_get_contents($input), true);
        if (! is_array($objective)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $registry = $this->getLaravel()->make(AtlasLoopPatternRegistry::class);
        $result = $selector->select($objective, $registry);
        $pattern = $result['pattern'] ?? null;

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.pattern_select.v1',
            'rejected' => (bool) ($result['rejected'] ?? true),
            'pattern_id' => $pattern instanceof AtlasLoopPatternSpec ? $pattern->id : null,
            'score' => $result['score'] ?? 0,
            'reason' => (string) ($result['reason'] ?? ''),
            'ranking' => $result['ranking'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
