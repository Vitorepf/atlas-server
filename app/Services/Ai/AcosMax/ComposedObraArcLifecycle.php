<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MULTN17-02 — arc lifecycle: kill-gate, archival receipts, per-task independence.
 *
 * A task rejected at seed-gate does NOT collapse the arc. K consecutive task failures
 * archive the arc with a receipt and remaining tasks are never served.
 */
final class ComposedObraArcLifecycle
{
    public const SCHEMA_VERSION = 'atlas.originator.composed_obra_arc_lifecycle.v1';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_REFUSED = 'refused';

    public const TASK_STATUS_FAILED = 'failed';

    public const TASK_STATUS_LANDED = 'landed';

    public const TASK_STATUS_NEVER_SERVED_ARCHIVED = 'never_served_archived';

    public const REASON_ARC_NOT_ACTIVE = 'arc_not_active';

    public const REASON_ARC_NOT_FOUND = 'arc_not_found';

    public const REASON_KILL_GATE_CONSECUTIVE_FAILURES = 'kill_gate_consecutive_failures';

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
        $arcId = AiValueNormalizer::trimmedStringOrNull($arc['arc_id'] ?? null) ?? '';
        if ($arcId === '') {
            return;
        }

        $tasks = [];
        foreach (AiValueNormalizer::arrayOrEmpty($arc['tasks'] ?? null) as $task) {
            if (! is_array($task)) {
                continue;
            }
            $taskId = AiValueNormalizer::trimmedStringOrNull($task['task_id'] ?? null) ?? '';
            if ($taskId === '') {
                continue;
            }
            $tasks[$taskId] = [
                'task_id' => $taskId,
                'order' => (int) (AiValueNormalizer::finiteFloatOrNull($task['order'] ?? null) ?? 0),
                'target_path' => AiValueNormalizer::trimmedStringOrNull($task['target_path'] ?? null) ?? '',
                'status' => self::STATUS_PENDING,
            ];
        }

        self::$state[$arcId] = [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'obra_id' => AiValueNormalizer::trimmedStringOrNull($arc['obra_id'] ?? null) ?? '',
            'status' => self::STATUS_ACTIVE,
            'consecutive_failures' => 0,
            'kill_gate_k' => max(1, (int) (AiValueNormalizer::finiteFloatOrNull(data_get($arc, 'kill_gate.consecutive_failures_k')) ?? ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES)),
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
            return self::refusal(self::REASON_ARC_NOT_ACTIVE, $arcId);
        }

        self::markTask($arcId, $taskId, self::TASK_STATUS_FAILED);
        $state = self::$state[$arcId];
        $state['consecutive_failures'] = (int) (AiValueNormalizer::finiteFloatOrNull($state['consecutive_failures'] ?? null) ?? 0) + 1;
        self::$state[$arcId] = $state;

        if ($state['consecutive_failures'] >= (int) (AiValueNormalizer::finiteFloatOrNull($state['kill_gate_k'] ?? null) ?? 0)) {
            return self::archive($arcId, self::REASON_KILL_GATE_CONSECUTIVE_FAILURES);
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
            return self::refusal(self::REASON_ARC_NOT_ACTIVE, $arcId);
        }

        self::markTask($arcId, $taskId, self::TASK_STATUS_LANDED);
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
            return self::refusal(self::REASON_ARC_NOT_ACTIVE, $arcId);
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
        if (! is_array($state) || ($state['status'] ?? '') !== self::STATUS_ACTIVE) {
            return null;
        }

        $tasks = array_values(AiValueNormalizer::arrayOrEmpty($state['tasks'] ?? null));
        usort($tasks, static fn (array $a, array $b): int => ((int) (AiValueNormalizer::finiteFloatOrNull($a['order'] ?? null) ?? 0)) <=> ((int) (AiValueNormalizer::finiteFloatOrNull($b['order'] ?? null) ?? 0)));

        foreach ($tasks as $task) {
            $status = AiValueNormalizer::trimmedStringOrNull($task['status'] ?? null) ?? '';
            if (in_array($status, [self::STATUS_PENDING], true)) {
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
            return self::refusal(self::REASON_ARC_NOT_FOUND, $arcId);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'status' => AiValueNormalizer::trimmedStringOrNull($state['status'] ?? null) ?? 'unknown',
            'consecutive_failures' => (int) (AiValueNormalizer::finiteFloatOrNull($state['consecutive_failures'] ?? null) ?? 0),
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
            return self::refusal(self::REASON_ARC_NOT_FOUND, $arcId);
        }

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'obra_id' => AiValueNormalizer::trimmedStringOrNull($state['obra_id'] ?? null) ?? '',
            'archived_at_basis' => $reason,
            'consecutive_failures' => (int) (AiValueNormalizer::finiteFloatOrNull($state['consecutive_failures'] ?? null) ?? 0),
            'kill_gate_k' => (int) ($state['kill_gate_k'] ?? ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES),
            'receipt_hash' => hash('sha256', json_encode([$arcId, $reason, $state['consecutive_failures'] ?? 0], JSON_UNESCAPED_SLASHES)),
        ];

        foreach (AiValueNormalizer::arrayOrEmpty($state['tasks'] ?? null) as $taskId => $task) {
            if (! is_array($task)) {
                continue;
            }
            if (($task['status'] ?? '') === self::STATUS_PENDING) {
                $state['tasks'][$taskId]['status'] = self::TASK_STATUS_NEVER_SERVED_ARCHIVED;
            }
        }

        $state['status'] = self::STATUS_ARCHIVED;
        $state['archive_receipt'] = $receipt;
        self::$state[$arcId] = $state;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'status' => self::STATUS_ARCHIVED,
            'consecutive_failures' => (int) (AiValueNormalizer::finiteFloatOrNull($state['consecutive_failures'] ?? null) ?? 0),
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
        if (! is_array($state) || ($state['status'] ?? '') !== self::STATUS_ACTIVE) {
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
            'status' => self::STATUS_REFUSED,
            'reason' => $reason,
            'remaining_servable' => false,
        ];
    }
}
