<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Strategy specialist flow. Produces decision memos with explicit
 * assumptions, options, tradeoffs, recommendation. Read-only: strategic
 * actions still go through operator approval flows.
 */
final class AtlasStrategyFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_STRATEGY;
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
            'execution_mode' => 'decision_memo',
            'side_effect_policy' => SpecialistFlowsCanon::SIDE_EFFECT_READ_ONLY,
            'strategy_invariants' => [
                'document_assumptions_explicitly',
                'list_options_with_tradeoffs',
                'separate_fact_from_hypothesis',
                'no_execution_without_operator_approval',
            ],
        ]);
    }
}
