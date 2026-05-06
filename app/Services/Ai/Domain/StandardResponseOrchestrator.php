<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class StandardResponseOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly GeneralAnswerService $general,
    ) {}

    public function orchestratorId(): string
    {
        return 'StandardResponseOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['general'];
    }

    public function supportedFlows(): array
    {
        return ['general.answer'];
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

        $packet = $this->general->packet($flow, $input);

        return [
            'schema_version' => 'atlas.general.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'general',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_general_answer_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'general.answer');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'question' => (string) ($brief['question'] ?? $plan['question'] ?? ''),
            'context' => (array) ($brief['context'] ?? $plan['context'] ?? []),
            'attachments' => (array) ($brief['attachments'] ?? $plan['attachments'] ?? []),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'desired_output' => (string) ($brief['desired_output'] ?? $plan['desired_output'] ?? 'answer'),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_general'),
        ];

        $result = $this->general->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.general.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'general_answer',
            'domain' => 'general',
            'flow' => $flow,
            'result' => $result,
            'answer_or_triage_only' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.general.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'general_repairs_by_requesting_question_or_domain_handoff',
            'domain' => 'general',
            'flow' => (string) ($failure['flow'] ?? 'general.answer'),
            'recommended_action' => 'Clarify the question, context, desired output, or route to a specialized Atlas domain through Decide.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.general.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'General generated a governed answer/triage packet and must hand off specialized work to the correct Atlas domain.',
            'domain' => 'general',
            'flow' => (string) ($result['flow'] ?? 'general.answer'),
            'runtime_boundary' => 'answer_or_triage_only',
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
            'schema_version' => 'atlas.general.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_general_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
