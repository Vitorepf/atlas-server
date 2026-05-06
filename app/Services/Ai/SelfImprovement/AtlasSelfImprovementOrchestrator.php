<?php

namespace App\Services\Ai\SelfImprovement;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use Illuminate\Support\Str;

class AtlasSelfImprovementOrchestrator implements AtlasDomainOrchestrator
{
    /**
     * @var array<int,string>
     */
    public const SUPPORTED_FLOWS = [
        'self_improvement.nightly_review',
        'self_improvement.weekly_architecture_audit',
        'self_improvement.capability_gap_scan',
        'self_improvement.benchmark_review',
        'self_improvement.memory_quality_review',
        'self_improvement.tool_runtime_review',
        'self_improvement.repair_loop_review',
        'self_improvement.kernel_pipeline_review',
        'self_improvement.domain_learning_review',
        'self_improvement.docs_drift_review',
        'self_improvement.provider_performance_review',
        'self_improvement.proposal_generation',
    ];

    public function __construct(
        private readonly AtlasDomainProfileRegistry $profiles,
        private readonly AtlasSelfImprovementRuntime $runtime,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function nightlyReviewPlan(array $options = []): array
    {
        return $this->flowPlan('self_improvement.nightly_review', $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function flowPlan(string $flow, array $options = []): array
    {
        $flow = $this->normalizeFlow($flow);
        $profile = $this->profiles->resolve($flow);
        $hours = max(1, min(
            AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS,
            (int) ($options['hours'] ?? config('atlas_ai.self_improvement.hours', AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS)),
        ));
        $limit = max(1, min(
            AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN,
            (int) ($options['limit'] ?? config('atlas_ai.self_improvement.limit', 5)),
        ));
        $emit = array_key_exists('emit', $options)
            ? (bool) $options['emit']
            : (bool) config('atlas_ai.self_improvement.emit', false);

        return [
            'schema_version' => 1,
            'plan_id' => (string) Str::orderedUuid(),
            'orchestrator' => 'AtlasSelfImprovementOrchestrator',
            'domain' => 'self_improvement',
            'flow' => $flow,
            'runtime' => 'SelfImprovementRuntime',
            'autonomy' => 'low',
            'background_allowed' => true,
            'destructive_actions_allowed' => false,
            'operator_approval_required_for_emit' => $emit,
            'options' => [
                'hours' => $hours,
                'limit' => $limit,
                'emit' => $emit,
                'filters' => $this->dimensionFilters($options),
            ],
            'domain_profile' => $profile['domain_profile'] ?? [],
            'flow_profile' => $profile['flow_profile'] ?? [],
            'required_gates' => data_get($profile, 'flow_profile.gate_policy.required', []),
            'execution_policy' => data_get($profile, 'flow_profile.execution_policy', []),
            'created_at' => now()->toJSON(),
        ];
    }

    public function orchestratorId(): string
    {
        return 'AtlasSelfImprovementOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['self_improvement'];
    }

    public function supportedFlows(): array
    {
        return self::SUPPORTED_FLOWS;
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
        return $this->executeFlow((string) ($plan['flow'] ?? 'self_improvement.nightly_review'), array_merge($context, [
            'hours' => data_get($plan, 'options.hours'),
            'limit' => data_get($plan, 'options.limit'),
            'emit' => data_get($plan, 'options.emit'),
            'filters' => data_get($plan, 'options.filters', []),
        ]));
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => 'proposal_only',
            'reason' => 'self_improvement_repairs_emit_proposals_before_runtime_changes',
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

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function executeNightlyReview(array $options = []): array
    {
        return $this->executeFlow('self_improvement.nightly_review', $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function executeFlow(string $flow, array $options = []): array
    {
        $flow = $this->normalizeFlow($flow);
        $plan = $this->flowPlan($flow, $options);
        $runtime = $this->runtime->nightlyReview(
            flow: $flow,
            emit: (bool) data_get($plan, 'options.emit', false),
            hours: (int) data_get($plan, 'options.hours', 24),
            limit: (int) data_get($plan, 'options.limit', 5),
            filters: (array) data_get($plan, 'options.filters', []),
        );

        return [
            'schema_version' => 1,
            'status' => $runtime['ok'] ? 'completed' : 'failed',
            'orchestrator' => 'AtlasSelfImprovementOrchestrator',
            'plan' => $plan,
            'runtime' => $runtime,
            'evidence_refs' => array_filter([
                $runtime['run_id'] ? 'atlas_initiative_run:'.$runtime['run_id'] : null,
                $runtime['run_id'] ? 'ledger:self_improvement_run:'.$runtime['run_id'] : null,
            ]),
            'completed_at' => now()->toJSON(),
        ];
    }

    private function normalizeFlow(string $flow): string
    {
        $flow = trim($flow);
        if ($flow === '') {
            $flow = 'self_improvement.nightly_review';
        }

        if (! str_starts_with($flow, 'self_improvement.')) {
            $flow = 'self_improvement.'.$flow;
        }

        if (! in_array($flow, self::SUPPORTED_FLOWS, true)) {
            throw new \InvalidArgumentException("Unsupported self-improvement flow [{$flow}].");
        }

        return $flow;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,string>
     */
    private function dimensionFilters(array $options): array
    {
        $filters = is_array($options['filters'] ?? null) ? $options['filters'] : [];
        $aliases = [
            'domain' => ['domain'],
            'flow' => ['dimension_flow', 'slo_flow'],
            'surface_id' => ['surface_id', 'surface'],
            'provider' => ['provider'],
            'model' => ['model'],
            'runtime' => ['runtime'],
            'tool_id' => ['tool_id', 'tool'],
            'status' => ['status', 'repair_status', 'kernel_status'],
            'strategy' => ['strategy', 'repair_strategy'],
            'failure_domain' => ['failure_domain'],
            'emitter_stage' => ['emitter_stage', 'repair_emitter_stage'],
            'input_mode' => ['input_mode', 'kernel_input_mode'],
            'onboarding_status' => ['onboarding_status'],
        ];

        foreach ($aliases as $dimension => $keys) {
            foreach ($keys as $key) {
                $value = $options[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $filters[$dimension] = trim((string) $value);
                    break;
                }
            }
        }

        return collect($filters)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->only(['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id', 'status', 'strategy', 'failure_domain', 'emitter_stage', 'input_mode', 'onboarding_status'])
            ->all();
    }
}
