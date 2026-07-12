<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

/**
 * MULTN17-02 — arc lifecycle: kill-gate, archival receipts, per-task independence.
 *
 * A task rejected at seed-gate does NOT collapse the arc. K consecutive task failures
 * archive the arc with a receipt and remaining tasks are never served.
 */
final class ComposedObraArcLifecycle
{
    public const SCHEMA_VERSION = 'atlas.originator.composed_obra_arc_lifecycle.v1';

    /** @var array<string,array<string,mixed>> */
    private static array $state = [];

    public static function reset(): void
    {
        self::$state = [];
    }

    /**
     * @param  array<string,mixed>  $arc
     */
    public static function register(array $arc): void
    {
        $arcId = (string) ($arc['arc_id'] ?? '');
        if ($arcId === '') {
            return;
        }

        $tasks = [];
        foreach ((array) ($arc['tasks'] ?? []) as $task) {
            if (! is_array($task)) {
                continue;
            }
            $taskId = (string) ($task['task_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $tasks[$taskId] = [
                'task_id' => $taskId,
                'order' => (int) ($task['order'] ?? 0),
                'target_path' => (string) ($task['target_path'] ?? ''),
                'status' => 'pending',
            ];
        }

        self::$state[$arcId] = [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'obra_id' => (string) ($arc['obra_id'] ?? ''),
            'status' => 'active',
            'consecutive_failures' => 0,
            'kill_gate_k' => max(1, (int) data_get($arc, 'kill_gate.consecutive_failures_k', ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES)),
            'tasks' => $tasks,
            'archive_receipt' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function recordTaskFailure(string $arcId, string $taskId): array
    {
        $state = self::requireActive($arcId);
        if ($state === null) {
            return self::refusal('arc_not_active', $arcId);
        }

        self::markTask($arcId, $taskId, 'failed');
        $state = self::$state[$arcId];
        $state['consecutive_failures'] = (int) ($state['consecutive_failures'] ?? 0) + 1;
        self::$state[$arcId] = $state;

        if ($state['consecutive_failures'] >= (int) $state['kill_gate_k']) {
            return self::archive($arcId, 'kill_gate_consecutive_failures');
        }

        return self::status($arcId);
    }

    /**
     * @return array<string,mixed>
     */
    public static function recordTaskSuccess(string $arcId, string $taskId): array
    {
        $state = self::requireActive($arcId);
        if ($state === null) {
            return self::refusal('arc_not_active', $arcId);
        }

        self::markTask($arcId, $taskId, 'landed');
        self::$state[$arcId]['consecutive_failures'] = 0;

        return self::status($arcId);
    }

    /**
     * Seed-gate rejection is independent: the arc stays active and the task is skipped.
     *
     * @return array<string,mixed>
     */
    public static function recordSeedGateRejection(string $arcId, string $taskId): array
    {
        $state = self::requireActive($arcId);
        if ($state === null) {
            return self::refusal('arc_not_active', $arcId);
        }

        self::markTask($arcId, $taskId, 'seed_gate_rejected');
        // Independence: consecutive failure counter is NOT incremented.

        return self::status($arcId);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function nextServableTask(string $arcId): ?array
    {
        $state = self::$state[$arcId] ?? null;
        if (! is_array($state) || ($state['status'] ?? '') !== 'active') {
            return null;
        }

        $tasks = array_values((array) ($state['tasks'] ?? []));
        usort($tasks, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

        foreach ($tasks as $task) {
            $status = (string) ($task['status'] ?? '');
            if (in_array($status, ['pending'], true)) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public static function status(string $arcId): array
    {
        $state = self::$state[$arcId] ?? null;
        if (! is_array($state)) {
            return self::refusal('arc_not_found', $arcId);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'status' => (string) ($state['status'] ?? 'unknown'),
            'consecutive_failures' => (int) ($state['consecutive_failures'] ?? 0),
            'kill_gate_k' => (int) ($state['kill_gate_k'] ?? ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES),
            'archive_receipt' => $state['archive_receipt'] ?? null,
            'remaining_servable' => self::nextServableTask($arcId) !== null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function archive(string $arcId, string $reason): array
    {
        $state = self::$state[$arcId] ?? null;
        if (! is_array($state)) {
            return self::refusal('arc_not_found', $arcId);
        }

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'obra_id' => (string) ($state['obra_id'] ?? ''),
            'archived_at_basis' => $reason,
            'consecutive_failures' => (int) ($state['consecutive_failures'] ?? 0),
            'kill_gate_k' => (int) ($state['kill_gate_k'] ?? ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES),
            'receipt_hash' => hash('sha256', json_encode([$arcId, $reason, $state['consecutive_failures'] ?? 0], JSON_UNESCAPED_SLASHES)),
        ];

        foreach ((array) ($state['tasks'] ?? []) as $taskId => $task) {
            if (! is_array($task)) {
                continue;
            }
            if (($task['status'] ?? '') === 'pending') {
                $state['tasks'][$taskId]['status'] = 'never_served_archived';
            }
        }

        $state['status'] = 'archived';
        $state['archive_receipt'] = $receipt;
        self::$state[$arcId] = $state;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'status' => 'archived',
            'consecutive_failures' => (int) ($state['consecutive_failures'] ?? 0),
            'kill_gate_k' => (int) ($state['kill_gate_k'] ?? ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES),
            'archive_receipt' => $receipt,
            'remaining_servable' => false,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function requireActive(string $arcId): ?array
    {
        $state = self::$state[$arcId] ?? null;
        if (! is_array($state) || ($state['status'] ?? '') !== 'active') {
            return null;
        }

        return $state;
    }

    private static function markTask(string $arcId, string $taskId, string $status): void
    {
        if (! isset(self::$state[$arcId]['tasks'][$taskId])) {
            return;
        }
        self::$state[$arcId]['tasks'][$taskId]['status'] = $status;
    }

    /**
     * @return array<string,mixed>
     */
    private static function refusal(string $reason, string $arcId): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'status' => 'refused',
            'reason' => $reason,
            'remaining_servable' => false,
        ];
    }
}
