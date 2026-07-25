<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Services\Ai\Kernel\Failure\FailureDomain;

/**
 * Pure programming-repair contract/data helper family extracted VERBATIM from AiWorker (GOD-DEBULK D3 split).
 *
 * Pure scalar/array contract helpers live in {@see AiWorkerProgrammingRepairContractsSupport}.
 * Facade AiWorker keeps same-signature delegators; call-site/signature/ctor scanner
 * pins stay on the facade. No scanner pin token moved with this family.
 */
class ProgrammingRepairSupportSection
{
    /**
     * @return array<string,mixed>
     */
    public function programmingProviderGateContract(AiJob $job): array
    {
        return AiWorkerProgrammingRepairContractsSupport::firstNonEmptyArray([
            data_get($job->payload, 'programming_policy_contracts.gates'),
            data_get($job->metadata, 'programming_policy_contracts.gates'),
            data_get($job->payload, 'programming_message_plan.policy_contracts.gates'),
            data_get($job->payload, 'programming_message_plan.policy_profile.policy_contracts.gates'),
            data_get($job->payload, 'programming_message_plan.policy_profile.effective_policy.operational_contracts.gates'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.gates'),
            data_get($job->trace?->metadata, 'programming_policy_contracts.gates'),
            data_get($job->trace?->metadata, 'programming_dispatch.policy_contracts.gates'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function programmingProviderToolContract(AiJob $job): array
    {
        return AiWorkerProgrammingRepairContractsSupport::firstNonEmptyArray([
            data_get($job->payload, 'programming_policy_contracts.tools'),
            data_get($job->metadata, 'programming_policy_contracts.tools'),
            data_get($job->payload, 'programming_message_plan.policy_contracts.tools'),
            data_get($job->payload, 'programming_message_plan.policy_profile.policy_contracts.tools'),
            data_get($job->payload, 'programming_message_plan.policy_profile.effective_policy.operational_contracts.tools'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.tools'),
            data_get($job->trace?->metadata, 'programming_policy_contracts.tools'),
            data_get($job->trace?->metadata, 'programming_dispatch.policy_contracts.tools'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $messagePlan
     * @return array<string,mixed>
     */
    public function programmingRepairGateContract(AiJob $job, array $repair, array $messagePlan): array
    {
        return AiWorkerProgrammingRepairContractsSupport::firstNonEmptyArray([
            data_get($repair, 'gate_contract'),
            data_get($messagePlan, 'execution_profile.gate_contract'),
            data_get($messagePlan, 'policy_contracts.gates'),
            data_get($messagePlan, 'policy_profile.policy_contracts.gates'),
            data_get($messagePlan, 'policy_profile.effective_policy.operational_contracts.gates'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.gates'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $messagePlan
     * @return array<string,mixed>
     */
    public function programmingRepairToolContract(AiJob $job, array $repair, array $messagePlan): array
    {
        return AiWorkerProgrammingRepairContractsSupport::firstNonEmptyArray([
            data_get($repair, 'tool_contract'),
            data_get($messagePlan, 'execution_profile.tool_contract'),
            data_get($messagePlan, 'policy_contracts.tools'),
            data_get($messagePlan, 'policy_profile.policy_contracts.tools'),
            data_get($messagePlan, 'policy_profile.effective_policy.operational_contracts.tools'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.tools'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $gateContract
     */
    public function programmingRepairGateRequiresEvidence(array $gateContract): bool
    {
        return AiWorkerProgrammingRepairContractsSupport::gateRequiresEvidence($gateContract);
    }

    /**
     * @param  array<string,mixed>  $toolContract
     */
    public function programmingRepairAllowsWorkspaceWrite(array $toolContract): bool
    {
        return AiWorkerProgrammingRepairContractsSupport::allowsWorkspaceWrite($toolContract);
    }

    public function programmingRepairWorkspace(AiJob $job): ?string
    {
        $workspace = data_get($job->payload, 'workspace_context.repo_root')
            ?: data_get($job->payload, 'workspace_context.workspace')
            ?: data_get($job->payload, 'programming_message_plan.workspace');

        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        return realpath($workspace) ?: $workspace;
    }

    public function programmingRepairTestCommand(AiJob $job): ?string
    {
        $command = data_get($job->payload, 'dev_execution_plan.operator_options.harness_overrides.test_command');

        return is_string($command) && trim($command) !== '' ? trim($command) : null;
    }

    public function programmingRepairQualityWorsened(string $currentStatus, mixed $previousStatus): bool
    {
        return AiWorkerProgrammingRepairContractsSupport::qualityWorsened($currentStatus, $previousStatus);
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public function programmingRepairLedgerPayload(array $quality, array $extra = []): array
    {
        return AiWorkerProgrammingRepairContractsSupport::ledgerPayload($quality, $extra);
    }

    public function nativeProgrammingRepairFailureDomain(string $qualityStatus): FailureDomain
    {
        return AiWorkerProgrammingRepairContractsSupport::failureDomain($qualityStatus);
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return array<int,string>
     */
    public function nativeProgrammingRepairEvidenceRefs(AiJob $job, AiJobAttempt $attempt, array $quality): array
    {
        return AiWorkerProgrammingRepairContractsSupport::evidenceRefs(
            $job->trace_id ? (string) $job->trace_id : null,
            (string) $job->id,
            (string) $attempt->id,
            $quality,
        );
    }

    /**
     * @param  array<string,mixed>  $repairUpdates
     * @param  array<string,mixed>  $quality
     * @return array<int,array<string,mixed>>
     */
    public function programmingRepairHistory(AiJob $job, array $repairUpdates, array $quality): array
    {
        $history = (array) data_get($job->payload, 'programming_repair_history', []);
        $currentIteration = (int) ($repairUpdates['current_iteration'] ?? data_get($job->payload, 'programming_repair.current_iteration', 1));
        $history[] = [
            'iteration' => max(1, $currentIteration),
            'status' => $quality['status'] ?? null,
            'diff_hash' => $quality['diff_hash'] ?? null,
            'recorded_at' => now()->toJSON(),
        ];

        return array_values(array_slice($history, -10));
    }
}
