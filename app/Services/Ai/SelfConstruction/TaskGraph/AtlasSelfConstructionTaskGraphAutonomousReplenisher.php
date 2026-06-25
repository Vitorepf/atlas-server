<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Bounded autonomous replenisher cycle for the Self-Construction task graph.
 *
 * Composes:
 *   - $coverageFacts   : coverage auditor output (read-only, not mutated)
 *   - $plannerDrafts   : list of drafts emitted by the missing-organ planner (passed straight in)
 *   - {@see AtlasSelfConstructionTaskGraphDraftEnqueuePlan} : filters drafts + builds enqueue inputs
 *
 * Default mode is DRY-RUN. With $options['apply']=true, the replenisher invokes the optional
 * injected enqueue callback (`enqueue_callback`) only for gate-passing, non-duplicate inputs,
 * honouring `max_applied`. NEVER calls providers, spawns workers, or runs git.
 *
 * Output:
 *   {
 *     schema_version, status, dry_run, applied_count, withheld_count, duplicate_count,
 *     max_applied, enqueue_results, plan, replenisher_hash
 *   }
 */
final class AtlasSelfConstructionTaskGraphAutonomousReplenisher
{
    public const SCHEMA = 'atlas.self_construction.task_graph_autonomous_replenisher.v1';

    public const DEFAULT_MAX_APPLIED = 10;

    public function __construct(
        private readonly ?AtlasSelfConstructionTaskGraphDraftEnqueuePlan $planBuilder = null,
    ) {}

    /**
     * @param  array<string,mixed>  $coverageFacts
     * @param  list<array<string,mixed>>  $plannerDrafts
     * @param  array<string,mixed>  $queueFacts
     * @param  array<string,mixed>  $options  {apply?:bool, max_applied?:int, enqueue_callback?:callable}
     * @return array<string,mixed>
     */
    public function run(array $coverageFacts, array $plannerDrafts, array $queueFacts = [], array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $maxApplied = (int) ($options['max_applied'] ?? self::DEFAULT_MAX_APPLIED);
        if ($maxApplied < 0) {
            $maxApplied = 0;
        }
        $callback = $options['enqueue_callback'] ?? null;
        $callbackIsCallable = is_callable($callback);

        $planBuilder = $this->planBuilder ?? new AtlasSelfConstructionTaskGraphDraftEnqueuePlan();
        $plan = $planBuilder->plan($plannerDrafts, $queueFacts);

        $enqueueInputs = array_values((array) $plan['enqueue_inputs']);
        $withheld = array_values((array) $plan['withheld']);
        $duplicates = array_values((array) $plan['duplicates']);

        $enqueueResults = [];
        $appliedCount = 0;

        if ($apply && $callbackIsCallable) {
            foreach ($enqueueInputs as $input) {
                if ($appliedCount >= $maxApplied) {
                    $withheld[] = [
                        'task_packet_id' => (string) ($input['task_packet']['task_packet_id'] ?? ''),
                        'reason' => 'max_applied_reached',
                        'blockers' => ['max_applied_reached:'.$maxApplied],
                    ];

                    continue;
                }

                $callbackResult = null;
                $callbackError = null;
                try {
                    $callbackResult = $callback($input);
                } catch (\Throwable $e) {
                    $callbackError = $e->getMessage();
                }
                $enqueueResults[] = [
                    'task_packet_id' => (string) ($input['task_packet']['task_packet_id'] ?? ''),
                    'applied' => $callbackError === null,
                    'callback_result' => is_array($callbackResult) ? $callbackResult : null,
                    'error' => $callbackError,
                ];
                if ($callbackError === null) {
                    $appliedCount++;
                }
            }
        }

        // Stable order.
        usort($enqueueResults, static fn (array $a, array $b): int => strcmp((string) $a['task_packet_id'], (string) $b['task_packet_id']));

        $replenisherHash = $this->replenisherHash($plan, $enqueueResults, $apply, $maxApplied);

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'dry_run' => ! $apply || ! $callbackIsCallable,
            'applied_count' => $appliedCount,
            'withheld_count' => count($withheld),
            'duplicate_count' => count($duplicates),
            'max_applied' => $maxApplied,
            'enqueue_results' => $enqueueResults,
            'plan' => [
                'enqueue_input_count' => count($enqueueInputs),
                'enqueue_inputs' => $enqueueInputs,
                'withheld' => $withheld,
                'duplicates' => $duplicates,
                'plan_hash' => (string) ($plan['plan_hash'] ?? ''),
            ],
            'coverage_facts_status' => (string) ($coverageFacts['status'] ?? ''),
            'replenisher_hash' => $replenisherHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<array<string,mixed>>  $enqueueResults
     */
    private function replenisherHash(array $plan, array $enqueueResults, bool $apply, int $maxApplied): string
    {
        $canonical = json_encode([
            'plan_hash' => (string) ($plan['plan_hash'] ?? ''),
            'enqueue_results' => $enqueueResults,
            'apply' => $apply,
            'max_applied' => $maxApplied,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'replenisher_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
