<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Emits recovery tasks from local client failure modes that affect
 * autonomous task execution and proof capture.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainLocalClientFailureRecoveryTaskEmitter
{
    public const SCHEMA = 'atlas.self_construction.external_brain_local_client_failure_recovery_task_emitter.v1';

    public const FAILURE_TIMEOUT = 'timeout';
    public const FAILURE_MISSING_OUTPUT = 'missing_output';
    public const FAILURE_MALFORMED_REPORT = 'malformed_report';
    public const FAILURE_STALE_LEASE = 'stale_lease';

    /**
     * @param  array<int, array<string, mixed>>  $failures
     * @return array<string, mixed>
     */
    public function emit(array $failures): array
    {
        $recoveryTasks = [];

        foreach ($failures as $failure) {
            if (! is_array($failure)) {
                continue;
            }
            $mode = (string) ($failure['mode'] ?? '');
            $taskId = (string) ($failure['task_id'] ?? '');
            $workerId = (string) ($failure['worker_id'] ?? '');

            $recoverySpec = $this->buildRecoverySpec($mode, $taskId, $workerId);
            if ($recoverySpec !== null) {
                $recoveryTasks[] = $recoverySpec;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'recovery_tasks' => $recoveryTasks,
            'recovery_count' => count($recoveryTasks),
            'failure_count' => count($failures),
        ];
    }

    private function buildRecoverySpec(string $mode, string $taskId, string $workerId): ?array
    {
        return match ($mode) {
            self::FAILURE_TIMEOUT => [
                'failure_mode' => $mode,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'recovery_action' => 'retry_with_extended_timeout',
                'repair_spec' => 'increase timeout and retry the task',
            ],
            self::FAILURE_MISSING_OUTPUT => [
                'failure_mode' => $mode,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'recovery_action' => 're_run_and_capture_output',
                'repair_spec' => 're-run the task and capture output artifacts',
            ],
            self::FAILURE_MALFORMED_REPORT => [
                'failure_mode' => $mode,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'recovery_action' => 'repair_report_format',
                'repair_spec' => 'fix the report format and re-submit',
            ],
            self::FAILURE_STALE_LEASE => [
                'failure_mode' => $mode,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'recovery_action' => 'reclaim_lease',
                'repair_spec' => 'reclaim the lease and continue execution',
            ],
            default => null,
        };
    }
}
