<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Research specialist flow — source-grounded answers, claims table,
 * uncertainty declaration, never claims unsourced facts.
 */
final class AtlasResearchFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_RESEARCH;
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
            'execution_mode' => 'source_grounded_answer',
            'side_effect_policy' => SpecialistFlowsCanon::SIDE_EFFECT_READ_ONLY,
            'research_invariants' => [
                'every_claim_needs_source_or_uncertainty_flag',
                'no_provider_call_inside_runtime_contract',
                'memory_promotion_requires_human_review',
            ],
        ]);
    }
}
