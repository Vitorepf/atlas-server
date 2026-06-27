<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the deterministic Selector: given a loop objective descriptor, it
 * picks the single BEST selectable pattern to structure the work — or REFUSES.
 *
 * Why this class exists (the load-bearing invariant): the canonical Loop definition is that the loop
 * evolves a scope EXPONENTIALLY, never optimizes a proxy and never performs behaviour-preserving cosmetic
 * work (see docs/loop-canonical-definition.md + memory loop-not-proxy-cleanup-feedback). A selector that
 * could hand a pattern to a cosmetic objective would be a structural hole through which the loop derives
 * into faxina/Goodhart. So the ANTI-COSMETIC GATE here is not a heuristic — it is a hard refusal: a
 * cosmetic objective (or an objective with ~zero expected impact and no real target) can NEVER yield a
 * selected pattern. That refusal is the whole point; ranking is secondary.
 *
 * Determinism & purity: this class is pure. It reads ONLY the objective descriptor and the registry's
 * selectable() set, applies a fixed scoring function, and breaks ties by pattern id. The same inputs
 * always produce the same selection, the same score and the same ranking — no wall-clock, no I/O, no
 * provider/LLM call, no hidden state. It only ever considers $registry->selectable(); source_material,
 * candidate and deprecated patterns are structurally un-pickable (the registry already excludes them and
 * we re-assert it as defence in depth).
 *
 * Scoring (deterministic, bounded): leverage rises with stronger verification (more success_gates AND an
 * independent verifier lane that forbids self-approval), with expected_impact and with how much evidence
 * already exists, and falls with risk and cost. touches_loop is explicitly allowed (the loop's first
 * territory is the loop itself) and grants no bypass of the cosmetic gate.
 */
final class AtlasLoopPatternSelector
{
    /**
     * The set of objective kinds the loop understands. An objective whose kind is outside this vocabulary
     * is not matchable; the selector rejects it rather than guessing a pattern.
     */
    public const OBJECTIVE_KINDS = ['docs', 'bug', 'refactor', 'verification', 'self_improvement', 'feature'];

    /**
     * Below this expected_impact, an objective with no real target is treated as effectively cosmetic —
     * zero-leverage proxy work the loop must refuse. Kept as a named constant so the threshold is an
     * explicit, testable part of the contract (not a magic literal buried in the gate).
     */
    public const NEGLIGIBLE_IMPACT_EPSILON = 0.02;

    /**
     * The causal effect gate is OPTIONAL evidence (read-only over the pattern-learning ledger). When absent
     * OR when atlas.brain.causal_selector_enabled is OFF, the selector is exactly the pure, byte-identical
     * function it always was. When ON, a pattern whose causal effect's CI excludes zero earns an additive
     * leverage bonus — author≠judge intact (the gate only reads the ledger; the selector never writes).
     */
    public function __construct(private readonly ?AtlasBrainCausalEffectGate $causal = null) {}

    /**
     * Choose the best selectable pattern for a loop objective, or refuse.
     *
     * @param  array<string,mixed>  $objective  descriptor with keys: objective_kind (string), expected_impact
     *                                          (float 0..1), evidence (float 0..1), risk (float 0..1),
     *                                          cost (float 0..1), cosmetic (bool), touches_loop (bool).
     * @return array{
     *     pattern: ?AtlasLoopPatternSpec,
     *     score: float,
     *     rejected: bool,
     *     reason: string,
     *     ranking: list<array{id:string,score:float}>
     * }
     */
    public function select(array $objective, AtlasLoopPatternRegistry $registry): array
    {
        $kind = trim((string) ($objective['objective_kind'] ?? ''));
        $expectedImpact = $this->clamp01($objective['expected_impact'] ?? 0.0);
        $evidence = $this->clamp01($objective['evidence'] ?? 0.0);
        $risk = $this->clamp01($objective['risk'] ?? 0.0);
        $cost = $this->clamp01($objective['cost'] ?? 0.0);
        $cosmetic = (bool) ($objective['cosmetic'] ?? false);

        // ── ANTI-COSMETIC GATE (the load-bearing refusal) ────────────────────────────────────────────
        // Behaviour-preserving / proxy work earns ZERO leverage per the canonical Loop definition, so it
        // must NEVER yield a selected pattern. Explicit cosmetic flag OR a vanishing expected_impact with
        // no real target both collapse here. This is checked BEFORE any matching/ranking so no amount of
        // strong-verification scoring can rescue a cosmetic objective.
        if ($cosmetic || $expectedImpact <= self::NEGLIGIBLE_IMPACT_EPSILON) {
            return $this->reject(
                'Cosmetic / behaviour-preserving / proxy work earns zero leverage per the canonical Loop '
                .'definition (no exponential evolution of the scope), so no pattern is selected. Bring a '
                .'real target with non-trivial expected impact instead of optimizing a proxy.'
            );
        }

        // Objective kind must be in vocabulary; an unknown kind is not a real, matchable objective.
        if (! in_array($kind, self::OBJECTIVE_KINDS, true)) {
            return $this->reject(
                "objective_kind '{$kind}' is not one of ".implode('|', self::OBJECTIVE_KINDS)
                .'; cannot match a loop pattern to an unrecognized objective.'
            );
        }

        // Only ever consider promoted patterns; re-assert selectability as defence in depth.
        $candidates = array_values(array_filter(
            $registry->selectable(),
            static fn (AtlasLoopPatternSpec $s): bool => $s->isSelectable()
                && in_array($kind, $s->objectiveKinds(), true)
        ));

        if ($candidates === []) {
            return $this->reject(
                "no selectable pattern fits objective_kind '{$kind}'."
            );
        }

        // Score every candidate, then sort by score DESC with a deterministic id tie-break so the
        // ranking is stable for identical inputs.
        $scored = array_map(
            fn (AtlasLoopPatternSpec $s): array => [
                'spec' => $s,
                'id' => $s->id,
                'score' => round($this->score($s, $expectedImpact, $evidence, $risk, $cost) + $this->causalBonus($s), 6),
            ],
            $candidates
        );

        usort($scored, static function (array $a, array $b): int {
            return [$b['score'], $a['id']] <=> [$a['score'], $b['id']];
        });

        $ranking = array_map(
            static fn (array $row): array => ['id' => $row['id'], 'score' => $row['score']],
            $scored
        );

        $winner = $scored[0];

        return [
            'pattern' => $winner['spec'],
            'score' => $winner['score'],
            'rejected' => false,
            'reason' => "selected '{$winner['id']}' as the strongest-verification fit for objective_kind '{$kind}'.",
            'ranking' => $ranking,
        ];
    }

    /**
     * The deterministic, bounded leverage score for a candidate against an objective.
     *
     * Verification strength dominates: a pattern with more reproducible success_gates AND an independent
     * verifier lane (creator ≠ verifier) is structurally harder to game, so it scores higher. The
     * objective signals then push the score up (impact, existing evidence) or down (risk, cost). The
     * weights are fixed constants so the function is reproducible and the unit test can assert orderings.
     */
    private function score(
        AtlasLoopPatternSpec $spec,
        float $expectedImpact,
        float $evidence,
        float $risk,
        float $cost
    ): float {
        // Verification strength: gate count saturates so a pile of weak gates can't dominate, and an
        // independent verifier lane is a large discrete bonus (it is the anti-self-cert moat).
        $gateStrength = min(count($spec->successGates), 3) / 3.0; // 0..1
        $independentVerifier = $spec->forbidsSelfApproval() ? 1.0 : 0.0;
        $verification = (0.6 * $independentVerifier) + (0.4 * $gateStrength); // 0..1

        $score = (1.4 * $verification)
            + (1.0 * $expectedImpact)
            + (0.5 * $evidence)
            - (0.6 * $risk)
            - (0.4 * $cost);

        return round($score, 6);
    }

    /**
     * Additive causal leverage bonus for a candidate. 0.0 (byte-identical) when the gate is absent or the
     * flag is OFF, OR when the path is not causally proven (insufficient_n / CI includes zero / effect not
     * positive) — so a lucky/weak/unproven delta NEVER lifts a pattern (no fabricated compounding). Only a
     * path whose effect CI excludes zero on the positive side earns the bonus; invariance across ≥2
     * objective classes (IRM) earns more. Additive (not multiplicative) so it can't flip a negative score.
     */
    private function causalBonus(AtlasLoopPatternSpec $spec): float
    {
        if ($this->causal === null || ! (bool) config('atlas.brain.causal_selector_enabled', false)) {
            return 0.0;
        }

        if (($this->causal->effect($spec->id)['admit_compounding'] ?? false) !== true) {
            return 0.0; // neutral: not causally proven ⇒ ranks strictly below an identical proven path
        }

        return $this->causal->isInvariantPositive($spec->id) ? 0.45 : 0.30;
    }

    /**
     * @return array{pattern:null, score:float, rejected:true, reason:string, ranking:list<array{id:string,score:float}>}
     */
    private function reject(string $reason): array
    {
        return [
            'pattern' => null,
            'score' => 0.0,
            'rejected' => true,
            'reason' => $reason,
            'ranking' => [],
        ];
    }

    private function clamp01(mixed $value): float
    {
        $f = is_numeric($value) ? (float) $value : 0.0;

        // Non-finite (NaN / ±INF) collapses to 0.0. Without this, a NaN expected_impact would slip the
        // anti-cosmetic gate — `NAN <= EPSILON` is false by IEEE-754, so a no-real-target objective with
        // a NaN impact would be treated as healthy work (an adversarially-found bypass). Mapping it to 0.0
        // routes it straight into the negligible-impact rejection, which is the honest classification.
        if (! is_finite($f)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $f));
    }
}
