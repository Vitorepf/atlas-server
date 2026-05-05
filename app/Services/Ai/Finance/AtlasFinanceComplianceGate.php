<?php

namespace App\Services\Ai\Finance;

class AtlasFinanceComplianceGate
{
    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $blockedRequestedActions
     * @return array{passed:bool,status:string,reasons:array<int,string>}
     */
    public function evaluate(array $plan, array $blockedRequestedActions = []): array
    {
        $reasons = [];

        if (($plan['domain'] ?? null) !== AtlasFinanceDomainContract::DOMAIN_ID) {
            $reasons[] = 'invalid_finance_domain';
        }

        if (($plan['output_mode'] ?? null) !== AtlasFinanceDomainContract::OUTPUT_MODE) {
            $reasons[] = 'output_mode_must_be_analysis_review_only';
        }

        if ((bool) ($plan['market_execution_allowed'] ?? true)) {
            $reasons[] = 'market_execution_must_be_disabled';
        }

        if ((bool) ($plan['destructive_actions_allowed'] ?? true)) {
            $reasons[] = 'destructive_actions_must_be_disabled';
        }

        if (! (bool) ($plan['compliance_gate_required'] ?? false)) {
            $reasons[] = 'finance_compliance_gate_required';
        }

        $requiredGates = (array) ($plan['required_gates'] ?? []);
        foreach (AtlasFinanceDomainContract::GLOBAL_GATES as $gate) {
            if (! in_array($gate, $requiredGates, true)) {
                $reasons[] = "missing_required_gate:{$gate}";
            }
        }

        if ($blockedRequestedActions !== []) {
            $reasons[] = 'market_execution_request_blocked';
        }

        $passed = $reasons === [];

        return [
            'passed' => $passed,
            'status' => $passed ? 'passed_for_analysis_review_only' : 'blocked',
            'reasons' => $reasons,
        ];
    }
}
