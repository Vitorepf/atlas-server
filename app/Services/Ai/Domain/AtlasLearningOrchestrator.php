<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasLearningOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly LearningPlanService $learning,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasLearningOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['learning'];
    }

    public function supportedFlows(): array
    {
        return ['learning.plan', 'learning.practice', 'learning.review', 'learning.spaced_review'];
    }

    public function maturity(): string
    {
        return 'implemented';
    }

    public function plan(string $flow, array $input = [], array $context = []): array
    {
        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $input, $context);
        }

        $packet = $this->learning->packet($flow, $input);

        return [
            'schema_version' => 'atlas.learning.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'learning',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_learning_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'learning.plan');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'objective' => (string) ($brief['objective'] ?? $plan['objective'] ?? ''),
            'topic' => (string) ($brief['topic'] ?? $plan['topic'] ?? ''),
            'current_level' => (string) ($brief['current_level'] ?? $plan['current_level'] ?? 'unknown'),
            'target_level' => (string) ($brief['target_level'] ?? $plan['target_level'] ?? ''),
            'time_budget' => (string) ($brief['time_budget'] ?? $plan['time_budget'] ?? ''),
            'resources' => (array) ($brief['resources'] ?? $plan['resources'] ?? []),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_learning'),
        ];

        $result = $this->learning->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.learning.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'learning_packet',
            'domain' => 'learning',
            'flow' => $flow,
            'result' => $result,
            'plan_only_until_operator_acceptance' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.learning.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'learning_repairs_by_requesting_objective_topic_or_mastery_target',
            'domain' => 'learning',
            'flow' => (string) ($failure['flow'] ?? 'learning.plan'),
            'recommended_action' => 'Clarify objective, topic, current level, target level, time budget, resources, and mastery evidence.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.learning.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Learning generated a governed plan/practice/review packet with mastery evidence, spaced review, and no automatic task/calendar mutation.',
            'domain' => 'learning',
            'flow' => (string) ($result['flow'] ?? 'learning.plan'),
            'core_learning_plane' => 'not_modified',
            'context' => $context,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function unsupportedFlow(string $flow, array $payload, array $context): array
    {
        return [
            'schema_version' => 'atlas.learning.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_learning_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
