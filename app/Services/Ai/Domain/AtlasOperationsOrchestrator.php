<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasOperationsOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly OperationsDiagnosticService $operations,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasOperationsOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['operations'];
    }

    public function supportedFlows(): array
    {
        return ['operations.diagnostic', 'operations.runbook', 'operations.incident_review', 'operations.readiness_review'];
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

        $packet = $this->operations->packet($flow, $input);

        return [
            'schema_version' => 'atlas.operations.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'operations',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_operations_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'operations.diagnostic');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'system' => (string) ($brief['system'] ?? $plan['system'] ?? ''),
            'scope' => (string) ($brief['scope'] ?? $plan['scope'] ?? ''),
            'symptoms' => (array) ($brief['symptoms'] ?? $plan['symptoms'] ?? []),
            'signals' => (array) ($brief['signals'] ?? $plan['signals'] ?? []),
            'evidence_refs' => (array) ($brief['evidence_refs'] ?? $plan['evidence_refs'] ?? []),
            'risk_class' => (string) ($brief['risk_class'] ?? $plan['risk_class'] ?? 'medium'),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_operations'),
        ];

        $result = $this->operations->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.operations.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'operations_packet',
            'domain' => 'operations',
            'flow' => $flow,
            'result' => $result,
            'diagnostic_only_until_operator_acceptance' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.operations.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'operations_repairs_by_requesting_system_scope_signals_or_evidence',
            'domain' => 'operations',
            'flow' => (string) ($failure['flow'] ?? 'operations.diagnostic'),
            'recommended_action' => 'Clarify system, scope, symptoms, signals, evidence references, risk class, and operational constraints.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.operations.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Operations generated a governed diagnostic/runbook packet with evidence requirements and no restart, deploy, infrastructure mutation or data deletion.',
            'domain' => 'operations',
            'flow' => (string) ($result['flow'] ?? 'operations.diagnostic'),
            'runtime_boundary' => 'diagnostic_only',
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
            'schema_version' => 'atlas.operations.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_operations_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
