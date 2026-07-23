<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Services\Ai\Kernel\Failure\FailureDomain;

/**
 * Pure programming-repair contract/data helper family extracted VERBATIM from AiWorker (GOD-DEBULK D3 split).
 *
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
        return $this->firstArray([
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
        return $this->firstArray([
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
        return $this->firstArray([
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
        return $this->firstArray([
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
        $minimum = strtolower(trim((string) ($gateContract['minimum_gate'] ?? '')));

        return (bool) ($gateContract['evidence_required'] ?? false)
            || in_array($minimum, ['strict', 'release'], true);
    }

    /**
     * @param  array<string,mixed>  $toolContract
     */
    public function programmingRepairAllowsWorkspaceWrite(array $toolContract): bool
    {
        if ($toolContract === []) {
            return true;
        }

        $mode = strtolower(trim((string) ($toolContract['mode'] ?? '')));
        if ($mode === 'read_only') {
            return false;
        }

        return (bool) ($toolContract['workspace_write'] ?? in_array($mode, ['workspace_write', 'harness'], true));
    }

    /**
     * @param  array<int,mixed>  $candidates
     * @return array<string,mixed>
     */
    private function firstArray(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
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
        if (! is_string($previousStatus) || trim($previousStatus) === '') {
            return false;
        }

        return $this->programmingRepairStatusRank($currentStatus) < $this->programmingRepairStatusRank($previousStatus);
    }

    private function programmingRepairStatusRank(string $status): int
    {
        return match ($status) {
            'passed' => 4,
            'needs_review' => 3,
            'failed' => 2,
            'blocked' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public function programmingRepairLedgerPayload(array $quality, array $extra = []): array
    {
        return array_merge([
            'quality_status' => $quality['status'] ?? null,
            'quality_score' => $quality['score'] ?? null,
            'quality_decision' => $quality['decision'] ?? null,
            'diff_hash' => $quality['diff_hash'] ?? null,
            'test_command_hash' => is_string($quality['test_command'] ?? null) && $quality['test_command'] !== ''
                ? hash('sha256', $quality['test_command'])
                : null,
            'evidence_hash' => hash('sha256', json_encode($this->programmingRepairEvidenceProjection($quality), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    private function programmingRepairEvidenceProjection(array $quality): array
    {
        return [
            'status' => $quality['status'] ?? null,
            'score' => $quality['score'] ?? null,
            'decision' => $quality['decision'] ?? null,
            'diff_hash' => $quality['diff_hash'] ?? null,
            'test_status' => data_get($quality, 'tests.status'),
            'lint_status' => data_get($quality, 'lint.status'),
            'typecheck_status' => data_get($quality, 'typecheck.status'),
        ];
    }

    public function nativeProgrammingRepairFailureDomain(string $qualityStatus): FailureDomain
    {
        return match ($qualityStatus) {
            'failed', 'needs_review', 'blocked' => FailureDomain::GateFailed,
            default => FailureDomain::OutputInvalid,
        };
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return array<int,string>
     */
    public function nativeProgrammingRepairEvidenceRefs(AiJob $job, AiJobAttempt $attempt, array $quality): array
    {
        return array_values(array_filter([
            $job->trace_id ? 'trace://'.$job->trace_id : null,
            'ai-job://'.$job->id,
            'ai-attempt://'.$attempt->id,
            is_string($quality['diff_hash'] ?? null) && $quality['diff_hash'] !== ''
                ? 'diff-hash://'.$quality['diff_hash']
                : null,
        ]));
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
