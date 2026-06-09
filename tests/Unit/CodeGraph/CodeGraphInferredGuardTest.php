<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphInferredGuard;
use Tests\TestCase;

/**
 * AP-815 · Q-4 — contract for the edge-level anti-over-claim guard.
 *
 * Pure (no DB): the guard is a deterministic array transform. We prove the three
 * invariants the canon requires — extracted edges are sacred, weak inferred are
 * dropped, and the inferred:total ratio is structurally capped (weakest first).
 */
class CodeGraphInferredGuardTest extends TestCase
{
    private function guard(): CodeGraphInferredGuard
    {
        return new CodeGraphInferredGuard;
    }

    /**
     * Happy path: inferred dominate. After the guard the final inferred ratio must
     * be at/under the cap, no extracted edge is dropped, and the weakest inferred
     * are the ones removed.
     */
    public function test_caps_inferred_ratio_keeps_extracted_and_drops_weakest_inferred_first(): void
    {
        $edges = [
            ['id' => 'X1', 'edge_type' => 'extracted'],                  // sacred
            ['id' => 'I_strong', 'edge_type' => 'inferred', 'score' => 0.95],
            ['id' => 'I_mid', 'edge_type' => 'inferred', 'score' => 0.70],
            ['id' => 'I_weak', 'edge_type' => 'inferred', 'score' => 0.50],
            ['id' => 'I_weaker', 'edge_type' => 'inferred', 'score' => 0.30],
        ];

        // 1 extracted, cap 0.35 → max inferred k where k/(1+k) ≤ 0.35 ⇒ k ≤ 0.538 ⇒ 0.
        // So with a single extracted edge and a 0.35 cap, ALL inferred get capped out.
        $result = $this->guard()->apply($edges, ['min_score' => 0.2, 'max_ratio' => 0.35]);

        $this->assertLessThanOrEqual(
            $result['stats']['cap'],
            $result['stats']['inferred_ratio'],
            'Final inferred ratio must never exceed the cap.'
        );

        $keptIds = array_column($result['kept'], 'id');
        $this->assertContains('X1', $keptIds, 'Extracted edge must NEVER be dropped.');
        $this->assertSame(['X1'], $keptIds, 'With one extracted and a 0.35 cap, ALL inferred are capped out.');
        $this->assertSame(0, $result['stats']['kept_inferred']);
        $this->assertSame(4, $result['stats']['dropped_inferred']);
        // The load-bearing "weakest-dropped-first" ordering is proven in the
        // dedicated test below (where the cap leaves room for some inferred).

        $this->assertSame(1, $result['stats']['extracted']);
        $this->assertSame(4, $result['stats']['inferred']);
        $this->assertSame(0.35, $result['stats']['cap']);
    }

    /**
     * With enough extracted edges to make the cap permissive, the cap drops the
     * LOWEST-score inferred first and keeps the strongest.
     */
    public function test_weakest_inferred_dropped_first_when_cap_allows_some(): void
    {
        // 10 extracted edges → cap 0.35 allows k where k/(10+k) ≤ 0.35 ⇒ k ≤ 5.38 ⇒ 5.
        $edges = [];
        for ($i = 0; $i < 10; $i++) {
            $edges[] = ['id' => "X{$i}", 'edge_type' => 'extracted'];
        }
        // 7 inferred (above the floor) — 2 must be capped out, and they must be the
        // two lowest scores (0.21 and 0.25).
        $edges[] = ['id' => 'I_021', 'edge_type' => 'inferred', 'score' => 0.21];
        $edges[] = ['id' => 'I_090', 'edge_type' => 'inferred', 'score' => 0.90];
        $edges[] = ['id' => 'I_025', 'edge_type' => 'inferred', 'score' => 0.25];
        $edges[] = ['id' => 'I_080', 'edge_type' => 'inferred', 'score' => 0.80];
        $edges[] = ['id' => 'I_070', 'edge_type' => 'inferred', 'score' => 0.70];
        $edges[] = ['id' => 'I_060', 'edge_type' => 'inferred', 'score' => 0.60];
        $edges[] = ['id' => 'I_050', 'edge_type' => 'inferred', 'score' => 0.50];

        $result = $this->guard()->apply($edges, ['min_score' => 0.2, 'max_ratio' => 0.35]);

        $this->assertSame(5, $result['stats']['kept_inferred'], 'Cap allows exactly 5 inferred against 10 extracted.');
        $this->assertSame(2, $result['stats']['dropped_inferred']);
        $this->assertLessThanOrEqual($result['stats']['cap'], $result['stats']['inferred_ratio']);

        $keptIds = array_column($result['kept'], 'id');
        // The two weakest survivors of the floor were capped out.
        $this->assertNotContains('I_021', $keptIds, 'Lowest-score inferred dropped first.');
        $this->assertNotContains('I_025', $keptIds, 'Second-lowest-score inferred dropped next.');
        // The strongest are kept.
        foreach (['I_090', 'I_080', 'I_070', 'I_060', 'I_050'] as $strong) {
            $this->assertContains($strong, $keptIds, "Strong inferred {$strong} must be kept.");
        }
        // All 10 extracted kept.
        for ($i = 0; $i < 10; $i++) {
            $this->assertContains("X{$i}", $keptIds);
        }

        // Original input order is preserved among the kept edges.
        $this->assertSame(
            array_values(array_filter($keptIds, static fn ($id) => str_starts_with($id, 'X'))),
            ['X0', 'X1', 'X2', 'X3', 'X4', 'X5', 'X6', 'X7', 'X8', 'X9'],
            'Kept edges preserve original input order.'
        );
    }

    /** Edge case: empty input returns a safe, fully-zeroed shape (never throws). */
    public function test_empty_input_returns_safe_zeroed_shape(): void
    {
        $result = $this->guard()->apply([]);

        $this->assertSame([], $result['kept']);
        $this->assertSame([], $result['dropped']);
        $this->assertSame(0, $result['stats']['total']);
        $this->assertSame(0, $result['stats']['extracted']);
        $this->assertSame(0, $result['stats']['inferred']);
        $this->assertSame(0, $result['stats']['kept_inferred']);
        $this->assertSame(0, $result['stats']['dropped_inferred']);
        $this->assertSame(0.0, $result['stats']['inferred_ratio'], 'Ratio is 0.0 when nothing is kept (no division by zero).');
        // Cap falls back to the configured default 0.35.
        $this->assertSame(0.35, $result['stats']['cap']);
    }

    /**
     * Edge case: malformed/garbage input — non-array entries, missing scores,
     * non-numeric scores, the `confidence` field as the grade source, and an
     * unlabelled edge (trusted as extracted). The guard must never throw.
     */
    public function test_malformed_and_unlabelled_edges_handled_failsafe(): void
    {
        $edges = [
            'not-an-array',                                              // dropped (uninterpretable)
            42,                                                          // dropped
            null,                                                       // dropped
            ['id' => 'unlabelled'],                                      // no grade → extracted (trusted)
            ['id' => 'conf_inferred', 'confidence' => 'INFERRED', 'score' => 0.9], // grade via confidence
            ['id' => 'no_score_inferred', 'edge_type' => 'inferred'],    // missing score → 0.0 → below floor → dropped
            ['id' => 'garbage_score', 'edge_type' => 'inferred', 'score' => 'NaN'], // non-numeric → 0.0 → dropped
        ];

        $result = $this->guard()->apply($edges, ['min_score' => 0.2, 'max_ratio' => 0.9]);

        $keptIds = array_column($result['kept'], 'id');
        $this->assertContains('unlabelled', $keptIds, 'Unlabelled edge is trusted as extracted.');
        $this->assertContains('conf_inferred', $keptIds, 'Grade can be read from the confidence field; high score survives.');
        $this->assertNotContains('no_score_inferred', $keptIds, 'Inferred with no score is the weakest (0.0) and dropped by the floor.');
        $this->assertNotContains('garbage_score', $keptIds, 'Non-numeric score becomes 0.0 and is dropped by the floor.');

        // 3 non-array + 2 sub-floor inferred = 5 dropped; 2 kept.
        $this->assertCount(5, $result['dropped']);
        $this->assertSame(2, $result['stats']['total']);
        $this->assertSame(1, $result['stats']['extracted'], 'unlabelled counts as extracted.');
        $this->assertSame(3, $result['stats']['inferred'], 'three edges were graded inferred (one survived).');
        $this->assertSame(1, $result['stats']['kept_inferred']);
    }

    /** An all-extracted set is passed through untouched, ratio 0.0. */
    public function test_all_extracted_passthrough(): void
    {
        $edges = [
            ['id' => 'A', 'edge_type' => 'extracted'],
            ['id' => 'B', 'edge_type' => 'extracted', 'score' => 0.01], // low score is irrelevant for extracted
            ['id' => 'C'], // unlabelled → extracted
        ];

        $result = $this->guard()->apply($edges);

        $this->assertCount(3, $result['kept']);
        $this->assertCount(0, $result['dropped']);
        $this->assertSame(3, $result['stats']['extracted']);
        $this->assertSame(0, $result['stats']['inferred']);
        $this->assertSame(0.0, $result['stats']['inferred_ratio']);
    }

    /** A misconfigured cap of 1.0 is clamped strictly below 1 so the guard stays defined. */
    public function test_cap_of_one_is_clamped_below_one(): void
    {
        $edges = [
            ['id' => 'X', 'edge_type' => 'extracted'],
            ['id' => 'I', 'edge_type' => 'inferred', 'score' => 0.9],
        ];

        $result = $this->guard()->apply($edges, ['max_ratio' => 1.0]);

        $this->assertLessThan(1.0, $result['stats']['cap'], 'A 1.0 cap is clamped below 1 (structural ceiling never disabled).');
        // With an effectively-permissive cap, the single strong inferred survives.
        $this->assertSame(1, $result['stats']['kept_inferred']);
    }

    /** $opts overrides win over config defaults. */
    public function test_opts_override_config_defaults(): void
    {
        config([
            'atlas.code_graph.min_inferred_score' => 0.2,
            'atlas.code_graph.max_inferred_ratio' => 0.35,
        ]);

        $edges = [
            ['id' => 'I', 'edge_type' => 'inferred', 'score' => 0.4],
        ];

        // Raise the floor above the edge's score via opts → it is dropped.
        $result = $this->guard()->apply($edges, ['min_score' => 0.5]);

        $this->assertSame(0, $result['stats']['kept_inferred'], 'opts min_score overrides config and drops the edge.');
        $this->assertCount(1, $result['dropped']);
    }

    /** Same input yields byte-identical output (determinism). */
    public function test_deterministic_output(): void
    {
        $edges = [
            ['id' => 'X', 'edge_type' => 'extracted'],
            ['id' => 'I_a', 'edge_type' => 'inferred', 'score' => 0.5],
            ['id' => 'I_b', 'edge_type' => 'inferred', 'score' => 0.5], // identical score → tie-break by index
            ['id' => 'I_c', 'edge_type' => 'inferred', 'score' => 0.4],
        ];

        $first = $this->guard()->apply($edges, ['min_score' => 0.2, 'max_ratio' => 0.35]);
        $second = $this->guard()->apply($edges, ['min_score' => 0.2, 'max_ratio' => 0.35]);

        $this->assertSame($first, $second, 'Identical input must produce identical output.');
    }
}
