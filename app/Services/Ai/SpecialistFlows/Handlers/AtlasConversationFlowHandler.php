<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Conversation specialist flow — canonical fallback. Stays read-only,
 * never assumes workspace access, never pretends to have edited code.
 */
final class AtlasConversationFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_CONVERSATION;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function extend(array $base, array $router, array $payload): array
    {
        return array_merge($base, [
            'execution_mode' => 'conversation',
            'side_effect_policy' => SpecialistFlowsCanon::SIDE_EFFECT_READ_ONLY,
            'workspace_required' => false,
            'conversation_invariants' => [
                'no_workspace_assumption',
                'no_silent_flow_change',
                'no_provider_call_promised_inside_contract',
            ],
        ]);
    }
}
