<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopLeverageScorer;
use Tests\TestCase;

/**
 * Freezes the leverage equation that decides "biggest leap per least time":
 *   leverage = (strategic_impact × breadth × compounding) / (cost × risk)
 * plus the ambition floor that rejects trivia, dreams (non-verifiable), and no-unblock work.
 */
class AtlasLoopLeverageScorerTest extends TestCase
{
    private function scorer(): AtlasLoopLeverageScorer
    {
        return new AtlasLoopLeverageScorer;
    }

    public function test_leverage_equation_is_exact_and_explainable(): void
    {
        // breadth = 10/20 = 0.5 ; compounding(default) = 0.5*0.5 + 0.5*(15/30) = 0.5
        // leverage = (0.8 * 0.5 * 0.5) / (0.5 * 0.5) = 0.2 / 0.25 = 0.8
        $s = $this->scorer()->score([
            'path' => 'app/Foo.php', 'shape' => 'refactor',
            'caller_count' => 10, 'cyclomatic' => 15,
            'strategic_impact' => 0.8, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true,
        ]);

        $this->assertSame(0.8, $s['leverage']);
        $this->assertSame(0.5, $s['components']['breadth']);
        $this->assertSame(0.5, $s['components']['compounding']);
        $this->assertSame(0.8, $s['components']['strategic_impact']);
        $this->assertStringContainsString('leverage=0.80', $s['rationale']);
    }

    public function test_strategic_impact_breaks_ties_toward_the_brain_aligned_leap(): void
    {
        // identical structure, different strategic alignment → aligned one ranks first.
        $ranked = $this->scorer()->rank([
            ['path' => 'low', 'caller_count' => 10, 'cyclomatic' => 15, 'strategic_impact' => 0.3, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true],
            ['path' => 'high', 'caller_count' => 10, 'cyclomatic' => 15, 'strategic_impact' => 0.9, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true],
        ]);

        $this->assertSame('high', $ranked[0]['path']);
        $this->assertSame('low', $ranked[1]['path']);
        $this->assertGreaterThan($ranked[1]['_score']['leverage'], $ranked[0]['_score']['leverage']);
    }

    public function test_cost_and_risk_shrink_leverage(): void
    {
        $cheap = $this->scorer()->score(['caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0, 'cost' => 0.2, 'risk' => 0.2, 'verifiable' => true]);
        $costly = $this->scorer()->score(['caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0, 'cost' => 1.0, 'risk' => 1.0, 'verifiable' => true]);

        $this->assertGreaterThan($costly['leverage'], $cheap['leverage']);
    }

    public function test_ambition_floor_rejects_trivia_dreams_and_no_unblock(): void
    {
        $sc = $this->scorer();

        // trivial: 1 caller, cyclomatic 1 → tiny leverage, tiny unblock → REJECTED.
        $trivial = $sc->score(['caller_count' => 1, 'cyclomatic' => 1, 'verifiable' => true]);
        $this->assertFalse($sc->passesAmbitionFloor($trivial));

        // a real high-leverage hub but NOT verifiable (no provable acceptance) → REJECTED (a dream).
        $dream = $sc->score(['caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => false]);
        $this->assertGreaterThanOrEqual(0.6, $dream['leverage']);
        $this->assertFalse($sc->passesAmbitionFloor($dream));

        // genuine high-leverage, verifiable leap → PASSES.
        $leap = $sc->score(['caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0, 'cost' => 1.0, 'risk' => 1.0, 'verifiable' => true]);
        $this->assertTrue($sc->passesAmbitionFloor($leap));
    }

    public function test_missing_signals_fail_open_not_throw(): void
    {
        // empty candidate must score deterministically (defaults), never throw.
        $s = $this->scorer()->score([]);
        $this->assertIsFloat($s['leverage']);
        $this->assertSame(0.30, $s['components']['strategic_impact']); // STRATEGIC_DEFAULT
    }

    public function test_new_signals_accepted_without_throwing_when_absent(): void
    {
        $s = $this->scorer()->score([
            'caller_count' => 10, 'cyclomatic' => 15, 'strategic_impact' => 0.8,
            'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true,
            // new signals absent — must not throw and must leave existing leverage unchanged
        ]);
        $this->assertSame(0.8, $s['leverage'], 'absent new signals must not change the base formula');
        foreach (['unblock', 'risk_reduction', 'autonomy_gain', 'simplification_gain', 'dependency_unlock'] as $k) {
            $this->assertArrayHasKey($k, $s['components'], "component '{$k}' must always be present");
            $this->assertSame(0.0, $s['components'][$k], "absent signal '{$k}' must default to 0.0");
        }
    }

    public function test_new_signals_included_as_normalized_components_when_supplied(): void
    {
        $s = $this->scorer()->score([
            'caller_count' => 0, 'strategic_impact' => 0.5, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true,
            'unblock_count' => 5,          // 5/10 = 0.5
            'risk_reduction' => 0.6,
            'autonomy_gain' => 0.8,
            'simplification_gain' => 0.4,
            'dependency_unlock_count' => 5, // 5/5 = 1.0
        ]);
        $this->assertSame(0.5, $s['components']['unblock']);
        $this->assertSame(0.6, $s['components']['risk_reduction']);
        $this->assertSame(0.8, $s['components']['autonomy_gain']);
        $this->assertSame(0.4, $s['components']['simplification_gain']);
        $this->assertSame(1.0, $s['components']['dependency_unlock']);
    }

    public function test_rank_prefers_candidate_with_higher_autonomy_gain_when_base_inputs_tied(): void
    {
        $ranked = $this->scorer()->rank([
            ['path' => 'low-auto',  'caller_count' => 10, 'cyclomatic' => 15, 'strategic_impact' => 0.8, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true, 'autonomy_gain' => 0.1],
            ['path' => 'high-auto', 'caller_count' => 10, 'cyclomatic' => 15, 'strategic_impact' => 0.8, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true, 'autonomy_gain' => 0.9],
        ]);
        $this->assertSame('high-auto', $ranked[0]['path'], 'higher autonomy_gain must rank first when all other inputs are equal');
        $this->assertGreaterThan($ranked[1]['_score']['leverage'], $ranked[0]['_score']['leverage']);
    }

    public function test_ambition_floor_still_rejects_verifiable_false_even_with_high_autonomy(): void
    {
        $sc = $this->scorer();
        $dream = $sc->score([
            'caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0,
            'cost' => 0.5, 'risk' => 0.5, 'verifiable' => false,
            'autonomy_gain' => 1.0, 'unblock_count' => 10,
        ]);
        $this->assertFalse($sc->passesAmbitionFloor($dream), 'verifiable=false must always be rejected regardless of new signals');
    }

    public function test_ambition_floor_still_rejects_no_unblock_trivia_with_new_signals_zero(): void
    {
        $sc = $this->scorer();
        $trivial = $sc->score(['caller_count' => 1, 'cyclomatic' => 1, 'verifiable' => true]);
        $this->assertFalse($sc->passesAmbitionFloor($trivial), 'trivial candidates with no new signals must still be rejected');
    }
}
