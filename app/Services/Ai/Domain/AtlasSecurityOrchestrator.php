<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasSecurityOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly SecurityReviewService $security,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasSecurityOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['security'];
    }

    public function supportedFlows(): array
    {
        return ['security.threat_review', 'security.privacy_review', 'security.compliance_review', 'security.incident_review'];
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

        $packet = $this->security->packet($flow, $input);

        return [
            'schema_version' => 'atlas.security.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => $packet['mode'],
            'domain' => 'security',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_security_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'security.threat_review');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $brief = (array) ($packet['brief'] ?? []);
        $input = [
            ...$context,
            'asset' => (string) ($brief['asset'] ?? $plan['asset'] ?? ''),
            'scope' => (string) ($brief['scope'] ?? $plan['scope'] ?? ''),
            'threat_model' => (array) ($brief['threat_model'] ?? $plan['threat_model'] ?? []),
            'controls' => (array) ($brief['controls'] ?? $plan['controls'] ?? []),
            'evidence_refs' => (array) ($brief['evidence_refs'] ?? $plan['evidence_refs'] ?? []),
            'risk_class' => (string) ($brief['risk_class'] ?? $plan['risk_class'] ?? 'medium'),
            'constraints' => (array) ($brief['constraints'] ?? $plan['constraints'] ?? []),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_security'),
        ];

        $result = $this->security->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.security.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => $packet['mode'] ?? 'security_packet',
            'domain' => 'security',
            'flow' => $flow,
            'result' => $result,
            'defensive_review_only' => true,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.security.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'security_repairs_by_requesting_asset_scope_controls_or_evidence',
            'domain' => 'security',
            'flow' => (string) ($failure['flow'] ?? 'security.threat_review'),
            'recommended_action' => 'Clarify asset, scope boundary, threat model, controls, evidence references, risk class, and privacy/compliance constraints.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.security.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Security generated a governed defensive review packet with scope boundaries, evidence requirements, and no exploit, secret, scan or control-disabling action.',
            'domain' => 'security',
            'flow' => (string) ($result['flow'] ?? 'security.threat_review'),
            'runtime_boundary' => 'defensive_review_only',
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
            'schema_version' => 'atlas.security.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_security_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
