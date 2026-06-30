<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns repeated worker give_back events into actionable repair recommendations.
 *
 * INPUT give_back events:
 *   list<{ task_packet_id:string, give_back_class:string, give_back_count:int, reason?:string }>
 *
 * GIVE_BACK CLASSES → root causes:
 *   scope_repair_*   → bad_allowed_files      (packet_defect) → respec
 *   forbidden_*      → forbidden_target        (packet_defect) → respec
 *   contradictory_*  → contradictory_acceptance(packet_defect) → respec
 *   schema_*         → missing_schema          (packet_defect) → respec
 *   duplicate_*      → duplicate_implemented   (packet_defect) → cancel
 *   flaky_test / test_fail_*  → flaky_test     (packet_defect) → respec
 *   impossible_dep*  → impossible_dependency   (packet_defect) → operator_only
 *   context_* / capability_* / timeout / provider_* → worker_weakness (not packet_defect) → retry_different_worker
 *   (anything else with count >= POISON_THRESHOLD) → unclassified_repeat → operator_only
 *
 * INVARIANT: a task_packet_id in poison_packets must never be recommended for unchanged retry.
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainGiveBackRootCauseMiner
{
    public const SCHEMA = 'atlas.external_brain.giveback_root_cause_miner.v1';

    public const DEFECT_PACKET = 'packet_defect';

    public const DEFECT_WORKER = 'worker_weakness';

    public const ACTION_RESPEC = 'respec';

    public const ACTION_CANCEL = 'cancel';

    public const ACTION_OPERATOR_ONLY = 'operator_only';

    public const ACTION_RETRY_DIFFERENT_WORKER = 'retry_different_worker';

    /** Minimum give_back_count before a task is considered a poison packet. */
    public const POISON_THRESHOLD = 2;

    /**
     * @param  list<array{task_packet_id:string, give_back_class:string, give_back_count:int, reason?:string}>  $giveBacks
     * @return array{schema:string, root_causes:list<array<string,mixed>>, poison_packets:list<string>}
     */
    public function mine(array $giveBacks): array
    {
        // Group by root cause cluster.
        $clusters = [];
        foreach ($giveBacks as $gb) {
            $id = (string) ($gb['task_packet_id'] ?? '');
            $class = (string) ($gb['give_back_class'] ?? '');
            $count = max(1, (int) ($gb['give_back_count'] ?? 1));
            $reason = (string) ($gb['reason'] ?? '');

            [$rootCause, $defectType, $action] = $this->classify($class, $reason);

            $key = $rootCause;
            if (! isset($clusters[$key])) {
                $clusters[$key] = [
                    'root_cause' => $rootCause,
                    'defect_type' => $defectType,
                    'recommended_action' => $action,
                    'task_packet_ids' => [],
                    'evidence' => [],
                ];
            }

            if ($id !== '' && ! in_array($id, $clusters[$key]['task_packet_ids'], true)) {
                $clusters[$key]['task_packet_ids'][] = $id;
            }

            $clusters[$key]['evidence'][] = array_filter([
                'task_packet_id' => $id,
                'give_back_class' => $class,
                'give_back_count' => $count,
                'reason' => $reason !== '' ? $reason : null,
            ], static fn ($v): bool => $v !== null);
        }

        // Poison packets: packet_defect tasks with count >= threshold.
        $poisonPackets = [];
        foreach ($giveBacks as $gb) {
            $id = (string) ($gb['task_packet_id'] ?? '');
            $class = (string) ($gb['give_back_class'] ?? '');
            $count = max(1, (int) ($gb['give_back_count'] ?? 1));
            $reason = (string) ($gb['reason'] ?? '');

            [, $defectType] = $this->classify($class, $reason);

            if ($id !== '' && $defectType === self::DEFECT_PACKET && $count >= self::POISON_THRESHOLD) {
                if (! in_array($id, $poisonPackets, true)) {
                    $poisonPackets[] = $id;
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'root_causes' => array_values($clusters),
            'poison_packets' => $poisonPackets,
        ];
    }

    /** @return array{string, string, string} [root_cause, defect_type, recommended_action] */
    private function classify(string $class, string $reason): array
    {
        $c = strtolower($class);
        $r = strtolower($reason);

        if (str_starts_with($c, 'scope_repair') || str_contains($c, 'bad_allowed') || str_contains($r, 'allowed_files')) {
            return ['bad_allowed_files', self::DEFECT_PACKET, self::ACTION_RESPEC];
        }

        if (str_contains($c, 'forbidden') || str_contains($r, 'forbidden')) {
            return ['forbidden_target', self::DEFECT_PACKET, self::ACTION_RESPEC];
        }

        if (str_contains($c, 'contradict') || str_contains($r, 'contradict') || str_contains($r, 'contradictory')) {
            return ['contradictory_acceptance', self::DEFECT_PACKET, self::ACTION_RESPEC];
        }

        if (str_contains($c, 'schema') || str_contains($r, 'schema')) {
            return ['missing_schema', self::DEFECT_PACKET, self::ACTION_RESPEC];
        }

        if (str_contains($c, 'duplicate') || str_contains($r, 'duplicate') || str_contains($r, 'already_implemented')) {
            return ['duplicate_implemented', self::DEFECT_PACKET, self::ACTION_CANCEL];
        }

        if (str_contains($c, 'flaky') || str_contains($c, 'test_fail') || str_contains($r, 'flaky') || str_contains($r, 'test_fail')) {
            return ['flaky_test', self::DEFECT_PACKET, self::ACTION_RESPEC];
        }

        if (str_contains($c, 'impossible_dep') || str_contains($c, 'impossible_dependency') || str_contains($r, 'impossible_dep')) {
            return ['impossible_dependency', self::DEFECT_PACKET, self::ACTION_OPERATOR_ONLY];
        }

        // Worker weakness signals — not a packet defect.
        if (
            str_contains($c, 'context') || str_contains($c, 'capability') ||
            str_contains($c, 'timeout') || str_contains($c, 'provider') ||
            str_contains($r, 'context_overflow') || str_contains($r, 'capability_gap') ||
            str_contains($r, 'provider_error')
        ) {
            return ['worker_weakness', self::DEFECT_WORKER, self::ACTION_RETRY_DIFFERENT_WORKER];
        }

        // Unclassified repeated failure → escalate to operator.
        return ['unclassified_repeat', self::DEFECT_PACKET, self::ACTION_OPERATOR_ONLY];
    }
}
