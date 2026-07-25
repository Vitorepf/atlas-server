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
        $traceSource = (string) ($job->trace?->source_type ?? '');
        $payload = is_array($job->payload) ? $job->payload : [];
        if (! AiWorkerMacBackgroundReadinessSupport::requiresReadiness($traceSource, $payload)) {
            return null;
        }

        $status = $this->macAgent->status(refresh: true);
        $readiness = (array) ($status['readiness'] ?? []);

        return AiWorkerMacBackgroundReadinessSupport::deferProjection(
            $readiness,
            now()->toJSON(),
            self::MAC_BACKGROUND_RETRY_DELAY_SECONDS,
        );
    }
}
