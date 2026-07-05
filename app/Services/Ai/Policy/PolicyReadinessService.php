<?php

namespace App\Services\Ai\Policy;

use App\Models\AiApprovalRequest;
use App\Models\AiBudgetEnvelope;
use App\Models\AiForbiddenAction;
use App\Models\AiPermissionGate;
use App\Models\AiPolicyProfile;
use App\Models\AiRiskAssessment;
use App\Models\AiSafetyDecision;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class PolicyReadinessService
{
    use \App\Services\Ai\Support\BuildsReadinessChecks;

    private const REQUIRED_TABLES = [
        'ai_policy_profiles',
        'ai_permission_gates',
        'ai_approval_requests',
        'ai_budget_envelopes',
        'ai_risk_assessments',
        'ai_safety_decisions',
        'ai_forbidden_actions',
    ];

    private const REQUIRED_MODELS = [
        AiPolicyProfile::class,
        AiPermissionGate::class,
        AiApprovalRequest::class,
        AiBudgetEnvelope::class,
        AiRiskAssessment::class,
        AiSafetyDecision::class,
        AiForbiddenAction::class,
    ];

    private const REQUIRED_SERVICES = [
        PolicyProfileRegistryService::class,
        PermissionGateService::class,
        ApprovalRequestService::class,
        BudgetEnvelopeService::class,
        RiskAssessmentService::class,
        SafetyDecisionService::class,
        ForbiddenActionService::class,
        PolicyControlPlaneService::class,
    ];

    private const REQUIRED_DEFAULT_POLICY_IDS = [
        'global.default',
        'programming.default',
        'finance.research_only',
        'finance.live_trade_blocked_by_default',
        'cyber.defensive_only',
        'cyber.offensive_requires_authorization',
        'marketing.publish_requires_approval',
        'automation.external_action_requires_policy',
        'tool.external_cost_requires_approval',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        $checks = array_merge($checks, $this->tableChecks(self::REQUIRED_TABLES));

        $checks = array_merge($checks, $this->modelChecks(self::REQUIRED_MODELS));

        $checks = array_merge($checks, $this->serviceChecks($this->container, self::REQUIRED_SERVICES));

        $checks[] = $this->checkDefaultsCoverage();
        $checks[] = $this->checkDecisionEnum();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.policy.readiness.v1',
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'checks' => $checks,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDefaultsCoverage(): array
    {
        if (! DatabaseTableAvailability::has('ai_policy_profiles')) {
            return [
                'name' => 'defaults:policy_profiles_seeded',
                'status' => 'failed',
                'detail' => 'ai_policy_profiles table missing',
            ];
        }

        $existing = AiPolicyProfile::query()
            ->whereIn('policy_id', self::REQUIRED_DEFAULT_POLICY_IDS)
            ->pluck('policy_id')
            ->all();

        $missing = array_values(array_diff(self::REQUIRED_DEFAULT_POLICY_IDS, $existing));
        $passed = $missing === [];

        return [
            'name' => 'defaults:policy_profiles_seeded',
            'status' => $passed ? 'passed' : 'failed',
            'detail' => $passed
                ? 'all '.count(self::REQUIRED_DEFAULT_POLICY_IDS).' default policies present'
                : 'missing defaults: '.implode(',', $missing).' - run atlas:ai:policy --action=seed-defaults',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDecisionEnum(): array
    {
        $expected = [
            PolicyCanon::DECISION_ALLOW,
            PolicyCanon::DECISION_DENY,
            PolicyCanon::DECISION_REQUIRE_APPROVAL,
            PolicyCanon::DECISION_BLOCKED,
        ];
        $actual = PolicyCanon::DECISIONS;
        $ok = $actual === $expected;

        return [
            'name' => 'canon:decision_enum',
            'status' => $ok ? 'passed' : 'failed',
            'detail' => $ok ? 'decision enum aligned' : 'decision enum drift',
        ];
    }
}
