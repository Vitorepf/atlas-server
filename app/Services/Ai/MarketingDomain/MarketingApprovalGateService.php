<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiApprovalRequest;
use App\Models\AiMarketingApprovalGate;
use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingExperiment;
use App\Models\AiMarketingRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MarketingApprovalGateService
{
    /**
     * Open a new approval gate for a publish/paid_media/send_email/launch
     * action. The gate stays `pending` until an operator approves or rejects
     * it. When Meta 3 (Policy) approval requests are available, the gate
     * also opens an `AiApprovalRequest` and stores its uuid for cross-link.
     *
     * @param  array<string,mixed>  $payload
     */
    public function request(AiMarketingRun $run, array $payload): AiMarketingApprovalGate
    {
        $gateType = (string) ($payload['gate_type'] ?? '');
        if (! in_array($gateType, MarketingDomainCanon::GATE_TYPES, true)) {
            throw new InvalidArgumentException("approval gate requires valid gate_type, got [{$gateType}]");
        }
        $artifactId = $payload['artifact_id'] ?? null;
        $experimentId = $payload['experiment_id'] ?? null;
        $action = (string) ($payload['requested_action'] ?? $gateType);
        $budget = $payload['proposed_budget'] ?? null;
        if ($gateType === MarketingDomainCanon::GATE_PAID_MEDIA && ($budget === null || ! is_numeric($budget))) {
            throw new InvalidArgumentException('paid_media gate requires numeric proposed_budget');
        }

        $policyRequestId = null;
        if (Schema::hasTable('ai_approval_requests')) {
            $policy = AiApprovalRequest::query()->create([
                'uuid' => (string) Str::uuid(),
                'mission_id' => $run->mission_id,
                'work_order_id' => $run->work_order_id,
                'approval_type' => $gateType,
                'requested_action' => $action,
                'requester_type' => 'marketing_domain',
                'status' => 'pending',
                'approver' => null,
                'reason' => $payload['reason'] ?? null,
                'decision_payload' => [
                    'marketing_run_uuid' => $run->uuid,
                    'artifact_id' => $artifactId,
                    'experiment_id' => $experimentId,
                    'proposed_budget' => $budget,
                    'currency' => $payload['currency'] ?? null,
                ],
                'expires_at' => null,
                'decided_at' => null,
                'receipt_hash' => null,
            ]);
            $policyRequestId = $policy->id;
        }

        $receipt = MissionCanonicalHash::sha256([
            'marketing_run_uuid' => $run->uuid,
            'gate_type' => $gateType,
            'artifact_id' => $artifactId,
            'experiment_id' => $experimentId,
            'proposed_budget' => $budget,
            'requested_action' => $action,
            'policy_approval_request_id' => $policyRequestId,
        ]);

        return AiMarketingApprovalGate::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_id' => $artifactId,
            'experiment_id' => $experimentId,
            'gate_type' => $gateType,
            'requested_action' => $action,
            'proposed_budget' => $budget,
            'currency' => $payload['currency'] ?? null,
            'status' => MarketingDomainCanon::GATE_PENDING,
            'approver' => null,
            'reason' => $payload['reason'] ?? null,
            'policy_approval_request_id' => $policyRequestId,
            'receipt_hash' => $receipt,
        ]);
    }

    public function approve(AiMarketingApprovalGate $gate, string $approver, ?string $reason = null): AiMarketingApprovalGate
    {
        return $this->decide($gate, MarketingDomainCanon::GATE_APPROVED, $approver, $reason);
    }

    public function reject(AiMarketingApprovalGate $gate, string $approver, ?string $reason = null): AiMarketingApprovalGate
    {
        return $this->decide($gate, MarketingDomainCanon::GATE_REJECTED, $approver, $reason);
    }

    private function decide(AiMarketingApprovalGate $gate, string $status, string $approver, ?string $reason): AiMarketingApprovalGate
    {
        if ($gate->status !== MarketingDomainCanon::GATE_PENDING) {
            throw new InvalidArgumentException("approval gate already decided as [{$gate->status}]");
        }
        $gate->status = $status;
        $gate->approver = $approver;
        $gate->reason = $reason;
        $gate->save();

        // Best-effort propagate to AiApprovalRequest if present.
        if ($gate->policy_approval_request_id !== null) {
            $policy = AiApprovalRequest::query()->find($gate->policy_approval_request_id);
            if ($policy !== null && $policy->status === 'pending') {
                $policy->status = $status === MarketingDomainCanon::GATE_APPROVED ? 'approved' : 'rejected';
                $policy->approver = $approver;
                $policy->reason = $reason;
                $policy->decided_at = now();
                $policy->receipt_hash = MissionCanonicalHash::sha256([
                    'uuid' => $policy->uuid,
                    'status' => $policy->status,
                    'decided_at' => $policy->decided_at->toJSON(),
                ]);
                $policy->save();
            }
        }

        // Propagate to artifact / experiment status when relevant.
        if ($gate->artifact_id !== null) {
            $artifact = AiMarketingArtifact::query()->find($gate->artifact_id);
            if ($artifact !== null) {
                $artifact->status = $status === MarketingDomainCanon::GATE_APPROVED
                    ? MarketingDomainCanon::ARTIFACT_APPROVED
                    : MarketingDomainCanon::ARTIFACT_REJECTED;
                $artifact->save();
            }
        }
        if ($gate->experiment_id !== null) {
            $experiment = AiMarketingExperiment::query()->find($gate->experiment_id);
            if ($experiment !== null) {
                $experiment->status = $status === MarketingDomainCanon::GATE_APPROVED
                    ? MarketingDomainCanon::EXPERIMENT_READY
                    : MarketingDomainCanon::EXPERIMENT_BLOCKED;
                $experiment->save();
            }
        }

        return $gate->refresh();
    }
}
