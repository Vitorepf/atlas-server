<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use Symfony\Component\Process\Process;

final readonly class EliteExecutorKernelDevAdapter implements DevKernelExecutionPort
{
    public function __construct(
        private EliteExecutorKernel $kernel,
        private ?EngineeringModeExecutionOrderFactory $orders = null,
    ) {}

    public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome
    {
        $order = ($this->orders ?? new EngineeringModeExecutionOrderFactory)->make($this->orderData($run, $plan));

        return $this->kernel->execute($order);
    }

    /** @return array<string,mixed> */
    private function orderData(ConfirmedDevRun $run, DevPlan $plan): array
    {
        $intent = $run->intent;
        $runId = $plan->result->envelope->runId;
        $deliveryId = 'dev-'.$run->runHash;
        $authority = ['kind' => 'atlas_dev_confirmed_run', 'operator_id' => $run->operatorId, 'authority_hash' => $run->authorityHash];
        $order = [
            'run_hash' => $run->runHash,
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
            'provider_route' => ['provider' => 'atlas_kernel', 'model' => 'shared_quality_foundry'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'mutate' => false,
            'experiment_ref' => 'atlas-dev/'.$run->runHash,
            'idempotency_key' => 'atlas-dev:'.$run->runHash,
            'budget_posture' => 'unbounded_quality_first',
        ];
        if ($intent->marketDecisionHash !== null) {
            $order['market_decision_hash'] = $intent->marketDecisionHash;
        }

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
}
