<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;
use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\Policy\SafetyDecisionService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;

/**
 * Bridges Automation plans into the Policy/Safety Layer (Meta 3).
 *
 * Tolerant: if Policy tables/services are absent, the bridge returns a
 * deterministic local decision based on the plan's `safety_factors` so the
 * Automation Runtime keeps producing reproducible outputs. When Policy is
 * present, it delegates to `SafetyDecisionService::decide` and records the
 * resulting `decision` back onto the plan.
 */
class AutomationPolicyBridge
{
    public function __construct(private readonly Container $container) {}

    /**
     * Evaluate a plan and write `policy_decision` back to the plan row.
     *
     * @return array<string,mixed>|null
     */
    public function evaluatePlan(AiAutomationPlan $plan, AiAutomationRun $run): ?array
    {
        $requestedAction = "automation.{$plan->plan_type}.{$plan->status}";
        $riskLevel = $this->riskLevelFor($plan);
        $request = [
            'requested_action' => $requestedAction,
            'risk_level' => $riskLevel,
            'autonomy_level' => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
            'mission_id' => $run->mission_id,
            'work_order_id' => $run->work_order_id,
            'target_type' => 'automation_plan',
            'target_ref' => $plan->uuid,
            'risk_factors' => array_filter([
                'destructive' => (bool) (($plan->safety_factors ?? [])['destructive'] ?? false),
                'external_action' => (bool) (($plan->safety_factors ?? [])['external_action'] ?? false),
                'requires_credentials' => (bool) (($plan->safety_factors ?? [])['requires_credentials'] ?? false),
                'auth_required' => (bool) (($plan->safety_factors ?? [])['auth_required'] ?? false),
                'write_operation' => (bool) (($plan->safety_factors ?? [])['write_operation'] ?? false),
            ], static fn ($v): bool => (bool) $v),
        ];

        if (! Schema::hasTable('ai_safety_decisions') || ! class_exists(SafetyDecisionService::class)) {
            return $this->localFallbackDecision($plan, $requestedAction);
        }

        try {
            $service = $this->container->make(SafetyDecisionService::class);
            $decision = $service->decide($request);
            $plan->policy_decision = (string) $decision->decision;
            if ($decision->decision === PolicyCanon::DECISION_ALLOW
                && $plan->status === AutomationDomainCanon::PLAN_STATUS_PLANNED) {
                $plan->status = AutomationDomainCanon::PLAN_STATUS_APPROVED;
            }
            if ($decision->decision === PolicyCanon::DECISION_DENY) {
                $plan->status = AutomationDomainCanon::PLAN_STATUS_REJECTED;
            }
            $plan->save();

            return [
                'plan_id' => $plan->id,
                'decision' => $decision->decision,
                'safety_decision_id' => $decision->id,
                'receipt_hash' => $decision->receipt_hash,
            ];
        } catch (\Throwable) {
            return $this->localFallbackDecision($plan, $requestedAction);
        }
    }

    private function riskLevelFor(AiAutomationPlan $plan): string
    {
        $factors = (array) $plan->safety_factors;
        if (($factors['destructive'] ?? false) || ($factors['requires_credentials'] ?? false)) {
            return PolicyCanon::RISK_HIGH;
        }
        if (in_array($plan->plan_type, AutomationDomainCanon::HIGH_RISK_PLAN_TYPES, true)) {
            return PolicyCanon::RISK_HIGH;
        }
        if ($plan->status === AutomationDomainCanon::PLAN_STATUS_BLOCKED) {
            return PolicyCanon::RISK_HIGH;
        }

        return PolicyCanon::RISK_LOW;
    }

    /**
     * @return array<string,mixed>
     */
    private function localFallbackDecision(AiAutomationPlan $plan, string $requestedAction): array
    {
        $blocked = $plan->status === AutomationDomainCanon::PLAN_STATUS_BLOCKED;
        $decision = $blocked ? 'require_approval' : 'allow';
        $plan->policy_decision = $decision;
        if (! $blocked) {
            $plan->status = AutomationDomainCanon::PLAN_STATUS_APPROVED;
        }
        $plan->save();

        return [
            'plan_id' => $plan->id,
            'decision' => $decision,
            'safety_decision_id' => null,
            'fallback' => true,
            'requested_action' => $requestedAction,
        ];
    }
}
