<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasWritingOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly WritingDraftService $writing,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasWritingOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['writing'];
    }

    public function supportedFlows(): array
    {
        return ['writing.draft', 'writing.edit', 'writing.voice_review', 'writing.publish_review'];
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

        $packet = $this->writing->packet($flow, $input);

        return [
            'schema_version' => 'atlas.writing.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'writing',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_writing_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'writing.draft');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'goal' => (string) ($brief['goal'] ?? $plan['goal'] ?? ''),
            'audience' => (string) ($brief['audience'] ?? $plan['audience'] ?? ''),
            'voice' => (string) ($brief['voice'] ?? $plan['voice'] ?? ''),
            'source_material' => (array) ($brief['source_material'] ?? $plan['source_material'] ?? []),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_writing'),
        ];

        $result = $this->writing->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.writing.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'writing_packet',
            'domain' => 'writing',
            'flow' => $flow,
            'result' => $result,
            'external_publish_allowed' => false,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.writing.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'writing_repairs_by_requesting_brief_voice_or_sources',
            'domain' => 'writing',
            'flow' => (string) ($failure['flow'] ?? 'writing.draft'),
            'recommended_action' => 'Clarify goal, audience, voice, source material, publication risk, and review criteria.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.writing.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Writing generated a governed draft/review packet with voice alignment, human review, and no autonomous publication.',
            'domain' => 'writing',
            'flow' => (string) ($result['flow'] ?? 'writing.draft'),
            'external_publish_allowed' => false,
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
            'schema_version' => 'atlas.writing.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_writing_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
