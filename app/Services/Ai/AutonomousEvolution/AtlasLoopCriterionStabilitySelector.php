<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE lever V3 — criterion-stability selection across the best-of-N feature fan-out.
 *
 * The shipped T1 fan-out produces N candidate implementations of a feature; the frozen judge picks one by a
 * single score. V3 adds a DETERMINISTIC cross-candidate selector keyed on CRITERION STABILITY: run the feature's
 * bound sub-criteria atoms against EACH candidate and keep the one that satisfies the MOST criteria (ties broken
 * by the smaller change, then lexicographic id). A single pass — even a strong one — gives ONE sample with no
 * signal that it silently skipped an implied requirement; V3 uses WIDTH to expose that, selecting the candidate
 * that the most independent falsifiable criteria agree is complete.
 *
 * Pure + deterministic — it selects over machine-resolved per-criterion pass counts (the F2 completeness atoms /
 * the frozen acceptance), never a model claim, so nothing grades its own bar. HONEST LIMIT: this is the selector
 * half; it bites only once the feature fan-out collects a per-candidate criterion vector to hand it (the bounded
 * read-side wiring the verifier flagged).
 */
final class AtlasLoopCriterionStabilitySelector
{
    /**
     * Select the most criterion-stable candidate, or null when none is usable.
     *
     * @param  list<array{id?:string, criteria_passed?:int|list<mixed>, criteria_total?:int, change_size?:int}>  $candidates
     * @return array<string,mixed>|null
     */
    public function select(array $candidates): ?array
    {
        $scored = [];
        foreach ($candidates as $c) {
            if (! is_array($c) || trim((string) ($c['id'] ?? '')) === '') {
                continue;
            }
            $passed = is_array($c['criteria_passed'] ?? null)
                ? count($c['criteria_passed'])
                : max(0, (int) ($c['criteria_passed'] ?? 0));
            $change = max(0, (int) ($c['change_size'] ?? 0));
            $scored[] = ['candidate' => $c, 'passed' => $passed, 'change' => $change, 'id' => (string) $c['id']];
        }
        if ($scored === []) {
            return null;
        }

        // Most criteria passed first; ties => the SMALLER change (less risk); then lexicographic id (stable).
        usort($scored, static function (array $a, array $b): int {
            return [$b['passed'], $a['change'], $a['id']] <=> [$a['passed'], $b['change'], $b['id']];
        });

        return $scored[0]['candidate'];
    }
}
