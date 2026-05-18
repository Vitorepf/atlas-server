<?php

namespace App\Services\Ai\Policy;

use App\Models\AiApprovalRequest;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ApprovalRequestService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function request(array $payload): AiApprovalRequest
    {
        if (empty($payload['requested_action'])) {
            throw new InvalidArgumentException('requested_action required');
        }
        if (empty($payload['approval_type'])) {
            throw new InvalidArgumentException('approval_type required');
        }
        $expiresAt = $payload['expires_at'] ?? Carbon::now()->addMinutes((int) ($payload['expires_in_minutes'] ?? 60));

        return AiApprovalRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $payload['mission_id'] ?? null,
            'work_order_id' => $payload['work_order_id'] ?? null,
            'approval_type' => (string) $payload['approval_type'],
            'requested_action' => (string) $payload['requested_action'],
            'requester_type' => (string) ($payload['requester_type'] ?? 'system'),
            'status' => PolicyCanon::APPROVAL_PENDING,
            'approver' => $payload['approver'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'decision_payload' => $payload['decision_payload'] ?? null,
            'expires_at' => $expiresAt,
            'decided_at' => null,
            'receipt_hash' => null,
        ]);
    }

    public function approve(AiApprovalRequest $request, string $approver, ?string $reason = null): AiApprovalRequest
    {
        return $this->decide($request, PolicyCanon::APPROVAL_APPROVED, $approver, $reason);
    }

    public function reject(AiApprovalRequest $request, string $approver, ?string $reason = null): AiApprovalRequest
    {
        return $this->decide($request, PolicyCanon::APPROVAL_REJECTED, $approver, $reason);
    }

    public function cancel(AiApprovalRequest $request, ?string $reason = null): AiApprovalRequest
    {
        return $this->decide($request, PolicyCanon::APPROVAL_CANCELLED, 'system', $reason);
    }

    public function expireDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return AiApprovalRequest::query()
            ->where('status', PolicyCanon::APPROVAL_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->update([
                'status' => PolicyCanon::APPROVAL_EXPIRED,
                'decided_at' => $now,
            ]);
    }

    private function decide(AiApprovalRequest $request, string $status, string $approver, ?string $reason): AiApprovalRequest
    {
        if ($request->status !== PolicyCanon::APPROVAL_PENDING) {
            throw new InvalidArgumentException("approval already decided as [{$request->status}]");
        }
        $request->status = $status;
        $request->approver = $approver;
        $request->reason = $reason;
        $request->decided_at = Carbon::now();
        $request->receipt_hash = MissionCanonicalHash::sha256([
            'uuid' => $request->uuid,
            'requested_action' => $request->requested_action,
            'status' => $status,
            'approver' => $approver,
            'reason' => $reason,
            'decided_at' => $request->decided_at?->toJSON(),
        ]);
        $request->save();

        return $request;
    }
}
