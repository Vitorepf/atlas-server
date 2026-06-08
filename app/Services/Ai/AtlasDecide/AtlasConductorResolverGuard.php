<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use Closure;

/**
 * Opt-in decorator around the conductor's per-arm resolver closure that adds two
 * Atlas-sovereign ports of the Dynamic Workflows primitives the reverse-engineering
 * surfaced — a turn-wide token ceiling and a provider-boundary structured-output
 * gate — WITHOUT rebuilding the conductor and WITHOUT changing the default path.
 *
 * If neither option is requested, {@see decorate()} returns the inner resolver
 * unchanged (byte-for-byte default behaviour). When requested:
 *   - turn_budget_units: the K arms of one dispatch share a single ceiling; an arm
 *     whose estimated input cost would exceed it is refused with a held
 *     `turn_budget_exhausted` failure outcome — never a throw (the executor mangles
 *     throws into resolver_error), so the existing tie-break simply routes around it.
 *   - output_schema: a successful arm whose output does not satisfy the declared
 *     JSON-Schema subset is failed `schema_unsatisfied` instead of being trusted as
 *     an unchecked string.
 *
 * Stateless and dependency-free, so the conductor can new it up at the call site
 * without a constructor change, and it is testable in full isolation.
 */
final class AtlasConductorResolverGuard
{
    /**
     * Returns a resolver closure scoped to ONE dispatch: the turn budget it captures
     * pools across that dispatch's arms, so callers must re-invoke decorate() per
     * dispatch (the conductor does — one setResolver per run()) and must never carry
     * the closure across dispatches, which would leak spend forward.
     *
     * @param  array<string,mixed>  $options
     */
    public function decorate(Closure $inner, array $options): Closure
    {
        $budgetUnits = (float) ($options['turn_budget_units'] ?? 0.0);
        $schema = is_array($options['output_schema'] ?? null) ? $options['output_schema'] : null;

        if ($budgetUnits <= 0.0 && $schema === null) {
            return $inner;
        }

        $budget = new AtlasConductorTurnBudget($budgetUnits);
        $validator = $schema !== null ? new AtlasStructuredOutputValidator() : null;

        return function (array $arm, array $context) use ($inner, $budget, $validator, $schema): array {
            // Estimate this arm's input cost (chars/4 token heuristic — the same
            // currency the per-call AiCallCostGuard uses) and consume from the pool.
            $promptUnits = (float) max(1, (int) ceil(mb_strlen((string) ($context['input'] ?? '')) / 4));
            if (! $budget->tryConsume($promptUnits)) {
                return [
                    'result' => 'failure',
                    'latency_ms' => 0,
                    'quality_score' => null,
                    'output' => 'turn_budget_exhausted',
                ];
            }

            $outcome = $inner($arm, $context);

            if ($validator !== null && (string) ($outcome['result'] ?? '') === 'success') {
                $check = $validator->validate((string) ($outcome['output'] ?? ''), (array) $schema);
                if (! $check['valid']) {
                    return [
                        'result' => 'failure',
                        'latency_ms' => $outcome['latency_ms'] ?? null,
                        'quality_score' => null,
                        'output' => 'schema_unsatisfied: '.implode('; ', array_slice($check['errors'], 0, 5)),
                    ];
                }
            }

            return $outcome;
        };
    }
}
