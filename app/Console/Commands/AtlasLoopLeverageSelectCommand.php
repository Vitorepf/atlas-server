<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverageSelector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopLeverageSelector::rank()} at the operator surface: reads grounded candidates
 * from a JSON file and emits the leverage-ranked ordering (the model's single highest-leverage pick first, the
 * rest stable) as deterministic facts.
 *
 * Read-only: rank() returns a PERMUTATION of the input — it never adds, drops, or mutates a candidate, and
 * fail-closes to the producer's order when no honest pick is available. The command performs no mutation.
 */
final class AtlasLoopLeverageSelectCommand extends Command
{
    protected $signature = 'atlas:loop:leverage-select {--input=} {--json}';

    protected $description = 'Read-only leverage-ranked selection of grounded candidates (highest-leverage pick first).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'leverage-select requires --input=<path to a readable candidates JSON>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'input file is not a JSON array/object',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $candidates = isset($decoded['candidates']) && is_array($decoded['candidates']) ? $decoded['candidates'] : $decoded;

        $ranked = app(AtlasLoopLeverageSelector::class)->rank(array_values($candidates));

        $facts = [
            'schema' => 'atlas.loop.leverage_selection.v1',
            'count' => count($ranked),
            'winner' => $ranked[0] ?? null,
            'ranked' => $ranked,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($ranked as $i => $c) {
                $this->line('['.$i.'] '.($c['summary'] ?? $c['objective'] ?? $c['candidateId'] ?? json_encode($c)));
            }
        }

        return self::SUCCESS;
    }
}
