<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;
use Illuminate\Support\Str;

/**
 * Generic Automation plan persistence. Specialised planners
 * (Browser/Api/Terminal/ToolBuilder) delegate here so every plan row gets the
 * same canonical hash + status discipline.
 */
class AutomationPlanService
{
    /**
     * @param  array<string,mixed>  $args
     */
    public function record(AiAutomationRun $run, array $args): AiAutomationPlan
    {
        $planType = (string) ($args['plan_type'] ?? '');
        if (! in_array($planType, AutomationDomainCanon::PLAN_TYPES, true)) {
            throw AutomationDomainException::invalidPlanType($planType);
        }
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw AutomationDomainException::missingField('title');
        }
        if (! isset($args['payload']) || ! is_array($args['payload'])) {
            throw AutomationDomainException::missingField('payload');
        }

        $status = (string) ($args['status'] ?? AutomationDomainCanon::PLAN_STATUS_PLANNED);
        if (! in_array($status, AutomationDomainCanon::PLAN_STATUSES, true)) {
            throw AutomationDomainException::missingField('status');
        }

        $payload = $args['payload'];
        $safetyFactors = (array) ($args['safety_factors'] ?? []);
        $rollback = isset($args['rollback']) && is_array($args['rollback']) ? $args['rollback'] : null;

        // Hash captures the plan CONTENT, not the run that emitted it, so
        // the same plan body produces a stable hash across runs and across
        // machines (canonical sha256). Identity is content-addressable;
        // `automation_run_id` lives on the row for navigation only.
        $hashInput = [
            'plan_type' => $planType,
            'title' => $title,
            'payload' => $payload,
            'safety_factors' => $safetyFactors,
            'rollback' => $rollback,
        ];

        return AiAutomationPlan::query()->create([
            'uuid' => (string) Str::uuid(),
            'automation_run_id' => $run->id,
            'plan_type' => $planType,
            'title' => $title,
            'summary' => $args['summary'] ?? null,
            'payload' => $payload,
            'safety_factors' => $safetyFactors !== [] ? $safetyFactors : null,
            'rollback' => $rollback,
            'status' => $status,
            'policy_decision' => $args['policy_decision'] ?? null,
            'plan_hash' => AutomationCanonicalHash::sha256($hashInput),
        ]);
    }
}
