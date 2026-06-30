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
     * Convert final-brain coverage gaps into ordered packet drafts.
     *
     * Each gap: { lane: string, depends_on_lanes?: list<string>, category?: string }
     * Returns no_op=true (empty packet_drafts) when $gaps is empty.
     * wave_order is computed via topological level (Kahn's BFS); lanes outside this
     * gap set are ignored in depends_on to avoid dangling references.
     *
     * @param  list<array<string,mixed>>  $gaps
     * @return array<string,mixed>
     */
    public function replenishFromGaps(array $gaps): array
    {
        if ($gaps === []) {
            return [
                'schema'         => self::SCHEMA,
                'no_op'          => true,
                'packet_drafts'  => [],
            ];
        }

        // Build lane → packet_id map.
        $laneToId = [];
        foreach ($gaps as $gap) {
            $lane = (string) ($gap['lane'] ?? '');
            if ($lane === '') {
                continue;
            }
            $laneToId[$lane] = 'final-brain-gap-'.$lane;
        }

        // Compute in-degree and adjacency for Kahn's BFS (within this gap set only).
        $inDegree  = array_fill_keys(array_keys($laneToId), 0);
        $dependsMap = [];  // lane → list<lane> of its predecessors in this set
        foreach ($gaps as $gap) {
            $lane = (string) ($gap['lane'] ?? '');
            if (! isset($laneToId[$lane])) {
                continue;
            }
            $deps = is_array($gap['depends_on_lanes'] ?? null) ? $gap['depends_on_lanes'] : [];
            $localDeps = [];
            foreach ($deps as $dep) {
                $dep = (string) $dep;
                if (isset($laneToId[$dep])) {
                    $localDeps[] = $dep;
                    $inDegree[$lane]++;
                }
            }
            $dependsMap[$lane] = $localDeps;
        }

        // BFS to assign wave levels (1-based).
        $queue = [];
        foreach ($inDegree as $lane => $deg) {
            if ($deg === 0) {
                $queue[] = $lane;
            }
        }
        $waveLevel = [];
        $successors = array_fill_keys(array_keys($laneToId), []);
        foreach ($dependsMap as $lane => $preds) {
            foreach ($preds as $pred) {
                $successors[$pred][] = $lane;
            }
        }
        $head = 0;
        while ($head < count($queue)) {
            $lane = $queue[$head++];
            $level = $waveLevel[$lane] ?? 1;
            foreach ($successors[$lane] as $successor) {
                $inDegree[$successor]--;
                $newLevel = max($waveLevel[$successor] ?? 1, $level + 1);
                $waveLevel[$successor] = $newLevel;
                if ($inDegree[$successor] === 0) {
                    $queue[] = $successor;
                }
            }
            if (! isset($waveLevel[$lane])) {
                $waveLevel[$lane] = 1;
            }
        }

        // Build packet drafts in wave order.
        $byLane = [];
        foreach ($gaps as $gap) {
            $lane = (string) ($gap['lane'] ?? '');
            if (isset($laneToId[$lane])) {
                $byLane[$lane] = $gap;
            }
        }

        $drafts = [];
        foreach ($byLane as $lane => $gap) {
            $packetId   = $laneToId[$lane];
            $waveOrder  = $waveLevel[$lane] ?? 1;
            $localDeps  = $dependsMap[$lane] ?? [];
            $dependsOn  = array_values(array_map(fn (string $d): string => $laneToId[$d], $localDeps));
            $category   = (string) ($gap['category'] ?? 'final_brain');

            $drafts[] = [
                'task_packet_id'     => $packetId,
                'lane'               => $lane,
                'wave_order'         => $waveOrder,
                'wave'               => 'final-brain-w'.$waveOrder,
                'depends_on'         => $dependsOn,
                'objective'          => 'Implement missing final-brain lane: '.$lane.' ('.$category.')',
                'allowed_files'      => [
                    'app/Services/Ai/SelfConstruction/'.ucfirst($lane).'/AtlasSelfConstruction'.ucfirst($lane).'Service.php',
                    'tests/Unit/Ai/SelfConstruction/'.ucfirst($lane).'/AtlasSelfConstruction'.ucfirst($lane).'ServiceTest.php',
                ],
                'acceptance_criteria' => [
                    'Lane '.$lane.' passes all required capability checks.',
                    'No provider/git/worker calls in service source.',
                ],
                'tags'               => ['self_construction', 'final_brain', $lane],
                'priority'           => 5,
            ];
        }

        usort($drafts, static fn (array $a, array $b): int =>
            $a['wave_order'] <=> $b['wave_order'] ?: strcmp($a['lane'], $b['lane']));

        return [
            'schema'        => self::SCHEMA,
            'no_op'         => false,
            'packet_drafts' => $drafts,
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
