<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;

final class AtlasDevExecutionService
{
    public function __construct(private readonly AtlasDevFastPathOrchestrator $orchestrator) {}

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

    public function run(ConfirmedDevRun $run): DevRunResult
    {
        $plan = $this->plan($run->intent);
        if ($plan->isBlocked()) {
            return DevRunResult::blocked($run, 'dev_plan_blocked', $plan->planHash, ['blockers' => $plan->result->blockers]);
        }
        if ($plan->requiresForgeHandoff()) {
            return DevRunResult::handedOff($run, $plan->planHash, ['handoff' => [
                'kind' => $plan->result->routing->kind,
                'reasons' => $plan->result->routing->reasons,
                'blockers' => $plan->result->routing->blockers,
                'suggested_flow' => $plan->result->routing->suggestedFlow(),
            ]]);
        }

        // The existing fast-path provider executor is intentionally not called here.
        // Until the shared Kernel execution adapter is wired, Dev remains fail-closed
        // after planning instead of creating a second mutative executor.
        return DevRunResult::blocked($run, 'shared_kernel_execution_adapter_pending', $plan->planHash);
    }
}
