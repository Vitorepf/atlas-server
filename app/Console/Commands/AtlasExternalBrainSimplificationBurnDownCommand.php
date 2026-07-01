<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganSprawlReductionPlanner;
use Illuminate\Console\Command;

/**
 * Read-only operator entry point for {@see AtlasExternalBrainOrganSprawlReductionPlanner}.
 * Turns the organ inventory into a first-safe retirement/merge/simplify batch and prints it,
 * so simplification is an honest, evidence-bounded throughput lever — never a vague cleanup
 * instinct, and never applied against a claimable-task-yield regression.
 *
 * Never mutates files, calls providers, or runs git — read-only planning only.
 *
 * Input: a single JSON file (--input=PATH) with key: { organs:list<array<string,mixed>> }.
 * Missing/absent organs defaults to empty and simply produces an empty batch.
 */
final class AtlasExternalBrainSimplificationBurnDownCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:external-brain:simplification-burndown
        {--input= : Path to a JSON file with an organs list}';

    /** @var string */
    protected $description = 'Read-only first-safe retire/merge/simplify batch plan with preserved task-feed yield.';

    public function handle(AtlasExternalBrainOrganSprawlReductionPlanner $planner): int
    {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $organs = is_array($decoded['organs'] ?? null) ? $decoded['organs'] : [];

        $plan = $planner->plan(['organs' => $organs]);

        $payload = [
            'status' => 'ok',
            'first_safe_batch' => $plan['first_safe_batch'],
            'expected_line_delta' => $plan['expected_line_delta'],
            'capability_preserved_count' => $plan['capability_preserved_count'],
            'required_tests' => $plan['required_tests'],
            'ranked_actions' => $plan['ranked_actions'],
            'capability_groups' => $plan['capability_groups'],
            'task_feed_impact' => $plan['task_feed_impact'],
            'yield_preserved' => $plan['task_feed_impact']['yield_preserved'],
        ];

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
