<?php

namespace App\Services\Ai\SelfImprovement;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use Illuminate\Support\Str;

class AtlasSelfImprovementOrchestrator implements AtlasDomainOrchestrator
{
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
        $profile = $this->profiles->resolve('self_improvement.nightly_review');
        $hours = max(1, min(168, (int) ($options['hours'] ?? config('atlas_ai.self_improvement.hours', 24))));
        $limit = max(1, min(20, (int) ($options['limit'] ?? config('atlas_ai.self_improvement.limit', 5))));
        $emit = array_key_exists('emit', $options)
            ? (bool) $options['emit']
            : (bool) config('atlas_ai.self_improvement.emit', false);

        return [
            'schema_version' => 1,
            'plan_id' => (string) Str::orderedUuid(),
            'orchestrator' => 'AtlasSelfImprovementOrchestrator',
            'domain' => 'self_improvement',
            'flow' => 'self_improvement.nightly_review',
            'runtime' => 'SelfImprovementRuntime',
            'autonomy' => 'low',
            'background_allowed' => true,
            'destructive_actions_allowed' => false,
            'operator_approval_required_for_emit' => $emit,
            'options' => [
                'hours' => $hours,
                'limit' => $limit,
                'emit' => $emit,
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
        return ['self_improvement.nightly_review'];
    }

    public function maturity(): string
    {
        return 'implemented';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function executeNightlyReview(array $options = []): array
    {
        $plan = $this->nightlyReviewPlan($options);
        $runtime = $this->runtime->nightlyReview(
            emit: (bool) data_get($plan, 'options.emit', false),
            hours: (int) data_get($plan, 'options.hours', 24),
            limit: (int) data_get($plan, 'options.limit', 5),
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
}
