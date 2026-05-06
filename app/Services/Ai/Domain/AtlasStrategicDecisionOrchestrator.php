<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasStrategicDecisionOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly StrategicDecisionReviewService $reviews,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasStrategicDecisionOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['strategic_decision'];
    }

    public function supportedFlows(): array
    {
        return [
            'strategic_decision.review',
            'strategic_decision.cooldown',
            'strategic_decision.values_alignment',
            'strategic_decision.counterargument',
            'strategic_decision.regret_tracking',
            'strategic_decision.longitudinal_pattern',
        ];
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

        $packet = $this->reviews->packet([
            ...$input,
            'flow' => $flow,
        ]);

        return [
            'schema_version' => 'atlas.strategic_decision.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => 'review_only',
            'domain' => 'strategic_decision',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_review_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'strategic_decision.review');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $input = [
            ...$context,
            'title' => (string) ($packet['title'] ?? $plan['title'] ?? ''),
            'decision' => (string) data_get($packet, 'decision_frame.decision', $plan['decision'] ?? ''),
            'impact' => (string) data_get($packet, 'decision_frame.impact', $plan['impact'] ?? 'medium'),
            'horizon_days' => (int) data_get($packet, 'decision_frame.horizon_days', $plan['horizon_days'] ?? 90),
            'options' => (array) data_get($packet, 'decision_frame.options', $plan['options'] ?? []),
            'values' => (array) data_get($packet, 'values_alignment.values', $plan['values'] ?? []),
            'constraints' => (array) data_get($packet, 'decision_frame.constraints', $plan['constraints'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_strategic_decision'),
        ];

        $result = $this->reviews->auditedPacket($input);

        return [
            'schema_version' => 'atlas.strategic_decision.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => 'review_only',
            'domain' => 'strategic_decision',
            'flow' => 'strategic_decision.review',
            'result' => $result,
            'no_external_side_effects' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.strategic_decision.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_applicable',
            'reason' => 'strategic_decision_is_review_only_and_repairs_by_reframing',
            'domain' => 'strategic_decision',
            'flow' => (string) ($failure['flow'] ?? 'strategic_decision.review'),
            'recommended_action' => 'Regenerate the review packet with clearer title, options, values, constraints, and evidence.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.strategic_decision.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Strategic Decision generated a review-only packet with agency, cool-down, values, counterargument, and Rivals Strategy controls.',
            'domain' => 'strategic_decision',
            'flow' => (string) ($result['flow'] ?? 'strategic_decision.review'),
            'no_external_side_effects' => true,
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
            'schema_version' => 'atlas.strategic_decision.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_strategic_decision_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
