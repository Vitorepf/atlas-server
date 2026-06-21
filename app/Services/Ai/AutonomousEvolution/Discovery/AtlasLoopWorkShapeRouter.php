<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * DECISION ("o quê a seguir") — the deterministic, provider-free work-SHAPE router.
 *
 * Target PICKING (which file) is already a sound leverage stack in
 * {@see AtlasLoopTargetDiscoveryService}. The gap this closes is work-SHAPE: the refiller used to
 * pick edge-fix vs single-file-refactor by a STATIC flag cascade, never by leverage reasoning.
 * This router consumes the signals discovery ALREADY stamps (impact_real_callers tri-state,
 * cyclomatic AST, refactor_leverage, has_sibling_test, framework_reach) and REASONS the
 * highest-leverage shape, with an auditable rationale:
 *
 *   - work_skip            a CONFIRMED orphan (measured 0 callers, 0 evidence, 0 reach) — grinding
 *                          it is provably zero real-world value; defer instead of spending a
 *                          provider call on dead code (the honest decision the cascade lacked).
 *   - multi_file           a detected COUPLED CLUSTER (hub + >=1 covered caller) — the heaviest
 *                          high-leverage shape; routes to the multi-file refactor synthesizer
 *                          (the cluster the single-file shape can only ever touch one file of).
 *   - extract_class        a WIRED, very-complex, test-backed hub whose worst-method cyclomatic is
 *                          well above the floor — the 2-file extract-class lane (target +
 *                          <Target>Support.php) that splits a god-method instead of nudging it.
 *   - single_file_refactor a WIRED, complex, test-backed hub — the substantive, behavior-preserving
 *                          refactor lane (proven by the certifier's AST drop), the dominant
 *                          high-leverage shape.
 *   - edge_fix             everything else — the default provider-grind lane.
 *
 * SHAPE VOCABULARY BROADENING (ACDE): for the longest time decideShape() could only name the
 * SMALLEST refactor shape (single_file_refactor) — the heavier extract-class and multi-file lanes
 * were fully built+proven but reachable ONLY from the refiller's static flag cascade, never from
 * this leverage reasoner. So the brain's shape selector was structurally forbidden from naming a
 * god-class split or a coupled-cluster refactor even when that was the obvious high-leverage target.
 * The two new shapes close that: extract_class (a worst-method cyclomatic well above the floor on a
 * test-backed hub) and multi_file (a detected coupled cluster). Each is gated behind a default-OFF
 * flag so with both OFF the function is BYTE-IDENTICAL to today; armed, it lets the rédea ORIGINATE
 * the heavier shapes the refiller already knows how to synthesize.
 *
 * PURE + FAIL-OPEN: no provider call, no repo write, no flag write, no DB. Any missing/garbled
 * signal or doubt returns edge_fix (today's default), so the refiller can only GAIN a reasoned
 * decision, never regress. The shape only ROUTES; the synthesizer/generator RED-gates downstream
 * remain the authority — the router never bypasses a gate and never sees a forbidden target
 * (discovery's admit() already filtered it). obra_candidate stays DETECT-only (the producer).
 */
final class AtlasLoopWorkShapeRouter
{
    public const SHAPE_SKIP = 'work_skip';

    public const SHAPE_REFACTOR = 'single_file_refactor';

    /** A 2-file extract-class refactor (target + <Target>Support.php) — a god-method split. */
    public const SHAPE_EXTRACT_CLASS = 'extract_class';

    /** A >=2-file coupled-cluster refactor (hub + covered callers). */
    public const SHAPE_MULTI_FILE = 'multi_file';

    public const SHAPE_EDGE_FIX = 'edge_fix';

    /**
     * @param  array<string,mixed>  $signals  the discovery signals packet stamped on the target
     * @return array{shape:string, reason:string, log:array<string,mixed>}
     */
    public function decideShape(array $signals): array
    {
        $minCyclomatic = max(1, (int) config('atlas.loop.decision_min_refactor_cyclomatic', config('atlas.loop.framework_refactor_min_cyclomatic', 10)));
        $leverageFloor = max(0.0, (float) config('atlas.loop.decision_leverage_floor', 0.6));

        // Tri-state caller measurement: int = measured (0 = confirmed orphan), null/absent = unmeasured.
        $callers = $signals['impact_real_callers'] ?? null;
        $callers = is_int($callers) ? $callers : null;
        $cyclomatic = (int) ($signals['cyclomatic'] ?? 0);
        $leverage = (float) ($signals['refactor_leverage'] ?? 0.0);
        $hasSibling = (bool) ($signals['has_sibling_test'] ?? false);
        $frameworkReach = (int) ($signals['framework_reach'] ?? 0);
        $orphan = (bool) ($signals['orphan'] ?? false);

        $log = [
            'impact_real_callers' => $callers,
            'cyclomatic' => $cyclomatic,
            'refactor_leverage' => round($leverage, 4),
            'has_sibling_test' => $hasSibling,
            'framework_reach' => $frameworkReach,
            'orphan' => $orphan,
            'min_cyclomatic' => $minCyclomatic,
            'leverage_floor' => $leverageFloor,
        ];

        // 1. Confirmed dead code => skip (don't spend a provider call). ONLY when discovery
        //    measured it an orphan; never skips on missing data (fail-open).
        if ($orphan === true) {
            return ['shape' => self::SHAPE_SKIP, 'reason' => 'confirmed_orphan_zero_leverage', 'log' => $log];
        }

        return (new AtlasLoopWorkShapeRouterSupport())->decideRoutableShape(
            $signals,
            $log,
            $minCyclomatic,
            $leverageFloor,
            $callers,
            $cyclomatic,
            $leverage,
            $hasSibling,
            $frameworkReach,
        );
    }
}
