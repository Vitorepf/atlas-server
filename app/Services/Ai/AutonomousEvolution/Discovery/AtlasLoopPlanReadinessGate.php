<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

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

    public function __construct(private readonly ?AtlasLoopObraPlanValidator $validator = null)
    {
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<string>         $allowedFiles
     * @return array{decision:string, ready:bool, readiness_score:float, structural_valid:bool, gaps:list<string>}
     */
    public function assess(array $plan, array $allowedFiles): array
    {
        $validator = $this->validator ?? new AtlasLoopObraPlanValidator();
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
