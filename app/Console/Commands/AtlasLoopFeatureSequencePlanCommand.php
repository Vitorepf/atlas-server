<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFeatureSequencePlanner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopFeatureSequencePlanner::plan()} at the operator surface: reads a feature's
 * human-frozen verification atoms from a JSON file and emits the ordered build-step sequence (each step a
 * contiguous subset of at most --max-step-size atoms) as deterministic facts.
 *
 * Read-only + pure: it only GROUPS + ORDERS the atoms the human authored (intent order is build order) — it
 * never invents, weakens, or re-authors a criterion. No provider/DB/mutation.
 */
final class AtlasLoopFeatureSequencePlanCommand extends Command
{
    protected $signature = 'atlas:loop:feature-sequence-plan {--input=} {--max-step-size=2} {--json}';

    protected $description = 'Read-only feature build-step sequence from human-frozen verification atoms (order-preserving).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'feature-sequence-plan requires --input=<path to a readable atoms JSON>',
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
        $atoms = isset($decoded['atoms']) && is_array($decoded['atoms']) ? $decoded['atoms'] : $decoded;
        $maxStepSize = max(1, (int) $this->option('max-step-size'));

        $plan = app(AtlasLoopFeatureSequencePlanner::class)->plan(array_values($atoms), $maxStepSize);

        $facts = [
            'schema' => 'atlas.loop.feature_sequence_plan.v1',
            'max_step_size' => $maxStepSize,
            'step_count' => count($plan),
            'steps' => $plan,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('step_count: '.$facts['step_count']);
            foreach ($plan as $step) {
                $this->line('step '.$step['step'].': '.count($step['atoms']).' atom(s)');
            }
        }

        return self::SUCCESS;
    }
}
