<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Finance specialist flow.
 *
 * Defensive by default: never executes live trades, never fabricates
 * data, never promises returns. Real-money actions require an explicit
 * operator approval key (`finance_live_trade_approved`) in the payload.
 */
final class AtlasFinanceFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_FINANCE;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function extend(array $base, array $router, array $payload): array
    {
        $liveTradeApproved = $this->operatorApproved($payload, 'finance_live_trade_approved');

        return array_merge($base, [
            'execution_mode' => 'analysis_with_assumptions',
            'side_effect_policy' => $liveTradeApproved
                ? SpecialistFlowsCanon::SIDE_EFFECT_REQUIRES_APPROVAL
                : SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN,
            'live_trade_authorized' => $liveTradeApproved,
            'risk_gate' => [
                'require_data_snapshot' => true,
                'require_assumption_log' => true,
                'require_risk_register' => true,
            ],
            'finance_invariants' => [
                'no_live_trade_without_operator_approval',
                'no_promise_of_returns',
                'redact_account_identifiers_in_output',
                'separate_fact_from_assumption',
            ],
        ]);
    }
}
