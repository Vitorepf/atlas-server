<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class DomainRouterService
{
    public function __construct(
        private readonly ObjectiveRoutingService $objectives,
        private readonly RouterPolicyBridgeService $policyBridge,
        private readonly RouterEvidenceBridgeService $evidenceBridge,
    ) {}

    /**
     * Persist a router decision for the given intent. Decides primary domain,
     * secondary domains, routing_mode and the policy/evidence/tool flags.
     */
    public function route(AiAtlasIntentClassification $intent): AiAtlasRouterDecision
    {
        $objective = $this->objectives->resolveObjective($intent);
        $primaryDomain = $objective['derived_domain'];
        $secondaryDomains = $this->resolveSecondaryDomains($intent, $primaryDomain);
        $routingMode = $this->resolveRoutingMode($intent, $primaryDomain, $secondaryDomains);

        $policyRequired = $this->policyBridge->isPolicyRequired($primaryDomain, $intent);
        $evidenceRequired = $this->evidenceBridge->isEvidenceRequired($primaryDomain, $intent);
        $toolPlanRequired = in_array(
            $primaryDomain,
            RouterRuntimeCanon::TOOL_PLAN_REQUIRED_DOMAINS,
            true,
        );

        if ($routingMode === RouterRuntimeCanon::MODE_BLOCKED) {
            $policyRequired = true;
        }

        $reason = $this->buildReason(
            $intent,
            $primaryDomain,
            $secondaryDomains,
            $routingMode,
            $policyRequired,
            $evidenceRequired,
            $toolPlanRequired,
        );

        $decision = AiAtlasRouterDecision::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $intent->mission_id,
            'work_order_id' => null,
            'intent_classification_id' => $intent->id,
            'primary_domain' => $primaryDomain,
            'secondary_domains' => $secondaryDomains,
            'routing_mode' => $routingMode,
            'decision_reason' => $reason,
            'policy_required' => $policyRequired,
            'evidence_required' => $evidenceRequired,
            'tool_plan_required' => $toolPlanRequired,
            'status' => 'routed',
            'receipt_hash' => null,
        ]);

        $decision->receipt_hash = MissionCanonicalHash::sha256([
            'router_decision_uuid' => $decision->uuid,
            'intent_type' => $intent->intent_type,
            'primary_domain' => $primaryDomain,
            'secondary_domains' => $secondaryDomains,
            'routing_mode' => $routingMode,
            'policy_required' => $policyRequired,
            'evidence_required' => $evidenceRequired,
            'tool_plan_required' => $toolPlanRequired,
            'reason' => $reason,
        ]);
        $decision->save();

        return $decision;
    }

    /**
     * @return array<int,string>
     */
    private function resolveSecondaryDomains(AiAtlasIntentClassification $intent, string $primary): array
    {
        $secondary = [];
        $matched = (array) ($intent->signals['matched_keywords'] ?? []);
        $normalized = (string) ($intent->normalized_intent ?? '');

        if ($primary === 'programming' && (str_contains($normalized, 'segurança') || str_contains($normalized, 'security') || str_contains($normalized, 'auth'))) {
            $secondary[] = 'cyber';
        }
        if ($primary !== 'research' && (str_contains($normalized, 'fontes') || str_contains($normalized, 'pesquise'))) {
            $secondary[] = 'research';
        }
        if ($primary === 'marketing' && (str_contains($normalized, 'orçamento') || str_contains($normalized, 'orcamento') || str_contains($normalized, 'budget'))) {
            $secondary[] = 'finance';
        }
        if ($primary === 'automation' && (str_contains($normalized, 'extrai') || str_contains($normalized, 'analise'))) {
            $secondary[] = 'research';
        }
        if (count($matched) >= 3 && ! in_array('strategy', $secondary, true) && $primary !== 'strategy') {
            $secondary[] = 'strategy';
        }

        return array_values(array_unique($secondary));
    }

    /**
     * @param  array<int,string>  $secondary
     */
    private function resolveRoutingMode(
        AiAtlasIntentClassification $intent,
        string $primary,
        array $secondary,
    ): string {
        if ($intent->intent_type === RouterRuntimeCanon::INTENT_UNKNOWN) {
            return RouterRuntimeCanon::MODE_STANDARD;
        }
        if ($primary === 'cyber') {
            return RouterRuntimeCanon::MODE_DEEP;
        }
        if ($primary === 'finance') {
            return RouterRuntimeCanon::MODE_DEEP;
        }
        if (in_array($intent->intent_type, [
            RouterRuntimeCanon::INTENT_EXPLAIN,
            RouterRuntimeCanon::INTENT_CONVERSATION,
        ], true)) {
            return RouterRuntimeCanon::MODE_LIGHTWEIGHT;
        }
        if (in_array($intent->intent_type, [
            RouterRuntimeCanon::INTENT_RESEARCH,
            RouterRuntimeCanon::INTENT_PLAN,
            RouterRuntimeCanon::INTENT_STRATEGY,
        ], true)) {
            return RouterRuntimeCanon::MODE_DEEP;
        }
        if (count($secondary) >= 2) {
            return RouterRuntimeCanon::MODE_DEEP;
        }

        return RouterRuntimeCanon::MODE_STANDARD;
    }

    /**
     * @param  array<int,string>  $secondary
     * @return array<string,mixed>
     */
    private function buildReason(
        AiAtlasIntentClassification $intent,
        string $primary,
        array $secondary,
        string $routingMode,
        bool $policyRequired,
        bool $evidenceRequired,
        bool $toolPlanRequired,
    ): array {
        $reasons = [];
        $reasons[] = "intent_type:{$intent->intent_type}";
        $reasons[] = "primary_domain:{$primary}";
        if ($secondary !== []) {
            $reasons[] = 'secondary_domains:'.implode(',', $secondary);
        }
        $reasons[] = "routing_mode:{$routingMode}";
        if ($policyRequired) {
            $reasons[] = 'policy_gate_required';
        }
        if ($evidenceRequired) {
            $reasons[] = 'evidence_gate_required';
        }
        if ($toolPlanRequired) {
            $reasons[] = 'tool_plan_required';
        }
        if (($intent->ambiguity_score ?? 0.0) > 0.8) {
            $reasons[] = 'clarification_needed:high_ambiguity';
        }

        return [
            'reasons' => $reasons,
            'intent_uuid' => $intent->uuid,
            'confidence' => (float) ($intent->confidence ?? 0.0),
            'ambiguity_score' => (float) ($intent->ambiguity_score ?? 0.0),
            'matched_keywords' => (array) ($intent->signals['matched_keywords'] ?? []),
        ];
    }
}
