<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionOutcomeRecorder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionShapePrior;

/**
 * PLAN-READINESS GATE — the structure that minimises WASTE: spend on the CHEAP thing (planning,
 * structuring, verifying the plan) so the EXPENSIVE thing (implementation) almost always LANDS.
 *
 * The operator's refinement of the convex-payoff strategy: a discarded attempt is cheap per-unit but
 * still WASTE (time + tokens). The optimal move is to front-load planning until the implementation is
 * very likely to succeed — measure twice, cut once. The economics:
 *
 *     total_expected_cost = planning_cost + P(fail) · implementation_waste
 *
 * planning is CHEAP (deterministic checks + at most a focused review); implementation is EXPENSIVE
 * (multi-node provider runs). So lowering P(fail) by verifying the PLAN is almost always net-positive,
 * and the right action on a weak plan is REPLAN (cheap), never IMPLEMENT-and-discard (expensive).
 *
 * This gate runs BEFORE {@see AtlasLoopObraExecutionAdapter} spends a single implementation token. A
 * plan only earns IMPLEMENT when it is:
 *   - STRUCTURALLY sound ({@see AtlasLoopObraPlanValidator}: acyclic, scoped, no self-target);
 *   - FULLY SPECIFIED — every node names its target file and states a concrete, non-trivial change (no
 *     vague "do a thing" steps that the provider has to guess);
 *   - PRE-VERIFIED — every node carries a concrete acceptance contract DEFINED UP FRONT (a runnable
 *     command / frozen test / complexity_proof), so "how do we know it landed" is answered before, not
 *     discovered after.
 * Anything short of impeccable returns REPLAN with the exact gaps — cheap to fix, expensive to skip.
 *
 * It does NOT guarantee the implementation lands (the provider can still err — that residual is
 * empirical and irreducible); it guarantees we do not spend the EXPENSIVE step on an ILL-FORMED plan.
 * Pure: no provider, no DB, no mutation.
 */
final class AtlasLoopPlanReadinessGate
{
    public const IMPLEMENT = 'implement';

    public const REPLAN = 'replan';

    /** Minimum readiness score (the share of nodes that are fully specified + pre-verified) to implement. */
    private const MIN_READINESS = 1.0; // every node must be impeccable — one vague node and we replan

    public function __construct(
        private readonly ?AtlasLoopObraPlanValidator $validator = null,
        // ACDE Leap 2 — the human-frozen decomposition boundary-oracle reader. Nullable + LAST so the
        // container autowires it to null (Laravel does not inject `?Type = null`); assess() falls back to
        // a fresh instance. Touched ONLY when atlas.loop.decomposition_oracle_enabled is ON AND a $goal is
        // passed AND a fixture exists for that goal — otherwise the gate is byte-identical to before.
        private readonly ?AtlasLoopDecompositionBoundaryOracle $oracle = null,
        // ACDE Leap 5 — the decomposition outcome corpus reader for the shape-prior advisory band. Nullable
        // + LAST (container autowires to null; assess() falls back to a fresh instance). Touched ONLY when
        // atlas.loop.shape_prior_gate_enabled is ON AND the corpus has >= minSamples for this plan's shape —
        // otherwise (flag OFF, thin/cold corpus, or no DB) the gate is byte-identical to before.
        private readonly ?AtlasLoopDecompositionOutcomeRecorder $outcomeRecorder = null,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $allowedFiles
     * @param  ?string  $goal  the obra objective — required ONLY for the Leap 2 boundary-oracle
     *                         lookup; null (default) => no oracle check => byte-identical to before
     * @return array{decision:string, ready:bool, readiness_score:float, structural_valid:bool, gaps:list<string>}
     */
    public function assess(array $plan, array $allowedFiles, ?string $goal = null): array
    {
        $validator = $this->validator ?? new AtlasLoopObraPlanValidator;
        $structural = $validator->validate($plan, $allowedFiles);
        $structuralValid = (bool) ($structural['valid'] ?? false);

        $gaps = [];
        foreach ((array) ($structural['reasons'] ?? []) as $r) {
            $gaps[] = 'structure:'.$r;
        }

        $nodes = array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []);
        $specifiedCount = 0;
        foreach ($nodes as $i => $node) {
            $node = is_array($node) ? $node : [];
            $tag = trim((string) ($node['id'] ?? ('#'.$i)));
            $nodeGaps = $this->nodeSpecGaps($node);
            if ($nodeGaps === []) {
                $specifiedCount++;
            } else {
                foreach ($nodeGaps as $g) {
                    $gaps[] = 'node_'.$tag.':'.$g;
                }
            }
        }

        $readiness = $nodes === [] ? 0.0 : $specifiedCount / count($nodes);
        $ready = $structuralValid && $readiness >= self::MIN_READINESS && $nodes !== [];

        // ACDE Leap 2 — DECOMPOSITION BOUNDARY-ORACLE gate (the moat). An ADDITIONAL deterministic gate:
        // when the flag is ON and a HUMAN froze the required node-boundaries for THIS objective, the DAG
        // must SUPERSET them — a plausible-but-wrong split that drops a required seam REPLANS with the
        // missing-boundary reasons (which the planner's iterate-to-ready loop threads forward as priorGaps).
        // Flag OFF, no $goal, or no oracle for the goal => degrades to the structural-only gate (byte-identical).
        foreach ($this->oracleGaps($plan, $goal) as $g) {
            $gaps[] = $g;
            $ready = false; // a missing required boundary is a hard not-ready (REPLAN), never IMPLEMENT
        }

        // ACDE Leap 5 — SHAPE-PRIOR advisory band (the loop compounds decomposition competence). When the
        // flag is ON and the outcome corpus holds >= minSamples for THIS plan's structural shape with a
        // Wilson lower-bound certified-rate below target, append 'shape_historically_thrashes' => REPLAN
        // (cheap) so the planner regenerates a different-shape plan instead of burning the implementation
        // budget on a shape that empirically fails the frozen gates. Anchored on machine-resolved terminal
        // outcomes, never model self-report. Flag OFF, thin/cold corpus (n<minSamples => UNKNOWN), or no DB
        // => contributes nothing => byte-identical to before (a novel shape is never blocked by a thin prior).
        foreach ($this->shapePriorGaps($plan) as $g) {
            $gaps[] = $g;
            $ready = false;
        }

        // ACDE Leap 6 (plan-time) — NODE-INTERFACE seam-edge band. When the flag is ON and a human froze an
        // interface contract carrying seam-to-seam edge rules for THIS objective, a missing required edge or
        // a forbidden (inverted) edge in the generated DAG is a hard not-ready (REPLAN) — feeding the planner
        // its first machine DESIGN-steering gap. Flag OFF, no $goal, or no contract => [] (byte-identical).
        foreach ($this->interfaceGaps($plan, $goal) as $g) {
            $gaps[] = $g;
            $ready = false;
        }

        // ACDE Leap 8 (greenfield ceiling) — ARCHETYPE invariant band. When the flag is ON and the objective
        // DETERMINISTICALLY classifies into a human-frozen decomposition archetype, the plan must honour that
        // archetype's structural invariants (min nodes, a mandatory create-class file, min distinct targets);
        // a violation REPLANS — importing the moat into greenfield for ANY goal in the family, no per-goal
        // fixture. Flag OFF, no $goal, or no archetype match => [] (degrade to structural-only, byte-identical).
        foreach ($this->archetypeGaps($plan, $goal) as $g) {
            $gaps[] = $g;
            $ready = false;
        }

        // ACDE P3 — SPEC-COVERAGE band. The bands above prove each node is well-formed, but nothing checked that
        // the UNION of nodes COVERS the spec's acceptance_criteria — a structurally-impeccable DAG can silently
        // drop a whole behavioral dimension. When armed and the plan carries acceptance_criteria, a criterion
        // whose salient keywords appear in NO node REPLANS (the planner regenerates a DAG that covers it). Flag
        // OFF / no criteria on the plan => [] => byte-identical.
        foreach ($this->specCoverageGaps($plan) as $g) {
            $gaps[] = $g;
            $ready = false;
        }

        return [
            // The decision encodes the economics: a weak plan REPLANS (cheap) rather than IMPLEMENTING
            // (expensive). Only an impeccable plan spends the implementation budget.
            'decision' => $ready ? self::IMPLEMENT : self::REPLAN,
            'ready' => $ready,
            'readiness_score' => round($readiness, 4),
            'structural_valid' => $structuralValid,
            'gaps' => array_values(array_unique($gaps)),
        ];
    }

    /**
     * ACDE P3 — SPEC-COVERAGE gaps: for each acceptance_criterion carried on the plan, require that at least one
     * salient keyword appears in the union of node requests/acceptance; an uncovered criterion is a REPLAN gap.
     * Conservative (a criterion sharing even ONE salient token with any node is "covered") so it never false-
     * rejects. Returns [] (byte-identical) when the flag is OFF or the plan carries no acceptance_criteria.
     *
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    private function specCoverageGaps(array $plan): array
    {
        $enabled = false;
        try {
            $enabled = (bool) config('atlas.loop.spec_coverage_band_enabled', false);
        } catch (\Throwable) {
            $enabled = false;
        }
        if (! $enabled) {
            return [];
        }

        $criteria = $plan['acceptance_criteria'] ?? data_get($plan, 'spec.acceptance_criteria', []);
        $criteria = array_values(array_filter(array_map(
            static fn (mixed $c): string => is_string($c) ? trim($c) : '',
            is_array($criteria) ? $criteria : [],
        )));
        if ($criteria === []) {
            return [];
        }

        // The union of every node's request + acceptance text (lowercased) — what the DAG actually addresses.
        $nodeText = '';
        foreach (array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []) as $node) {
            if (is_array($node)) {
                $nodeText .= ' '.mb_strtolower((string) ($node['request'] ?? ''));
                $nodeText .= ' '.mb_strtolower(json_encode($node['acceptance'] ?? '') ?: '');
            }
        }

        $gaps = [];
        foreach ($criteria as $criterion) {
            $tokens = $this->salientTokens($criterion);
            if ($tokens === []) {
                continue; // a criterion with no salient keyword cannot be matched => never a false REPLAN
            }
            $covered = false;
            foreach ($tokens as $t) {
                if (str_contains($nodeText, $t)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $gaps[] = 'spec_criterion_uncovered:'.mb_substr($criterion, 0, 60);
            }
        }

        return $gaps;
    }

    /**
     * Salient lowercased keywords of a criterion: identifier-like words of length >= 4, minus common filler.
     *
     * @return list<string>
     */
    private function salientTokens(string $criterion): array
    {
        $stop = ['must', 'should', 'that', 'with', 'when', 'then', 'this', 'they', 'have', 'from', 'into', 'each', 'than', 'them', 'will', 'shall', 'returns', 'return', 'value', 'given'];
        $words = preg_split('/[^a-z0-9_]+/i', mb_strtolower($criterion)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 4 && ! in_array($w, $stop, true)) {
                $out[$w] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * ACDE Leap 2 — the boundary-oracle gaps for a plan, or [] (the byte-identical degrade path) when the
     * flag is OFF, no $goal was supplied, or no human froze a boundary-oracle for THIS objective. When an
     * oracle DOES exist, returns the missing-required-boundary reasons from the validator's superset check.
     *
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    private function oracleGaps(array $plan, ?string $goal): array
    {
        $goal = trim((string) $goal);
        if ($goal === '' || ! $this->oracleEnabled()) {
            return [];
        }

        $oracle = ($this->oracle ?? new AtlasLoopDecompositionBoundaryOracle)->load($goal);
        if ($oracle === null) {
            return []; // no human-frozen bar for this goal => degrade to structural-only (no false-reject)
        }

        $validator = $this->validator ?? new AtlasLoopObraPlanValidator;

        return $validator->assertDecompositionMatchesOracle($plan, $oracle);
    }

    /**
     * Is the boundary-oracle flag ON? Read defensively: this gate is otherwise PURE (the pure-unit planner
     * tests construct it WITHOUT a Laravel container), so a bare config() call would fatal there. When no
     * config binding is resolvable, treat the flag as OFF — the byte-identical structural-only degrade — so
     * the gate stays container-free for pure callers AND honours the flag in a real (booted) run.
     */
    private function oracleEnabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.decomposition_oracle_enabled', false);
    }

    /**
     * ACDE Leap 5 — the shape-prior gap for a plan, or [] (the byte-identical degrade path) when the flag is
     * OFF, no DB is resolvable, or the corpus is too thin (n<minSamples => UNKNOWN) for this plan's structural
     * shape. When the corpus DOES have enough history and the Wilson lower-bound certified-rate is below the
     * target, returns the single 'shape_historically_thrashes' advisory reason.
     *
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    private function shapePriorGaps(array $plan): array
    {
        if (! $this->shapePriorEnabled()) {
            return [];
        }

        try {
            $fp = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint($plan);
            $history = ($this->outcomeRecorder ?? new AtlasLoopDecompositionOutcomeRecorder)->history((string) $fp['hash']);
            $minSamples = max(1, (int) config('atlas.loop.shape_prior_min_samples', 8));
            $targetRate = (float) config('atlas.loop.shape_prior_target_rate', 0.5);
            $verdict = (new AtlasLoopDecompositionShapePrior)->assess(
                (int) ($history['certified'] ?? 0),
                (int) ($history['total'] ?? 0),
                $targetRate,
                $minSamples,
            );
            if (($verdict['verdict'] ?? 'unknown') === 'suspect') {
                return ['shape_historically_thrashes:'.((string) $fp['hash']).':'.((string) ($verdict['reason'] ?? ''))];
            }
        } catch (\Throwable) {
            return []; // any infra hiccup degrades to no-prior (never a false-reject)
        }

        return [];
    }

    /**
     * Is the shape-prior gate flag ON? Defensive (the pure-unit planner tests construct this gate WITHOUT a
     * container) — no resolvable config binding => treated OFF => byte-identical structural-only degrade.
     */
    private function shapePriorEnabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.shape_prior_gate_enabled', false);
    }

    /**
     * ACDE Leap 6 (plan-time) — the node-interface seam-EDGE gaps for a plan, or [] (the byte-identical
     * degrade) when the flag is OFF, no $goal was supplied, or no human froze an interface contract for THIS
     * objective. When a contract DOES exist, returns the validator's missing/inverted seam-edge reasons.
     *
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    private function interfaceGaps(array $plan, ?string $goal): array
    {
        $goal = trim((string) $goal);
        if ($goal === '' || ! $this->interfacePlanGateEnabled()) {
            return [];
        }

        $contract = (new AtlasLoopNodeInterfaceContract)->load($goal);
        if ($contract === null) {
            return [];
        }

        $validator = $this->validator ?? new AtlasLoopObraPlanValidator;

        return $validator->assertDecompositionEdges($plan, $contract);
    }

    /** Is the plan-time node-interface gate flag ON? Defensive (container-less pure callers => OFF). */
    private function interfacePlanGateEnabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.node_interface_plan_gate_enabled', false);
    }

    /**
     * ACDE Leap 8 (greenfield) — the archetype-invariant gaps for a plan, or [] when the flag is OFF, no
     * $goal was supplied, or the goal classifies into NO frozen archetype (degrade to structural-only).
     *
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    private function archetypeGaps(array $plan, ?string $goal): array
    {
        $goal = trim((string) $goal);
        if ($goal === '' || ! $this->archetypeEnabled()) {
            return [];
        }

        return (new AtlasLoopDecompositionArchetypeOracle)->violations($plan, $goal);
    }

    /** Is the greenfield archetype gate flag ON? Defensive (container-less pure callers => OFF). */
    private function archetypeEnabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.decomposition_archetype_enabled', false);
    }

    /**
     * The specification gaps for ONE node — empty means impeccably specified + pre-verified.
     *
     * @param  array<string,mixed>  $node
     * @return list<string>
     */
    private function nodeSpecGaps(array $node): array
    {
        $gaps = [];
        $request = trim((string) ($node['request'] ?? ''));
        $target = trim((string) ($node['target_area'] ?? ''));

        // SPECIFIED: the step states a concrete, non-trivial change scoped to its named file — not a
        // vague instruction the provider has to invent the meaning of.
        if (mb_strlen($request) < 20) {
            $gaps[] = 'request_too_vague';
        }
        if ($target === '' && (array) ($node['allowed_files'] ?? []) === []) {
            $gaps[] = 'no_named_target';
        } elseif ($target !== '' && ! str_contains($request, basename($target)) && ! str_contains($request, $target)) {
            // The request should reference the file it edits, so the change is anchored, not floating.
            $gaps[] = 'request_does_not_reference_its_target';
        }

        // PRE-VERIFIED: the acceptance contract exists BEFORE implementation (how we'll know it landed).
        if (! $this->hasPreDefinedAcceptance($node)) {
            $gaps[] = 'no_predefined_acceptance';
        }

        return $gaps;
    }

    /** @param  array<string,mixed>  $node */
    private function hasPreDefinedAcceptance(array $node): bool
    {
        $acc = is_array($node['acceptance'] ?? null) ? (array) $node['acceptance'] : [];
        if (array_filter((array) ($acc['commands'] ?? []), static fn ($c): bool => is_string($c) && trim($c) !== '')) {
            return true;
        }
        if (array_filter((array) ($node['frozen_tests'] ?? []), 'is_array')) {
            return true;
        }

        return (bool) ($node['complexity_proof'] ?? $acc['complexity_proof'] ?? false);
    }
}
