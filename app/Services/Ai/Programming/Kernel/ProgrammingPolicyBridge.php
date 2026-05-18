<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Policy\PermissionGateService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgrammingPolicyBridge
{
    public function __construct(private readonly Container $container) {}

    public function bridgeAvailable(): bool
    {
        return Schema::hasTable('ai_permission_gates') && Schema::hasTable('ai_policy_profiles');
    }

    /**
     * Evaluate a programming action against Atlas Policy / Permission / Budget / Safety (Meta 3).
     * Tolerant: if the policy runtime is missing, returns a safe-default decision and notes that
     * the bridge was unavailable. High-risk action keywords always require approval in fallback.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function evaluate(string $requestedAction, AiMission $mission, array $context = []): array
    {
        $riskKeywords = ProgrammingDomainKernelCanon::detectHighRiskActions($requestedAction.' '.$mission->raw_prompt);
        $riskLevel = $context['risk_level'] ?? ($riskKeywords !== [] ? 'high' : 'low');

        $request = [
            'requested_action' => $requestedAction,
            'gate_type' => 'tool',
            'risk_level' => $riskLevel,
            'domain_id' => ProgrammingDomainKernelCanon::DOMAIN_ID,
            'tool_id' => $context['tool_id'] ?? null,
            'mission_id' => $mission->id,
            'work_order_id' => $context['work_order_id'] ?? null,
            'evidence_refs' => $context['evidence_refs'] ?? [],
        ];

        if (! $this->bridgeAvailable()) {
            $decision = $riskKeywords !== [] ? 'require_approval' : 'allow';

            return [
                'decision' => $decision,
                'source' => 'programming_policy_fallback',
                'reason' => 'policy_runtime_unavailable',
                'risk_keywords' => $riskKeywords,
                'gate_id' => null,
                'receipt_hash' => MissionCanonicalHash::sha256($request),
            ];
        }

        try {
            $gate = $this->container->make(PermissionGateService::class)->evaluate($request);

            return [
                'decision' => (string) $gate->decision,
                'source' => 'atlas_policy_permission_gate',
                'reason' => 'policy_runtime',
                'risk_keywords' => $riskKeywords,
                'gate_id' => (string) $gate->id,
                'receipt_hash' => (string) $gate->receipt_hash,
                'reasons' => $gate->reasons,
            ];
        } catch (Throwable $e) {
            return [
                'decision' => $riskKeywords !== [] ? 'require_approval' : 'allow',
                'source' => 'programming_policy_fallback',
                'reason' => 'policy_runtime_exception:'.$e->getMessage(),
                'risk_keywords' => $riskKeywords,
                'gate_id' => null,
                'receipt_hash' => MissionCanonicalHash::sha256($request),
            ];
        }
    }

    public function isBlocked(string $decision): bool
    {
        return in_array($decision, ['deny', 'blocked'], true);
    }
}
