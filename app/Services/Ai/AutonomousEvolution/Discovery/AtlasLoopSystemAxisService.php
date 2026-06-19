<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopUtilityGradeService;

/**
 * LOOP-OS · FASE 4 · SLICE 6 — the SYSTEM-AXIS vector (the MOVING objective).
 *
 * The {@see AtlasLoopExpectedValueDecider} needs a `context.axis_values` — the current [0,1] value of
 * each utility-grade axis — to know which axis is the BINDING bottleneck. This service emits that vector
 * RE-RESOLVED every cycle, so the objective MOVES: relieve the binding axis and the next-largest weighted
 * gap auto-becomes the new bottleneck (the "made code-gen fast ⇒ review is now the bottleneck" emergent —
 * NOT a hand-coded sequence, NOT a stored field).
 *
 * The re-resolution discipline is BORROWED, not forked: it CONSUMES the FORBIDDEN/pétreo
 * {@see AtlasLoopUtilityGradeService::grade()}, which re-queries the merged-proposal ledger AND
 * re-resolves the production caller graph FRESH at grade time (it never trusts a stored receipt count).
 * Because grade() recomputes ground truth on every call, this vector is a moving objective BY
 * CONSTRUCTION — there is no cached axis field anywhere to go stale. This service is NOT an edit of the
 * grade service (that file is frozen); it is a thin, honest reader on top of it.
 *
 * The axis weights are pinned to a single source of truth and asserted (in the slice's test) to match
 * BOTH the grade service's U formula AND the decider's AXIS_WEIGHTS — a drift between the three would
 * silently mis-rank the bottleneck.
 */
final class AtlasLoopSystemAxisService
{
    public const SCHEMA_VERSION = 'atlas.loop.system_axis.v1';

    /**
     * Utility-grade axis weights. MUST match {@see AtlasLoopUtilityGradeService} U and
     * {@see AtlasLoopExpectedValueDecider::AXIS_WEIGHTS} (the slice test guards the drift).
     */
    public const AXIS_WEIGHTS = [
        'wired' => 0.35,
        'real_target' => 0.20,
        'non_trivial' => 0.15,
        'compounding' => 0.20,
        'safety' => 0.10,
    ];

    /**
     * @param  null|callable(string,?int):array<string,mixed>  $graderOverride  TEST SEAM ONLY — defaults
     *         to the frozen {@see AtlasLoopUtilityGradeService::grade()}; injected only so the binding-axis
     *         math + the "re-resolved every call, never cached" property are deterministically provable
     *         without re-resolving the whole production caller graph in a unit test.
     */
    public function __construct(private $graderOverride = null) {}

    /**
     * The per-cycle axis vector — RE-RESOLVED on every call (no memoization). The binding axis is the
     * largest weighted gap weight·(1−value); relieving it auto-pivots the next call to the next axis.
     *
     * @return array{schema_version:string, axis_values:array<string,float>, binding_axis:string,
     *               weighted_gap:float, gaps:array<string,float>, graded_merges:int, resolved_at:string}
     */
    public function vector(string $repoRoot, ?int $window = null): array
    {
        $grade = $this->grade($repoRoot, $window);

        return $this->fromGrade($grade);
    }

    /**
     * PURE mapping grade → axis vector (no DB, no git) — the binding-axis math, unit-testable on its own.
     * Any missing axis defaults to 1.0 (fully satisfied ⇒ zero gap), so a partial grade never invents a
     * phantom bottleneck.
     *
     * @param  array<string,mixed>  $grade  a {@see AtlasLoopUtilityGradeService::grade()} envelope
     * @return array{schema_version:string, axis_values:array<string,float>, binding_axis:string,
     *               weighted_gap:float, gaps:array<string,float>, graded_merges:int, resolved_at:string}
     */
    public function fromGrade(array $grade): array
    {
        $axes = is_array($grade['axes'] ?? null) ? (array) $grade['axes'] : [];

        $axisValues = [];
        $gaps = [];
        foreach (self::AXIS_WEIGHTS as $axis => $weight) {
            $v = max(0.0, min(1.0, (float) ($axes[$axis] ?? 1.0)));
            $axisValues[$axis] = round($v, 4);
            $gaps[$axis] = round($weight * (1.0 - $v), 6);
        }

        // Binding axis = the largest weighted gap. Deterministic tie-break on axis name so two cycles with
        // identical numbers always name the same bottleneck (a moving objective must not flap on ties).
        $bindingAxis = 'wired';
        $maxGap = -1.0;
        foreach ($gaps as $axis => $gap) {
            if ($gap > $maxGap || ($gap === $maxGap && strcmp($axis, $bindingAxis) < 0)) {
                $maxGap = $gap;
                $bindingAxis = $axis;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'axis_values' => $axisValues,
            'binding_axis' => $bindingAxis,
            'weighted_gap' => round(max(0.0, $maxGap), 6),
            'gaps' => $gaps,
            'graded_merges' => max(0, (int) ($grade['graded_merges'] ?? 0)),
            'resolved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * RE-RESOLVE the grade FRESH. The default path constructs the grade service against THIS repo so the
     * caller graph + merged ledger are re-read every call — the property the slice proves. Built explicitly
     * (not container-autowired) because the nested {@see AtlasLoopWiredCallerService} needs the repo root.
     *
     * @return array<string,mixed>
     */
    private function grade(string $repoRoot, ?int $window): array
    {
        if (is_callable($this->graderOverride)) {
            return ($this->graderOverride)($repoRoot, $window);
        }

        $repoRoot = rtrim($repoRoot, '/');

        return (new AtlasLoopUtilityGradeService(new AtlasLoopWiredCallerService($repoRoot), $repoRoot))->grade($window);
    }
}
