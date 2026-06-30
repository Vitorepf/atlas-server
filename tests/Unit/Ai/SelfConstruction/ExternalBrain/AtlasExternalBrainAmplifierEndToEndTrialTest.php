<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierEndToEndTrial;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierEndToEndTrialTest extends TestCase
{
    private function trial(): AtlasExternalBrainAmplifierEndToEndTrial
    {
        return new AtlasExternalBrainAmplifierEndToEndTrial;
    }

    private function dims(array $overrides = []): array
    {
        return array_merge([
            'accuracy' => [
                'small_model_score' => 0.60,
                'scaffolded_score'  => 0.80,
                'frontier_score'    => 0.90,
            ],
        ], $overrides);
    }

    // ── AC4 / output shape ────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->trial()->run([]);
        $this->assertSame(AtlasExternalBrainAmplifierEndToEndTrial::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('tier_scores',         $r);
        $this->assertArrayHasKey('scaffold_lift',       $r);
        $this->assertArrayHasKey('frontier_gain',       $r);
        $this->assertArrayHasKey('recommendation',      $r);
        $this->assertArrayHasKey('dimension_breakdown', $r);
        $this->assertArrayHasKey('quality_assessment',  $r);
    }

    // ── AC2: tier_scores / scaffold_lift / frontier_gain ─────────────────────

    public function test_tier_scores_are_per_dimension_averages(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $ts = $r['tier_scores'];
        $this->assertEqualsWithDelta(0.60, $ts['small_model'],            0.001);
        $this->assertEqualsWithDelta(0.80, $ts['scaffolded_small_model'], 0.001);
        $this->assertEqualsWithDelta(0.90, $ts['frontier_model'],         0.001);
    }

    public function test_scaffold_lift_computed_correctly(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $this->assertEqualsWithDelta(0.20, $r['scaffold_lift'], 0.001); // 0.80 - 0.60
    }

    public function test_frontier_gain_computed_correctly(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $this->assertEqualsWithDelta(0.10, $r['frontier_gain'], 0.001); // 0.90 - 0.80
    }

    public function test_multi_dimension_average_computed(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim1' => ['small_model_score' => 0.6, 'scaffolded_score' => 0.8, 'frontier_score' => 0.9],
                'dim2' => ['small_model_score' => 0.4, 'scaffolded_score' => 0.6, 'frontier_score' => 0.7],
            ],
        ]);
        $ts = $r['tier_scores'];
        $this->assertEqualsWithDelta(0.50, $ts['small_model'],            0.001); // (0.6+0.4)/2
        $this->assertEqualsWithDelta(0.70, $ts['scaffolded_small_model'], 0.001); // (0.8+0.6)/2
    }

    // ── AC3: promote_scaffold ─────────────────────────────────────────────────

    public function test_promote_scaffold_when_meets_floor_and_lift_sufficient(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions'   => $this->dims(), // scaffolded=0.80 >= 0.75, lift=0.20 >= 0.10
            'quality_floor'          => 0.75,
            'min_lift_for_promotion' => 0.10,
        ]);
        $this->assertSame('promote_scaffold', $r['recommendation']);
        $this->assertTrue($r['quality_assessment']['scaffold_meets_floor']);
        $this->assertTrue($r['quality_assessment']['lift_sufficient']);
    }

    // ── AC3: use_frontier ─────────────────────────────────────────────────────

    public function test_use_frontier_when_scaffold_below_floor_but_frontier_above(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim' => ['small_model_score' => 0.5, 'scaffolded_score' => 0.70, 'frontier_score' => 0.90],
            ],
            'quality_floor' => 0.75, // scaffolded=0.70 < 0.75, frontier=0.90 >= 0.75
        ]);
        $this->assertSame('use_frontier', $r['recommendation']);
    }

    // ── AC3: use_scaffolded_small_model ───────────────────────────────────────

    public function test_use_scaffolded_when_meets_floor_but_lift_insufficient(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim' => ['small_model_score' => 0.78, 'scaffolded_score' => 0.80, 'frontier_score' => 0.85],
            ],
            'quality_floor'          => 0.75, // scaffold 0.80 >= floor
            'min_lift_for_promotion' => 0.10, // lift = 0.02 < 0.10 → insufficient
        ]);
        $this->assertSame('use_scaffolded_small_model', $r['recommendation']);
    }

    // ── AC3: use_small_model ──────────────────────────────────────────────────

    public function test_use_small_model_when_nothing_meets_floor(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim' => ['small_model_score' => 0.50, 'scaffolded_score' => 0.60, 'frontier_score' => 0.65],
            ],
            'quality_floor' => 0.75, // none meet floor
        ]);
        $this->assertSame('use_small_model', $r['recommendation']);
    }

    // ── dimension_breakdown ───────────────────────────────────────────────────

    public function test_dimension_breakdown_contains_all_tiers(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $bd = $r['dimension_breakdown']['accuracy'];
        $this->assertArrayHasKey('small_model',            $bd);
        $this->assertArrayHasKey('scaffolded_small_model', $bd);
        $this->assertArrayHasKey('frontier_model',         $bd);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'benchmark_dimensions'   => $this->dims(),
            'quality_floor'          => 0.75,
            'min_lift_for_promotion' => 0.10,
        ];
        $a = $this->trial()->run($facts);
        $b = $this->trial()->run($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
