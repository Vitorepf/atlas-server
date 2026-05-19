<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowHandlerContract;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Shared scaffolding for specialist flow handlers. Provides:
 *   - canonical envelope skeleton (status, owner, surface_id, policy_refs);
 *   - boundary guard: handlers NEVER set delegation.target to atlas_dev/forge
 *     unless the inbound router explicitly authorized it (router envelope
 *     carries `handoff_payload.workspace_present` + explicit programming
 *     domain); cross-domain delegation defaults to `not_delegated`.
 *
 * Subclasses override `flowId()` + `extend()` to add their domain-specific
 * fields (output_contract / forbidden_actions / mode-specific data).
 */
abstract class BaseSpecialistFlowHandler implements SpecialistFlowHandlerContract
{
    abstract public function flowId(): string;

    /**
     * Domain-specific extension hook. Receives the base contract + router +
     * payload; returns the merged extension to attach.
     *
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    abstract protected function extend(array $base, array $router, array $payload): array;

    /**
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function buildContract(array $router, array $payload): array
    {
        $flowId = $this->flowId();
        $workspacePresent = (bool) data_get($router, 'handoff_payload.workspace_present', false);
        $surfaceId = $this->stringOrNull(data_get($router, 'handoff_payload.surface_id'))
            ?? $this->stringOrNull($payload['surface_id'] ?? null);

        $base = [
            'schema_version' => SpecialistFlowsCanon::RUNTIME_SCHEMA_VERSION,
            'status' => SpecialistFlowsCanon::STATUS_PLANNED,
            'flow_id' => $flowId,
            'owner' => $flowId,
            'flow_origin' => $this->stringOrNull($router['flow_origin'] ?? null) ?? 'router_auto',
            'command_intent' => $this->stringOrNull($router['command_intent'] ?? null),
            'routing_reason' => $this->stringOrNull($router['routing_reason'] ?? null),
            'intent' => $this->stringOrNull(data_get($router, 'intent.intent_type'))
                ?? $this->stringOrNull($router['intent_type'] ?? null),
            'domain_id' => $this->stringOrNull(data_get($router, 'domain.id'))
                ?? $this->stringOrNull($router['domain_id'] ?? null),
            'workspace_present' => $workspacePresent,
            'surface_id' => $surfaceId,
            'side_effect_policy' => SpecialistFlowsCanon::SIDE_EFFECT_READ_ONLY,
            'policy_refs' => SpecialistFlowsCanon::policyRefsFor($flowId),
            'required_evidence' => SpecialistFlowsCanon::requiredEvidenceFor($flowId),
            'output_contract' => SpecialistFlowsCanon::outputContractFor($flowId),
            'forbidden_actions' => SpecialistFlowsCanon::forbiddenActionsFor($flowId),
            'delegation' => [
                // Specialist flows NEVER auto-delegate to Atlas Dev / Forge.
                // The programming adapter owns those handoffs explicitly.
                'status' => 'not_delegated',
                'reason' => 'specialist_flow_matches_router_decision',
                'forbidden_targets' => RouterRuntimeCanon::PROGRAMMING_FLOW_IDS,
            ],
            'fallback_flow_id' => SpecialistFlowsCanon::FALLBACK_FLOW,
        ];

        return $this->extend($base, $router, $payload);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Helper: detect operator approval signals in the inbound payload.
     */
    protected function operatorApproved(array $payload, string $approvalKey): bool
    {
        $approvals = data_get($payload, 'operator_approvals');
        if (is_array($approvals) && in_array($approvalKey, $approvals, true)) {
            return true;
        }
        $envelope = data_get($payload, 'atlas_ai_router.operator_approvals');

        return is_array($envelope) && in_array($approvalKey, $envelope, true);
    }
}
