<?php

namespace App\Services\Ai\Policy;

use App\Models\AiForbiddenAction;
use App\Models\AiPolicyProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyProfileRegistryService
{
    /**
     * Canonical default profiles. Keep this list aligned with the Meta 3 doc.
     *
     * @return array<int,array<string,mixed>>
     */
    public function defaults(): array
    {
        return [
            [
                'policy_id' => 'global.default',
                'name' => 'Global Default Policy',
                'scope_type' => PolicyCanon::SCOPE_GLOBAL,
                'scope_ref' => null,
                'autonomy_level' => PolicyCanon::AUTONOMY_DRAFT,
                'risk_tolerance' => PolicyCanon::RISK_MEDIUM,
                'approval_rules' => [
                    'external_cost' => true,
                    'credential_use' => true,
                    'destructive_action' => true,
                ],
                'tool_permissions' => [
                    'allow' => ['read', 'analysis', 'doc'],
                    'deny' => ['destructive'],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 5.0,
                    'max_tokens' => 200000,
                    'max_tool_calls' => 50,
                ],
                'forbidden_actions' => [],
            ],
            [
                'policy_id' => 'programming.default',
                'name' => 'Programming Default Policy',
                'scope_type' => PolicyCanon::SCOPE_DOMAIN,
                'scope_ref' => 'programming',
                'autonomy_level' => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
                'risk_tolerance' => PolicyCanon::RISK_LOW,
                'approval_rules' => [
                    'destructive_action' => true,
                    'production_deploy' => true,
                ],
                'tool_permissions' => [
                    'allow' => ['read', 'edit', 'test', 'lint'],
                    'deny' => ['production_deploy'],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 10.0,
                    'max_tokens' => 1000000,
                    'max_tool_calls' => 200,
                ],
                'forbidden_actions' => [],
            ],
            [
                'policy_id' => 'finance.research_only',
                'name' => 'Finance Research-Only Policy',
                'scope_type' => PolicyCanon::SCOPE_DOMAIN,
                'scope_ref' => 'finance',
                'autonomy_level' => PolicyCanon::AUTONOMY_DRAFT,
                'risk_tolerance' => PolicyCanon::RISK_LOW,
                'approval_rules' => [
                    'finance.simulate_trade' => false,
                ],
                'tool_permissions' => [
                    'allow' => ['research', 'analysis', 'simulation'],
                    'deny' => ['live_trade', 'transfer'],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 2.0,
                    'max_tokens' => 200000,
                    'max_tool_calls' => 30,
                ],
                'forbidden_actions' => [
                    'finance.execute_trade',
                    'finance.transfer_funds',
                ],
            ],
            [
                'policy_id' => 'finance.live_trade_blocked_by_default',
                'name' => 'Finance Live Trade Hard Block',
                'scope_type' => PolicyCanon::SCOPE_DOMAIN,
                'scope_ref' => 'finance',
                'autonomy_level' => PolicyCanon::AUTONOMY_SUGGEST,
                'risk_tolerance' => PolicyCanon::RISK_LOW,
                'approval_rules' => [],
                'tool_permissions' => [
                    'allow' => [],
                    'deny' => ['live_trade', 'transfer'],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 0.0,
                ],
                'forbidden_actions' => [
                    'finance.live_trade',
                    'finance.broker_order',
                ],
            ],
            [
                'policy_id' => 'cyber.defensive_only',
                'name' => 'Cyber Defensive-Only Policy',
                'scope_type' => PolicyCanon::SCOPE_DOMAIN,
                'scope_ref' => 'cyber',
                'autonomy_level' => PolicyCanon::AUTONOMY_DRAFT,
                'risk_tolerance' => PolicyCanon::RISK_MEDIUM,
                'approval_rules' => [
                    'cyber.scan_authorized' => true,
                ],
                'tool_permissions' => [
                    'allow' => ['appsec_review', 'grc', 'defensive_research'],
                    'deny' => ['offensive_exploit'],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 5.0,
                    'max_tokens' => 500000,
                ],
                'forbidden_actions' => [],
            ],
            [
                'policy_id' => 'cyber.offensive_requires_authorization',
                'name' => 'Cyber Offensive Hard Block Without Authorization',
                'scope_type' => PolicyCanon::SCOPE_DOMAIN,
                'scope_ref' => 'cyber',
                'autonomy_level' => PolicyCanon::AUTONOMY_SUGGEST,
                'risk_tolerance' => PolicyCanon::RISK_LOW,
                'approval_rules' => [],
                'tool_permissions' => [
                    'allow' => [],
                    'deny' => ['offensive_exploit'],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 0.0,
                ],
                'forbidden_actions' => [
                    'cyber.offensive_without_authorization',
                    'cyber.exploit_without_scope',
                ],
            ],
            [
                'policy_id' => 'marketing.publish_requires_approval',
                'name' => 'Marketing Publish + Paid Media Approval',
                'scope_type' => PolicyCanon::SCOPE_DOMAIN,
                'scope_ref' => 'marketing',
                'autonomy_level' => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
                'risk_tolerance' => PolicyCanon::RISK_MEDIUM,
                'approval_rules' => [
                    'marketing.publish' => true,
                    'marketing.paid_media_spend' => true,
                    'marketing.send_email' => true,
                ],
                'tool_permissions' => [
                    'allow' => ['draft', 'analysis', 'plan'],
                    'deny' => [],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 5.0,
                    'max_tokens' => 500000,
                ],
                'forbidden_actions' => [],
            ],
            [
                'policy_id' => 'automation.external_action_requires_policy',
                'name' => 'Automation External Action Gate',
                'scope_type' => PolicyCanon::SCOPE_DOMAIN,
                'scope_ref' => 'automation',
                'autonomy_level' => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
                'risk_tolerance' => PolicyCanon::RISK_MEDIUM,
                'approval_rules' => [
                    'automation.external_action' => true,
                    'automation.browser_login' => true,
                    'automation.api_paid' => true,
                ],
                'tool_permissions' => [
                    'allow' => ['scrape_read', 'analysis', 'browser_read'],
                    'deny' => ['anti_bot_bypass'],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 5.0,
                    'max_tool_calls' => 100,
                ],
                'forbidden_actions' => [
                    'automation.anti_bot_bypass',
                ],
            ],
            [
                'policy_id' => 'tool.external_cost_requires_approval',
                'name' => 'Tool External Cost Approval Policy',
                'scope_type' => PolicyCanon::SCOPE_TOOL,
                'scope_ref' => 'external_cost',
                'autonomy_level' => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
                'risk_tolerance' => PolicyCanon::RISK_LOW,
                'approval_rules' => [
                    'tool.external_cost' => true,
                    'tool.paid_api' => true,
                ],
                'tool_permissions' => [
                    'allow' => [],
                    'deny' => [],
                ],
                'provider_permissions' => null,
                'data_permissions' => null,
                'budget_defaults' => [
                    'max_cost' => 1.0,
                    'max_external_calls' => 25,
                ],
                'forbidden_actions' => [],
            ],
        ];
    }

    /**
     * Seed defaults idempotently. Returns the resulting profiles.
     *
     * @return Collection<int,AiPolicyProfile>
     */
    public function seedDefaults(): Collection
    {
        return DB::transaction(function (): Collection {
            $profiles = collect();
            foreach ($this->defaults() as $payload) {
                $profile = AiPolicyProfile::query()->where('policy_id', $payload['policy_id'])->first();
                if ($profile === null) {
                    $profile = AiPolicyProfile::query()->create(array_merge($payload, [
                        'uuid' => (string) Str::uuid(),
                        'status' => 'active',
                    ]));
                }
                $this->reconcileForbiddenActions($profile, (array) $payload['forbidden_actions']);
                $profiles->push($profile->refresh());
            }

            return $profiles;
        });
    }

    /**
     * Resolve the most specific applicable profile for a request.
     *
     * @param  array<string,mixed>  $request
     */
    public function findForRequest(array $request): ?AiPolicyProfile
    {
        $domain = $this->extractDomain($request);
        $tool = isset($request['tool_id']) ? (string) $request['tool_id'] : null;
        $mission = isset($request['mission_id']) ? (string) $request['mission_id'] : null;
        $workOrder = isset($request['work_order_id']) ? (string) $request['work_order_id'] : null;

        $candidates = [
            [PolicyCanon::SCOPE_TOOL, $tool],
            [PolicyCanon::SCOPE_WORK_ORDER, $workOrder],
            [PolicyCanon::SCOPE_MISSION, $mission],
            [PolicyCanon::SCOPE_DOMAIN, $domain],
            [PolicyCanon::SCOPE_GLOBAL, null],
        ];

        foreach ($candidates as [$scopeType, $scopeRef]) {
            $query = AiPolicyProfile::query()
                ->where('scope_type', $scopeType)
                ->where('status', 'active');

            if ($scopeRef === null) {
                $query->whereNull('scope_ref');
            } else {
                $query->where('scope_ref', $scopeRef);
            }

            $profile = $query->orderBy('updated_at', 'desc')->first();
            if ($profile instanceof AiPolicyProfile) {
                return $profile;
            }
        }

        return null;
    }

    public function get(string $policyId): ?AiPolicyProfile
    {
        return AiPolicyProfile::query()->where('policy_id', $policyId)->first();
    }

    /**
     * Extract a domain hint from the request. If the request includes a
     * domain_id, use it; otherwise try to derive from the action prefix
     * (e.g. "finance.execute_trade" -> "finance").
     *
     * @param  array<string,mixed>  $request
     */
    public function extractDomain(array $request): ?string
    {
        if (! empty($request['domain_id'])) {
            return (string) $request['domain_id'];
        }
        $action = (string) ($request['requested_action'] ?? '');
        if ($action === '') {
            return null;
        }
        $parts = explode('.', $action, 2);

        return $parts[0] !== '' ? $parts[0] : null;
    }

    /**
     * @param  array<int,string>  $forbidden
     */
    private function reconcileForbiddenActions(AiPolicyProfile $profile, array $forbidden): void
    {
        foreach ($forbidden as $actionKey) {
            $existing = AiForbiddenAction::query()
                ->where('policy_profile_id', $profile->id)
                ->where('action_key', $actionKey)
                ->first();
            if ($existing !== null) {
                continue;
            }
            AiForbiddenAction::query()->create([
                'uuid' => (string) Str::uuid(),
                'policy_profile_id' => $profile->id,
                'action_key' => $actionKey,
                'description' => "Default forbidden action seeded with profile {$profile->policy_id}",
                'scope_type' => $profile->scope_type,
                'scope_ref' => $profile->scope_ref,
                'severity' => 'critical',
                'status' => 'active',
            ]);
        }
    }
}
