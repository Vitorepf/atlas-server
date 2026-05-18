<?php

namespace App\Services\Ai\Policy;

use App\Models\AiForbiddenAction;
use App\Models\AiPolicyProfile;
use Illuminate\Support\Str;

class ForbiddenActionService
{
    public function block(
        string $actionKey,
        string $description,
        ?string $scopeType = null,
        ?string $scopeRef = null,
        string $severity = 'high',
        ?AiPolicyProfile $profile = null,
    ): AiForbiddenAction {
        return AiForbiddenAction::query()->create([
            'uuid' => (string) Str::uuid(),
            'policy_profile_id' => $profile?->id,
            'action_key' => $actionKey,
            'description' => $description,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
            'severity' => $severity,
            'status' => 'active',
        ]);
    }

    public function isForbidden(string $actionKey, ?string $scopeType = null, ?string $scopeRef = null): ?AiForbiddenAction
    {
        $query = AiForbiddenAction::query()
            ->where('action_key', $actionKey)
            ->where('status', 'active');

        if ($scopeType !== null) {
            $query->where(function ($q) use ($scopeType, $scopeRef): void {
                $q->whereNull('scope_type')
                    ->orWhere(function ($inner) use ($scopeType, $scopeRef): void {
                        $inner->where('scope_type', $scopeType);
                        if ($scopeRef !== null) {
                            $inner->where(function ($leaf) use ($scopeRef): void {
                                $leaf->whereNull('scope_ref')->orWhere('scope_ref', $scopeRef);
                            });
                        }
                    });
            });
        }

        return $query->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->first();
    }
}
