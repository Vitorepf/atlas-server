<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasResearchOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly ResearchPacketService $research,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasResearchOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['research'];
    }

    public function supportedFlows(): array
    {
        return ['research.quick', 'research.super'];
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

        $packet = $this->research->packet($flow, $input);

        return [
            'schema_version' => 'atlas.research.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'research',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_research_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'research.quick');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $input = [
            ...$context,
            'question' => (string) ($packet['question'] ?? $plan['question'] ?? ''),
            'purpose' => (string) ($packet['purpose'] ?? $plan['purpose'] ?? ''),
            'sources' => (array) data_get($packet, 'source_pack.sources', $plan['sources'] ?? []),
            'constraints' => (array) data_get($packet, 'research_plan.constraints', $plan['constraints'] ?? []),
            'freshness' => (string) data_get($packet, 'source_pack.freshness_requirement', $plan['freshness'] ?? 'normal'),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_research'),
        ];

        $result = $this->research->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.research.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'research_plan',
            'domain' => 'research',
            'flow' => $flow,
            'result' => $result,
            'memory_promotion' => 'proposal_only',
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.research.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'research_repairs_by_requesting_sources_or_narrowing_question',
            'domain' => 'research',
            'flow' => (string) ($failure['flow'] ?? 'research.quick'),
            'recommended_action' => 'Add source refs, narrow the question, declare freshness, and include contradiction search for deep research.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.research.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Research generated a source-grounded packet with citations, uncertainty, contradiction checks, and memory promotion as proposal-only.',
            'domain' => 'research',
            'flow' => (string) ($result['flow'] ?? 'research.quick'),
            'memory_promotion' => 'proposal_only',
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
            'schema_version' => 'atlas.research.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_research_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
