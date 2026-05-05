<?php

namespace App\Services\Ai\Kernel\Pipeline;

final readonly class PipelineExecutionResult
{
    /**
     * @param  array<int,PipelineStageResult>  $stageResults
     * @param  array<string,mixed>  $auditPlan
     * @param  array<int,string>  $evidenceRefs
     * @param  array<int,string>  $traceRefs
     * @param  array<string,mixed>  $complianceReport
     */
    public function __construct(
        public string $pipelineId,
        public string $schemaVersion,
        public string $status,
        public bool $dryRun,
        public string $planHash,
        public array $stageResults,
        public array $auditPlan,
        public array $evidenceRefs,
        public array $traceRefs,
        public bool $providerExecutionAttempted,
        public array $complianceReport,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'pipeline_id' => $this->pipelineId,
            'schema_version' => $this->schemaVersion,
            'status' => $this->status,
            'dry_run' => $this->dryRun,
            'plan_hash' => $this->planHash,
            'stage_results' => array_map(
                fn (PipelineStageResult $result): array => $result->toArray(),
                $this->stageResults,
            ),
            'audit_plan' => $this->auditPlan,
            'evidence_refs' => $this->evidenceRefs,
            'trace_refs' => $this->traceRefs,
            'provider_execution_attempted' => $this->providerExecutionAttempted,
            'compliance_report' => $this->complianceReport,
        ];
    }
}
