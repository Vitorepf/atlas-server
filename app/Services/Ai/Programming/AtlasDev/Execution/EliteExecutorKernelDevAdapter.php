<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use Symfony\Component\Process\Process;

final readonly class EliteExecutorKernelDevAdapter implements DevKernelExecutionPort
{
    public function __construct(private EliteExecutorKernel $kernel) {}

    public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome
    {
        $order = ExecutionOrder::fromArray($this->orderData($run, $plan));

        return $this->kernel->execute($order);
    }

    /** @return array<string,mixed> */
    private function orderData(ConfirmedDevRun $run, DevPlan $plan): array
    {
        $intent = $run->intent;
        $runId = $plan->result->envelope->runId;
        $deliveryId = 'dev-'.$run->runHash;
        $roles = [];
        $roleEvents = [];
        foreach (EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $roles[$role] = [
                'depth' => $this->depthForRisk($intent->riskClass, $role),
                'risk_band' => $intent->riskClass,
                'independent_context' => in_array($role, ['qa_testing', 'evidence_audit', 'final_certification'], true),
            ];
            $roleEvents[$role] = 'dev-role-'.$run->runHash.'-'.$role;
        }

        $authority = ['kind' => 'atlas_dev_confirmed_run', 'operator_id' => $run->operatorId, 'authority_hash' => $run->authorityHash];
        $order = [
            'schema_version' => 'atlas.execution_order.v2',
            'run_id' => $runId,
            'delivery_id' => $deliveryId,
            'mode' => 'dev',
            'risk_class' => $intent->riskClass,
            'complexity_band' => 'C1',
            'duration_regime' => $intent->durationRegime,
            'work_topology' => $intent->topology,
            'product_intent_verdict_hash' => $intent->productIntentHash,
            'spec_hash' => $intent->specHash,
            'world_model_snapshot_hash' => $intent->worldModelSnapshotHash,
            'workspace' => $intent->workspace,
            'base_commit' => $this->baseCommit($intent->workspace),
            'allowed_scope' => $plan->result->miniSpec->allowedFiles !== [] ? $plan->result->miniSpec->allowedFiles : ['README.md'],
            'forbidden_scope' => $plan->result->miniSpec->forbiddenFiles,
            'authority_envelope' => $authority,
            'decision_receipt' => ['decision_event_id' => 'dev-decision-'.$run->runHash],
            'operator_contract' => ['presence' => 'confirmed', 'operator_id' => $run->operatorId],
            'role_roster' => $roles,
            'provider_route' => ['provider' => 'atlas_kernel', 'model' => 'shared_quality_foundry'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => [
                'acceptance_event_id' => 'dev-acceptance-'.$run->runHash,
                'role_disposition_event_ids' => $roleEvents,
            ],
            'release_policy' => ['kind' => 'no_release_read_only'],
            'rollback_policy' => ['kind' => 'not_applicable_read_only'],
            'outcome_policy' => ['windows' => EngineeringOutcome::WINDOWS],
            'experiment_ref' => 'atlas-dev/'.$run->runHash,
            'idempotency_key' => 'atlas-dev:'.$run->runHash,
            'budget_posture' => 'unbounded_quality_first',
        ];
        if ($intent->marketDecisionHash !== null) $order['market_decision_hash'] = $intent->marketDecisionHash;

        return $order;
    }

    private function baseCommit(string $workspace): string
    {
        $process = new Process(['git', '-C', $workspace, 'rev-parse', 'HEAD']);
        $process->run();
        $commit = trim($process->getOutput());
        if (! $process->isSuccessful() || preg_match('/^[a-f0-9]{40,64}$/', $commit) !== 1) {
            throw new \RuntimeException('dev_kernel_base_commit_unavailable');
        }

        return $commit;
    }

    private function depthForRisk(string $riskClass, string $role): string
    {
        $risk = (int) ltrim(strtoupper(trim($riskClass)), 'R');

        return match (true) {
            $risk <= 1 => 'minimal_evidence',
            $risk <= 3 => 'light_independent_review',
            $risk <= 5 => 'standard_contract_integration',
            $risk <= 7 => 'multi_verifier_regression_compatibility',
            $risk <= 9 && in_array($role, ['appsec_privacy', 'performance_resilience', 'devops_sre', 'evidence_audit'], true) => 'security_mutation_property_chaos_rollback',
            $risk <= 9 => 'deep_independent_regression',
            default => 'competing_candidates_different_family_disaster_drill',
        };
    }
}
