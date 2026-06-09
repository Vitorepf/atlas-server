<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use Throwable;

/**
 * AP-815 · D-2 — Interactive latency budget (SLO meter) for code-graph reads.
 *
 * Cross-project context becomes useful only if a graph query / traversal answers
 * inside an INTERACTIVE budget — when a second indexed project bloats the read-model,
 * a walk that used to be instant can quietly drift past the point where it is worth
 * blocking a turn on. This class is the meter that makes that drift VISIBLE: it runs
 * an operation, measures wall-clock elapsed against a budget, and records every breach
 * so the orchestrator can surface or react to a slow path (e.g. fall back to a cheaper
 * answer, widen the cap, or flag the workspace for re-indexing).
 *
 * It is a METER, not an error boundary nor a timeout:
 *   - It NEVER aborts, cancels or times out the operation — PHP cannot pre-empt a
 *     running callable, and pretending to would be a lie. The budget is observed, not
 *     enforced; the op always runs to completion.
 *   - It NEVER swallows an exception. If the operation throws, the elapsed time is still
 *     captured (and a breach still recorded if the failing op ran over budget — a slow
 *     failure is exactly what you want to see), then the original throwable is rethrown
 *     unchanged. A budget that ate your errors would be worse than no budget.
 *   - A non-positive budget means "no interactive bound" — the op is measured and timed
 *     but can never breach (within_budget is always true). This keeps the meter usable
 *     in non-interactive / batch contexts without a separate code path.
 *
 * Timing is deterministic-by-injection: the constructor takes a clock callable returning
 * a monotonic millisecond reading (int|float). The default is the real monotonic clock
 * `hrtime(true) / 1e6`, which (unlike microtime) never goes backwards on NTP adjustments,
 * so a breach reading can never be negative in production. Tests inject a fake clock and
 * assert breach/within-budget classification with zero real sleeping — fully reproducible.
 *
 * [php] by the runtime-language boundary: this is interactive orchestration / SLO
 * governance, not heavy data or ML. Pure of DB; the only state is the in-memory breach
 * log (resettable). No global clock, no randomness in the logic.
 *
 * @phpstan-type Breach array{label:string, elapsed_ms:float, budget_ms:int, over_by_ms:float, at:int}
 * @phpstan-type RunResult array{result:mixed, elapsed_ms:float, within_budget:bool, budget_ms:int, label:string, over_by_ms:float}
 */
class CodeGraphLatencyBudget
{
    public const SCHEMA = 'atlas.code_graph.latency_budget.v1';

    /**
     * Monotonic clock returning milliseconds (int|float). Injected for determinism.
     *
     * @var callable():(int|float)
     */
    private $clock;

    /**
     * Recorded breaches, oldest first (append-only until reset()).
     *
     * @var array<int,array{label:string, elapsed_ms:float, budget_ms:int, over_by_ms:float, at:int}>
     */
    private array $breaches = [];

    /**
     * Monotonically increasing sequence stamped on each breach as `at`. Lets a caller
     * order breaches without a wall clock (the meter has no Date dependency).
     */
    private int $sequence = 0;

    /**
     * @param  (callable():(int|float))|null  $clock  Monotonic ms clock; defaults to hrtime(true)/1e6.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e6;
    }

    /**
     * Run an operation under a latency budget and return the result plus the measurement.
     *
     * The operation always runs to completion (this is a meter, not a timeout). Elapsed
     * is captured in a `finally` so it is recorded whether the op returns or throws; on a
     * throw the breach (if any) is still logged and the original throwable is rethrown
     * unchanged — the budget never becomes an error boundary.
     *
     * Edge handling, all fail-safe:
     *   - A non-positive `$budgetMs` means "no interactive bound": measured but never a
     *     breach (within_budget always true).
     *   - A clock that returns non-finite (NaN/INF) or moves backwards yields a clamped,
     *     non-negative finite `elapsed_ms` of 0.0 rather than a poisoned/negative reading,
     *     so a misbehaving injected clock can never corrupt the breach log.
     *
     * @param  callable():mixed  $op  The graph query / traversal to measure.
     * @param  int  $budgetMs  Interactive budget in ms; <= 0 disables the bound.
     * @param  string  $label  Human label for the breach log (defaults to a stable token).
     * @return array{
     *   result:mixed,
     *   elapsed_ms:float,
     *   within_budget:bool,
     *   budget_ms:int,
     *   label:string,
     *   over_by_ms:float
     * }
     *
     * @throws Throwable Re-throws unchanged whatever $op throws (after recording elapsed).
     */
    public function run(callable $op, int $budgetMs, string $label = ''): array
    {
        $budget = max(0, $budgetMs);
        $cleanLabel = $this->label($label);
        $start = $this->now();
        $elapsed = 0.0;
        $threw = false;

        try {
            $result = $op();
        } catch (Throwable $e) {
            $threw = true;
            // Capture elapsed for the failing path, record a breach if it ran over,
            // then rethrow the ORIGINAL error unchanged — the budget is a meter.
            $elapsed = $this->elapsedSince($start);
            $this->recordIfBreached($cleanLabel, $elapsed, $budget);

            throw $e;
        } finally {
            // On the success path, measure here (finally runs before the value is
            // returned). On the throw path elapsed is already set above; do not
            // re-measure (the catch's reading is the authoritative one).
            if (! $threw) {
                $elapsed = $this->elapsedSince($start);
            }
        }

        $withinBudget = $this->withinBudget($elapsed, $budget);
        if (! $withinBudget) {
            $this->record($cleanLabel, $elapsed, $budget);
        }

        return [
            'result' => $result,
            'elapsed_ms' => $elapsed,
            'within_budget' => $withinBudget,
            'budget_ms' => $budget,
            'label' => $cleanLabel,
            'over_by_ms' => $this->overBy($elapsed, $budget),
        ];
    }

    /**
     * Recorded breaches, oldest first. Each is a self-describing row:
     * {label, elapsed_ms, budget_ms, over_by_ms, at}. Returned by value (a copy),
     * so a caller can never mutate the internal log.
     *
     * @return array<int,array{label:string, elapsed_ms:float, budget_ms:int, over_by_ms:float, at:int}>
     */
    public function breaches(): array
    {
        return $this->breaches;
    }

    /**
     * Whether any breach has been recorded since construction / last reset().
     */
    public function hasBreaches(): bool
    {
        return $this->breaches !== [];
    }

    /**
     * Number of breaches recorded since construction / last reset().
     */
    public function breachCount(): int
    {
        return count($this->breaches);
    }

    /**
     * Clear the breach log (and the breach sequence). The injected clock is untouched.
     */
    public function reset(): void
    {
        $this->breaches = [];
        $this->sequence = 0;
    }

    /**
     * Record a breach only when the elapsed time exceeded a real (positive) budget.
     * A non-positive budget can never breach. Used on the throwing path.
     */
    private function recordIfBreached(string $label, float $elapsed, int $budget): void
    {
        if (! $this->withinBudget($elapsed, $budget)) {
            $this->record($label, $elapsed, $budget);
        }
    }

    /**
     * Append a breach row to the log with a monotonic sequence stamp.
     */
    private function record(string $label, float $elapsed, int $budget): void
    {
        $this->breaches[] = [
            'label' => $label,
            'elapsed_ms' => $elapsed,
            'budget_ms' => $budget,
            'over_by_ms' => $this->overBy($elapsed, $budget),
            'at' => ++$this->sequence,
        ];
    }

    /**
     * True when the op is within budget. A non-positive budget means "no bound" and is
     * always within budget; otherwise elapsed must not exceed the budget. The boundary
     * is inclusive (elapsed == budget is WITHIN budget — exactly on the SLO is not a breach).
     */
    private function withinBudget(float $elapsed, int $budget): bool
    {
        if ($budget <= 0) {
            return true;
        }

        return $elapsed <= (float) $budget;
    }

    /**
     * Milliseconds the op ran past the budget, never negative and never reported for an
     * unbounded (non-positive) budget. 0.0 when within budget.
     */
    private function overBy(float $elapsed, int $budget): float
    {
        if ($budget <= 0) {
            return 0.0;
        }

        $over = $elapsed - (float) $budget;

        return $over > 0.0 ? $over : 0.0;
    }

    /**
     * Elapsed ms from a start reading to now, clamped to a finite non-negative value so a
     * non-monotonic / misbehaving injected clock can never produce a negative or NaN/INF
     * elapsed that would poison the breach log.
     */
    private function elapsedSince(float $start): float
    {
        $elapsed = $this->now() - $start;

        if (! is_finite($elapsed) || $elapsed < 0.0) {
            return 0.0;
        }

        return $elapsed;
    }

    /**
     * Read the injected clock and coerce to a finite float. A clock that returns a
     * non-numeric or non-finite value degrades to 0.0 (paired with the same coercion at
     * the start reading, this yields a safe 0.0 elapsed rather than a poisoned reading).
     */
    private function now(): float
    {
        $value = ($this->clock)();

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        return 0.0;
    }

    /**
     * Normalise the breach label: trimmed; falls back to a stable token when blank so a
     * breach row always carries an identifiable, serialisable label.
     */
    private function label(string $label): string
    {
        $trimmed = trim($label);

        return $trimmed !== '' ? $trimmed : 'code_graph.query';
    }
}
