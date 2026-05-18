<?php

namespace App\Services\Ai\Policy;

use App\Models\AiApprovalRequest;
use App\Models\AiBudgetEnvelope;
use App\Models\AiForbiddenAction;
use App\Models\AiPermissionGate;
use App\Models\AiPolicyProfile;
use App\Models\AiRiskAssessment;
use App\Models\AiSafetyDecision;

class PolicyControlPlaneService
{
    /**
     * Aggregated read-only snapshot of the policy plane. Returns a stable JSON
     * shape suitable for surfaces (CLI, REST, UI) and downstream IAs that want
     * to answer "what is the policy plane state right now".
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        return [
            'schema' => 'atlas.ai.policy.control_plane.v1',
            'profiles' => $this->profilesSection(),
            'gates' => $this->gatesSection($limitRecent),
            'approvals' => $this->approvalsSection($limitRecent),
            'budgets' => $this->budgetsSection($limitRecent),
            'risks' => $this->risksSection($limitRecent),
            'decisions' => $this->decisionsSection($limitRecent),
            'forbidden_actions' => $this->forbiddenSection($limitRecent),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profilesSection(): array
    {
        $profiles = AiPolicyProfile::query()->orderBy('policy_id')->get();

        return [
            'count' => $profiles->count(),
            'by_scope' => $profiles->groupBy('scope_type')->map->count()->all(),
            'items' => $profiles->map(static fn (AiPolicyProfile $p): array => [
                'uuid' => $p->uuid,
                'policy_id' => $p->policy_id,
                'name' => $p->name,
                'scope_type' => $p->scope_type,
                'scope_ref' => $p->scope_ref,
                'autonomy_level' => $p->autonomy_level,
                'risk_tolerance' => $p->risk_tolerance,
                'status' => $p->status,
                'forbidden_action_count' => is_array($p->forbidden_actions) ? count($p->forbidden_actions) : 0,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gatesSection(int $limit): array
    {
        $gates = AiPermissionGate::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiPermissionGate::query()->count(),
            'by_decision' => AiPermissionGate::query()
                ->selectRaw('decision, count(*) as total')
                ->groupBy('decision')
                ->pluck('total', 'decision')
                ->all(),
            'recent' => $gates->map(static fn (AiPermissionGate $g): array => [
                'uuid' => $g->uuid,
                'requested_action' => $g->requested_action,
                'decision' => $g->decision,
                'risk_level' => $g->risk_level,
                'receipt_hash' => $g->receipt_hash,
                'created_at' => $g->created_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function approvalsSection(int $limit): array
    {
        $approvals = AiApprovalRequest::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiApprovalRequest::query()->count(),
            'by_status' => AiApprovalRequest::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'recent' => $approvals->map(static fn (AiApprovalRequest $a): array => [
                'uuid' => $a->uuid,
                'requested_action' => $a->requested_action,
                'approval_type' => $a->approval_type,
                'status' => $a->status,
                'approver' => $a->approver,
                'expires_at' => $a->expires_at?->toJSON(),
                'decided_at' => $a->decided_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function budgetsSection(int $limit): array
    {
        $envelopes = AiBudgetEnvelope::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiBudgetEnvelope::query()->count(),
            'by_status' => AiBudgetEnvelope::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'recent' => $envelopes->map(static fn (AiBudgetEnvelope $e): array => [
                'uuid' => $e->uuid,
                'scope_type' => $e->scope_type,
                'scope_ref' => $e->scope_ref,
                'status' => $e->status,
                'max_cost' => $e->max_cost,
                'current_cost' => $e->current_cost,
                'max_tokens' => $e->max_tokens,
                'current_tokens' => $e->current_tokens,
                'max_tool_calls' => $e->max_tool_calls,
                'current_tool_calls' => $e->current_tool_calls,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function risksSection(int $limit): array
    {
        $risks = AiRiskAssessment::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiRiskAssessment::query()->count(),
            'by_level' => AiRiskAssessment::query()
                ->selectRaw('risk_level, count(*) as total')
                ->groupBy('risk_level')
                ->pluck('total', 'risk_level')
                ->all(),
            'recent' => $risks->map(static fn (AiRiskAssessment $r): array => [
                'uuid' => $r->uuid,
                'target_type' => $r->target_type,
                'target_ref' => $r->target_ref,
                'risk_level' => $r->risk_level,
                'residual_risk' => $r->residual_risk,
                'assessment_hash' => $r->assessment_hash,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionsSection(int $limit): array
    {
        $decisions = AiSafetyDecision::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiSafetyDecision::query()->count(),
            'by_decision' => AiSafetyDecision::query()
                ->selectRaw('decision, count(*) as total')
                ->groupBy('decision')
                ->pluck('total', 'decision')
                ->all(),
            'recent' => $decisions->map(static fn (AiSafetyDecision $d): array => [
                'uuid' => $d->uuid,
                'decision_type' => $d->decision_type,
                'requested_action' => $d->requested_action,
                'decision' => $d->decision,
                'receipt_hash' => $d->receipt_hash,
                'created_at' => $d->created_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function forbiddenSection(int $limit): array
    {
        $actions = AiForbiddenAction::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiForbiddenAction::query()->count(),
            'by_severity' => AiForbiddenAction::query()
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity')
                ->all(),
            'recent' => $actions->map(static fn (AiForbiddenAction $a): array => [
                'uuid' => $a->uuid,
                'action_key' => $a->action_key,
                'severity' => $a->severity,
                'status' => $a->status,
                'scope_type' => $a->scope_type,
                'scope_ref' => $a->scope_ref,
            ])->all(),
        ];
    }
}
