<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;

final class AtlasDevExecutionService implements DevPlanRunFacade
{
    public function __construct(
        private readonly AtlasDevFastPathOrchestrator $orchestrator,
        private readonly ?DevKernelExecutionPort $kernel = null,
    ) {}

    public function plan(DevIntent $intent): DevPlan
    {
        $result = $this->orchestrator->planOnly(
            'atlas_dev_execution', $intent->workspace, $intent->rawGoal, $intent->constraints,
            ['risk_class' => $intent->riskClass, 'duration_regime' => $intent->durationRegime, 'topology' => $intent->topology,
                'product_intent_hash' => $intent->productIntentHash, 'spec_hash' => $intent->specHash,
                'world_model_snapshot_hash' => $intent->worldModelSnapshotHash, 'authority_hash' => $intent->authorityHash],
        );

        return DevPlan::fromResult($intent, $result);
    }

    public function run(ConfirmedDevRun $run, ?DevPlan $planned = null): DevRunResult
    {
        // A caller that already completed the governed plan phase must pass
        // that exact plan through. Re-planning here would let discovery,
        // routing or blockers drift between approval and execution.
        $plan = $planned ?? $this->plan($run->intent);
        if (! $plan->isBoundTo($run->intent)) {
            return DevRunResult::blocked($run, 'dev_plan_intent_mismatch', $plan->planHash);
        }
        if ($plan->isBlocked()) {
            return DevRunResult::blocked($run, 'dev_plan_blocked', $plan->planHash, ['blockers' => $plan->result->blockers]);
        }
        if ($plan->requiresForgeHandoff() && ! $this->forgeHandoffContractAllows($run->intent)) {
            return DevRunResult::blocked($run, 'dev_forge_handoff_contract_mismatch', $plan->planHash, [
                'duration_regime' => $run->intent->durationRegime,
                'topology' => $run->intent->topology,
                'required_duration_regimes' => ['durable_task', 'obra', 'continuous'],
                'required_topologies' => ['workcell', 'DAG', 'portfolio'],
            ]);
        }
        if ($plan->requiresForgeHandoff()) {
            $handoff = [
                'kind' => $plan->result->routing->kind,
                'reasons' => $plan->result->routing->reasons,
                'blockers' => $plan->result->routing->blockers,
                'suggested_flow' => $plan->result->routing->suggestedFlow(),
            ];
            $handoff['idempotency_key'] = CanonicalKernelPayload::hash([
                'schema' => 'atlas.dev.forge_handoff.v1',
                'intent_hash' => $run->intent->intentHash,
                'plan_hash' => $plan->planHash,
            ]);
            $handoff['handoff_hash'] = CanonicalKernelPayload::hash([
                'run_hash' => $run->runHash,
                'plan_hash' => $plan->planHash,
                'handoff' => $handoff,
            ]);

            return DevRunResult::handedOff($run, $plan->planHash, ['handoff' => [
                ...$handoff,
            ]]);
        }

        try {
            $outcome = ($this->kernel ?? app(EliteExecutorKernelDevAdapter::class))->execute($run, $plan);
        } catch (\Throwable $exception) {
            return DevRunResult::blocked($run, 'shared_kernel_execution_failed', $plan->planHash, [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);
        }

        return DevRunResult::fromKernelOutcome($run, $plan->planHash, $outcome);
    }

    private function forgeHandoffContractAllows(DevIntent $intent): bool
    {
        return in_array($intent->durationRegime, ['durable_task', 'obra', 'continuous'], true)
            && in_array($intent->topology, ['workcell', 'DAG', 'portfolio'], true);
    }
}
