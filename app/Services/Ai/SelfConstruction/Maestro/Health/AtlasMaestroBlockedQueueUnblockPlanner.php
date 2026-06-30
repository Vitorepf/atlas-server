<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure planner: maps quarantined/blocked packet facts to an ordered unblock plan.
 *
 * Root cause → action (first match wins):
 *   operator_only | forbidden_self_target → operator_only   (no impl work enqueued)
 *   schema_errors                          → create_migration_task
 *   deficiencies / contradictory spec      → rescope
 *   dependency_status stale|missing        → fix_dependency
 *   already_done | give_back >= threshold  → retire
 *   (default)                              → leave_blocked   (no impl work enqueued)
 *
 * Output entries are ordered by downstream_unlock_count descending.
 * implementation_work_enqueued=false for operator_only and leave_blocked.
 */
final class AtlasMaestroBlockedQueueUnblockPlanner
{
    public const SCHEMA = 'atlas.maestro.health.blocked_queue_unblock_planner.v1';

    public const ACTION_RETIRE = 'retire';

    public const ACTION_RESCOPE = 'rescope';

    public const ACTION_CREATE_MIGRATION_TASK = 'create_migration_task';

    public const ACTION_FIX_DEPENDENCY = 'fix_dependency';

    public const ACTION_OPERATOR_ONLY = 'operator_only';

    public const ACTION_LEAVE_BLOCKED = 'leave_blocked';

    private const GIVE_BACK_RETIRE_THRESHOLD = 8;

    /** @var list<string> Actions for which implementation work is NOT enqueued. */
    private const NO_IMPL_WORK_ACTIONS = [self::ACTION_OPERATOR_ONLY, self::ACTION_LEAVE_BLOCKED];

    /**
     * @param  list<array<string,mixed>>  $blockedPackets
     * @return array<string,mixed>
     */
    public function plan(array $blockedPackets): array
    {
        $entries = [];
        foreach ($blockedPackets as $packet) {
            $id = (string) ($packet['task_packet_id'] ?? ($packet['id'] ?? ''));
            $action = $this->classifyAction($packet);
            $downstreamUnlock = max(0, (int) ($packet['downstream_unlock_count'] ?? 0));

            $entries[] = [
                'task_packet_id' => $id,
                'action' => $action,
                'root_cause' => $this->rootCause($action),
                'downstream_unlock_count' => $downstreamUnlock,
                'implementation_work_enqueued' => ! in_array($action, self::NO_IMPL_WORK_ACTIONS, true),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $b['downstream_unlock_count'] <=> $a['downstream_unlock_count']);

        $byAction = [];
        foreach ($entries as $e) {
            $byAction[$e['action']][] = $e['task_packet_id'];
        }

        $refusedIds = array_values(array_map(
            static fn (array $e): string => $e['task_packet_id'],
            array_filter($entries, static fn (array $e): bool => ! $e['implementation_work_enqueued']),
        ));

        return [
            'schema_version' => self::SCHEMA,
            'total_blocked' => count($blockedPackets),
            'entries' => $entries,
            'by_action' => $byAction,
            'implementation_work_refused_ids' => $refusedIds,
        ];
    }

    private function classifyAction(array $packet): string
    {
        $operatorOnly = (bool) ($packet['operator_only'] ?? false);
        $forbiddenSelfTarget = (bool) ($packet['forbidden_self_target'] ?? false);
        $schemaErrors = is_array($packet['schema_errors'] ?? null) ? $packet['schema_errors'] : [];
        $deficiencies = is_array($packet['deficiencies'] ?? null)
            ? $packet['deficiencies']
            : (is_array($packet['blocking_deficiencies'] ?? null) ? $packet['blocking_deficiencies'] : []);
        $dependencyStatus = trim((string) ($packet['dependency_status'] ?? ''));
        $giveBackCount = max(0, (int) ($packet['give_back_count'] ?? 0));
        $alreadyDone = (bool) ($packet['already_done'] ?? false);

        if ($operatorOnly || $forbiddenSelfTarget) {
            return self::ACTION_OPERATOR_ONLY;
        }
        if ($schemaErrors !== []) {
            return self::ACTION_CREATE_MIGRATION_TASK;
        }
        if ($deficiencies !== []) {
            return self::ACTION_RESCOPE;
        }
        if (in_array($dependencyStatus, ['stale', 'missing'], true)) {
            return self::ACTION_FIX_DEPENDENCY;
        }
        if ($alreadyDone || $giveBackCount >= self::GIVE_BACK_RETIRE_THRESHOLD) {
            return self::ACTION_RETIRE;
        }

        return self::ACTION_LEAVE_BLOCKED;
    }

    private function rootCause(string $action): string
    {
        return match ($action) {
            self::ACTION_OPERATOR_ONLY => 'operator_only_or_forbidden_self_target',
            self::ACTION_CREATE_MIGRATION_TASK => 'schema_errors',
            self::ACTION_RESCOPE => 'contradictory_or_missing_spec',
            self::ACTION_FIX_DEPENDENCY => 'stale_or_missing_dependency',
            self::ACTION_RETIRE => 'repeated_give_back_or_already_done',
            default => 'unresolvable_blocked',
        };
    }
}
