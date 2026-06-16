<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraAutoMergeService;
use App\Services\Ai\Obra\AtlasObraExecutor;

/**
 * Lever 1 — the DECOMPOSITION PLANNER (the "extremely large" enabler).
 *
 * The obra EXECUTION machinery already exists and is robust: {@see AtlasObraExecutor}
 * runs a node DAG in an isolated worktree with a per-node gate + a whole-obra integrated test + never-merge,
 * and {@see AtlasLoopObraAutoMergeService} merges only a genuinely
 * certified obra. What was MISSING is the planner that turns a large, complex goal into the dependency-
 * ordered DAG those gates run on — the {@see AtlasLoopObraPlanValidator} doc literally says a plan is
 * "decomposed (by the provider, or a FUTURE PLANNER)". This is that planner.
 *
 * It is ITERATE-TO-READY (the decomposition analog of iterate-to-green): ask the generator for a DAG,
 * assess it with the {@see AtlasLoopPlanReadinessGate} (which wraps the structural validator + per-node
 * specification + pre-verified-acceptance checks), and if the gate says REPLAN, feed the exact GAPS back
 * and regenerate — up to a budget. Only an IMPECCABLE plan (every node concrete, scoped, falsifiable, the
 * graph acyclic + pétreo-safe) is returned ready; a vague/unsafe decomposition is refused with its gaps so
 * no expensive execution budget is ever spent on an ill-formed plan.
 *
 * The plan GENERATION (the provider call producing the raw DAG) is abstracted as a callable so the planner
 * is deterministically testable against the REAL readiness gate without any provider.
 */
final class AtlasLoopObraDecompositionPlanner
{
    public function __construct(
        private readonly ?AtlasLoopPlanReadinessGate $readinessGate = null,
    ) {}

    /**
     * Iterate-to-READY: regenerate the DAG (feeding prior gaps back) until the readiness gate says
     * IMPLEMENT, or the attempt budget is exhausted.
     *
     * @param  callable(string, array<string,mixed>, list<string>): array<string,mixed>  $generatePlan
     *                                                                                                  (goal, context, priorGaps) => raw plan {plan_id, nodes:[{id, request, target_area|allowed_files,
     *                                                                                                  depends_on?, acceptance?}]}. priorGaps is non-empty on a REPLAN so the generator can fix them.
     * @param  list<string>  $allowedFiles  the obra's overall scope; every node's files must fall inside it
     * @return array{ready:bool, plan:?array<string,mixed>, attempts:int, assessment:array<string,mixed>, gaps:list<string>}
     */
    public function plan(string $goal, array $context, array $allowedFiles, callable $generatePlan, int $maxAttempts = 3): array
    {
        $gate = $this->readinessGate ?? new AtlasLoopPlanReadinessGate;
        $maxAttempts = max(1, $maxAttempts);

        $assessment = ['decision' => 'replan', 'ready' => false, 'gaps' => ['no_plan_generated']];
        $gaps = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $plan = $this->normalize($generatePlan($goal, $context, $gaps));
            // Pass the goal so the readiness gate can consult the Leap 2 boundary-oracle for THIS objective;
            // any missing-required-boundary gap round-trips here as priorGaps into the next regeneration.
            $assessment = $gate->assess($plan, $allowedFiles, $goal);

            if (($assessment['ready'] ?? false) === true) {
                return [
                    'ready' => true,
                    'plan' => $plan,
                    'attempts' => $attempt,
                    'assessment' => $assessment,
                    'gaps' => [],
                ];
            }
            $gaps = array_values(array_filter(array_map(
                static fn (mixed $g): string => is_string($g) ? $g : '',
                (array) ($assessment['gaps'] ?? []),
            ), static fn (string $g): bool => $g !== ''));
        }

        return [
            'ready' => false,
            'plan' => null,
            'attempts' => $maxAttempts,
            'assessment' => $assessment,
            'gaps' => $gaps,
        ];
    }

    /**
     * Coerce a raw generated plan into the shape the validator/gate expect. Defensive: a generator that
     * returns junk yields an empty-node plan that the gate cleanly rejects (REPLAN), never a fatal.
     *
     * @return array<string,mixed>
     */
    private function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $nodes = array_values(array_filter(
            is_array($raw['nodes'] ?? null) ? (array) $raw['nodes'] : [],
            'is_array',
        ));

        return [
            'plan_id' => trim((string) ($raw['plan_id'] ?? '')),
            'nodes' => $nodes,
        ];
    }
}
