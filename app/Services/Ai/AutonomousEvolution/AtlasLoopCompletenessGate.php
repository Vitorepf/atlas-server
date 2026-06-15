<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * NEXT-LEVER 1 — COMPLETENESS certification (the "complete" dimension).
 *
 * Every other gate proves CORRECTNESS: tests pass, behaviour preserved, complexity drops, judges agree.
 * None proves COMPLETENESS — that the change did the WHOLE job, not just enough to pass one test. A
 * refactor that simplifies ONE method of a god-class "passes" while the class stays god; a feature that
 * passes its single test silently ignores an implied requirement. This gate closes that hole: a goal
 * carries a CHECKLIST of acceptance criteria (each a falsifiable sub-requirement), and a delivery is
 * complete ONLY when every REQUIRED criterion is satisfied and overall coverage clears the floor.
 *
 * Pure + deterministic; each criterion arrives with its `satisfied` already resolved (by the criterion's
 * own command/pattern check upstream), so the completeness math is unit-testable. FAIL-OPEN on an empty
 * checklist (a delivery with no declared criteria is not gated here — byte-identical until criteria exist).
 */
final class AtlasLoopCompletenessGate
{
    /**
     * @param  list<array{id?:string, description?:string, satisfied?:bool, required?:bool}>  $criteria
     * @return array{complete:bool, coverage:float, total:int, satisfied:int, missing:list<string>, required_missing:list<string>, reason:?string}
     */
    public function evaluate(array $criteria, ?float $minCoverage = null): array
    {
        $minCoverage = $minCoverage === null ? 1.0 : max(0.0, min(1.0, $minCoverage));
        $total = count($criteria);

        if ($total === 0) {
            return [
                'complete' => true, 'coverage' => 1.0, 'total' => 0, 'satisfied' => 0,
                'missing' => [], 'required_missing' => [], 'reason' => null,
            ];
        }

        $satisfied = 0;
        $missing = [];
        $requiredMissing = [];
        foreach ($criteria as $i => $c) {
            $id = trim((string) ($c['id'] ?? ('criterion#'.$i))) ?: ('criterion#'.$i);
            $ok = (bool) ($c['satisfied'] ?? false);
            $required = (bool) ($c['required'] ?? true);
            if ($ok) {
                $satisfied++;
            } else {
                $missing[] = $id;
                if ($required) {
                    $requiredMissing[] = $id;
                }
            }
        }

        // Compare the EXACT ratio against the floor (round only for the human-facing value) — comparing a
        // 4-decimal-rounded coverage could false-PASS at a rounding band straddling a >4-decimal floor.
        $exact = $satisfied / $total;
        $coverage = round($exact, 4);
        $complete = $requiredMissing === [] && $exact + 1e-9 >= $minCoverage;

        return [
            'complete' => $complete,
            'coverage' => $coverage,
            'total' => $total,
            'satisfied' => $satisfied,
            'missing' => array_values(array_unique($missing)),
            'required_missing' => array_values(array_unique($requiredMissing)),
            'reason' => $complete ? null : 'incomplete:'.($requiredMissing !== []
                ? 'required_unmet:'.implode(',', array_slice(array_unique($requiredMissing), 0, 6))
                : 'coverage:'.$coverage.'<'.$minCoverage),
        ];
    }
}
