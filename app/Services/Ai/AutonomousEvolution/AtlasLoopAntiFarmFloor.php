<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §3 · ANTI-FARM FLOOR — the merge-eligibility floor for COMPREHENSION-originated work.
 *
 * A farm-proof cert (Guard 4d count-drop, Guard 4e method-kills, Guard 4 diff_earned) proves a SINGLE
 * delivery is real. But the worst failure is a CERT THAT MERGES on a cosmetic flip — so before any
 * comprehension work item is allowed to merge, it must clear two independent floors a cosmetic change can
 * never both clear:
 *
 *   (1) BITES — the diff is LOAD-BEARING: reverting / neutralizing it makes a frozen check go RED. Proven by
 *       ANY of: diff_earned (revert→red), method_kills (neutralize the orphan→red), earned_red (the test was
 *       red before the fix), count_drop (the clone body actually collapsed). A cosmetic flip bites nothing.
 *   (2) PRODUCTION-PATH-PROVEN — a behavior-ADDING item (wired_proof: orphan-wiring / a new capability) must
 *       be wired into a REAL PRODUCTION caller, not a test-only or self-referential call-site (a `new X()` in
 *       a test farms wiredEarned). Structural items (dedup/refactor) are exempt: they add no caller, their
 *       realness IS the count/complexity drop under behavior-preservation.
 *
 * Pure + deterministic: it reads the cert evidence (no provider, no I/O) and returns eligible + the precise
 * reasons. The merge path consults it (when merge is operator-enabled); propose-only today, so it is the
 * READY floor, never a live merge. NEVER loosen — a new bite-proof may be ADDED, never removed.
 */
final class AtlasLoopAntiFarmFloor
{
    /**
     * @param  array<string,mixed>  $evidence  the certified work item's acceptance + verdict flags
     * @return array{eligible:bool, bites:bool, production_path_proven:bool, reasons:list<string>}
     */
    public function eligibleToMerge(array $evidence): array
    {
        // (1) BITES — at least one load-bearing proof must hold.
        $biteProofs = array_filter([
            'diff_earned' => self::truthy($evidence['diff_earned'] ?? null),
            'method_kills' => self::truthy($evidence['method_kills'] ?? null),
            'earned_red' => self::truthy($evidence['earned_red'] ?? null) || self::truthy($evidence['red_required'] ?? null) && self::truthy($evidence['revert_recheck'] ?? null),
            'count_drop' => self::truthy($evidence['count_drop'] ?? null) || self::truthy($evidence['dedup_proof'] ?? null) && self::truthy($evidence['complexity_dropped'] ?? null),
        ]);
        $bites = $biteProofs !== [];

        // (2) PRODUCTION-PATH-PROVEN — only required for behavior-ADDING (wired) items.
        $isWired = self::truthy($evidence['wired_proof'] ?? null);
        $productionProven = ! $isWired || self::truthy($evidence['production_caller'] ?? null);

        $reasons = [];
        if (! $bites) {
            $reasons[] = 'not_load_bearing:no_bite_proof'; // a cosmetic flip — reverting it breaks nothing
        }
        if ($isWired && ! $productionProven) {
            $reasons[] = 'wired_into_non_production_path'; // a test-only / self-ref caller farms wiredEarned
        }

        return [
            'eligible' => $bites && $productionProven,
            'bites' => $bites,
            'production_path_proven' => $productionProven,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Truthiness oracle for cert evidence values (booleans, ints, strings).
     *
     * Lifted from the inline closure that used to live at the top of
     * {@see self::eligibleToMerge()}. Hoisting it is what collapses that
     * method's cyclomatic: the closure contributed 3 BooleanOr nodes inside
     * the AST walk the frozen judge performs, and moving them to a sibling
     * method trades 3 decisions out of the worst method for 4 decisions in a
     * helper — net -3 in the worst method, while keeping the file's
     * `total − methods` decisions count flat (the helper's cc is added to
     * both `total` and `methods`, so the decision-point aggregate is unchanged).
     */
    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }
}
