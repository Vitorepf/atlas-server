<?php

namespace App\Services\Ai\Kernel\Pipeline;

class ScaffoldAtlasKernelPipeline implements AtlasKernelPipeline
{
    /**
     * @return array<int,PipelineStageDefinition>
     */
    public function stages(): array
    {
        return [
            new PipelineStageDefinition(KernelPipelineStage::Input, 1, ['surface.raw_input'], ['input.normalized'], 'Normalize surface input into kernel-safe input.'),
            new PipelineStageDefinition(KernelPipelineStage::OperationEnvelope, 2, ['input.normalized'], ['envelope.id', 'audit.chain_hash'], 'Plan OperationEnvelope creation boundary before routing and decision.'),
            new PipelineStageDefinition(KernelPipelineStage::Intent, 3, ['envelope.id', 'input.normalized'], ['routing.intent'], 'Classify intent, risk and routing hints inside the envelope.'),
            new PipelineStageDefinition(KernelPipelineStage::Decide, 4, ['envelope.id', 'input.normalized', 'routing.intent'], ['decision.plan'], 'Plan domain, flow, provider, model, budget and gates.'),
            new PipelineStageDefinition(KernelPipelineStage::DecisionReceipt, 5, ['decision.plan', 'envelope.id'], ['decision.receipt'], 'Attach the signed DecisionReceipt contract and execution limits.'),
            new PipelineStageDefinition(KernelPipelineStage::Domain, 6, ['routing.intent', 'decision.receipt', 'envelope.id'], ['routing.domain', 'routing.flow'], 'Resolve target domain and flow from the receipt.'),
            new PipelineStageDefinition(KernelPipelineStage::Context, 7, ['envelope.id', 'routing.domain', 'routing.flow'], ['context.refs'], 'Plan context composition references.'),
            new PipelineStageDefinition(KernelPipelineStage::Policy, 8, ['decision.receipt', 'routing.domain', 'routing.flow', 'context.refs'], ['policy.effective'], 'Compile effective policy inputs.'),
            new PipelineStageDefinition(KernelPipelineStage::Runtime, 9, ['envelope.id', 'decision.receipt', 'policy.effective'], ['execution.runtime_plan'], 'Build dry-run runtime plan only.'),
            new PipelineStageDefinition(KernelPipelineStage::Gate, 10, ['execution.runtime_plan', 'policy.effective'], ['gate.results'], 'Plan gate evaluation and approval points.'),
            new PipelineStageDefinition(KernelPipelineStage::Repair, 11, ['decision.receipt', 'policy.effective', 'gate.results', 'execution.runtime_plan'], ['repair.plan'], 'Plan repair/escalation path without mutating runtime.'),
            new PipelineStageDefinition(KernelPipelineStage::Evidence, 12, ['envelope.id', 'gate.results', 'repair.plan'], ['evidence.refs'], 'Collect evidence placeholders for audit/replay.'),
            new PipelineStageDefinition(KernelPipelineStage::Learning, 13, ['evidence.refs', 'repair.plan'], ['learning.proposals'], 'Plan learning proposals without promotion.'),
            new PipelineStageDefinition(KernelPipelineStage::Output, 14, ['learning.proposals', 'evidence.refs'], ['output.render_plan'], 'Plan output rendering for the calling surface.'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function plan(PipelineInput $input): array
    {
        return $this->buildPlan($input, $this->pipelineId($input));
    }

    public function execute(PipelineInput $input): PipelineExecutionResult
    {
        $pipelineId = $this->pipelineId($input);
        $auditPlan = $this->buildPlan($input, $pipelineId);
        $stageResults = array_map(
            fn (PipelineStageDefinition $stage): PipelineStageResult => $this->stageResult($pipelineId, $stage),
            $this->stages(),
        );
        $evidenceRefs = [];
        $traceRefs = [];

        foreach ($stageResults as $result) {
            array_push($evidenceRefs, ...$result->evidenceRefs);
            array_push($traceRefs, ...$result->traceRefs);
        }

        return new PipelineExecutionResult(
            pipelineId: $pipelineId,
            schemaVersion: KernelPipelineContract::SCHEMA_VERSION,
            status: KernelPipelineContract::STATUS,
            dryRun: true,
            planHash: $this->planHash($auditPlan),
            stageResults: $stageResults,
            auditPlan: $auditPlan,
            evidenceRefs: array_values(array_unique($evidenceRefs)),
            traceRefs: array_values(array_unique($traceRefs)),
            providerExecutionAttempted: false,
            complianceReport: $this->complianceReport(),
        );
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,stage_count:int,stage_order:array<int,string>,slot_flow_valid:bool,provider_execution_allowed:bool,runtime_execution_allowed:bool}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $stages = $this->stages();
        $actualOrder = array_map(
            fn (PipelineStageDefinition $stage): string => $stage->stage->value,
            $stages,
        );

        if ($actualOrder !== KernelPipelineStage::orderedValues()) {
            $errors[] = 'Kernel pipeline stages are not in canonical order.';
        }

        $seenStages = [];
        $seenOrders = [];
        $writtenSlots = [];

        foreach ($stages as $index => $stage) {
            if (isset($seenStages[$stage->stage->value])) {
                $errors[] = "Stage [{$stage->stage->value}] is declared more than once.";
            }

            $seenStages[$stage->stage->value] = true;

            if (isset($seenOrders[$stage->order])) {
                $errors[] = "Stage order [{$stage->order}] is declared more than once.";
            }

            $seenOrders[$stage->order] = true;

            if ($stage->order !== $index + 1) {
                $errors[] = "Stage [{$stage->stage->value}] order must be ".($index + 1).".";
            }

            if (trim($stage->description) === '') {
                $errors[] = "Stage [{$stage->stage->value}] must declare an audit description.";
            }

            if ($stage->reads === []) {
                $errors[] = "Stage [{$stage->stage->value}] must declare read slots.";
            }

            if ($stage->writes === []) {
                $errors[] = "Stage [{$stage->stage->value}] must declare write slots.";
            }

            foreach ($this->duplicateValues($stage->reads) as $slot) {
                $errors[] = "Stage [{$stage->stage->value}] declares read slot [{$slot}] more than once.";
            }

            foreach ($this->duplicateValues($stage->writes) as $slot) {
                $errors[] = "Stage [{$stage->stage->value}] declares write slot [{$slot}] more than once.";
            }

            foreach (array_merge($stage->reads, $stage->writes) as $slot) {
                if (! $this->isValidSlot($slot)) {
                    $errors[] = "Stage [{$stage->stage->value}] uses invalid slot name [{$slot}].";
                }
            }

            foreach ($stage->writes as $slot) {
                if (isset($writtenSlots[$slot])) {
                    $errors[] = "Stage [{$stage->stage->value}] writes slot [{$slot}] already written by stage [{$writtenSlots[$slot]}].";
                }

                $writtenSlots[$slot] = $stage->stage->value;
            }
        }

        $slotFlowViolations = $this->slotFlowViolations($stages);

        foreach ($slotFlowViolations as $violation) {
            $errors[] = $violation;
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => ['scaffold pipeline does not migrate existing dev/forge/chat execution'],
            'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
            'stage_count' => count($stages),
            'stage_order' => $actualOrder,
            'stage_order_hash' => KernelPipelineContract::canonicalFlowHash(),
            'slot_flow_valid' => $slotFlowViolations === [],
            'provider_execution_allowed' => false,
            'runtime_execution_allowed' => false,
        ];
    }

    private function stageResult(string $pipelineId, PipelineStageDefinition $stage): PipelineStageResult
    {
        return new PipelineStageResult(
            stage: $stage->stage->value,
            status: 'planned',
            reads: $stage->reads,
            writes: $stage->writes,
            evidenceRefs: ["evidence://kernel-pipeline/{$pipelineId}/{$stage->stage->value}"],
            traceRefs: ["trace://kernel-pipeline/{$pipelineId}/{$stage->stage->value}"],
            metadata: [
                'order' => $stage->order,
                'description' => $stage->description,
                'scaffold' => true,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPlan(PipelineInput $input, string $pipelineId): array
    {
        return [
            'pipeline_id' => $pipelineId,
            'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
            'mode' => KernelPipelineContract::MODE,
            'status' => KernelPipelineContract::STATUS,
            'input' => $input->toAuditArray(),
            'input_fingerprint' => $input->auditFingerprint(),
            'canonical_flow' => KernelPipelineContract::canonicalFlow(),
            'canonical_flow_hash' => KernelPipelineContract::canonicalFlowHash(),
            'stage_count' => count($this->stages()),
            'stages' => array_map(
                fn (PipelineStageDefinition $stage): array => $stage->toArray(),
                $this->stages(),
            ),
            'slot_manifest' => $this->slotManifest($this->stages()),
            'execution_guards' => KernelPipelineContract::executionGuards($input->dryRun),
            'provider_execution_allowed' => false,
            'runtime_execution_allowed' => false,
        ];
    }

    private function pipelineId(PipelineInput $input): string
    {
        return 'pipe_'.substr(KernelPipelineContract::canonicalFlowHash(), 0, 8).'_'.substr($input->auditFingerprint(), 0, 16);
    }

    /**
     * @param  array<int,PipelineStageDefinition>  $stages
     * @return array<int,string>
     */
    private function slotFlowViolations(array $stages): array
    {
        $available = ['surface.raw_input' => true];
        $violations = [];

        foreach ($stages as $stage) {
            foreach ($stage->reads as $slot) {
                if (! isset($available[$slot])) {
                    $violations[] = "Stage [{$stage->stage->value}] reads slot [{$slot}] before any prior stage declares it.";
                }
            }

            foreach ($stage->writes as $slot) {
                $available[$slot] = true;
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,PipelineStageDefinition>  $stages
     * @return array{initial_slots:array<int,string>,read_slots:array<int,string>,write_slots:array<int,string>,terminal_slots:array<int,string>}
     */
    private function slotManifest(array $stages): array
    {
        $readSlots = [];
        $writeSlots = [];

        foreach ($stages as $stage) {
            array_push($readSlots, ...$stage->reads);
            array_push($writeSlots, ...$stage->writes);
        }

        $readSlots = array_values(array_unique($readSlots));
        $writeSlots = array_values(array_unique($writeSlots));
        sort($readSlots);
        sort($writeSlots);

        return [
            'initial_slots' => ['surface.raw_input'],
            'read_slots' => $readSlots,
            'write_slots' => $writeSlots,
            'terminal_slots' => ['output.render_plan'],
        ];
    }

    /**
     * @param  array<string,mixed>  $auditPlan
     */
    private function planHash(array $auditPlan): string
    {
        return hash('sha256', json_encode($auditPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function duplicateValues(array $values): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($values as $value) {
            if (isset($seen[$value])) {
                $duplicates[$value] = $value;
            }

            $seen[$value] = true;
        }

        return array_values($duplicates);
    }

    private function isValidSlot(string $slot): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $slot) === 1;
    }
}
