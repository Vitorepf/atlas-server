<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasHealthOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly HealthReviewService $health,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasHealthOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['health'];
    }

    public function supportedFlows(): array
    {
        return ['health.review', 'health.routine_review', 'health.recovery_review', 'health.safety_review'];
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

        $packet = $this->health->packet($flow, $input);

        return [
            'schema_version' => 'atlas.health.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'health',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_health_review_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'health.review');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'topic' => (string) ($brief['topic'] ?? $plan['topic'] ?? ''),
            'goal' => (string) ($brief['goal'] ?? $plan['goal'] ?? ''),
            'signals' => (array) ($brief['signals'] ?? $plan['signals'] ?? []),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'evidence_refs' => (array) ($brief['evidence_refs'] ?? $plan['evidence_refs'] ?? []),
            'risk_flags' => (array) ($brief['risk_flags'] ?? $plan['risk_flags'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_health'),
        ];

        $result = $this->health->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.health.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'health_review',
            'domain' => 'health',
            'flow' => $flow,
            'result' => $result,
            'non_clinical_review_only' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.health.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'health_repairs_by_requesting_topic_goal_constraints_and_risk_flags',
            'domain' => 'health',
            'flow' => (string) ($failure['flow'] ?? 'health.review'),
            'recommended_action' => 'Clarify topic, goal, signals, constraints, evidence references and risk flags, or escalate to professional review.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.health.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Health generated a governed non-clinical wellness/review packet and cannot diagnose, prescribe, change medication or replace professional care.',
            'domain' => 'health',
            'flow' => (string) ($result['flow'] ?? 'health.review'),
            'runtime_boundary' => 'non_clinical_review_only',
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
            'schema_version' => 'atlas.health.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_health_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
