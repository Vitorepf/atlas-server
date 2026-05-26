<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\DurableExecution;

/**
 * Programming Harness — Durable Execution Runner (orchestrator).
 *
 * The single entry point that chains Preflight → DecisionContract →
 * HandoffPacket → Receipt. Implements the "unify execution behind
 * Decision Receipt + Evidence Ledger + repair policy" DoD from
 * Safe Next Block #2 of `implemented-vs-scaffold-matrix.md`.
 *
 * The runner does NOT call providers itself — it produces canonical
 * envelopes that the existing AtlasDev/Forge invokers consume. This is
 * the seam that lets durable execution coexist with the legacy fast
 * path without rewriting either.
 */
final class DurableExecutionRunner
{
    public const SCHEMA_VERSION = 'atlas.programming.durable_execution_run.v1';

    public function __construct(
        private readonly DurableExecutionPreflight $preflight,
        private readonly DurableExecutionDecisionContract $decisions,
        private readonly DurableExecutionHandoffPacket $handoff,
        private readonly DurableExecutionReceipt $receipts,
    ) {}

    /**
     * Plan a durable run end-to-end. Returns the full envelope chain so
     * the caller can persist each step into the Evidence Ledger.
     *
     * @param  array<string,mixed>  $snapshot  Work item snapshot.
     * @param  string  $actor                  operator:|service:|agent:<id>
     * @return array{
     *   schema_version: string,
     *   preflight: array<string,mixed>,
     *   decision: array<string,mixed>,
     *   handoff: ?array<string,mixed>,
     *   ready_to_execute: bool,
     *   blocking_reasons: list<string>
     * }
     */
    public function plan(array $snapshot, string $actor): array
    {
        $preflight = $this->preflight->evaluate($snapshot);
        $autoDecision = $this->autoDecision($preflight, $snapshot);
        $decision = $this->decisions->decide($preflight, $autoDecision, $actor);

        $handoff = null;
        if (in_array($decision['decision'], [
            DurableExecutionDecisionContract::DECISION_EXECUTE,
            DurableExecutionDecisionContract::DECISION_ESCALATE_FORGE,
        ], true)) {
            $handoff = $this->handoff->build($preflight, $decision);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'preflight' => $preflight,
            'decision' => $decision,
            'handoff' => $handoff,
            'ready_to_execute' => $decision['decision'] === DurableExecutionDecisionContract::DECISION_EXECUTE,
            'blocking_reasons' => (array) ($preflight['blocking_reasons'] ?? []),
        ];
    }

    /**
     * Close a durable run with a receipt of the outcome.
     *
     * @param  array<string,mixed>  $decision  The decision envelope from plan().
     * @param  array<string,mixed>  $metrics   Runtime metrics.
     */
    public function closeWithOutcome(string $outcome, array $decision, array $metrics = []): array
    {
        return $this->receipts->issue($outcome, $decision, $metrics);
    }

    /**
     * Heuristic auto-decision: when the preflight blocks, suggest the
     * minimal safe action. Operator/service can override via explicit
     * `actor_override_decision` in snapshot.
     *
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $snapshot
     */
    private function autoDecision(array $preflight, array $snapshot): string
    {
        $override = $snapshot['actor_override_decision'] ?? null;
        if (is_string($override) && in_array($override, DurableExecutionDecisionContract::ALLOWED_DECISIONS, true)) {
            return $override;
        }

        if ((bool) ($preflight['may_execute'] ?? false)) {
            // Risk-based heuristic: critical risk → escalate to Forge
            $risk = (string) ($preflight['evidence']['risk_level'] ?? 'medium');
            if ($risk === 'critical') {
                return DurableExecutionDecisionContract::DECISION_ESCALATE_FORGE;
            }

            return DurableExecutionDecisionContract::DECISION_EXECUTE;
        }

        // Preflight blocked → defer for replanning
        return DurableExecutionDecisionContract::DECISION_DEFER;
    }
}
