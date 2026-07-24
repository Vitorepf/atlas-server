<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows;

use App\Support\CanonicalValue;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Atlas AI Specialist Flows · canonical entry router.
 *
 * Given a router envelope (`atlas_ai_router` block from the gateway payload)
 * and the surrounding payload, this router:
 *
 *  1. Resolves the canonical flow_id (with explicit fallback to
 *     `atlas_conversation` for unknown ids — NEVER falls into Dev/Forge);
 *  2. Refuses to handle programming-anchored flows (those belong to the
 *     Programming Adapter); returns a `delegate_to_programming_adapter`
 *     contract instead of stealing the traffic;
 *  3. Builds the specialist runtime contract via the registry handler;
 *  4. Attaches a deterministic receipt (sha256 over the canonicalized
 *     contract), schema `atlas.ai.specialist_flow_receipt.v1`.
 *
 * The router is provider-free: no Claude/Codex/Gemini, no HTTP call to any
 * external service. It transforms structured input into structured output.
 */
final class SpecialistFlowsRouter
{
    public function __construct(
        private readonly SpecialistFlowsRegistry $registry,
    ) {}

    /**
     * @param  array<string,mixed>  $router  `atlas_ai_router` block
     * @param  array<string,mixed>  $payload  full gateway payload (surface_id, operator_approvals, etc.)
     * @return array<string,mixed> specialist_flow_runtime contract + receipt
     */
    public function buildSpecialistFlowRuntime(array $router, array $payload = []): array
    {
        $rawFlowId = $this->stringOrNull($router['flow_id'] ?? null);

        // Refuse to handle programming-anchored flow_ids — they are owned by
        // the Programming Adapter canon, not by SpecialistFlows.
        if ($rawFlowId !== null && $this->registry->isProgrammingAnchored($rawFlowId)) {
            return $this->programmingDelegationContract($rawFlowId, $router, $payload);
        }

        // Resolve handler: known flow_id → its handler; else fallback to
        // conversation with explicit fallback reason recorded.
        // Missing flow_id ALSO counts as fallback (audit trail must show the
        // router envelope was incomplete) — even though conversation is the
        // resolved handler, the contract carries the `fallback` block.
        $flowId = $rawFlowId ?? SpecialistFlowsCanon::FALLBACK_FLOW;
        $isFallback = $rawFlowId === null || ! $this->registry->has($flowId);
        $effectiveFlowId = $isFallback ? SpecialistFlowsCanon::FALLBACK_FLOW : $flowId;

        $handler = $this->registry->handlerFor($effectiveFlowId);
        $contract = $handler->buildContract($router, $payload);

        if ($isFallback) {
            $contract['fallback'] = [
                'used' => true,
                'requested_flow_id' => $flowId,
                'effective_flow_id' => $effectiveFlowId,
                'reason' => $rawFlowId === null
                    ? 'router_envelope_did_not_carry_flow_id'
                    : 'requested_flow_id_not_owned_by_specialist_flows_registry',
            ];
        }

        return $this->withReceipt($contract);
    }

    /**
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function programmingDelegationContract(string $flowId, array $router, array $payload): array
    {
        $contract = [
            'schema_version' => SpecialistFlowsCanon::RUNTIME_SCHEMA_VERSION,
            'status' => SpecialistFlowsCanon::STATUS_PLANNED,
            'flow_id' => $flowId,
            'owner' => 'programming_adapter',
            'flow_origin' => $this->stringOrNull($router['flow_origin'] ?? null) ?? 'router_auto',
            'command_intent' => $this->stringOrNull($router['command_intent'] ?? null),
            'routing_reason' => $this->stringOrNull($router['routing_reason'] ?? null),
            'intent' => $this->stringOrNull(data_get($router, 'intent.intent_type'))
                ?? $this->stringOrNull($router['intent_type'] ?? null),
            'domain_id' => $this->stringOrNull(data_get($router, 'domain.id'))
                ?? $this->stringOrNull($router['domain_id'] ?? null),
            'surface_id' => $this->stringOrNull(data_get($router, 'handoff_payload.surface_id'))
                ?? $this->stringOrNull($payload['surface_id'] ?? null),
            'side_effect_policy' => SpecialistFlowsCanon::SIDE_EFFECT_READ_ONLY,
            'policy_refs' => SpecialistFlowsCanon::policyRefsFor($flowId),
            'required_evidence' => ['router_decision', 'programming_adapter_handoff'],
            'output_contract' => ['delegation_target', 'reason'],
            'forbidden_actions' => ['specialist_flows_must_not_execute_programming_flow_directly'],
            'delegation' => [
                'status' => 'delegate_to_programming_adapter',
                'target_flow_id' => $flowId,
                'reason' => 'programming_anchored_flow_owned_by_programming_adapter_canon',
            ],
            'fallback_flow_id' => SpecialistFlowsCanon::FALLBACK_FLOW,
        ];

        return $this->withReceipt($contract);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function withReceipt(array $contract): array
    {
        $canon = CanonicalValue::canonicalize($contract);
        $contractHash = MissionCanonicalHash::sha256($canon);

        $contract['receipt'] = [
            'schema_version' => SpecialistFlowsCanon::RECEIPT_SCHEMA_VERSION,
            'receipt_id' => 'sfr_'.substr($contractHash, 0, 32),
            'contract_hash' => $contractHash,
            'issued_by' => SpecialistFlowsCanon::RUNTIME_SCHEMA_VERSION,
            'flow_id' => $contract['flow_id'] ?? null,
            'owner' => $contract['owner'] ?? null,
            'execution_mode' => $contract['execution_mode'] ?? null,
            'delegation_status' => data_get($contract, 'delegation.status'),
            'side_effect_policy' => $contract['side_effect_policy'] ?? null,
            'required_evidence' => $contract['required_evidence'] ?? [],
            'policy_refs' => $contract['policy_refs'] ?? [],
        ];

        return $contract;
    }


    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Canonical list of flow_ids this router can handle directly (excludes
     * programming-anchored which it explicitly delegates).
     *
     * @return array<int,string>
     */
    public function ownedFlowIds(): array
    {
        return $this->registry->ownedFlowIds();
    }

    /**
     * Whether the given flow_id will be delegated to the Programming
     * Adapter instead of handled locally.
     */
    public function delegatesToProgrammingAdapter(string $flowId): bool
    {
        return $this->registry->isProgrammingAnchored($flowId);
    }
}
