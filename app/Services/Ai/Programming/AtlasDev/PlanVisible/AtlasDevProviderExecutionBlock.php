<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PlanVisible;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use RuntimeException;

/**
 * Gap2.F4 DoD — "rejeitar plano cancela a execução".
 *
 * Single chokepoint that callers consult BEFORE invoking the provider.
 * Returns the canonical execution-block decision envelope and, when the
 * plan is rejected/pending/missing, raises a typed exception so the
 * caller fail-fast cancels.
 *
 * This is a thin orchestrator over the existing
 * `AtlasDevPlanProjectionService::loadPersisted()` and
 * `AtlasDevPlanApprovalGate::evaluate()`. The chokepoint exists so the
 * cancellation semantics are testable in isolation and so future
 * provider invokers don't reinvent the gate consult contract.
 */
final class AtlasDevProviderExecutionBlock
{
    public const SCHEMA_VERSION = 'atlas.dev.provider_execution_block.v1';

    public function __construct(
        private readonly AtlasDevPlanProjectionService $projection,
        private readonly AtlasDevPlanApprovalGate $gate,
    ) {}

    /**
     * Throws when execution must be cancelled. Returns the gate decision
     * envelope when execution is allowed.
     *
     * @return array<string,mixed>
     */
    public function assertMayFire(AtlasProgrammingWorkItem $workItem): array
    {
        $plan = $this->projection->loadPersisted($workItem);
        $decision = $this->gate->evaluate($plan);

        if ($decision['decision'] !== 'may_fire_provider') {
            throw new ProviderExecutionCancelledException(
                sprintf(
                    'Provider invocation cancelled by Plan Visible gate: %s — %s',
                    $decision['block_reason'] ?? 'unknown',
                    $decision['detail'] ?? 'no detail',
                ),
                $decision,
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'execution_allowed' => true,
            'plan_hash' => $decision['plan_hash'],
            'approval_status' => $decision['approval_status'],
            'gate_decision' => $decision,
        ];
    }

    /**
     * Non-throwing variant for callers that prefer envelope-based control flow.
     *
     * @return array<string,mixed>
     */
    public function evaluate(AtlasProgrammingWorkItem $workItem): array
    {
        $plan = $this->projection->loadPersisted($workItem);
        $decision = $this->gate->evaluate($plan);
        $allowed = $decision['decision'] === 'may_fire_provider';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'execution_allowed' => $allowed,
            'cancelled_reason' => $allowed ? null : ($decision['block_reason'] ?? 'unknown'),
            'plan_hash' => $decision['plan_hash'] ?? null,
            'approval_status' => $decision['approval_status'] ?? null,
            'gate_decision' => $decision,
        ];
    }

    /**
     * Used by Plan Visible CLI `reject` action to fail-fast any pending
     * provider invocation attached to the work item via its task queue.
     * In Phase 1 this just emits the cancellation envelope; in Phase 2
     * the queued AiJob will be marked `cancelled` via a sibling AP.
     *
     * @return array<string,mixed>
     */
    public function cancellationEnvelope(AtlasProgrammingWorkItem $workItem, ?PlanVisible $plan): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'execution_allowed' => false,
            'cancelled_reason' => $plan?->isRejected() === true
                ? 'plan_rejected_by_operator'
                : ($plan === null ? 'no_plan_persisted' : 'plan_pending_operator_approval'),
            'work_item_id' => $workItem->id,
            'plan_hash' => $plan?->planHash,
            'cancelled_at' => now()->toAtomString(),
        ];
    }
}

final class ProviderExecutionCancelledException extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $gateDecision
     */
    public function __construct(
        string $message,
        public readonly array $gateDecision,
    ) {
        parent::__construct($message);
    }
}
