<?php

namespace App\Services\Ai\Policy;

use App\Models\AiPermissionGate;
use App\Models\AiSafetyDecision;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class SafetyDecisionService
{
    public function __construct(
        private readonly PermissionGateService $permissionGate,
        private readonly RiskAssessmentService $riskAssessment,
        private readonly ApprovalRequestService $approvals,
        private readonly PolicyProfileRegistryService $profiles,
    ) {}

    /**
     * Top-level safety decision: evaluates permission, optionally records risk,
     * may open an approval request when decision is require_approval.
     *
     * @param  array<string,mixed>  $request
     */
    public function decide(array $request): AiSafetyDecision
    {
        $riskFactors = (array) ($request['risk_factors'] ?? []);
        $riskAssessment = $riskFactors !== []
            ? $this->riskAssessment->assess(
                (string) ($request['target_type'] ?? 'action'),
                $request['target_ref'] ?? ($request['requested_action'] ?? null),
                $riskFactors,
                (array) ($request['mitigations'] ?? []),
                [
                    'mission_id' => $request['mission_id'] ?? null,
                    'work_order_id' => $request['work_order_id'] ?? null,
                ],
            )
            : null;

        if ($riskAssessment !== null) {
            $request['risk_level'] = $request['risk_level'] ?? $riskAssessment->risk_level;
        }

        $gate = $this->permissionGate->evaluate($request);
        $profile = $this->profiles->findForRequest($request);

        if ($gate->decision === PolicyCanon::DECISION_REQUIRE_APPROVAL) {
            $this->openApprovalForGate($gate);
        }

        $reasons = $gate->reasons ?? [];

        $payload = [
            'requested_action' => $gate->requested_action,
            'decision' => $gate->decision,
            'risk_assessment_id' => $riskAssessment?->id,
            'policy_profile_id' => $profile?->id,
            'reasons' => $reasons,
            'gate_id' => $gate->id,
            'mission_id' => $gate->mission_id,
            'work_order_id' => $gate->work_order_id,
        ];

        return AiSafetyDecision::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $gate->mission_id,
            'work_order_id' => $gate->work_order_id,
            'decision_type' => (string) ($request['decision_type'] ?? 'permission'),
            'requested_action' => $gate->requested_action,
            'decision' => $gate->decision,
            'risk_assessment_id' => $riskAssessment?->id,
            'policy_profile_id' => $profile?->id,
            'reasons' => $reasons,
            'receipt_hash' => MissionCanonicalHash::sha256($payload),
        ]);
    }

    private function openApprovalForGate(AiPermissionGate $gate): void
    {
        $this->approvals->request([
            'mission_id' => $gate->mission_id,
            'work_order_id' => $gate->work_order_id,
            'approval_type' => $gate->gate_type ?: 'permission',
            'requested_action' => $gate->requested_action,
            'requester_type' => 'system',
            'reason' => 'permission_gate_require_approval',
            'decision_payload' => [
                'gate_uuid' => $gate->uuid,
                'reasons' => $gate->reasons,
                'required_approvals' => $gate->required_approvals,
            ],
        ]);
    }
}
