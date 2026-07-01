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

    /** At/below this claimable-per-active-worker ratio, downstream workers are about to starve. */
    private const DEFAULT_WORKER_FEED_FLOOR_RATIO = 2.0;

    /** Minimum value for an unlocked follow-up to count as "high-value" under starvation pressure. */
    private const DEFAULT_HIGH_VALUE_FLOOR = 7;

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
     * Orders dependency-unlocked candidate packets for replenishment, factoring in the worker feed
     * floor: when downstream workers are about to starve (claimable_per_active_worker at/below the
     * floor ratio), unlocked high-value follow-ups are preferred over speculative low-value branches
     * — even if the speculative branch would otherwise rank higher on value/dependency_count alone.
     * With a comfortable buffer, the existing value/dependency ordering is unchanged.
     *
     * @param  list<array<string,mixed>>  $candidates  {task_packet_id, value:int, dependency_count:int,
     *                                                   is_unlocked_follow_up?:bool, speculative?:bool}
     * @param  array<string,mixed>  $workerFloorFacts  {claimable_per_active_worker?:float,
     *                                                   worker_feed_floor_ratio?:float, high_value_floor?:int}
     * @return array<string,mixed>
     */
    public function prioritizeUnlockedFollowUps(array $candidates, array $workerFloorFacts = []): array
    {
        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $workerFloorFacts)
            ? (float) $workerFloorFacts['claimable_per_active_worker']
            : null;
        $floorRatio = (float) ($workerFloorFacts['worker_feed_floor_ratio'] ?? self::DEFAULT_WORKER_FEED_FLOOR_RATIO);
        $highValueFloor = (int) ($workerFloorFacts['high_value_floor'] ?? self::DEFAULT_HIGH_VALUE_FLOOR);

        $workerFeedThin = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= $floorRatio;

        $ordered = array_values(array_filter($candidates, 'is_array'));

        usort($ordered, function (array $a, array $b) use ($workerFeedThin, $highValueFloor): int {
            if ($workerFeedThin) {
                $aPriority = $this->isHighValueUnlockedFollowUp($a, $highValueFloor) ? 1 : 0;
                $bPriority = $this->isHighValueUnlockedFollowUp($b, $highValueFloor) ? 1 : 0;
                if ($aPriority !== $bPriority) {
                    return $bPriority <=> $aPriority;
                }
            }

            return ((int) ($b['value'] ?? 0)) <=> ((int) ($a['value'] ?? 0))
                ?: ((int) ($a['dependency_count'] ?? 0)) <=> ((int) ($b['dependency_count'] ?? 0))
                ?: strcmp((string) ($a['task_packet_id'] ?? ''), (string) ($b['task_packet_id'] ?? ''));
        });

        return [
            'schema' => self::SCHEMA,
            'ordered' => array_values($ordered),
            'worker_feed_thin' => $workerFeedThin,
            'claimable_per_active_worker' => $claimablePerActiveWorker,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function isHighValueUnlockedFollowUp(array $candidate, int $highValueFloor): bool
    {
        return (bool) ($candidate['is_unlocked_follow_up'] ?? false)
            && (int) ($candidate['value'] ?? 0) >= $highValueFloor;
    }

    /**
     * Decide whether to stop replenishing (queue depth is sufficient) or top up the task fabric —
     * a low claimable_per_active_worker always wins over a "sufficient_depth" recommendation, since
     * worker starvation is more urgent than raw queue depth.
     *
     * @param  array{recommendation?:string, claimable_per_active_worker?:float|null, urgent_repair_signal?:bool}  $facts
     * @return array{schema:string, action:'no_op'|'task_fabric_top_up'|'replenish', reason:string}
     */
    public function decideReplenishmentAction(array $facts): array
    {
        $recommendation = (string) ($facts['recommendation'] ?? '');
        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $facts) && $facts['claimable_per_active_worker'] !== null
            ? (float) $facts['claimable_per_active_worker']
            : null;
        $urgentRepairSignal = (bool) ($facts['urgent_repair_signal'] ?? false);
        $lowWorkerFloor = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::DEFAULT_WORKER_FEED_FLOOR_RATIO;

        if ($lowWorkerFloor) {
            return ['schema' => self::SCHEMA, 'action' => 'task_fabric_top_up', 'reason' => 'worker_floor_low'];
        }

        if ($recommendation === 'sufficient_depth' && ! $urgentRepairSignal) {
            return ['schema' => self::SCHEMA, 'action' => 'no_op', 'reason' => 'sufficient_depth'];
        }

        return ['schema' => self::SCHEMA, 'action' => 'replenish', 'reason' => $urgentRepairSignal ? 'urgent_repair_signal' : 'queue_not_sufficient'];
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
                'cycle_blockers' => [],
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

        // Lanes never dequeued by Kahn's BFS (in-degree never reached 0) sit on a
        // dependency cycle; they must never be silently assigned a fallback wave
        // level and emitted as if their drafts were complete.
        $cycleLanes = [];
        foreach ($inDegree as $lane => $deg) {
            if ($deg !== 0) {
                $cycleLanes[] = $lane;
            }
        }
        sort($cycleLanes);

        // Build packet drafts in wave order, excluding cyclic lanes.
        $byLane = [];
        foreach ($gaps as $gap) {
            $lane = (string) ($gap['lane'] ?? '');
            if (isset($laneToId[$lane]) && ! in_array($lane, $cycleLanes, true)) {
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
            'schema'         => self::SCHEMA,
            'no_op'          => false,
            'packet_drafts'  => $drafts,
            'cycle_blockers' => $cycleLanes,
        ];
    }

    /**
     * Filters candidate enqueue inputs against {@see AtlasExternalBrainTaskGraphRuntimeBridge}
     * guidance: while unresolved critical-path leverage or stale dependencies still dominate
     * (bridge_guidance.block_off_path_low_novelty=true), only inputs whose task_packet_id is on
     * the bridge's prioritized list are allowed through; every off-path input is withheld with a
     * named blocker instead of silently passing.
     *
     * @param  list<array<string,mixed>>  $enqueueInputs  each expected to carry a task_packet_id
     *                                                      (top-level or nested under task_packet)
     * @param  array<string,mixed>  $bridgeGuidance  output of AtlasExternalBrainTaskGraphRuntimeBridge::bridge()
     * @return array{schema:string, allowed: list<array<string,mixed>>, withheld: list<array<string,mixed>>}
     */
    public function applyRuntimeBridgeGuidance(array $enqueueInputs, array $bridgeGuidance): array
    {
        $blockOffPath = (bool) ($bridgeGuidance['block_off_path_low_novelty'] ?? false);
        $prioritizedIds = array_map('strval', (array) ($bridgeGuidance['prioritized_task_packet_ids'] ?? []));

        $allowed = [];
        $withheld = [];

        foreach ($enqueueInputs as $input) {
            if (! is_array($input)) {
                continue;
            }
            $taskPacketId = (string) ($input['task_packet_id'] ?? ($input['task_packet']['task_packet_id'] ?? ''));

            if (! $blockOffPath || in_array($taskPacketId, $prioritizedIds, true)) {
                $allowed[] = $input;

                continue;
            }

            $withheld[] = [
                'task_packet_id' => $taskPacketId,
                'reason' => 'blocked_by_unresolved_critical_path_leverage',
                'blockers' => ['off_path_low_novelty_blocked_while_critical_path_unresolved'],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'allowed' => $allowed,
            'withheld' => $withheld,
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
