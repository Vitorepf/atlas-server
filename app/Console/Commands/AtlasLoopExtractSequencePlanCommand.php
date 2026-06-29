<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExtractSequencePlanner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopExtractSequencePlanner::plan()} at the operator surface: reads a per-method
 * cyclomatic census from a JSON file and emits the worst-first extract-class step sequence (each method
 * strictly above the tractable threshold, capped at max-steps) as deterministic facts.
 *
 * Read-only + pure: a STATIC projection for budgeting/telemetry (actual execution re-measures after each step).
 * No provider/DB/mutation.
 */
final class AtlasLoopExtractSequencePlanCommand extends Command
{
    protected $signature = 'atlas:loop:extract-sequence-plan {--input=} {--threshold=10} {--max-steps=25} {--json}';

    protected $description = 'Read-only worst-first single-method extract step sequence from a per-method complexity map.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'extract-sequence-plan requires --input=<path to a readable per-method JSON>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'input file is not a JSON object',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $perMethod = isset($decoded['per_method']) && is_array($decoded['per_method']) ? $decoded['per_method'] : $decoded;
        $perMethod = array_map(static fn ($v): int => (int) $v, array_filter($perMethod, 'is_numeric'));

        $threshold = (int) $this->option('threshold');
        $maxSteps = (int) $this->option('max-steps');

        $planner = app(AtlasLoopExtractSequencePlanner::class);
        $plan = $planner->plan($perMethod, $threshold, $maxSteps);

        $facts = [
            'schema' => 'atlas.loop.extract_sequence_plan.v1',
            'tractable_threshold' => max(1, $threshold),
            'step_count' => count($plan),
            'plan' => $plan,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('step_count: '.$facts['step_count']);
            foreach ($plan as $step) {
                $this->line('['.$step['step_index'].'] '.$step['target_method'].'  cyclomatic='.$step['cyclomatic']);
            }
        }

        return self::SUCCESS;
    }
}
