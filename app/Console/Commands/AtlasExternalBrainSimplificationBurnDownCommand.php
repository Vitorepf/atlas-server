<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganSprawlReductionPlanner;
use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationExecutionSafetyRunner;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

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
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:simplification-burndown
        {--input= : Path to a JSON file with an organs list}';

    /** @var string */
    protected $description = 'Read-only first-safe retire/merge/simplify batch plan with preserved task-feed yield.';

    public function handle(
        AtlasExternalBrainOrganSprawlReductionPlanner $planner,
        AtlasSelfConstructionSimplificationExecutionSafetyRunner $safetyRunner,
    ): int
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

        // Gate every organ in the first_safe_batch through the execution safety runner.
        $executionSafetyByOrgan = [];
        $safeBatch = (array) ($plan['first_safe_batch'] ?? []);
        foreach ($safeBatch as $organId) {
            $organId = (string) $organId;
            $organData = [];
            foreach ($organs as $o) {
                if ((string) ($o['organ_id'] ?? '') === $organId) {
                    $organData = $o;
                    break;
                }
            }
            $executionSafetyByOrgan[$organId] = $safetyRunner->execute(array_merge($organData, [
                'organ_id' => $organId,
                'action' => (string) ($organData['action'] ?? 'retire'),
            ]));
        }

        $payload = [
            'status' => 'ok',
            'first_safe_batch' => $safeBatch,
            'expected_line_delta' => $plan['expected_line_delta'],
            'capability_preserved_count' => $plan['capability_preserved_count'],
            'required_tests' => $plan['required_tests'],
            'ranked_actions' => $plan['ranked_actions'],
            'capability_groups' => $plan['capability_groups'],
            'task_feed_impact' => $plan['task_feed_impact'],
            'yield_preserved' => $plan['task_feed_impact']['yield_preserved'],
            'required_prework_by_organ' => $this->buildRequiredPreworkByOrgan($plan['ranked_actions'], $safeBatch),
            'execution_safety' => $executionSafetyByOrgan,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    /**
     * Builds a per-organ map of exactly which prework blocks consolidation,
     * for every ranked action not present in first_safe_batch. Parses the
     * planner's `reasons` strings rather than re-deriving safety logic, so
     * the command stays a pure read-only projection of the planner's decision.
     *
     * @param  list<array<string,mixed>>  $rankedActions
     * @param  list<string>  $firstSafeBatch
     * @return array<string,list<string>>
     */
    private function buildRequiredPreworkByOrgan(array $rankedActions, array $firstSafeBatch): array
    {
        $result = [];

        foreach ($rankedActions as $action) {
            $organId = (string) ($action['organ_id'] ?? '');
            if ($organId === '' || in_array($organId, $firstSafeBatch, true)) {
                continue;
            }

            $prework = [];
            foreach ((array) ($action['reasons'] ?? []) as $reason) {
                $reason = (string) $reason;

                if (str_starts_with($reason, 'missing:')) {
                    $missing = explode(',', substr($reason, strlen('missing:')));
                    foreach ($missing as $item) {
                        $item = trim($item);
                        if ($item === 'test_coverage') {
                            $prework[] = 'behavior_coverage';
                        } elseif ($item !== '') {
                            $prework[] = $item;
                        }
                    }
                }

                if ($reason === 'consolidation_rejected:yield_drop_without_compensating_action') {
                    $prework[] = 'worker_feed_or_yield_proof';
                }
            }

            if ($prework !== []) {
                $result[$organId] = array_values(array_unique($prework));
            }
        }

        return $result;
    }
}
