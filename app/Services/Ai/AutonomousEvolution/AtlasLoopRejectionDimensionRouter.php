<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE lever X3 — structured rejection-dimension routing (the missing READ of a closed pair).
 *
 * The certifier already emits NAMESPACED rejection reasons (`complexity_gate:...`, `quality_bar:...`,
 * `changed_symbol_uncovered:...`, `completeness:...`, `delivery_confidence:...`, `overfit:...`) — the WRITE
 * half. But the re-attempt guidance the conductor feeds the next round is identical regardless of WHICH
 * dimension failed, so a weak engine handed a generic "repair failed" produces a structurally-identical
 * re-attempt and thrashes. This router is the READ half: it maps each cert dimension to a SPECIFIC, actionable
 * re-attempt constraint, turning "it failed" into "fix exactly THIS, THIS way". Deterministic, pure (no model,
 * no DB) — the directive is canned per dimension, never an LLM judgement, so the cert's frozen verdict is the
 * only authority and nothing grades its own bar.
 *
 * Why a weak engine beats a strong single pass: a single pass gets one shot with no targeted feedback; X3 turns
 * the cert's machine-resolved failure dimension into a precise next-attempt constraint, so width + iteration
 * converge on the actual defect instead of re-rolling blindly.
 */
final class AtlasLoopRejectionDimensionRouter
{
    /**
     * Dimension prefix => the specific, actionable re-attempt directive. Ordered by routing priority (most
     * structurally-rootcause first), so `primary` and the composed directive lead with the deepest fix.
     */
    private const DIRECTIVES = [
        'complexity_gate' => 'The worst method\'s cyclomatic complexity did NOT strictly drop. Extract its deepest-nested branch into a new, well-named private method (a real structural simplification, not a reformat) and keep behavior identical.',
        'completeness' => 'Not every acceptance criterion is satisfied. Enumerate the UNMET criteria and address EACH one explicitly in this attempt — do not stop at the first.',
        'changed_symbol_uncovered' => 'A changed/new public symbol is NOT exercised by any test. Add a test that calls that exact symbol and asserts its behavior on a real input; name the symbol explicitly.',
        'overfit' => 'The implementation overfits the frozen test (it hard-codes the expected value). Generalize the logic so it would also pass for other inputs of the same shape.',
        'behavioral_equivalence' => 'Existing behavior changed, or a behavior anchor lost its assertion. Preserve ALL prior behavior exactly and add an assertion that pins the unchanged behavior.',
        'quality_bar' => 'The change scored below the quality bar. Reduce the decision count in the changed methods and add real coverage of the change; a cosmetic edit will not pass.',
        'delivery_confidence' => 'Delivery confidence is below threshold. Strengthen the behavioral assertions in the sibling test so the change is provably exercised end-to-end.',
    ];

    /**
     * Map a set of cert rejection reasons to the dimensions they hit + a composed, dimension-specific directive.
     *
     * @param  list<string>  $reasons
     * @return array{primary: ?string, dimensions: list<string>, directive: string}
     */
    public function route(array $reasons): array
    {
        $hit = [];
        foreach ($reasons as $reason) {
            $reason = trim((string) $reason);
            if ($reason === '') {
                continue;
            }
            $dim = strtolower(trim((string) (explode(':', $reason, 2)[0] ?? '')));
            if ($dim !== '' && array_key_exists($dim, self::DIRECTIVES) && ! in_array($dim, $hit, true)) {
                $hit[] = $dim;
            }
        }

        // Preserve the DIRECTIVES priority order regardless of the reasons' arrival order.
        $ordered = array_values(array_filter(
            array_keys(self::DIRECTIVES),
            static fn (string $dim): bool => in_array($dim, $hit, true),
        ));
        $directive = implode(' ', array_map(static fn (string $dim): string => self::DIRECTIVES[$dim], $ordered));

        return [
            'primary' => $ordered[0] ?? null,
            'dimensions' => $ordered,
            'directive' => $directive,
        ];
    }
}
