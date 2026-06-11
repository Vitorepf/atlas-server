<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasFlowRoute;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Str;

class FlowRouterService
{
    /**
     * Decide the specialist flow_id and runtime mode for a router decision.
     */
    public function decideFlow(AiAtlasRouterDecision $decision, AiAtlasIntentClassification $intent): AiAtlasFlowRoute
    {
        $flowId = RouterRuntimeCanon::INTENT_TO_FLOW[$intent->intent_type]
            ?? 'atlas_conversation';
        $runtimeMode = $decision->routing_mode;

        if ($runtimeMode === RouterRuntimeCanon::MODE_FORGE) {
            $flowId = 'atlas_forge';
        }

        $expectedCapabilities = $this->expectedCapabilities($decision->primary_domain, $intent->intent_type);
        $requiredGates = $this->requiredGates($decision);
        $fallbackFlows = $this->fallbackFlows($flowId, $decision->primary_domain);

        return AiAtlasFlowRoute::query()->create([
            'uuid' => (string) Str::uuid(),
            'router_decision_id' => $decision->id,
            'flow_id' => $flowId,
            'flow_profile' => $this->flowProfile($decision->primary_domain),
            'runtime_mode' => $runtimeMode,
            'expected_capabilities' => $expectedCapabilities,
            'required_gates' => $requiredGates,
            'fallback_flows' => $fallbackFlows,
            'status' => 'ready',
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function expectedCapabilities(string $domain, string $intentType): array
    {
        $map = [
            'programming' => ['code.read', 'code.edit', 'tests.run'],
            'research' => ['source.fetch', 'source.quality', 'synthesis'],
            'finance' => ['research', 'valuation', 'simulation'],
            'marketing' => ['draft', 'analysis', 'plan'],
            'cyber' => ['defensive.review', 'authorization.check'],
            'automation' => ['browser.read', 'api.read', 'evidence.capture'],
            'strategy' => ['analysis', 'plan'],
            'personal_development' => ['plan', 'review'],
            'explain' => ['read', 'doc.lookup'],
            'conversation' => ['conversation'],
        ];
        $caps = $map[$domain] ?? ['conversation'];
        if ($intentType === RouterRuntimeCanon::INTENT_DEBUG) {
            $caps[] = 'logs.read';
        }
        if ($intentType === RouterRuntimeCanon::INTENT_REVIEW) {
            $caps[] = 'review.audit';
        }

        return AiStringListNormalizer::uniqueStrings($caps);
    }

    /**
     * @return array<int,string>
     */
    private function requiredGates(AiAtlasRouterDecision $decision): array
    {
        $gates = [];
        if ($decision->policy_required) {
            $gates[] = 'policy.gate';
        }
        if ($decision->evidence_required) {
            $gates[] = 'evidence.gate';
        }
        if ($decision->tool_plan_required) {
            $gates[] = 'tool_plan.gate';
        }
        if ($decision->routing_mode === RouterRuntimeCanon::MODE_BLOCKED) {
            $gates[] = 'blocked.gate';
        }

        return AiStringListNormalizer::uniqueStrings($gates);
    }

    /**
     * @return array<int,string>
     */
    private function fallbackFlows(string $flowId, string $domain): array
    {
        $fallbacks = [];
        if ($flowId !== 'atlas_conversation') {
            $fallbacks[] = 'atlas_conversation';
        }
        if ($flowId !== 'atlas_plan' && in_array($domain, ['research', 'finance', 'marketing', 'cyber', 'strategy', 'personal_development', 'automation'], true)) {
            $fallbacks[] = 'atlas_plan';
        }
        if ($flowId === 'atlas_dev') {
            $fallbacks[] = 'atlas_explain';
        }

        return AiStringListNormalizer::uniqueStrings($fallbacks);
    }

    private function flowProfile(string $domain): string
    {
        return match ($domain) {
            'programming' => 'programming.default',
            'research' => 'research.deep',
            'finance' => 'finance.research_only',
            'marketing' => 'marketing.draft_review',
            'cyber' => 'cyber.defensive_only',
            'automation' => 'automation.read_only',
            'strategy' => 'strategy.plan',
            'personal_development' => 'personal_development.plan',
            'explain' => 'explain.read_only',
            'conversation' => 'conversation.default',
            default => 'general.default',
        };
    }
}
