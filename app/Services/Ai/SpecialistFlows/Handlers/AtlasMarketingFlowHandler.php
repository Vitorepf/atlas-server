<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Marketing specialist flow.
 *
 * Plans campaigns, copy, metrics. NEVER publishes, NEVER spends from a real
 * budget, NEVER sends a real message to a real audience without explicit
 * operator approval (`marketing_publish_approved` / `marketing_spend_approved`).
 */
final class AtlasMarketingFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_MARKETING;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function extend(array $base, array $router, array $payload): array
    {
        $publishApproved = $this->operatorApproved($payload, 'marketing_publish_approved');
        $spendApproved = $this->operatorApproved($payload, 'marketing_spend_approved');

        return array_merge($base, [
            'execution_mode' => 'campaign_plan',
            'side_effect_policy' => ($publishApproved || $spendApproved)
                ? SpecialistFlowsCanon::SIDE_EFFECT_REQUIRES_APPROVAL
                : SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN,
            'publish_authorized' => $publishApproved,
            'spend_authorized' => $spendApproved,
            'approval_gate' => [
                'publish_requires_operator_approval' => true,
                'spend_requires_operator_approval' => true,
            ],
            'marketing_invariants' => [
                'no_publish_without_operator_approval',
                'no_spend_without_operator_approval',
                'no_fabricated_metrics',
                'declare_audience_assumptions_explicitly',
            ],
        ]);
    }
}
