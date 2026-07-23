<?php

namespace App\Services\Ai\PersonalDevelopment;

use App\Services\Ai\Policy\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use Illuminate\Support\Str;

class AtlasPersonalDevelopmentOrchestrator implements AtlasDomainOrchestrator
{
    /**
     * @var array<int,string>
     */
    public const FLOWS = [
        'personal_development.reflect',
        'personal_development.daily_review',
        'personal_development.weekly_review',
        'personal_development.habit_design',
        'personal_development.focus_plan',
        'personal_development.learning_plan',
        'personal_development.energy_review',
        'personal_development.goal_decomposition',
        'personal_development.recovery_plan',
        'personal_development.forge',
    ];

    public function __construct(
        private readonly AtlasDomainProfileRegistry $profiles,
        private readonly PersonalDevelopmentRuntime $runtime,
        private readonly PersonalDevelopmentSafetyPolicy $safety,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function flowPlan(string $flow = 'personal_development.reflect', array $options = []): array
    {
        $flow = self::normalizeFlowName($flow);
        $profile = $this->profiles->resolve($flow);
        $approvalRequired = $flow === 'personal_development.forge';
        $flowContract = PersonalDevelopmentFlowCatalog::get($flow);

        return [
            'schema_version' => 1,
            'plan_id' => (string) Str::orderedUuid(),
            'orchestrator' => $this->orchestratorId(),
            'domain' => 'personal_development',
            'flow' => $flow,
            'runtime' => 'PersonalDevelopmentRuntime',
            'autonomy' => $approvalRequired ? 'review_required' : 'low',
            'background_allowed' => false,
            'destructive_actions_allowed' => false,
            'approval_required' => $approvalRequired,
            'approval_reasons' => $approvalRequired ? ['forge_flow_requires_human_approval'] : [],
            'flow_contract' => [
                'cadence' => $flowContract['cadence'],
                'risk' => $flowContract['risk'],
                'type' => $flowContract['type'],
                'artifact' => $flowContract['artifact'],
                'focus' => $flowContract['focus'],
            ],
            'domain_profile' => $profile['domain_profile'] ?? [],
            'flow_profile' => $profile['flow_profile'] ?? [],
            'profile_receipt' => [
                'source' => $profile['source'] ?? null,
                'domain_id' => $profile['domain_id'] ?? 'personal_development',
                'flow_id' => $profile['flow_id'] ?? $flow,
            ],
            'memory_policy' => data_get($profile, 'flow_profile.memory_policy', data_get($profile, 'domain_profile.memory_policy', [])),
            'required_gates' => data_get($profile, 'flow_profile.gate_policy.required', []),
            'execution_policy' => data_get($profile, 'flow_profile.execution_policy', []),
            'runtime_contract' => [
                'returns_plan_and_artifacts_only' => true,
                'may_change_calendar_or_tasks' => false,
                'may_change_habit_tracker' => false,
                'may_send_external_messages' => false,
                'sensitive_recommendations_require_human_review' => true,
                'non_clinical' => true,
                'no_psychological_diagnosis' => true,
                'no_medical_treatment' => true,
            ],
            'blocked_actions' => $this->safety->blockedActions(),
            'options' => [
                'human_approved' => (bool) ($options['human_approved'] ?? false),
            ],
            'created_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function executeFlow(string $flow, array $input = [], array $options = []): array
    {
        $plan = $this->flowPlan($flow, $options);
        $runtime = $this->runtime->execute((string) $plan['flow'], $input, $options);

        return [
            'schema_version' => 1,
            'status' => $runtime['status'],
            'orchestrator' => $this->orchestratorId(),
            'plan' => $plan,
            'runtime' => $runtime,
            'evidence_refs' => $runtime['evidence_refs'],
            'completed_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function reflectPlan(array $options = []): array
    {
        return $this->flowPlan('personal_development.reflect', $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function forgePlan(array $options = []): array
    {
        return $this->flowPlan('personal_development.forge', $options);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function executeForge(array $input = [], array $options = []): array
    {
        return $this->executeFlow('personal_development.forge', $input, $options);
    }

    public function orchestratorId(): string
    {
        return 'AtlasPersonalDevelopmentOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['personal_development'];
    }

    public function supportedFlows(): array
    {
        return PersonalDevelopmentFlowCatalog::ids();
    }

    public function maturity(): string
    {
        return 'implemented';
    }

    public function plan(string $flow, array $input = [], array $context = []): array
    {
        return $this->flowPlan($flow, array_merge($context, $input));
    }

    public function execute(array $plan, array $context = []): array
    {
        return $this->runtime->execute(
            (string) ($plan['flow'] ?? 'personal_development.reflect'),
            is_array($context['input'] ?? null) ? $context['input'] : [],
            $context,
        );
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => 'reflection_replan_required',
            'reason' => 'personal_development_repairs_are_plan_only_and_non_clinical',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'evidence_refs' => (array) ($result['evidence_refs'] ?? []),
            'context' => $context,
        ];
    }

    public static function normalizeFlowName(string $flow): string
    {
        $flow = trim($flow);

        if ($flow === '') {
            return 'personal_development.reflect';
        }

        if (! str_starts_with($flow, 'personal_development.')) {
            $flow = 'personal_development.'.$flow;
        }

        if (! PersonalDevelopmentFlowCatalog::has($flow)) {
            throw new \InvalidArgumentException("Unsupported personal development flow [{$flow}].");
        }

        return $flow;
    }
}
