<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiLocalPerformanceMemoryStrategyService;
use Tests\TestCase;

/**
 * Pins the documented local-performance memory strategy rules: pyramid promotion
 * (single filtered step toward the prompt; never L3 raw -> L0), the degradation
 * ladder order, the 48GB reservation bands, the eight Context Pack quality gates
 * (all-or-nothing) and the local-model capability boundary.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
 */
class AtlasAiLocalPerformanceMemoryStrategyTest extends TestCase
{
    private function service(): AtlasAiLocalPerformanceMemoryStrategyService
    {
        return new AtlasAiLocalPerformanceMemoryStrategyService();
    }

    /**
     * Pyramid: a single filtered step toward the prompt (L3 -> L2) is legal; a
     * raw jump that skips levels (L3 -> L0) is rejected as a raw jump even when a
     * filter is declared — "Nunca pular direto de L3 bruto para L0."
     */
    public function test_pyramid_allows_single_filtered_step_and_rejects_raw_jump(): void
    {
        $svc = $this->service();

        $step = $svc->memoryPyramidPromotion('L3_canonical_store', 'L2_local_index', true);
        $this->assertTrue($step['legal']);
        $this->assertSame('promotion_allowed', $step['verdict']);

        $jump = $svc->memoryPyramidPromotion('L3_canonical_store', 'L0_prompt', true);
        $this->assertFalse($jump['legal']);
        $this->assertSame('rejected_raw_jump', $jump['verdict']);
    }

    /**
     * Pyramid: even an adjacent step is rejected when no filter is declared
     * (promotion happens "com filtros"), and a step in the wrong direction
     * (toward the archive) is rejected as wrong-direction.
     */
    public function test_pyramid_requires_filter_and_correct_direction(): void
    {
        $svc = $this->service();

        $unfiltered = $svc->memoryPyramidPromotion('L1_hot_ram', 'L0_prompt', false);
        $this->assertFalse($unfiltered['legal']);
        $this->assertSame('rejected_unfiltered', $unfiltered['verdict']);

        $backwards = $svc->memoryPyramidPromotion('L1_hot_ram', 'L2_local_index', true);
        $this->assertFalse($backwards['legal']);
        $this->assertSame('rejected_wrong_direction', $backwards['verdict']);
    }

    /**
     * Degradation ladder: under pressure the actions engage in the exact
     * documented order. At 1 step only the local model is disabled; the local
     * model is inactive the moment any degradation is applied; the Context Pack
     * is always preserved (never sacrificed for latency).
     */
    public function test_degradation_ladder_order_and_pack_preservation(): void
    {
        $svc = $this->service();

        $none = $svc->degradationPlan(0);
        $this->assertSame([], $none['applied']);
        $this->assertTrue($none['local_model_active']);

        $one = $svc->degradationPlan(1);
        $this->assertSame(['disable_local_model'], $one['applied']);
        $this->assertFalse($one['local_model_active']);

        $all = $svc->degradationPlan(4);
        $this->assertSame([
            'disable_local_model',
            'reduce_hot_cache',
            'reduce_rerank_batch',
            'external_provider_compact_pack',
        ], $all['applied']);
        $this->assertTrue($all['last_resort']);
        // The last resort still keeps a (compact) Context Pack.
        $this->assertTrue($all['context_pack_preserved']);

        // Over-clamping is bounded to the ladder length.
        $this->assertSame(4, $svc->degradationPlan(99)['pressure_steps']);
    }

    /**
     * RAM reservation: at the 48GB baseline the band maximums (12+6+16+20+8=62)
     * overcommit, so fits_at_max is false and the drop order starts with the
     * optional local model, then cache/indices — mirroring the degradation
     * ladder. The band minimums (8+3+8+8+4=31) fit comfortably.
     */
    public function test_ram_reservation_bands_overcommit_and_drop_order(): void
    {
        $plan = $this->service()->ramReservation(48);

        $this->assertSame(31, $plan['min_total_gb']);
        $this->assertSame(62, $plan['max_total_gb']);
        $this->assertFalse($plan['fits_at_max']);
        $this->assertSame(62 - 48, $plan['overcommit_gb']);
        $this->assertSame(
            ['local_model_quantized', 'cache_indices_hot_packs'],
            $plan['drop_order'],
        );

        // A requested value above the band is clamped to the band max.
        $clamped = $this->service()->ramReservation(48, ['local_model_quantized' => 64]);
        $this->assertSame(20, $clamped['bands']['local_model_quantized']['clamped']);
    }

    /**
     * Quality gates: a pack is admissible only when ALL eight gates pass. Seven
     * green + one missing => blocked; flipping the last one to true => admissible.
     * "Sem esses gates, a RAM vira acelerador de erro."
     */
    public function test_quality_gates_are_all_or_nothing(): void
    {
        $svc = $this->service();

        $allGates = AtlasAiLocalPerformanceMemoryStrategyService::QUALITY_GATES;
        $this->assertCount(8, $allGates);

        // Seven true, replay_metadata absent (defaults to false).
        $sevenTrue = array_fill_keys($allGates, true);
        unset($sevenTrue['replay_metadata']);

        $blocked = $svc->qualityGates($sevenTrue);
        $this->assertFalse($blocked['admissible']);
        $this->assertSame(['replay_metadata'], $blocked['failed']);
        $this->assertSame('blocked_failing_gate', $blocked['verdict']);

        $admissible = $svc->qualityGates(array_fill_keys($allGates, true));
        $this->assertTrue($admissible['admissible']);
        $this->assertTrue($admissible['all_passed']);
        $this->assertSame([], $admissible['failed']);
    }

    /**
     * Local-model boundary: a preparatory action (rerank) is allowed; a decision
     * action (apply / decide final provider) is denied; an unknown action is
     * denied by default. "Python pode pensar pesado, mas Laravel decide."
     */
    public function test_local_model_capability_boundary(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->localModelCapability('rerank')['allowed']);
        $this->assertTrue($svc->localModelCapability('classify_privacy')['allowed']);

        $this->assertFalse($svc->localModelCapability('apply')['allowed']);
        $this->assertFalse($svc->localModelCapability('decide_final_provider')['allowed']);

        // Anything outside the allowed set is denied by default.
        $this->assertFalse($svc->localModelCapability('write_to_disk')['allowed']);
    }
}
