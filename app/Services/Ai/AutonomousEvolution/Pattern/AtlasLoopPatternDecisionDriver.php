<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use Throwable;

/**
 * LOOP-PATTERN-REGISTRY · A0 — the PatternRegistry DECISION DRIVER.
 *
 * Slice 1 made the registry/selector ADVISORY: a pattern + contract were ATTACHED to an objective the EV
 * brain + critic had ALREADY chosen (see {@see AtlasLoopObjectiveProducer::attachPatternAdvisory()}). That
 * left a structural hole: nothing using the selector's anti-cosmetic refusal could veto the chosen work
 * BEFORE origination. If the top/EV candidate was a behaviour-preserving proxy refactor, the loop would
 * still originate it and merely staple an honest-rejection note afterwards. The advisory could observe the
 * derive-into-faxina; it could not stop it (canonical Loop definition: never optimize a proxy, never do
 * cosmetic behaviour-preserving work — docs/loop-canonical-definition.md + memory loop-not-proxy-cleanup-feedback).
 *
 * This driver closes that hole. It runs over the REAL floor-passing candidates (in their decided EV order)
 * BEFORE any goal is originated and is the DECIDER: it walks the candidates and selects the FIRST one the
 * {@see AtlasLoopPatternSelector} accepts. A cosmetic / negligible-impact / non-finite-impact candidate is
 * REJECTED (with a receipt) and the next acceptable candidate is chosen instead. If NONE is acceptable it
 * returns a governed terminal — the producer then emits NO objective rather than inventing one.
 *
 * Fail-CLOSED (the opposite stance from the advisory layer, by design): a throwing descriptor/selector
 * yields {@see STATE_SELECTOR_FAILED} with a null pattern (the caller must treat that as a hard stop, not
 * proceed un-governed); an empty candidate set yields {@see STATE_NO_CANDIDATES}. Contract compilation is a
 * separate fail-closed step ({@see compileContract()}): a spec that cannot bind to a complete, gated
 * contract returns [null, error], and the caller must abort rather than originate ungoverned work.
 *
 * Purity & determinism: this class holds no state, reads no clock/IO/provider, and is a pure function of
 * (candidates, descriptor function, selector, registry). The same inputs always yield the same decision,
 * the same rejection receipts and the same selected pattern — so the loop's pre-origination work choice is
 * reproducible and unit-testable.
 */
final class AtlasLoopPatternDecisionDriver
{
    /** The driver is gated OFF: the caller must fall back to the legacy advisory path (no driving). */
    public const STATE_DISABLED = 'disabled';

    /** A candidate cleared the selector; {@see decide()} returns it + its pattern as the work choice. */
    public const STATE_SELECTED = 'selected';

    /** Every candidate was refused by the selector (cosmetic/negligible/unmatched) — emit no objective. */
    public const STATE_REJECTED_ALL = 'rejected_all';

    /** There were no candidates to decide over — a governed no-op, not an error. */
    public const STATE_NO_CANDIDATES = 'no_candidates';

    /** The descriptor function or the selector threw — fail-closed: trust no selection this cycle. */
    public const STATE_SELECTOR_FAILED = 'selector_failed';

    /** A selected pattern could not be compiled into a complete contract — fail-closed terminal. */
    public const STATE_COMPILE_FAILED = 'compile_failed';

    /**
     * Decide the next unit of work over the floor-passing candidates, in order. Returns the first candidate
     * whose driver descriptor the selector accepts, or a governed terminal when none is acceptable.
     *
     * @param  list<array<string,mixed>>  $candidates  the EV-ordered floor-passers (index 0 == the top pick)
     * @param  callable(array<string,mixed>):array<string,mixed>  $descriptorFor  candidate → selector descriptor
     * @return array{
     *     state:string,
     *     selected:?array<string,mixed>,
     *     selected_index:?int,
     *     pattern:?AtlasLoopPatternSpec,
     *     score:float,
     *     rejections:list<array{index:int,path:string,reason:string}>
     * }
     */
    public function decide(
        array $candidates,
        callable $descriptorFor,
        AtlasLoopPatternSelector $selector,
        AtlasLoopPatternRegistry $registry
    ): array {
        $candidates = array_values($candidates);
        if ($candidates === []) {
            return $this->result(self::STATE_NO_CANDIDATES, null, null, null, 0.0, []);
        }

        $rejections = [];

        foreach ($candidates as $i => $candidate) {
            $path = is_array($candidate) ? (string) ($candidate['path'] ?? '?') : '?';

            if (! is_array($candidate)) {
                $rejections[] = $this->rejection($i, $path, 'candidate is not a descriptor-able packet');

                continue;
            }

            try {
                $descriptor = $descriptorFor($candidate);
                $selection = $selector->select($descriptor, $registry);
            } catch (Throwable $e) {
                // FAIL-CLOSED: a throwing descriptor/selector means NO selection this cycle can be trusted.
                // We stop and surface a terminal with a null pattern — the caller must NOT proceed to
                // originate ungoverned work on the back of a half-evaluated candidate list.
                $rejections[] = $this->rejection($i, $path, 'selector_error: '.$e->getMessage());

                return $this->result(self::STATE_SELECTOR_FAILED, null, null, null, 0.0, $rejections);
            }

            $pattern = $selection['pattern'] ?? null;
            if ($pattern instanceof AtlasLoopPatternSpec) {
                return $this->result(
                    self::STATE_SELECTED,
                    $candidate,
                    $i,
                    $pattern,
                    (float) ($selection['score'] ?? 0.0),
                    $rejections,
                );
            }

            $rejections[] = $this->rejection($i, $path, (string) ($selection['reason'] ?? 'rejected'));
        }

        // Every candidate was refused — the loop must NOT invent an objective. Governed terminal.
        return $this->result(self::STATE_REJECTED_ALL, null, null, null, 0.0, $rejections);
    }

    /**
     * Compile the DRIVER-SELECTED pattern into a concrete contract, fail-closed. The objective array carries
     * the originated work statement (objective text, scope, inputs, outputs, budget); the safety frontier
     * comes only from the spec ({@see AtlasLoopPatternCompiler}). A spec that cannot yield a complete,
     * gated contract returns [null, error] — and the caller MUST abort, never originate on a null contract.
     *
     * @param  array<string,mixed>  $objective
     * @return array{0:?AtlasLoopExecutionContract, 1:?string}  [contract, errorMessage] — exactly one is null.
     */
    public function compileContract(
        AtlasLoopPatternSpec $pattern,
        array $objective,
        AtlasLoopPatternCompiler $compiler
    ): array {
        try {
            return [$compiler->compile($pattern, $objective), null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @param  list<array{index:int,path:string,reason:string}>  $rejections
     * @return array{
     *     state:string,
     *     selected:?array<string,mixed>,
     *     selected_index:?int,
     *     pattern:?AtlasLoopPatternSpec,
     *     score:float,
     *     rejections:list<array{index:int,path:string,reason:string}>
     * }
     */
    private function result(
        string $state,
        ?array $selected,
        ?int $selectedIndex,
        ?AtlasLoopPatternSpec $pattern,
        float $score,
        array $rejections
    ): array {
        return [
            'state' => $state,
            'selected' => $selected,
            'selected_index' => $selectedIndex,
            'pattern' => $pattern,
            'score' => $score,
            'rejections' => $rejections,
        ];
    }

    /** @return array{index:int,path:string,reason:string} */
    private function rejection(int $index, string $path, string $reason): array
    {
        return ['index' => $index, 'path' => $path, 'reason' => $reason];
    }
}
