<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiMission;
use App\Services\Ai\Evidence\CertificationRuntimeService;
use App\Services\Ai\Evidence\MissionEvidenceAdapter;
use App\Services\Ai\Instrumentation\AiWorkerLogger;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineAuditService;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineRuntimeGuard;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Policy\PermissionGateService;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Kernel governance emission family (mission completion gate, PermissionGate
 * warn-only recording, accepted-pipeline runtime contract) extracted VERBATIM
 * from AiWorker (GOD-DEBULK D3 split).
 *
 * Facade AiWorker keeps same-signature delegators; call-site/signature/ctor
 * scanner pins stay on the facade. No scanner pin token moved with this family.
 */
class KernelGovernanceSection
{
    public function __construct(
        private readonly KernelPipelineRuntimeGuard $kernelPipelines,
        private readonly KernelPipelineAuditService $kernelPipelineAudit,
        private readonly MissionLifecycleService $missionLifecycle,
        private readonly MissionEvidenceService $missionEvidence,
        private readonly MissionCertificationService $missionCertification,
        private readonly MissionEvidenceAdapter $missionEvidenceAdapter,
        private readonly PermissionGateService $permissionGates,
        private readonly AiWorkerLogger $logger,
    ) {}

    public function recordAcceptedKernelPipelineRuntimeContract(AiJob $job): void
    {
        $pipelinePlan = $this->kernelPipelines->pipelinePlanForJob($job);
        if ($pipelinePlan === null) {
            return;
        }

        $this->kernelPipelineAudit->recordAcceptedPlan(
            $pipelinePlan,
            $this->kernelPipelines->auditContextForJob($job),
        );
    }

    public function completeKernelMissionFromSuccessfulJob(
        AiJob $job,
        AiJobAttempt $attempt,
        ?string $responseHash,
        string $workerId,
    ): void {
        $kernel = data_get($job->payload, 'kernel');
        if (! is_array($kernel) || empty($kernel['mission_id'])) {
            return;
        }
        if (! DatabaseTableAvailability::all([
            'ai_missions',
            'ai_mission_evidence_refs',
            'ai_mission_certifications',
            'ai_certifications',
            'ai_evidence_packs',
        ])) {
            return;
        }

        try {
            $mission = AiMission::query()->find((string) $kernel['mission_id']);
            if (! $mission instanceof AiMission || $mission->status === MissionLifecycleService::STATUS_COMPLETED) {
                return;
            }

            if ($mission->status === MissionLifecycleService::STATUS_PLANNED) {
                $this->missionLifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, [
                    'actor_type' => 'ai_worker',
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                ]);
                $mission->refresh();
            }

            $this->missionEvidence->attach($mission, [
                'evidence_type' => MissionEvidenceService::TYPE_RECEIPT,
                'evidence_ref' => 'ai_trace:'.$job->trace_id,
                'work_order_id' => data_get($kernel, 'work_order_id'),
                'actor_type' => 'ai_worker',
                'metadata' => array_filter([
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                    'provider' => $attempt->provider,
                    'model' => $attempt->model,
                    'response_hash' => $responseHash,
                    'decision_receipt_id' => data_get($job->metadata, 'decision_receipt.receipt_id'),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ]);
            $mission->refresh();

            if (in_array($mission->status, [
                MissionLifecycleService::STATUS_RUNNING,
                MissionLifecycleService::STATUS_REPAIRING,
            ], true)) {
                $this->missionLifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING, [
                    'actor_type' => 'ai_worker',
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                ]);
                $mission->refresh();
            }

            $foundationCertification = $this->missionCertification->certify($mission);
            $mission->refresh();
            $evidencePack = $this->missionEvidenceAdapter->buildMissionPack($mission);
            $universalCertification = $this->missionEvidenceAdapter->certifyMission($mission, (string) $evidencePack->id);

            if ($foundationCertification->status === MissionCertificationService::STATUS_PASSED
                && $universalCertification->status === CertificationRuntimeService::STATUS_PASSED
                && $mission->status === MissionLifecycleService::STATUS_CERTIFYING
            ) {
                $this->missionLifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED, [
                    'actor_type' => 'ai_worker',
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                    'mission_certification_hash' => $foundationCertification->certification_hash,
                    'universal_certification_hash' => $universalCertification->certification_hash,
                    'evidence_pack_id' => $evidencePack->id,
                ]);
                $mission->refresh();
            }

            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $metadata['kernel_mission_completion'] = [
                'schema_version' => 'atlas.ai.aiworker.kernel_mission_completion.v1',
                'mission_id' => (string) $mission->id,
                'mission_status' => (string) $mission->status,
                'mission_certification_status' => (string) $foundationCertification->status,
                'universal_certification_status' => (string) $universalCertification->status,
                'evidence_pack_id' => (string) $evidencePack->id,
                'mode' => 'enforced_completion_gate',
                'recorded_at' => now()->toJSON(),
            ];
            $job->forceFill(['metadata' => $metadata])->save();

            if ($job->trace) {
                $traceMetadata = is_array($job->trace->metadata) ? $job->trace->metadata : [];
                $traceMetadata['kernel_mission_completion'] = $metadata['kernel_mission_completion'];
                $job->trace->forceFill(['metadata' => $traceMetadata])->save();
            }
        } catch (\Throwable $exception) {
            $this->logger->event(
                eventType: 'kernel_mission_completion_failed',
                message: 'AiWorker could not complete Kernel mission; job result remains persisted and mission awaits repair.',
                severity: 'warning',
                provider: $attempt->provider,
                job: $job,
                attempt: $attempt,
                metadata: [
                    'exception_class' => $exception::class,
                    'reason' => $exception->getMessage(),
                    'mission_id' => data_get($kernel, 'mission_id'),
                ],
                workerId: $workerId,
            );
        }
    }

    public function recordKernelPermissionGate(AiJob $job, string $providerKey, string $workerId): void
    {
        $kernel = data_get($job->payload, 'kernel');
        if (! is_array($kernel) || empty($kernel['mission_id'])) {
            return;
        }
        if (! DatabaseTableAvailability::all(['ai_permission_gates', 'ai_policy_profiles'])) {
            return;
        }

        $domainId = (string) (
            data_get($kernel, 'domain_id')
            ?: data_get($job->payload, 'primary_domain')
            ?: data_get($job->payload, 'routing.primary_domain')
            ?: data_get($job->metadata, 'primary_domain')
            ?: data_get($job->metadata, 'intent.domain')
            ?: 'general'
        );
        $capability = (string) (
            data_get($kernel, 'capability')
            ?: data_get($job->payload, 'capability')
            ?: data_get($job->payload, 'task_request.capability')
            ?: data_get($job->metadata, 'task_request.capability')
            ?: 'ai.worker.provider_execute'
        );
        $riskLevel = (string) (
            data_get($kernel, 'risk_level')
            ?: data_get($job->payload, 'risk_level')
            ?: ($domainId === 'programming' ? 'medium' : 'low')
        );

        try {
            $gate = $this->permissionGates->evaluate([
                'requested_action' => 'ai.worker.provider_execute',
                'gate_type' => 'permission',
                'risk_level' => $riskLevel,
                'domain_id' => $domainId,
                'tool_id' => $providerKey,
                'mission_id' => (string) $kernel['mission_id'],
                'work_order_id' => data_get($kernel, 'work_order_id'),
                'evidence_refs' => array_values(array_filter([
                    $job->trace_id ? 'ai_trace:'.$job->trace_id : null,
                    'ai_job:'.$job->id,
                    data_get($job->metadata, 'decision_receipt.receipt_id')
                        ? 'decision_receipt:'.data_get($job->metadata, 'decision_receipt.receipt_id')
                        : null,
                ])),
            ]);

            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $metadata['kernel_permission_gate'] = [
                'schema_version' => 'atlas.ai.aiworker.kernel_permission_gate.v1',
                'gate_id' => (string) $gate->id,
                'decision' => (string) $gate->decision,
                'receipt_hash' => (string) $gate->receipt_hash,
                'provider' => $providerKey,
                'domain_id' => $domainId,
                'capability' => $capability,
                'mode' => 'warn_only',
                'recorded_at' => now()->toJSON(),
            ];
            $job->forceFill(['metadata' => $metadata])->save();

            if ($job->trace) {
                $traceMetadata = is_array($job->trace->metadata) ? $job->trace->metadata : [];
                $traceMetadata['kernel_permission_gate'] = $metadata['kernel_permission_gate'];
                $job->trace->forceFill(['metadata' => $traceMetadata])->save();
            }
        } catch (\Throwable $exception) {
            $this->logger->event(
                eventType: 'kernel_permission_gate_warn_only_failed',
                message: 'AiWorker could not record Kernel PermissionGate decision; legacy permission path remains authoritative.',
                severity: 'warning',
                provider: $providerKey,
                job: $job,
                metadata: [
                    'exception_class' => $exception::class,
                    'reason' => $exception->getMessage(),
                    'mode' => 'warn_only',
                ],
                workerId: $workerId,
            );
        }
    }
}
