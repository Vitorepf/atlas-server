<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCriterionStabilitySelector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCriterionStabilitySelector::select()} at the operator surface: reads
 * candidates from a JSON file and emits the stability-selected criterion — most criteria passed, ties broken
 * by the smaller change (less risk), then lexicographic id — as deterministic facts. Read-only and pure; it
 * only ranks; it selects nothing downstream.
 */
final class AtlasLoopCriterionStabilitySelectCommand extends Command
{
    protected $signature = 'atlas:loop:criterion-stability-select {--input=} {--json}';

    protected $description = 'Read-only: select the most-stable criterion from candidates (JSON list).';

    public function handle(AtlasLoopCriterionStabilitySelector $selector): int
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

        $candidates = json_decode((string) file_get_contents($input), true);
        if (! is_array($candidates)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $selected = $selector->select(array_values(array_filter($candidates, 'is_array')));

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.criterion_stability_select.v1',
            'has_selection' => $selected !== null,
            'selected' => $selected,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
