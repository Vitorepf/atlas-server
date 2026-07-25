<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

/**
 * Pure Mac background-readiness predicates (full-pass peel from MacBackgroundReadinessSection).
 * No DI, no models — callers pass already-extracted scalars/arrays.
 */
final class AiWorkerMacBackgroundReadinessSupport
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public static function requiresReadiness(string $traceSource, array $payload = []): bool
    {
        if ($traceSource === 'scheduled') {
            return true;
        }

        return in_array((string) data_get($payload, 'atlas_workflow_mode'), ['scheduled', 'background'], true)
            || in_array((string) data_get($payload, 'app_surface'), ['atlas_cli_schedule', 'scheduled', 'background'], true)
            || (bool) data_get($payload, 'scheduled_task.id');
    }

    /**
     * Build the deferred readiness projection from a Mac Agent readiness map.
     * Caller supplies checked_at (clock stays outside pure Support).
     *
     * @param  array<string,mixed>  $readiness
     * @return array<string,mixed>|null  null when already ready for background jobs
     */
    public static function deferProjection(array $readiness, string $checkedAt, int $retryAfterSeconds = 300): ?array
    {
        if (($readiness['ready_for_background_jobs'] ?? false) === true) {
            return null;
        }

        return [
            'schema_version' => 1,
            'status' => 'deferred',
            'reason' => 'mac_background_not_ready',
            'retry_after_seconds' => $retryAfterSeconds,
            'checked_at' => $checkedAt,
            'readiness' => [
                'overall' => $readiness['overall'] ?? 'unknown',
                'ready_for_remote' => (bool) ($readiness['ready_for_remote'] ?? false),
                'ready_for_scheduled_wake' => (bool) ($readiness['ready_for_scheduled_wake'] ?? false),
                'ready_for_background_jobs' => (bool) ($readiness['ready_for_background_jobs'] ?? false),
                'power_ready_for_background_jobs' => (bool) ($readiness['power_ready_for_background_jobs'] ?? false),
                'blockers' => $readiness['blockers'] ?? [],
                'warnings' => $readiness['warnings'] ?? [],
            ],
        ];
    }
}
