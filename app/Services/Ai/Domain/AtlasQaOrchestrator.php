<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasQaOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly QaReviewService $qa,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasQaOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['qa'];
    }

    public function supportedFlows(): array
    {
        return ['qa.regression_review', 'qa.acceptance_review', 'qa.evidence_audit', 'qa.release_readiness'];
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

        $packet = $this->qa->packet($flow, $input);

        return [
            'schema_version' => 'atlas.qa.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'qa',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_qa_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'qa.regression_review');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'subject' => (string) ($brief['subject'] ?? $plan['subject'] ?? ''),
            'scope' => (string) ($brief['scope'] ?? $plan['scope'] ?? ''),
            'change_summary' => (string) ($brief['change_summary'] ?? $plan['change_summary'] ?? ''),
            'acceptance_criteria' => (array) ($brief['acceptance_criteria'] ?? $plan['acceptance_criteria'] ?? []),
            'evidence_refs' => (array) ($brief['evidence_refs'] ?? $plan['evidence_refs'] ?? []),
            'risk_class' => (string) ($brief['risk_class'] ?? $plan['risk_class'] ?? 'medium'),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_qa'),
        ];

        $result = $this->qa->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.qa.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'qa_packet',
            'domain' => 'qa',
            'flow' => $flow,
            'result' => $result,
            'review_only_until_operator_acceptance' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.qa.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'qa_repairs_by_requesting_scope_criteria_or_evidence',
            'domain' => 'qa',
            'flow' => (string) ($failure['flow'] ?? 'qa.regression_review'),
            'recommended_action' => 'Clarify QA subject, scope, acceptance criteria, evidence references, risk class, and release constraints.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.qa.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'QA generated a governed cross-domain review packet with evidence requirements, uncertainty visibility, and no autonomous test execution or release.',
            'domain' => 'qa',
            'flow' => (string) ($result['flow'] ?? 'qa.regression_review'),
            'runtime_boundary' => 'review_only',
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
            'schema_version' => 'atlas.qa.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_qa_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
