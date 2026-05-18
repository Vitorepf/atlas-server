<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Models\AiToolHealthCheck;
use App\Models\AiToolInvocation;
use App\Models\AiToolPlan;
use App\Models\AiToolReceipt;
use App\Models\AiToolValidationRun;

class ToolRuntimeControlPlaneService
{
    public const SCHEMA = 'atlas.ai.tool_runtime.control_plane.v1';

    public function __construct(
        private readonly ToolPolicyBridgeService $policyBridge,
        private readonly ToolReceiptService $receipts,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $definitions = AiToolDefinition::query()->orderBy('tool_id')->get();
        $capabilitiesCount = collect();
        $definitionsPayload = $definitions->map(function (AiToolDefinition $tool) use ($capabilitiesCount): array {
            $latestHealth = $tool->healthChecks()->latest('created_at')->first();
            $capabilitiesCount->push($tool->capabilities()->count());

            return [
                'tool_id' => $tool->tool_id,
                'name' => $tool->name,
                'tool_type' => $tool->tool_type,
                'authority_group' => $tool->authority_group,
                'risk_level' => $tool->risk_level,
                'status' => $tool->status,
                'health_status' => $tool->health_status,
                'capability_count' => $tool->capabilities()->count(),
                'latest_health' => $latestHealth ? [
                    'status' => $latestHealth->status,
                    'health_hash' => $latestHealth->health_hash,
                ] : null,
            ];
        })->all();

        $plans = AiToolPlan::query()->latest('created_at')->limit(20)->get();
        $invocations = AiToolInvocation::query()->latest('created_at')->limit(20)->get();
        $receipts = AiToolReceipt::query()->latest('created_at')->limit(20)->get();
        $validations = AiToolValidationRun::query()->latest('created_at')->limit(20)->get();

        return [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'summary' => [
                'definitions' => $definitions->count(),
                'capabilities' => (int) $capabilitiesCount->sum(),
                'plans' => AiToolPlan::query()->count(),
                'invocations' => AiToolInvocation::query()->count(),
                'receipts' => AiToolReceipt::query()->count(),
                'health_checks' => AiToolHealthCheck::query()->count(),
                'validations' => AiToolValidationRun::query()->count(),
            ],
            'bridges' => [
                'policy_runtime_available' => $this->policyBridge->bridgeAvailable(),
                'evidence_runtime_available' => $this->receipts->bridgeAvailable(),
            ],
            'definitions' => $definitionsPayload,
            'plans' => $plans->map(static fn (AiToolPlan $p): array => [
                'id' => $p->id,
                'objective' => $p->objective,
                'status' => $p->status,
                'selected_count' => count((array) $p->tools_selected),
                'rejected_count' => count((array) $p->rejected_tools),
                'receipt_hash' => $p->receipt_hash,
            ])->all(),
            'invocations' => $invocations->map(static fn (AiToolInvocation $i): array => [
                'id' => $i->id,
                'tool_definition_id' => $i->tool_definition_id,
                'invocation_status' => $i->invocation_status,
                'input_hash' => $i->input_hash,
                'output_hash' => $i->output_hash,
                'policy_decision_ref' => $i->policy_decision_ref,
            ])->all(),
            'receipts' => $receipts->map(static fn (AiToolReceipt $r): array => [
                'id' => $r->id,
                'tool_invocation_id' => $r->tool_invocation_id,
                'status' => $r->status,
                'receipt_hash' => $r->receipt_hash,
            ])->all(),
            'validations' => $validations->map(static fn (AiToolValidationRun $v): array => [
                'id' => $v->id,
                'tool_definition_id' => $v->tool_definition_id,
                'validation_type' => $v->validation_type,
                'status' => $v->status,
                'validation_hash' => $v->validation_hash,
            ])->all(),
        ];
    }
}
