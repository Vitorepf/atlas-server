<?php

namespace App\Services\Ai\Policy;

use App\Models\AiPermissionGate;
use App\Models\AiPolicyProfile;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class PermissionGateService
{
    public function __construct(
        private readonly PolicyProfileRegistryService $profiles,
        private readonly ForbiddenActionService $forbidden,
    ) {}

    /**
     * Evaluate a requested action and record an AiPermissionGate row.
     *
     * Expected request shape:
     *   [
     *     'requested_action' => 'finance.live_trade',
     *     'gate_type' => 'permission' | 'budget' | 'risk' | 'tool',
     *     'risk_level' => 'low' | 'medium' | 'high' | 'critical',
     *     'domain_id' => 'finance',
     *     'tool_id' => 'broker_api',
     *     'mission_id' => uuid?,
     *     'work_order_id' => uuid?,
     *     'evidence_refs' => [],
     *   ]
     *
     * @param  array<string,mixed>  $request
     */
    public function evaluate(array $request): AiPermissionGate
    {
        $action = (string) ($request['requested_action'] ?? '');
        $gateType = (string) ($request['gate_type'] ?? 'permission');
        $riskLevel = (string) ($request['risk_level'] ?? PolicyCanon::RISK_LOW);

        $profile = $this->profiles->findForRequest($request);
        $reasons = [];
        $requiredApprovals = [];

        $decision = $this->resolveDecision($request, $profile, $reasons, $requiredApprovals);

        $payload = [
            'requested_action' => $action,
            'gate_type' => $gateType,
            'risk_level' => $riskLevel,
            'decision' => $decision,
            'reasons' => $reasons,
            'profile_id' => $profile?->policy_id,
        ];

        return AiPermissionGate::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $request['mission_id'] ?? null,
            'work_order_id' => $request['work_order_id'] ?? null,
            'domain_id' => $request['domain_id'] ?? $this->profiles->extractDomain($request),
            'tool_id' => $request['tool_id'] ?? null,
            'gate_type' => $gateType,
            'requested_action' => $action,
            'risk_level' => $riskLevel,
            'decision' => $decision,
            'reasons' => $reasons,
            'required_approvals' => $requiredApprovals !== [] ? $requiredApprovals : null,
            'evidence_refs' => $request['evidence_refs'] ?? null,
            'receipt_hash' => MissionCanonicalHash::sha256($payload),
        ]);
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<int,string>  $reasons
     * @param  array<int,string>  $requiredApprovals
     */
    private function resolveDecision(
        array $request,
        ?AiPolicyProfile $profile,
        array &$reasons,
        array &$requiredApprovals,
    ): string {
        $action = (string) ($request['requested_action'] ?? '');
        $riskLevel = (string) ($request['risk_level'] ?? PolicyCanon::RISK_LOW);
        $domain = $this->profiles->extractDomain($request);

        $hardBlock = $this->forbidden->isForbidden($action);
        if ($hardBlock !== null) {
            $reasons[] = "forbidden_action_registered:{$action}";

            return PolicyCanon::DECISION_BLOCKED;
        }
        if ($profile !== null) {
            $forbiddenInProfile = (array) ($profile->forbidden_actions ?? []);
            if (in_array($action, $forbiddenInProfile, true)) {
                $reasons[] = "forbidden_in_profile:{$profile->policy_id}";

                return PolicyCanon::DECISION_BLOCKED;
            }
        }
        if ($profile === null) {
            $reasons[] = 'no_policy_profile_matched';

            return PolicyCanon::DECISION_DENY;
        }

        if (PolicyCanon::riskExceeds($riskLevel, $profile->risk_tolerance)) {
            $reasons[] = "risk_exceeds_tolerance:{$riskLevel}>{$profile->risk_tolerance}";
            $requiredApprovals[] = 'risk_review';
            if ($profile->autonomy_level === PolicyCanon::AUTONOMY_SUGGEST) {
                return PolicyCanon::DECISION_BLOCKED;
            }

            return PolicyCanon::DECISION_REQUIRE_APPROVAL;
        }

        $approvalRules = (array) ($profile->approval_rules ?? []);
        $matchKeys = $this->approvalKeyCandidates($action, $domain, $request);
        foreach ($matchKeys as $key) {
            if (! array_key_exists($key, $approvalRules)) {
                continue;
            }
            if ($approvalRules[$key] === true) {
                $reasons[] = "approval_rule_match:{$key}";
                $requiredApprovals[] = $key;

                return PolicyCanon::DECISION_REQUIRE_APPROVAL;
            }
        }

        return match ($profile->autonomy_level) {
            PolicyCanon::AUTONOMY_AUTONOMOUS => PolicyCanon::DECISION_ALLOW,
            PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL => $this->autonomyAllowsAction($request, $profile, $reasons),
            PolicyCanon::AUTONOMY_DRAFT, PolicyCanon::AUTONOMY_SUGGEST => $this->autonomyAllowsAction($request, $profile, $reasons),
            default => PolicyCanon::DECISION_DENY,
        };
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<int,string>  $reasons
     */
    private function autonomyAllowsAction(array $request, AiPolicyProfile $profile, array &$reasons): string
    {
        $toolPermissions = (array) ($profile->tool_permissions ?? []);
        $action = (string) ($request['requested_action'] ?? '');
        $verb = explode('.', $action, 2)[1] ?? $action;
        $deny = (array) ($toolPermissions['deny'] ?? []);
        if (in_array($verb, $deny, true)) {
            $reasons[] = "tool_permission_deny:{$verb}";

            return PolicyCanon::DECISION_DENY;
        }
        $allow = (array) ($toolPermissions['allow'] ?? []);
        if ($allow !== [] && ! in_array($verb, $allow, true)) {
            $reasons[] = "tool_permission_not_in_allowlist:{$verb}";

            return PolicyCanon::DECISION_DENY;
        }
        $reasons[] = 'policy_profile_allows';

        return PolicyCanon::DECISION_ALLOW;
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<int,string>
     */
    private function approvalKeyCandidates(string $action, ?string $domain, array $request): array
    {
        $candidates = [$action];
        if ($domain !== null) {
            $candidates[] = $domain.'.publish';
            $candidates[] = $domain.'.paid_media_spend';
            $candidates[] = $domain.'.send_email';
        }
        if (! empty($request['gate_type']) && (string) $request['gate_type'] === 'budget') {
            $candidates[] = 'external_cost';
        }
        $tool = $request['tool_id'] ?? null;
        if ($tool !== null) {
            $candidates[] = 'tool.external_cost';
            $candidates[] = 'tool.paid_api';
        }

        return array_values(array_unique(array_filter(array_map('strval', $candidates))));
    }
}
