<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;


/**
 * E4 -- Pure comparison result produced by
 * {@see ShadowDiffService::evaluate()}.
 *
 * A pure value object: it carries WHAT the shadow-diff decided (divergence,
 * agreement, or a skip), never how to apply the verdict. The gate
 * ({@see ShadowDiffGate}) reads this result and routes through the
 * sanctioned channels.
 *
 * Fields:
 *   - $diverged:          true iff at least one symbol's old outputs differ
 *                          from its new outputs on the probed input set
 *                          (a shadow-diff regression, VAL-E4-005).
 *   - $agreed:            true iff at least one symbol was evaluated AND all
 *                          evaluated symbols agreed (behavior-preserving
 *                          refactor, VAL-E4-008).
 *   - $isSkipped:         true iff NO symbol could be evaluated (no PHP files,
 *                          no functions, all impure, all newly-added, or a
 *                          harness failure across the board). A skip NEVER
 *                          trips (VAL-E4-007, VAL-E4-011).
 *   - $skipReason:        non-empty when $isSkipped is true.
 *   - $divergentSymbols:  list of divergent symbol records carrying the
 *                          symbol name, file, the first divergent input,
 *                          old/new outputs as evidence (VAL-E4-005,
 *                          VAL-CROSS-016). Empty on agreement/skip.
 *   - $evaluatedSymbols:  list of {symbol, file} for every symbol that was
 *                          successfully evaluated (agreement). Echoed for
 *                          auditability.
 *   - $skippedSymbols:    list of {symbol, file, reason} for every symbol
 *                          skipped (impure / newly-added / harness failure).
 *                          Carries the explicit reason per VAL-E4-011.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class ShadowDiffResult
{
    /**
     * @param  list<array{symbol: non-empty-string, file: non-empty-string, input: string, oldOutput: string, newOutput: string}>  $divergentSymbols
     * @param  list<array{symbol: non-empty-string, file: non-empty-string}>  $evaluatedSymbols
     * @param  list<array{symbol: non-empty-string, file: non-empty-string, reason: non-empty-string}>  $skippedSymbols
     */
    public function __construct(
        public readonly bool $diverged,
        public readonly bool $agreed,
        public readonly bool $isSkipped,
        public readonly string $skipReason,
        public readonly array $divergentSymbols,
        public readonly array $evaluatedSymbols,
        public readonly array $skippedSymbols,
    ) {}

    /**
     * At least one symbol's old outputs differ from its new outputs.
     *
     * @param  list<array{symbol: non-empty-string, file: non-empty-string, input: string, oldOutput: string, newOutput: string}>  $divergentSymbols
     * @param  list<array{symbol: non-empty-string, file: non-empty-string}>  $evaluatedSymbols
     * @param  list<array{symbol: non-empty-string, file: non-empty-string, reason: non-empty-string}>  $skippedSymbols
     */
    public static function divergence(array $divergentSymbols, array $evaluatedSymbols = [], array $skippedSymbols = []): self
    {
        return new self(
            diverged: true,
            agreed: false,
            isSkipped: false,
            skipReason: '',
            divergentSymbols: $divergentSymbols,
            evaluatedSymbols: $evaluatedSymbols,
            skippedSymbols: $skippedSymbols,
        );
    }

    /**
     * All evaluated symbols agreed (behavior-preserving refactor). Carries
     * the evaluated + skipped symbol records for auditability.
     *
     * @param  list<array{symbol: non-empty-string, file: non-empty-string}>  $evaluatedSymbols
     * @param  list<array{symbol: non-empty-string, file: non-empty-string, reason: non-empty-string}>  $skippedSymbols
     */
    public static function agreement(array $evaluatedSymbols, array $skippedSymbols = []): self
    {
        return new self(
            diverged: false,
            agreed: true,
            isSkipped: false,
            skipReason: '',
            divergentSymbols: [],
            evaluatedSymbols: $evaluatedSymbols,
            skippedSymbols: $skippedSymbols,
        );
    }

    /**
     * No symbol could be evaluated (no PHP files, no functions, all impure,
     * all newly-added, or a harness failure across the board). Never trips.
     */
    public static function skipped(string $reason): self
    {
        return new self(
            diverged: false,
            agreed: false,
            isSkipped: true,
            skipReason: $reason,
            divergentSymbols: [],
            evaluatedSymbols: [],
            skippedSymbols: [],
        );
    }
}
