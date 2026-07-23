<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Services\Ai\Instrumentation\AiWorkerLogger;
use App\Services\MacAgent\MacAgentService;

/**
 * Mac background-readiness gating family extracted VERBATIM from AiWorker (GOD-DEBULK D3 split).
 *
 * Facade AiWorker keeps same-signature delegators; call-site/signature/ctor scanner
 * pins stay on the facade. No scanner pin token moved with this family.
 */
class MacBackgroundReadinessSection
{
    private const MAC_BACKGROUND_RETRY_DELAY_SECONDS = 300;

    public function __construct(
        private readonly AiWorkerLogger $logger,
        private readonly MacAgentService $macAgent,
    ) {}

    public function deferForMacBackgroundReadinessIfNeeded(AiJob $job, string $workerId): bool
    {
        if (! $defer = $this->macBackgroundReadinessDefer($job)) {
            return false;
        }

        $metadata = array_merge($job->metadata ?? [], [
            'mac_background_readiness' => $defer,
        ]);

        $job->update([
            'available_at' => now()->addSeconds(self::MAC_BACKGROUND_RETRY_DELAY_SECONDS),
            'metadata' => $metadata,
        ]);
        $job->trace?->update([
            'status' => 'queued',
            'metadata' => array_merge($job->trace->metadata ?? [], [
                'mac_background_readiness' => $defer,
            ]),
        ]);

        $this->logger->event(
            eventType: 'job_deferred',
            message: 'AI background job deferred until Mac Agent readiness is satisfied.',
            severity: 'warning',
            provider: $job->provider,
            job: $job,
            metadata: $defer,
            workerId: $workerId,
        );

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function macBackgroundReadinessDefer(AiJob $job): ?array
    {
        if (! $this->requiresMacBackgroundReadiness($job)) {
            return null;
        }

        $status = $this->macAgent->status(refresh: true);
        $readiness = (array) ($status['readiness'] ?? []);

        if (($readiness['ready_for_background_jobs'] ?? false) === true) {
            return null;
        }

        return [
            'schema_version' => 1,
            'status' => 'deferred',
            'reason' => 'mac_background_not_ready',
            'retry_after_seconds' => self::MAC_BACKGROUND_RETRY_DELAY_SECONDS,
            'checked_at' => now()->toJSON(),
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

    private function requiresMacBackgroundReadiness(AiJob $job): bool
    {
        $traceSource = (string) ($job->trace?->source_type ?? '');
        $payload = $job->payload ?? [];

        if ($traceSource === 'scheduled') {
            return true;
        }

        return in_array((string) data_get($payload, 'atlas_workflow_mode'), ['scheduled', 'background'], true)
            || in_array((string) data_get($payload, 'app_surface'), ['atlas_cli_schedule', 'scheduled', 'background'], true)
            || (bool) data_get($payload, 'scheduled_task.id');
    }
}
