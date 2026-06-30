<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

use Throwable;

/**
 * Bounded supervisor that drives multiple Atlas-native worker ticks WITHOUT depending on external
 * sessions (no Claude / Codex / git / shell / operator handoff).
 *
 * Inputs (options):
 *   - apply           bool — default false (dry-run plan, no cycle invocation).
 *   - max_cycles      int  — cap on total ticks executed in this run (>=1 in apply mode).
 *   - max_parallel    int  — advisory parallel-fan limit (sequential-honest execution; the field is
 *                            stamped in the output so a future async loop reads the same contract).
 *   - cycle_callback  callable(int $tickIndex): array — REQUIRED in apply mode. Returns the cycle
 *                            outcome with at least: ['outcome' => 'success'|'give_back'|'failed'|'safety_halt',
 *                            'task_id' => string|null, 'lease_id' => string|null, 'details' => mixed].
 *                            A throw is captured as `failed`.
 *   - stop_conditions array  — { stop_on_failed?:bool=false, stop_on_safety_halt?:bool=true }.
 *
 * Refusals: external_provider, operator_handoff, unrestricted_shell, git — even when the callback
 * returns them as an attempted action. These NEVER fire and surface in `blocked_actions`.
 */
final class AtlasNativeWorkerPoolSupervisor
{
    public const SCHEMA = 'atlas.native_worker.pool_supervisor.v1';

    public const REFUSED_ACTION_KINDS = [
        'external_provider',
        'operator_handoff',
        'unrestricted_shell',
        'git',
    ];

    /**
     * Deterministic capacity recommendation from a worker snapshot.
     * No shell, no provider, no human approval — facts in, recommendation out.
     *
     * @param  array{active_workers:int, stale_workers:int, queue_depth:int, max_worker_budget:int}  $snapshot
     * @return array{recommendation:'spawn'|'hold'|'drain', reason:string}
     */
    public function capacityPlan(array $snapshot): array
    {
        $active = max(0, (int) ($snapshot['active_workers'] ?? 0));
        $stale = max(0, (int) ($snapshot['stale_workers'] ?? 0));
        $queueDepth = max(0, (int) ($snapshot['queue_depth'] ?? 0));
        $maxBudget = max(1, (int) ($snapshot['max_worker_budget'] ?? 1));

        if ($active > $maxBudget) {
            return ['recommendation' => 'drain', 'reason' => 'active_exceeds_budget'];
        }
        if ($queueDepth > $active && $active < $maxBudget) {
            return ['recommendation' => 'spawn', 'reason' => 'queue_pressure_and_budget_available'];
        }

        return [
            'recommendation' => 'hold',
            'reason' => $stale > 0 ? 'hold_stale_workers_present' : 'hold_capacity_sufficient',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $maxCycles = max(0, (int) ($options['max_cycles'] ?? 0));
        $maxParallel = max(1, (int) ($options['max_parallel'] ?? 1));
        $cycleCallback = $options['cycle_callback'] ?? null;
        $stopConditions = is_array($options['stop_conditions'] ?? null) ? $options['stop_conditions'] : [];
        $stopOnFailed = (bool) ($stopConditions['stop_on_failed'] ?? false);
        $stopOnSafetyHalt = (bool) ($stopConditions['stop_on_safety_halt'] ?? true);

        $receipts = [];
        $blockedActions = [];
        $successCount = 0;
        $giveBackCount = 0;
        $failedCount = 0;
        $safetyStop = false;
        $stopReason = null;
        $cyclesExecuted = 0;

        if (! $apply || ! is_callable($cycleCallback) || $maxCycles === 0) {
            return $this->envelope(
                applied: false,
                cyclesPlanned: $maxCycles,
                cyclesExecuted: 0,
                maxCycles: $maxCycles,
                maxParallel: $maxParallel,
                successCount: 0,
                giveBackCount: 0,
                failedCount: 0,
                blockedActions: [],
                receipts: [],
                safetyStop: false,
                stopReason: $maxCycles === 0 ? 'max_cycles_zero' : (! $apply ? 'dry_run' : 'no_cycle_callback'),
            );
        }

        for ($i = 0; $i < $maxCycles; $i++) {
            $tickResult = $this->safeInvoke($cycleCallback, $i);
            $cyclesExecuted++;

            $outcome = (string) ($tickResult['outcome'] ?? 'failed');
            $actions = array_values((array) ($tickResult['attempted_actions'] ?? []));
            foreach ($actions as $action) {
                $kind = is_array($action) ? (string) ($action['kind'] ?? '') : '';
                if (in_array($kind, self::REFUSED_ACTION_KINDS, true)) {
                    $blockedActions[] = [
                        'tick_index' => $i,
                        'kind' => $kind,
                        'reason' => 'refused_action_kind:'.$kind,
                    ];
                }
            }

            $receipts[] = [
                'tick_index' => $i,
                'outcome' => $outcome,
                'task_id' => isset($tickResult['task_id']) ? (string) $tickResult['task_id'] : null,
                'lease_id' => isset($tickResult['lease_id']) ? (string) $tickResult['lease_id'] : null,
                'details_hash' => hash('sha256', (string) json_encode($tickResult['details'] ?? [], JSON_UNESCAPED_SLASHES)),
            ];

            switch ($outcome) {
                case 'success':
                    $successCount++;
                    break;
                case 'give_back':
                    $giveBackCount++;
                    break;
                case 'safety_halt':
                    $safetyStop = true;
                    if ($stopOnSafetyHalt) {
                        $stopReason = 'safety_halt';
                        break 2;
                    }
                    break;
                default:
                    $failedCount++;
                    if ($stopOnFailed) {
                        $stopReason = 'stop_on_failed';
                        break 2;
                    }
            }
        }

        return $this->envelope(
            applied: true,
            cyclesPlanned: $maxCycles,
            cyclesExecuted: $cyclesExecuted,
            maxCycles: $maxCycles,
            maxParallel: $maxParallel,
            successCount: $successCount,
            giveBackCount: $giveBackCount,
            failedCount: $failedCount,
            blockedActions: $blockedActions,
            receipts: $receipts,
            safetyStop: $safetyStop,
            stopReason: $stopReason,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function safeInvoke(callable $callback, int $tickIndex): array
    {
        try {
            $result = $callback($tickIndex);

            return is_array($result) ? $result : ['outcome' => 'failed', 'details' => 'non_array_callback_return'];
        } catch (Throwable $e) {
            return [
                'outcome' => 'failed',
                'details' => ['exception' => $e::class, 'message' => $e->getMessage()],
            ];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $blockedActions
     * @param  list<array<string,mixed>>  $receipts
     * @return array<string,mixed>
     */
    private function envelope(
        bool $applied,
        int $cyclesPlanned,
        int $cyclesExecuted,
        int $maxCycles,
        int $maxParallel,
        int $successCount,
        int $giveBackCount,
        int $failedCount,
        array $blockedActions,
        array $receipts,
        bool $safetyStop,
        ?string $stopReason,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'dry_run' => ! $applied,
            'max_cycles' => $maxCycles,
            'max_parallel' => $maxParallel,
            'cycle_count' => $cyclesExecuted,
            'planned_cycle_count' => $cyclesPlanned,
            'success_count' => $successCount,
            'give_back_count' => $giveBackCount,
            'failed_count' => $failedCount,
            'blocked_count' => count($blockedActions),
            'blocked_actions' => $blockedActions,
            'receipts' => $receipts,
            'safety_stop' => $safetyStop,
            'stop_reason' => $stopReason,
        ];
        $payload['supervisor_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['supervisor_hash']);
        $copy = $this->ksortDeep($copy);

        return hash('sha256', (string) json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private function ksortDeep(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortDeep($v);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }
}
