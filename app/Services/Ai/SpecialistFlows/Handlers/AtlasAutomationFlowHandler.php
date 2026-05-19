<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Automation specialist flow.
 *
 * Strict plan-vs-execute separation: the default `execution_mode` is `plan`
 * (no side effects). Switching to `execute` requires an explicit operator
 * approval key (`automation_execute_approved`); even then, every side effect
 * carries the canonical forbidden_actions guard.
 */
final class AtlasAutomationFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_AUTOMATION;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function extend(array $base, array $router, array $payload): array
    {
        $executeApproved = $this->operatorApproved($payload, 'automation_execute_approved');
        $stage = $executeApproved ? 'execute' : 'plan';

        return array_merge($base, [
            'execution_mode' => $stage === 'execute' ? 'workflow_execute_with_approval' : 'workflow_plan_only',
            'stage' => $stage,
            'side_effect_policy' => $executeApproved
                ? SpecialistFlowsCanon::SIDE_EFFECT_REQUIRES_APPROVAL
                : SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN,
            'execute_authorized' => $executeApproved,
            'plan_vs_execute' => [
                'default_stage' => 'plan',
                'execute_requires_operator_approval' => true,
                'rollback_plan_required' => true,
            ],
            'automation_invariants' => [
                'plan_before_execute',
                'no_destructive_action_without_operator_approval',
                'rollback_plan_mandatory_when_executing',
                'no_silent_scope_widening',
            ],
        ]);
    }
}
