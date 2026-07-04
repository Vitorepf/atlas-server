<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure balancer. Inspects the claimable task mix and recommends the next
 * origination action:
 *   - originate_more         : queue is thin or low-diversity; add tasks.
 *   - originate_targeted     : queue has depth but is dimension-sparse; add missing dims.
 *   - stop_or_consolidate    : queue is already deep and diverse (AC2); don't dilute.
 *
 * Decision priority:
 *   1. High poison ratio  → stop_or_consolidate (poison tasks block progress).
 *   2. Deep + diverse     → stop_or_consolidate (AC2 — already good enough).
 *   3. Deep + dimension-sparse → originate_targeted (fill missing capability dimensions).
 *   4. Otherwise          → originate_more.
 *
 * Canonical dimensions consulted for gap detection (8 families):
 *   queue_health, learning, verification, simplification,
 *   implementation, hardening, monitoring, research
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasTaskFabricReadyQueueValueBalancer
{
    public const SCHEMA = 'atlas.task_fabric.ready_queue_value_balancer.v1';

    private const DEEP_QUEUE_THRESHOLD = 20;

    private const MIN_DIMENSIONS       = 3;

    private const MAX_POISON_RATIO     = 0.3;

    private const DEFAULT_MINIMUM_READY_PER_WORKER = 1;

    private const MIN_AVG_VALUE_FLOOR = 0.50;

    private const CANONICAL_DIMENSIONS = [
        'queue_health', 'learning', 'verification', 'simplification',
        'implementation', 'hardening', 'monitoring', 'research',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function balance(array $facts): array
    {
        $groups      = is_array($facts['task_groups'] ?? null) ? $facts['task_groups'] : [];
        $workerCount = max(1, (int) ($facts['worker_count'] ?? 1));
        $minimumReadyPerWorker = max(0, (int) ($facts['minimum_ready_per_worker'] ?? self::DEFAULT_MINIMUM_READY_PER_WORKER));

        $totalTasks  = 0;
        $poisonSum   = 0.0;
        $valueSum    = 0.0;
        $presentDims = [];

        foreach ($groups as $g) {
            $count      = max(0, (int) ($g['count'] ?? 0));
            $poison     = max(0.0, min(1.0, (float) ($g['poison_risk'] ?? 0.0)));
            $value      = max(0.0, min(1.0, (float) ($g['expected_value'] ?? 0.0)));
            $dimension  = strtolower(trim((string) ($g['dimension'] ?? '')));

            $totalTasks += $count;
            $poisonSum  += $count * $poison;
            $valueSum   += $count * $value;
            if ($dimension !== '') {
                $presentDims[$dimension] = true;
            }
        }

        $distinctDimensions = count($presentDims);
        $poisonRatio        = $totalTasks > 0 ? $poisonSum / $totalTasks : 0.0;
        $avgValue           = $totalTasks > 0 ? $valueSum / $totalTasks : 0.0;
        $deepQueue          = $totalTasks >= self::DEEP_QUEUE_THRESHOLD;
        $diverse            = $distinctDimensions >= self::MIN_DIMENSIONS;
        $missingDims        = array_values(array_diff(self::CANONICAL_DIMENSIONS, array_keys($presentDims)));
        $workerFloor        = $workerCount * $minimumReadyPerWorker;
        $belowWorkerFloor   = $totalTasks < $workerFloor;

        // External worker-floor signals (e.g. from the live task health / Maestro urgency lens) are
        // additional evidence the queue is thin for ACTIVE workers right now — but they must NEVER
        // bypass dimension diversity: a deep, dimension-sparse queue still needs targeted filling,
        // not blind volume, even while workers are starved.
        $queueFacts = is_array($facts['queue_facts'] ?? null) ? $facts['queue_facts'] : [];
        $externalWorkerFloorSignal = (bool) ($queueFacts['worker_floor_breach'] ?? false)
            || in_array((string) ($queueFacts['replenish_recommendation'] ?? ''), ['replenish_soon', 'replenish_urgently'], true);
        $belowWorkerFloor   = $belowWorkerFloor || $externalWorkerFloorSignal;

        [$recommendation, $reason] = $this->decide($poisonRatio, $deepQueue, $diverse, $totalTasks, $belowWorkerFloor, $avgValue);

        $targetDimensions = ($recommendation === 'originate_targeted') ? array_slice($missingDims, 0, 3) : [];

        return [
            'schema_version'    => self::SCHEMA,
            'recommendation'    => $recommendation,
            'reason'            => $reason,
            'target_dimensions' => $targetDimensions,
            'diagnostics' => [
                'total_tasks'        => $totalTasks,
                'distinct_dimensions' => $distinctDimensions,
                'poison_ratio'       => round($poisonRatio, 3),
                'avg_expected_value' => round($avgValue, 3),
                'worker_count'       => $workerCount,
                'deep_queue'         => $deepQueue,
                'diverse'            => $diverse,
                'missing_dimensions' => $missingDims,
                'worker_floor'       => $workerFloor,
                'below_worker_floor' => $belowWorkerFloor,
                'external_worker_floor_signal' => $externalWorkerFloorSignal,
            ],
        ];
    }

    /**
     * @return array{string, string}
     */
    private function decide(float $poisonRatio, bool $deepQueue, bool $diverse, int $totalTasks, bool $belowWorkerFloor, float $avgValue): array
    {
        // 1. High poison ratio blocks progress regardless of depth.
        if ($poisonRatio > self::MAX_POISON_RATIO) {
            return ['stop_or_consolidate', 'high_poison_ratio_blocks_progress'];
        }

        // 2. Deep and diverse, but below the worker floor: dimension diversity does not
        // matter if there aren't enough ready tasks to keep every worker fed — workers
        // would drain to no_claimable_task. Originate before consolidating.
        if ($deepQueue && $diverse && $belowWorkerFloor) {
            return ['originate_more', 'worker_floor'];
        }

        // 3. Deep and diverse, but avg expected value is below the minimum floor:
        // targeted high-value replenishment keeps muscles fed with better work rather
        // than preserving a large weak backlog.
        if ($deepQueue && $diverse && $avgValue < self::MIN_AVG_VALUE_FLOOR) {
            return ['originate_targeted', 'low_value_queue'];
        }

        // 4. Deep and diverse with good value: already good enough — don't add more.
        if ($deepQueue && $diverse) {
            return ['stop_or_consolidate', 'queue_deep_and_diverse'];
        }

        // 5. Deep but dimension-sparse: target missing capability areas.
        if ($deepQueue && ! $diverse) {
            return ['originate_targeted', 'queue_deep_but_dimension_sparse'];
        }

        // 6. Thin queue: add more tasks.
        return ['originate_more', 'queue_too_thin'];
    }
}
