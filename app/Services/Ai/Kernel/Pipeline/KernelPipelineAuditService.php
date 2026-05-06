<?php

namespace App\Services\Ai\Kernel\Pipeline;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;

class KernelPipelineAuditService
{
    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    public function recordScaffoldExecution(
        PipelineExecutionResult $result,
        string $emitterStage = 'atlas.ai_pipeline.scaffold',
        string $emitterVersion = 'atlas.ai_pipeline.scaffold.v1',
    ): ?AtlasLedgerEvent {
        return $this->recordAcceptedPlan($result->auditPlan, [
            'emitter_stage' => $emitterStage,
            'emitter_version' => $emitterVersion,
        ]);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     */
    public function recordAcceptedPlan(array $plan, array $context = []): ?AtlasLedgerEvent
    {
        return $this->ledger->recordKernelPipelineAccepted(
            $plan,
            $this->contextForPlan($plan, $context),
        );
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $violations
     * @param  array<string,mixed>  $context
     */
    public function recordRejectedPlan(array $plan, array $violations, array $context = []): ?AtlasLedgerEvent
    {
        return $this->ledger->recordKernelPipelineRejected(
            $plan,
            $violations,
            $this->contextForPlan($plan, $context),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function eventPayload(?AtlasLedgerEvent $event): ?array
    {
        if (! $event instanceof AtlasLedgerEvent) {
            return null;
        }

        return [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'envelope_id' => $event->envelope_id,
            'payload_hash' => $event->payload_hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function contextForPlan(array $plan, array $context): array
    {
        $pipelineId = is_string($plan['pipeline_id'] ?? null) && trim((string) $plan['pipeline_id']) !== ''
            ? trim((string) $plan['pipeline_id'])
            : 'kernel_pipeline_unknown';

        return [
            ...$context,
            'tenant_id' => $context['tenant_id'] ?? data_get($plan, 'input.tenant_id', 'atlas-single-tenant'),
            'operator_id' => $context['operator_id'] ?? data_get($plan, 'input.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? 'kernel_pipeline:'.$pipelineId,
            'correlation_id' => $context['correlation_id'] ?? $pipelineId,
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.kernel_pipeline.audit',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.kernel_pipeline.audit.v1',
        ];
    }
}
