<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class BackgroundSafetyOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly BackgroundSafetyService $background,
    ) {}

    public function orchestratorId(): string
    {
        return 'BackgroundSafetyOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['background'];
    }

    public function supportedFlows(): array
    {
        return ['background.safe', 'background.readiness_review', 'background.schedule_review', 'background.permission_review'];
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

        $packet = $this->background->packet($flow, $input);

        return [
            'schema_version' => 'atlas.background.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'background',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_background_safety_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'background.safe');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'job' => (string) ($brief['job'] ?? $plan['job'] ?? ''),
            'scope' => (string) ($brief['scope'] ?? $plan['scope'] ?? ''),
            'trigger' => (string) ($brief['trigger'] ?? $plan['trigger'] ?? ''),
            'schedule' => (string) ($brief['schedule'] ?? $plan['schedule'] ?? ''),
            'permissions' => (array) ($brief['permissions'] ?? $plan['permissions'] ?? []),
            'evidence_refs' => (array) ($brief['evidence_refs'] ?? $plan['evidence_refs'] ?? []),
            'risk_class' => (string) ($brief['risk_class'] ?? $plan['risk_class'] ?? 'medium'),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'stop_conditions' => (array) ($brief['stop_conditions'] ?? $plan['stop_conditions'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_background'),
        ];

        $result = $this->background->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.background.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'background_packet',
            'domain' => 'background',
            'flow' => $flow,
            'result' => $result,
            'review_only_until_operator_acceptance' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.background.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'background_repairs_by_requesting_job_scope_schedule_permissions_and_stop_conditions',
            'domain' => 'background',
            'flow' => (string) ($failure['flow'] ?? 'background.safe'),
            'recommended_action' => 'Clarify background job, scope, trigger, schedule, permissions, evidence references, risk class, constraints, and stop conditions.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.background.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Background generated a governed safety/readiness packet with permission, schedule and stop-condition requirements, without starting jobs or changing schedules.',
            'domain' => 'background',
            'flow' => (string) ($result['flow'] ?? 'background.safe'),
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
            'schema_version' => 'atlas.background.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_background_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
