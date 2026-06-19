<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the ChampionGate: the deterministic, fail-closed promotion judge.
 *
 * A "champion" is the pattern the loop currently trusts for a territory; a "challenger" is a proposed
 * replacement (a new version, an external candidate that earned an Atlas eval, a run-learning mutation).
 * This gate answers exactly ONE question — *may the challenger replace the champion?* — and it answers
 * it the way the canonical Loop definition demands: only when the challenger has HONESTLY out-performed
 * the champion on a FRESH eval, with no creator self-approval and no guardrail regression. Everything
 * else keeps the champion. This is the anti-Goodhart fechadura at the promotion boundary: a proxy win,
 * a stale battery, a self-signed verdict, or a silent safety regression can never carry a promotion.
 *
 * INVARIANTS (all four must hold, AND is the contract — any one false ⇒ keep the champion):
 *   1. self_approval_blocked     — the proposer/creator may NEVER be the approver of their own challenger.
 *   2. fresh_eval_required        — the decision must rest on a fresh eval battery (eval_fresh + a named id).
 *   3. beats_champion             — challenger_score must be STRICTLY > champion_score + margin.
 *   4. no_guardrail_regression    — no guardrail/safety/sovereignty invariant may have regressed.
 *
 * FAIL-CLOSED: any missing, malformed, or non-finite field collapses the relevant check to false. An
 * unprovable promotion is a non-promotion — the champion holds. The gate never throws on bad input; it
 * simply refuses to promote, because "keep what we already trust" is the only safe default.
 *
 * PURITY: the gate is a pure function of its input. It optionally records the decision to the injected
 * {@see AtlasLoopPatternLearningLedger} when (and only when) a ledger is provided — the evaluation result
 * is byte-identical whether or not a ledger is attached, so wiring the ledger can never change a verdict.
 */
final class AtlasLoopPatternChampionGate
{
    private ?AtlasLoopPatternLearningLedger $ledger;

    /**
     * @param  AtlasLoopPatternLearningLedger|null  $ledger  optional sink for the decision; null = pure, no I/O.
     */
    public function __construct(?AtlasLoopPatternLearningLedger $ledger = null)
    {
        $this->ledger = $ledger;
    }

    /**
     * Decide whether the challenger may replace the champion.
     *
     * The four checks are computed independently and reported in full (so a caller can see WHY a
     * promotion was refused, not just THAT it was). Promotion requires every check to pass — the
     * conjunction is the whole point: a single failed invariant keeps the champion.
     *
     * @param  array<string,mixed>  $challenge {
     *     champion_id: string, challenger_id: string,
     *     proposer: string, approver: string,
     *     eval_fresh: bool, eval_battery_id: string,
     *     champion_score: float, challenger_score: float, margin?: float,
     *     guardrail_regressed: bool
     * }
     * @return array{promote:bool, reason:string, checks:array<string,bool>}
     */
    public function evaluate(array $challenge): array
    {
        // --- Identity lane: proposer/creator must not be the approver of their own challenger. -------
        // Fail-closed on emptiness too: an unnamed proposer or approver cannot prove independence, so an
        // anonymous "approval" is structurally indistinguishable from a self-signed one ⇒ block.
        $proposer = $this->str($challenge, 'proposer');
        $approver = $this->str($challenge, 'approver');
        $selfApprovalBlocked = $proposer !== '' && $approver !== '' && $proposer !== $approver;

        // --- Freshness lane: the verdict must rest on a fresh eval battery, and that battery must be
        // identified (an unnamed battery cannot be re-run/audited, so it is treated as not-fresh). ------
        $evalFresh = $this->bool($challenge, 'eval_fresh');
        $batteryId = $this->str($challenge, 'eval_battery_id');
        $freshEvalRequired = $evalFresh && $batteryId !== '';

        // --- Performance lane: challenger must be STRICTLY better than champion by at least the margin.
        // Non-finite or missing scores collapse to false (an unmeasured challenger never beats anyone).
        $margin = $this->finiteFloat($challenge['margin'] ?? 0.0, 0.0);
        $championScore = $this->finiteFloat($challenge['champion_score'] ?? null, null);
        $challengerScore = $this->finiteFloat($challenge['challenger_score'] ?? null, null);
        $beatsChampion = $championScore !== null
            && $challengerScore !== null
            && $margin !== null
            && $challengerScore > $championScore + $margin;

        // --- Safety lane: no guardrail/safety/sovereignty invariant may have regressed. Fail-closed:
        // a MISSING flag is treated as a regression (absence of proof of safety is not proof of safety).
        $noGuardrailRegression = array_key_exists('guardrail_regressed', $challenge)
            && $this->bool($challenge, 'guardrail_regressed') === false;

        $checks = [
            'self_approval_blocked' => $selfApprovalBlocked,
            'fresh_eval_required' => $freshEvalRequired,
            'beats_champion' => $beatsChampion,
            'no_guardrail_regression' => $noGuardrailRegression,
        ];

        // AND over every invariant. The default is "keep the champion"; promotion is the exception that
        // must be earned across all four lanes at once.
        $promote = $selfApprovalBlocked
            && $freshEvalRequired
            && $beatsChampion
            && $noGuardrailRegression;

        $reason = $this->reasonFor($promote, $checks);

        $this->recordDecision($challenge, $promote, $checks);

        return [
            'promote' => $promote,
            'reason' => $reason,
            'checks' => $checks,
        ];
    }

    /**
     * Human/audit-readable verdict. On promotion: a single positive line. On refusal: the exact set of
     * failed invariants, so the caller never has to re-derive why the champion held.
     *
     * @param  array<string,bool>  $checks
     */
    private function reasonFor(bool $promote, array $checks): string
    {
        if ($promote) {
            return 'promote: challenger beats champion on a fresh eval, independently approved, no guardrail regression';
        }

        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => $passed === false));

        return 'keep champion (fail-closed): '.implode(', ', $failed);
    }

    /**
     * Provider-safe, best-effort decision record. Only fires when a ledger is injected; the ledger's own
     * fail-closed contract rejects unattributable rows, so a thin/partial challenge is simply not written
     * rather than corrupting the ledger. Recording NEVER affects the returned verdict (purity invariant).
     *
     * @param  array<string,mixed>  $challenge
     * @param  array<string,bool>  $checks
     */
    private function recordDecision(array $challenge, bool $promote, array $checks): void
    {
        if ($this->ledger === null) {
            return;
        }

        $challengerId = $this->str($challenge, 'challenger_id');
        if ($challengerId === '') {
            return; // nothing attributable to record; keep the gate pure.
        }

        $passed = array_keys(array_filter($checks, static fn (bool $ok): bool => $ok === true));
        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => $ok === false));

        try {
            $this->ledger->record([
                'pattern_id' => $challengerId,
                'pattern_version' => $this->str($challenge, 'eval_battery_id') ?: 'champion_gate',
                'objective_class' => 'champion_gate_decision',
                'result' => $promote
                    ? AtlasLoopPatternLearningLedger::RESULT_SUCCESS
                    : AtlasLoopPatternLearningLedger::RESULT_BLOCKED,
                'gates_passed' => $passed,
                'gates_failed' => $failed,
                'measured_outcome' => $promote ? 1 : 0,
            ]);
        } catch (\Throwable) {
            // Decision recording is advisory; a ledger hiccup must never change or fail the verdict.
        }
    }

    /** Safe string extraction: missing/non-scalar ⇒ ''. */
    private function str(array $challenge, string $key): string
    {
        $value = $challenge[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Strict bool extraction: only a real true is true; everything else is false (fail-closed). */
    private function bool(array $challenge, string $key): bool
    {
        return ($challenge[$key] ?? null) === true;
    }

    /**
     * Coerce a value to a finite float, or return $default when it is missing/non-numeric/non-finite.
     * NaN and ±INF are rejected: a non-finite score can never satisfy a strict inequality safely.
     */
    private function finiteFloat(mixed $value, ?float $default): ?float
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (float) $value;
        }

        return $default;
    }
}
