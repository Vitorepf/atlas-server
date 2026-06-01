<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveOverviewService;
use Tests\TestCase;

/**
 * Pins the documented executive-overview rules of the Cognitive Plane: the
 * cognitive north-question (multiplies AND single-channel), the 4-pillars
 * mastery gate (all four or not mastered), the temporal-compression thresholds
 * (>= 3x floor, 5-10x target), and the 5-movements canonical order with the
 * gerar_erro-before-praticar inversion.
 *
 * @see docs/engineering-knowledge-base/cognitive/overview.md
 */
class AtlasCognitiveOverviewTest extends TestCase
{
    private function service(): AtlasCognitiveOverviewService
    {
        return new AtlasCognitiveOverviewService();
    }

    /**
     * North-question — build ONLY when the feature multiplies cognitive output
     * AND keeps Atlas as the single channel. Competing with learning OR creating
     * an escape forces a discard.
     */
    public function test_north_question_builds_only_when_multiplies_and_single_channel(): void
    {
        $build = $this->service()->applyNorthQuestion([
            'multiplies_cognitive_output' => true,
            'single_channel' => true,
        ]);
        $this->assertSame(AtlasCognitiveOverviewService::NORTH_BUILD, $build['verdict']);
        $this->assertTrue($build['build']);

        // Competes with the act of learning => discard even though it multiplies.
        $competes = $this->service()->applyNorthQuestion([
            'multiplies_cognitive_output' => true,
            'single_channel' => true,
            'competes_with_learning' => true,
        ]);
        $this->assertSame(AtlasCognitiveOverviewService::NORTH_DISCARD, $competes['verdict']);
        $this->assertFalse($competes['build']);
        $this->assertContains('competes_with_act_of_learning', $competes['reasons']);

        // Creates an escape-hatch out of the channel => discard.
        $escape = $this->service()->applyNorthQuestion([
            'multiplies_cognitive_output' => true,
            'single_channel' => true,
            'creates_escape' => true,
        ]);
        $this->assertSame(AtlasCognitiveOverviewService::NORTH_DISCARD, $escape['verdict']);
        $this->assertContains('creates_escape_friction', $escape['reasons']);

        // Fail-closed: empty feature does not build.
        $this->assertFalse($this->service()->shouldBuild([]));
    }

    /**
     * 4 Pillars — mastery requires ALL four. A rubric covering only one (or
     * three) pillars must NOT mark the area mastered, and must report exactly
     * which pillars are missing.
     */
    public function test_mastery_requires_all_four_pillars(): void
    {
        $onlyTheoretical = $this->service()->evaluateMastery([
            AtlasCognitiveOverviewService::PILLAR_THEORETICAL => true,
        ]);
        $this->assertFalse($onlyTheoretical['mastered']);
        $this->assertSame(0.25, $onlyTheoretical['coverage_ratio']);
        $this->assertContains(AtlasCognitiveOverviewService::PILLAR_PRACTICAL, $onlyTheoretical['missing']);
        $this->assertContains(AtlasCognitiveOverviewService::PILLAR_COGNITIVE, $onlyTheoretical['missing']);
        $this->assertContains(AtlasCognitiveOverviewService::PILLAR_TRANSFER, $onlyTheoretical['missing']);
        $this->assertSame($onlyTheoretical['missing'], $onlyTheoretical['invalidated_by']);

        // Three of four still invalidates mastery (the transfer pillar is open).
        $threeOfFour = $this->service()->evaluateMastery([
            AtlasCognitiveOverviewService::PILLAR_THEORETICAL => true,
            AtlasCognitiveOverviewService::PILLAR_PRACTICAL => true,
            AtlasCognitiveOverviewService::PILLAR_COGNITIVE => true,
            AtlasCognitiveOverviewService::PILLAR_TRANSFER => false,
        ]);
        $this->assertFalse($threeOfFour['mastered']);
        $this->assertSame([AtlasCognitiveOverviewService::PILLAR_TRANSFER], $threeOfFour['missing']);

        // All four => mastered.
        $allFour = $this->service()->evaluateMastery([
            AtlasCognitiveOverviewService::PILLAR_THEORETICAL => true,
            AtlasCognitiveOverviewService::PILLAR_PRACTICAL => true,
            AtlasCognitiveOverviewService::PILLAR_COGNITIVE => true,
            AtlasCognitiveOverviewService::PILLAR_TRANSFER => true,
        ]);
        $this->assertTrue($allFour['mastered']);
        $this->assertSame([], $allFour['missing']);
        $this->assertSame(1.0, $allFour['coverage_ratio']);
        $this->assertTrue($this->service()->isMastered([
            AtlasCognitiveOverviewService::PILLAR_THEORETICAL => true,
            AtlasCognitiveOverviewService::PILLAR_PRACTICAL => true,
            AtlasCognitiveOverviewService::PILLAR_COGNITIVE => true,
            AtlasCognitiveOverviewService::PILLAR_TRANSFER => true,
        ]));
    }

    /**
     * Temporal compression — below 3x is not admissible; exactly 3x meets the
     * floor; 5-10x is on-target; above 10x exceeds.
     */
    public function test_compression_thresholds(): void
    {
        $below = $this->service()->classifyCompression(2.5);
        $this->assertFalse($below['meets_floor']);
        $this->assertSame('below_floor', $below['band']);

        // Exactly at the 3x floor is admissible.
        $atFloor = $this->service()->classifyCompression(3.0);
        $this->assertTrue($atFloor['meets_floor']);
        $this->assertFalse($atFloor['on_target']);
        $this->assertSame('meets_floor', $atFloor['band']);

        $onTarget = $this->service()->classifyCompression(7.0);
        $this->assertTrue($onTarget['meets_floor']);
        $this->assertTrue($onTarget['on_target']);
        $this->assertSame('on_target', $onTarget['band']);

        $exceeds = $this->service()->classifyCompression(12.0);
        $this->assertTrue($exceeds['exceeds_target']);
        $this->assertSame('exceeds_target', $exceeds['band']);
    }

    /**
     * 5 Movements — the canonical order is DECLARAR -> GERAR ERRO -> PRATICAR ->
     * PROVAR -> REVISAR, and the documented inversion requires gerar_erro to
     * come strictly before praticar.
     */
    public function test_movement_sequence_enforces_canonical_order_and_inversion(): void
    {
        $canonical = $this->service()->validateMovementSequence(
            AtlasCognitiveOverviewService::MOVEMENTS,
        );
        $this->assertTrue($canonical['valid']);
        $this->assertTrue($canonical['canonical']);
        $this->assertTrue($canonical['error_before_practice']);
        $this->assertSame([], $canonical['reasons']);

        // Swapping gerar_erro and praticar breaks the inversion -> invalid.
        $swapped = $this->service()->validateMovementSequence([
            AtlasCognitiveOverviewService::MOVEMENT_DECLARE,
            AtlasCognitiveOverviewService::MOVEMENT_PRACTICE,
            AtlasCognitiveOverviewService::MOVEMENT_GENERATE_ERROR,
            AtlasCognitiveOverviewService::MOVEMENT_PROVE,
            AtlasCognitiveOverviewService::MOVEMENT_REVIEW,
        ]);
        $this->assertFalse($swapped['valid']);
        $this->assertFalse($swapped['error_before_practice']);
        $this->assertContains('generate_error_must_precede_practice', $swapped['reasons']);

        // A dropped movement (no revisar) is reported and invalidates.
        $missing = $this->service()->validateMovementSequence([
            AtlasCognitiveOverviewService::MOVEMENT_DECLARE,
            AtlasCognitiveOverviewService::MOVEMENT_GENERATE_ERROR,
            AtlasCognitiveOverviewService::MOVEMENT_PRACTICE,
            AtlasCognitiveOverviewService::MOVEMENT_PROVE,
        ]);
        $this->assertFalse($missing['valid']);
        $this->assertContains(AtlasCognitiveOverviewService::MOVEMENT_REVIEW, $missing['missing_movements']);
    }

    /**
     * Gerar Erro step — without BOTH a divergence comparison and a transfer
     * test, the "error" is just noise; with prediction capture + comparison +
     * transfer it is a productive-failure step.
     */
    public function test_generate_error_step_is_noise_without_comparison_and_transfer(): void
    {
        $noise = $this->service()->validateGenerateErrorStep([
            'captured_prediction' => true,
            'has_comparison' => false,
            'has_transfer_test' => false,
        ]);
        $this->assertFalse($noise['productive']);
        $this->assertTrue($noise['is_noise']);
        $this->assertContains('missing_divergence_comparison', $noise['reasons']);
        $this->assertContains('missing_transfer_test', $noise['reasons']);

        $productive = $this->service()->validateGenerateErrorStep([
            'captured_prediction' => true,
            'has_comparison' => true,
            'has_transfer_test' => true,
        ]);
        $this->assertTrue($productive['productive']);
        $this->assertFalse($productive['is_noise']);
        $this->assertSame([], $productive['reasons']);
    }
}
