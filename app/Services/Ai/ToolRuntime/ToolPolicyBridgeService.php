<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Policy\PermissionGateService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ToolPolicyBridgeService
{
    public function __construct(private readonly Container $container) {}

    /**
     * Bridge to Atlas Policy / Permission / Budget / Safety layer (Meta 3).
     * Tolerant: if the policy runtime tables/services are not available, return
     * a fallback decision so Tool Runtime stays operable in isolated environments.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function evaluate(AiToolDefinition $tool, array $context = []): array
    {
        $requestedAction = $tool->tool_id;
        $request = [
            'requested_action' => $requestedAction,
            'gate_type' => 'tool',
            'risk_level' => (string) $tool->risk_level,
            'domain_id' => $context['domain_id'] ?? null,
            'tool_id' => $tool->tool_id,
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'evidence_refs' => $context['evidence_refs'] ?? [],
        ];

        if (! $this->bridgeAvailable()) {
            return [
                'decision' => ToolRuntimeCanon::isHighRiskAuthority($tool->authority_group)
                    ? 'require_approval'
                    : 'allow',
                'source' => 'tool_runtime_fallback',
                'reason' => 'policy_runtime_unavailable',
                'receipt_hash' => MissionCanonicalHash::sha256($request),
                'gate_id' => null,
            ];
        }

        try {
            $gateService = $this->container->make(PermissionGateService::class);
            $gate = $gateService->evaluate($request);

            return [
                'decision' => (string) $gate->decision,
                'source' => 'atlas_policy_permission_gate',
                'reason' => 'policy_runtime',
                'receipt_hash' => (string) $gate->receipt_hash,
                'gate_id' => (string) $gate->id,
                'reasons' => $gate->reasons,
            ];
        } catch (Throwable $e) {
            return [
                'decision' => ToolRuntimeCanon::isHighRiskAuthority($tool->authority_group)
                    ? 'require_approval'
                    : 'allow',
                'source' => 'tool_runtime_fallback',
                'reason' => 'policy_runtime_exception:'.$e->getMessage(),
                'receipt_hash' => MissionCanonicalHash::sha256($request),
                'gate_id' => null,
            ];
        }
    }

    public function bridgeAvailable(): bool
    {
        return Schema::hasTable('ai_permission_gates') && Schema::hasTable('ai_policy_profiles');
    }
}
