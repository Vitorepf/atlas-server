<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;

/**
 * Plans a new internal tool blueprint when `build_internal` is the right
 * decision. NEVER writes code or executes anything. Produces a structured
 * blueprint (capability_id, interface, allowed_tools, evidence_required)
 * ready for the operator + Tool Runtime to register.
 */
class ToolBuilderPlanService
{
    public function __construct(private readonly AutomationPlanService $plans) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function plan(AiAutomationRun $run, array $args): AiAutomationPlan
    {
        $toolId = (string) ($args['tool_id'] ?? '');
        if ($toolId === '') {
            throw AutomationDomainException::missingField('tool_id');
        }
        if (! preg_match('/^[a-z][a-z0-9_.\-]{1,79}$/', $toolId)) {
            throw new \InvalidArgumentException("tool_id [{$toolId}] must be lowercase ascii (a-z0-9_.-).");
        }

        $name = (string) ($args['name'] ?? '');
        if ($name === '') {
            throw AutomationDomainException::missingField('name');
        }
        $purpose = (string) ($args['purpose'] ?? '');
        if ($purpose === '') {
            throw AutomationDomainException::missingField('purpose');
        }

        $authorityGroup = (string) ($args['authority_group'] ?? 'workspace_safe');
        $riskLevel = (string) ($args['risk_level'] ?? 'medium');

        $safetyFactors = [
            'risk_level' => $riskLevel,
            'authority_group' => $authorityGroup,
            'destructive' => (bool) ($args['destructive'] ?? false),
            'external_action' => (bool) ($args['external_action'] ?? false),
            'requires_credentials' => (bool) ($args['requires_credentials'] ?? false),
        ];

        $blueprint = [
            'tool_id' => $toolId,
            'name' => $name,
            'purpose' => $purpose,
            'capability_id' => (string) ($args['capability_id'] ?? "{$toolId}.run"),
            'input_schema' => $args['input_schema'] ?? ['type' => 'object'],
            'output_schema' => $args['output_schema'] ?? ['type' => 'object'],
            'allowed_tools' => array_values((array) ($args['allowed_tools'] ?? [])),
            'evidence_required' => array_values((array) ($args['evidence_required'] ?? ['command', 'receipt'])),
            'required_policy_gates' => array_values((array) ($args['required_policy_gates'] ?? ['scope_authorized'])),
            'rollback_strategy' => $args['rollback_strategy'] ?? 'idempotent_replay',
            'tests' => array_values((array) ($args['tests'] ?? ['unit', 'feature'])),
        ];

        // High-risk or external-action tools default to blocked until Policy
        // approves the blueprint.
        $highRisk = in_array($riskLevel, ['high', 'critical'], true)
            || $safetyFactors['external_action']
            || $safetyFactors['destructive']
            || $safetyFactors['requires_credentials'];

        $status = $highRisk
            ? AutomationDomainCanon::PLAN_STATUS_BLOCKED
            : AutomationDomainCanon::PLAN_STATUS_PLANNED;

        return $this->plans->record($run, [
            'plan_type' => AutomationDomainCanon::PLAN_TOOL_BUILDER,
            'title' => "Tool blueprint: {$name}",
            'summary' => $purpose,
            'payload' => $blueprint,
            'safety_factors' => $safetyFactors,
            'rollback' => [
                'strategy' => 'unregister_tool',
                'cleanup' => ['ai_tool_definitions', 'ai_tool_capabilities'],
            ],
            'status' => $status,
        ]);
    }
}
