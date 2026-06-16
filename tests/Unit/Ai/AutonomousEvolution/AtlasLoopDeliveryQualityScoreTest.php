<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryQualityScore;
use PHPUnit\Framework\TestCase;

/**
 * ACDE Bloco C — the Delivery Quality Score makes ">=2x vs ultracode" falsifiable and selection-bias-proof.
 * A REFUSAL counts as a defect (an engine cannot win by committing only winners); the ">=2x" verdict is the
 * relative-risk lower bound on the defect rate, so it demands real statistical evidence, not a small fluke.
 */
final class AtlasLoopDeliveryQualityScoreTest extends TestCase
{
    private function dqs(): AtlasLoopDeliveryQualityScore
    {
        return new AtlasLoopDeliveryQualityScore;
    }

    /** @return list<array<string,mixed>> n attempted: $refused refusals, $red committed-canary-red, rest committed-green */
    private function panel(int $n, int $refused, int $red): array
    {
        $p = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i < $refused) {
                $p[] = ['attempted' => true, 'committed' => false];
            } elseif ($i < $refused + $red) {
                $p[] = ['attempted' => true, 'committed' => true, 'canary' => 'red'];
            } else {
                $p[] = ['attempted' => true, 'committed' => true, 'canary' => 'green', 'mutation_kill_ratio' => 0.8, 'completeness' => 1.0, 'cyclomatic_drop' => 3.0];
            }
        }

        return $p;
    }

    public function test_a_refusal_counts_as_a_defect_killing_selection_bias(): void
    {
        // Commit only 50 winners, refuse 50 => defect_rate 0.5 (NOT 0). The refusals are delivery failures.
        $s = $this->dqs()->score($this->panel(100, 50, 0));

        $this->assertSame(100, $s['attempted']);
        $this->assertSame(50, $s['committed']);
        $this->assertSame(50, $s['refused']);
        $this->assertSame(50, $s['defects']);
        $this->assertSame(0.5, $s['defect_rate']);
    }

    public function test_a_committed_canary_red_is_a_defect(): void
    {
        $s = $this->dqs()->score($this->panel(20, 0, 4)); // 16 green, 4 committed-but-red

        $this->assertSame(20, $s['committed']);
        $this->assertSame(4, $s['escaped_defects']);
        $this->assertSame(0.2, $s['defect_rate']);
    }

    public function test_a_genuine_2x_gap_is_certified_with_confidence(): void
    {
        $ace = $this->panel(100, 5, 0);   // 5 defects / 100
        $opus = $this->panel(100, 0, 40); // 40 defects / 100

        $h = $this->dqs()->headToHead($ace, $opus, 2.0);

        $this->assertSame('a_at_least_factor_better', $h['verdict']);
        $this->assertTrue($h['confident_a_better']);
        $this->assertGreaterThanOrEqual(2.0, $h['defect_ratio_point']);
    }

    public function test_a_real_but_sub_2x_edge_is_not_certified_2x(): void
    {
        // 12 vs 18 / 100 — A is better but the relative-risk lower bound does not reach 2x.
        $ace = $this->panel(100, 12, 0);
        $opus = $this->panel(100, 0, 18);

        $h = $this->dqs()->headToHead($ace, $opus, 2.0);

        $this->assertFalse($h['confident_a_better']);
        $this->assertNotSame('a_at_least_factor_better', $h['verdict']);
    }

    public function test_b_better_is_detected(): void
    {
        $ace = $this->panel(100, 0, 40);
        $opus = $this->panel(100, 5, 0);

        $h = $this->dqs()->headToHead($ace, $opus, 2.0);

        $this->assertSame('b_better', $h['verdict']);
    }

    public function test_defect_parity_falls_to_machine_tie_breaks(): void
    {
        // Same defect rate (5/100 each), but A's committed obras kill more mutants => A wins the tie-break.
        $ace = $this->panel(100, 5, 0); // committed-green carry mutation 0.8
        $opus = $this->panel(100, 5, 0);
        foreach ($opus as $i => &$o) {
            if (($o['canary'] ?? null) === 'green') {
                $o['mutation_kill_ratio'] = 0.3;
            } // weaker suites
        }
        unset($o);

        $h = $this->dqs()->headToHead($ace, $opus, 2.0);

        $this->assertSame('parity_tie_breaks_decide', $h['verdict']);
        $this->assertSame('a', $h['tie_break_winner']);
    }

    public function test_mismatched_panels_are_rejected_not_compared(): void
    {
        $h = $this->dqs()->headToHead($this->panel(30, 1, 0), $this->panel(50, 1, 0), 2.0);

        $this->assertSame('panel_mismatch', $h['verdict'], 'different attempted counts => not the same frozen obra set');
        $this->assertFalse($h['confident_a_better']);
    }

    public function test_empty_panels_are_insufficient_data(): void
    {
        $this->assertSame('insufficient_data', $this->dqs()->headToHead([], [], 2.0)['verdict']);
    }
}
