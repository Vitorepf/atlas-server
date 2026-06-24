<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §2 · DOC-GAP CAPABILITY — the SUPPLY lane: the brain ORIGINATES a missing capability the canonical docs
 * DEMAND but no symbol provides. The third comprehension candidate type (after orphan-wiring + clone-dedup),
 * net-new work the proxy cyclomatic/coverage scan can never surface — it only sees code that EXISTS.
 *
 * SHAPE (mirrors orphan-wiring): the lane mints a DIRECTIVE only; the engine authors the capability + a
 * reproduction test that is RED before it exists and GREEN after (red_required ⇒ FrozenJudge Guard 4
 * diff_earned/revert_recheck certifies — a capability whose test was never red proves nothing). So the
 * DETERMINISTIC half (which gap, the red-gated objective) is here; the AUTHORING (building a real capability
 * from a doc sentence) is model-bound and fenced (§9) — never faked.
 *
 * PROVENANCE-HONEST: doc_stated_gaps are writable-untrusted PROSE ({@see AtlasLoopScopeComprehensionModel}).
 * The lane never trusts the doc as PROOF — it only uses it to NAME a candidate; the red→green cert is the
 * sole authority on whether a real, testable capability was actually built. A blank/degenerate gap ⇒ skipped.
 */
final class AtlasLoopDocGapSupplyLane
{
    /** doc-gap originates a NEW capability ⇒ a feature shape (red→green), the WorkTypeContract's red_to_green. */
    public const OBJECTIVE_KIND = 'feature';

    /**
     * Mint one capability-origination directive per doc-stated gap in the model.
     *
     * @return list<array{objective:string, payload:array<string,mixed>, members:list<never>}>
     */
    public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array
    {
        $specs = [];
        $seen = [];
        foreach ($model->docStatedGaps as $capability) {
            $cap = trim((string) $capability);
            if ($cap === '' || isset($seen[$cap])) {
                continue;
            }
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $cap)) {
                continue;
            }
            $seen[$cap] = true;
            $expectedTestPath = 'tests/Feature/Loop/'.$cap.'Test.php';

            $specs[] = [
                'objective' => sprintf(
                    'The canonical docs require `%s`, but no symbol provides it. Originate the missing capability: '
                    .'write a RED test that fails because `%s` does not exist, then build `%s` so the test goes GREEN '
                    .'(behavior-changing — the reproduction test is the acceptance bar).',
                    $cap, $cap, $cap,
                ),
                'payload' => [
                    'objective_kind' => self::OBJECTIVE_KIND,
                    'source' => 'doc_gap_capability',
                    'capability' => $cap,
                    // The new capability's test must be RED first, GREEN after — FrozenJudge Guard 4 (diff_earned)
                    // proves the diff is load-bearing; an always-green test certifies nothing.
                    'red_required' => true,
                    'acceptance' => [
                        'commands' => ['./vendor/bin/phpunit '.$expectedTestPath],
                        'red_required' => true,
                        'revert_recheck' => false,
                        'expected_test_path' => $expectedTestPath,
                    ],
                    'comprehension_originated' => true,     // provenance: the brain, not the proxy scan
                    'provenance' => AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
                ],
                'members' => [],
            ];
        }

        return $specs;
    }
}
