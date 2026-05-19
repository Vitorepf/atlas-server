<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows;

/**
 * Canonical contract every Atlas AI Specialist Flow handler MUST implement.
 *
 * Handlers transform a router-decided envelope into the canonical specialist
 * flow contract (flow_id + intent + domain + policy_refs + evidence_refs +
 * output_contract + forbidden_actions + receipt). They NEVER call providers,
 * NEVER execute side effects — they emit auditable plan data only.
 */
interface SpecialistFlowHandlerContract
{
    /** Canonical flow_id this handler is responsible for. */
    public function flowId(): string;

    /**
     * Build the specialist runtime contract from an inbound router envelope.
     *
     * @param  array<string,mixed>  $router  router envelope (`atlas_ai_router` block from the gateway payload)
     * @param  array<string,mixed>  $payload  full payload (used to extract surface_id, workspace_present, etc.)
     * @return array<string,mixed> canonical specialist_flow_runtime contract (no receipt — registry attaches it)
     */
    public function buildContract(array $router, array $payload): array;
}
