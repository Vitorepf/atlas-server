<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Cyber specialist flow.
 *
 * Defensive-only by default. Offensive actions (red team, scanning third
 * party targets) are FORBIDDEN unless a signed Rules of Engagement (RoE)
 * key is present in the payload (`cyber_roe_signed`). Even with the RoE,
 * execution side-effects still require explicit `cyber_execute_approved`.
 */
final class AtlasCyberFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_CYBER;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function extend(array $base, array $router, array $payload): array
    {
        $roeSigned = $this->operatorApproved($payload, 'cyber_roe_signed');
        $executeApproved = $this->operatorApproved($payload, 'cyber_execute_approved');

        return array_merge($base, [
            'execution_mode' => $roeSigned ? 'offensive_with_roe' : 'defensive_advisory',
            'side_effect_policy' => $roeSigned && $executeApproved
                ? SpecialistFlowsCanon::SIDE_EFFECT_REQUIRES_APPROVAL
                : SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN,
            'offensive_actions_allowed' => $roeSigned,
            'execute_authorized' => $roeSigned && $executeApproved,
            'roe_status' => $roeSigned ? 'signed_present_in_payload' : 'absent_defensive_only',
            'cyber_invariants' => [
                'no_offensive_action_without_signed_roe',
                'no_scanning_third_party_without_authorization',
                'no_exfiltration_under_any_circumstance',
                'all_findings_audit_logged',
            ],
        ]);
    }
}
