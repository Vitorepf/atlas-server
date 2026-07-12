<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\DecompositionShadowRecommender;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MULTK-05 — decomposition shadow recommender.
 *
 * Frontier plan §2389-2393 acceptance clauses:
 *   - large obra + bad proven history direct ⇒ shadow recommends split_n
 *   - n<10 in the size band ⇒ basis=insufficient, shadow does NOT recommend
 *   - shadow does NOT actuate (schema stamps shadow=true; payload is pure
 *     data — the actuation belongs to a later ELEV-26 promotion)
 *   - signal is DERIVED from evidence, never declared by the caller
 */
final class Multk05DecompositionShadowRecommenderTest extends TestCase
{
    #[Test]
    public function schema_and_decision_kind_are_pinned(): void
    {
        $this->assertSame('atlas.decide.decomposition_shadow.v1', DecompositionShadowRecommender::SCHEMA_VERSION);
        $this->assertSame('decomposition', DecompositionShadowRecommender::DECISION_KIND);
    }

    #[Test]
    public function every_output_carries_the_shadow_flag_true(): void
    {
        $out = DecompositionShadowRecommender::recommend([
            'size_estimate' => 12,
            'size_band_stats' => ['md' => ['n' => 25, 'proven_rate' => 0.8]],
        ]);
        $this->assertTrue($out['shadow']);
    }

    #[Test]
    public function large_obra_with_bad_proven_rate_recommends_split_n(): void
    {
        $out = DecompositionShadowRecommender::recommend([
            'size_estimate' => 80, // → 'lg' band
            'size_band_stats' => [
                'lg' => ['n' => 40, 'proven_rate' => 0.30],
            ],
            'evidence_refs' => ['outcome://lg/2026-06'],
        ]);

        $this->assertSame('split_n', $out['recommendation']);
        $this->assertSame('lg', $out['size_band']);
        $this->assertSame(40, $out['sample']['n']);
        $this->assertSame(['outcome://lg/2026-06'], $out['evidence_refs']);
    }

    #[Test]
    public function small_sample_below_floor_returns_insufficient_never_a_guess(): void
    {
        $out = DecompositionShadowRecommender::recommend([
            'size_estimate' => 80,
            'size_band_stats' => [
                'lg' => ['n' => 3, 'proven_rate' => 0.9],
            ],
        ]);

        $this->assertSame('insufficient', $out['recommendation']);
        $this->assertStringContainsString('insufficient', $out['basis']);
    }

    #[Test]
    public function missing_size_band_stats_returns_insufficient(): void
    {
        $out = DecompositionShadowRecommender::recommend([
            'size_estimate' => 80,
            'size_band_stats' => [], // no entry for 'lg' band
        ]);
        $this->assertSame('insufficient', $out['recommendation']);
    }

    #[Test]
    public function missing_size_estimate_returns_insufficient_without_a_band(): void
    {
        $out = DecompositionShadowRecommender::recommend([]);
        $this->assertSame('insufficient', $out['recommendation']);
        $this->assertNull($out['size_band']);
        $this->assertSame('size_estimate_absent', $out['basis']);
    }

    #[Test]
    public function above_the_floor_direct_execution_is_recommended(): void
    {
        $out = DecompositionShadowRecommender::recommend([
            'size_estimate' => 5, // 'sm' band
            'size_band_stats' => [
                'sm' => ['n' => 30, 'proven_rate' => 0.85],
            ],
        ]);
        $this->assertSame('direct', $out['recommendation']);
    }

    #[Test]
    public function garbage_proven_rate_is_ignored_returns_insufficient(): void
    {
        $out = DecompositionShadowRecommender::recommend([
            'size_estimate' => 5,
            'size_band_stats' => [
                'sm' => ['n' => 30, 'proven_rate' => 1.5], // out of [0,1]
            ],
        ]);
        $this->assertSame('insufficient', $out['recommendation']);
    }

    #[Test]
    public function size_bands_are_deterministic_across_calls(): void
    {
        $inputs = [1, 5, 20, 80, 500];
        $bands = [];
        foreach ($inputs as $size) {
            $out = DecompositionShadowRecommender::recommend(['size_estimate' => $size]);
            $bands[$size] = $out['size_band'];
        }
        $this->assertSame(['xs', 'sm', 'md', 'lg', 'xl'], array_values($bands));
    }
}
