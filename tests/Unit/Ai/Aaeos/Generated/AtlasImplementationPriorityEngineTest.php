<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasImplementationPriorityEngineService;
use Tests\TestCase;

/**
 * Pins the load-bearing rules of the Self-Construction Implementation Priority
 * Engine doc: the signed ten-term Priority Formula (six add, four subtract),
 * the P0..P3 banding, the eight ordered P0 Foundations with the voice/mobile
 * "only after core contracts" gate, the seven Selection Questions with the two
 * hard pre-conditions, and the Deprioritize triggers that forbid P0. Pure, no
 * DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md
 */
class AtlasImplementationPriorityEngineTest extends TestCase
{
    private function service(): AtlasImplementationPriorityEngineService
    {
        return new AtlasImplementationPriorityEngineService();
    }

    /**
     * Doc "Priority Formula": priority_score is the signed sum of the exact ten
     * named terms — six positive minus four negative. With the worked values
     * (positives 6+5+4+3+2+1 = 21, negatives 10+1+1+1 = 13) the score is 8, and
     * the four negative terms must appear in `contributions` with a NEGATIVE
     * sign.
     */
    public function test_priority_formula_is_signed_sum_of_ten_named_terms(): void
    {
        $this->assertSame(
            ['strategic_leverage', 'dependency_unlocks', 'quality_improvement', 'autonomy_enablement', 'user_value', 'evidence_confidence'],
            AtlasImplementationPriorityEngineService::POSITIVE_TERMS,
        );
        $this->assertSame(
            ['risk', 'implementation_size', 'uncertainty', 'maintenance_burden'],
            AtlasImplementationPriorityEngineService::NEGATIVE_TERMS,
        );

        $r = $this->service()->score([
            'strategic_leverage' => 6,
            'dependency_unlocks' => 5,
            'quality_improvement' => 4,
            'autonomy_enablement' => 3,
            'user_value' => 2,
            'evidence_confidence' => 1,
            'risk' => 10,
            'implementation_size' => 1,
            'uncertainty' => 1,
            'maintenance_burden' => 1,
        ]);

        $this->assertSame(21.0, $r['positive_total']);
        $this->assertSame(13.0, $r['negative_total']);
        $this->assertSame(8.0, $r['priority_score']);
        // Negative terms subtract: risk of 10 contributes -10.
        $this->assertSame(-10.0, $r['contributions']['risk']);
        $this->assertSame(6.0, $r['contributions']['strategic_leverage']);
    }

    /**
     * Doc "Priority Formula": an absent term counts as 0 (never inflates) and is
     * reported in `missing_terms`. An empty packet therefore scores exactly 0.
     */
    public function test_absent_terms_count_as_zero_and_are_reported_missing(): void
    {
        $r = $this->service()->score([]);

        $this->assertSame(0.0, $r['priority_score']);
        $this->assertCount(10, $r['missing_terms']);
        $this->assertContains('maintenance_burden', $r['missing_terms']);
    }

    /**
     * Doc "P0 Foundations": exactly eight ordered areas, governed memory first,
     * and area 8 is the gated voice/mobile surfaces line ("only after core
     * contracts stay intact").
     */
    public function test_p0_foundations_are_the_eight_ordered_doc_areas(): void
    {
        $f = $this->service()->p0Foundations();

        $this->assertCount(8, $f);
        $this->assertSame('governed memory', $f[1]);
        $this->assertSame('SDD runtime', $f[4]);
        $this->assertSame('self-construction loop', $f[7]);
        $this->assertStringContainsString('voice/mobile', $f[8]);
        $this->assertStringContainsString('only after core contracts', $f[8]);
    }

    /**
     * Doc "P0 Foundations" gate: voice/mobile surfaces stay blocked while ANY
     * core contract is not intact, and unblock only when all seven are intact.
     */
    public function test_surface_gate_blocks_voice_mobile_until_core_intact(): void
    {
        $svc = $this->service();

        $blocked = $svc->surfaceGate([1 => true, 2 => true, 3 => true, 4 => false, 5 => true, 6 => true, 7 => true]);
        $this->assertFalse($blocked['surface_unblocked']);
        $this->assertSame([4], $blocked['broken_core_areas']);

        $open = $svc->surfaceGate([1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 7 => true]);
        $this->assertTrue($open['surface_unblocked']);
        $this->assertSame([], $open['broken_core_areas']);
        $this->assertSame(8, $open['surface_area']);
    }

    /**
     * Doc "Selection Questions": the two hard pre-conditions are gates-available
     * and small-reversible-slice. Missing either makes the work not selectable;
     * meeting both (plus any others) passes.
     */
    public function test_selection_gate_requires_gates_and_small_reversible_slice(): void
    {
        $svc = $this->service();

        $noGates = $svc->selectionGate(['small_reversible_slice' => true, 'gates_available' => false]);
        $this->assertFalse($noGates['passes']);
        $this->assertSame(['gates_available'], $noGates['blocking_unmet']);

        $ok = $svc->selectionGate([
            'unlocks_multiple_downstream' => true,
            'small_reversible_slice' => true,
            'gates_available' => true,
        ]);
        $this->assertTrue($ok['passes']);
        $this->assertSame(3, $ok['yes_count']);
    }

    /**
     * Doc "Deprioritize" + "Priority Packet": a high-scoring candidate that trips
     * a Deprioritize trigger (provider-wrapper driven) can NEVER sit at P0 — it
     * is demoted at least one band — even though its raw score would band P0.
     */
    public function test_deprioritize_trigger_forbids_p0(): void
    {
        $svc = $this->service();

        // Strong score (positives 50, negatives 0 => 50 => P0 band) but flagged.
        $decision = $svc->decide([
            'id' => 'flashy-but-flagged',
            'terms' => [
                'strategic_leverage' => 20,
                'dependency_unlocks' => 20,
                'quality_improvement' => 10,
            ],
            'selection' => ['gates_available' => true, 'small_reversible_slice' => true],
            'deprioritize' => ['provider_wrapper_driven' => true],
        ]);

        $this->assertSame('P0', $decision['base_p_level']);
        $this->assertNotSame('P0', $decision['p_level']);
        $this->assertSame('P1', $decision['p_level']);
        $this->assertContains('provider_wrapper_driven', $decision['deprioritize_flags']);
    }

    /**
     * Doc "Selection Questions" (hard pre-conditions) + "Current Strategic Bias":
     * a compounding foundation inside the strategic bias with all gates met
     * outranks a flashy provider-wrapper candidate that misses gates. The
     * non-selectable flashy work is forced to P3; the foundation is the top pick.
     */
    public function test_rank_puts_selectable_foundation_over_unselectable_flashy(): void
    {
        $svc = $this->service();

        $foundation = [
            'id' => 'governed-memory',
            'terms' => [
                'strategic_leverage' => 18,
                'dependency_unlocks' => 16,
                'quality_improvement' => 12,
                'risk' => 4,
            ],
            'in_strategic_bias' => true,
            'selection' => ['gates_available' => true, 'small_reversible_slice' => true],
        ];

        $flashy = [
            'id' => 'flashy-wrapper',
            'terms' => ['user_value' => 40],
            'selection' => ['gates_available' => false, 'small_reversible_slice' => true],
            'deprioritize' => ['provider_wrapper_driven' => true],
        ];

        $ranking = $svc->rank([$flashy, $foundation]);

        $this->assertSame('governed-memory', $ranking['top']['id']);
        $this->assertSame('P0', $ranking['top']['p_level']);
        $this->assertTrue($ranking['top']['selectable']);

        // Flashy candidate is not selectable (no gates) => forced to P3.
        $flashyDecision = $ranking['ranked'][1];
        $this->assertSame('flashy-wrapper', $flashyDecision['id']);
        $this->assertFalse($flashyDecision['selectable']);
        $this->assertSame('P3', $flashyDecision['p_level']);
    }
}
