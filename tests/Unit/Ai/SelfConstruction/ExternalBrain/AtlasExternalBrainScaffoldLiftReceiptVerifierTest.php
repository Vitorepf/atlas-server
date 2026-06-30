<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldLiftReceiptVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldLiftReceiptVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainScaffoldLiftReceiptVerifier
    {
        return new AtlasExternalBrainScaffoldLiftReceiptVerifier;
    }

    private function pair(string $id, array $baseline, array $scaffolded): array
    {
        return ['challenge_id' => $id, 'baseline_scores' => $baseline, 'scaffolded_scores' => $scaffolded];
    }

    private function goodPairs(int $n, float $baseVal = 0.5, float $scafVal = 0.7): array
    {
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            $pairs[] = $this->pair("c$i", ['accuracy' => $baseVal], ['accuracy' => $scafVal]);
        }
        return $pairs;
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->verifier()->verify([]);
        $this->assertSame(AtlasExternalBrainScaffoldLiftReceiptVerifier::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('verified', $r);
        $this->assertArrayHasKey('lift_by_dimension', $r);
        $this->assertArrayHasKey('rejected_claims', $r);
        $this->assertArrayHasKey('sample_size', $r);
        $this->assertArrayHasKey('next_measurement_recommendation', $r);
    }

    // ── AC2: paired validation ────────────────────────────────────────────────

    public function test_pair_missing_baseline_excluded(): void
    {
        $bad = ['challenge_id' => 'x', 'scaffolded_scores' => ['accuracy' => 0.8]];
        $r = $this->verifier()->verify(['pairs' => [$bad]]);
        $this->assertSame(0, $r['sample_size']);
    }

    public function test_pair_missing_scaffolded_excluded(): void
    {
        $bad = ['challenge_id' => 'x', 'baseline_scores' => ['accuracy' => 0.5]];
        $r = $this->verifier()->verify(['pairs' => [$bad]]);
        $this->assertSame(0, $r['sample_size']);
    }

    public function test_valid_pairs_counted_correctly(): void
    {
        $r = $this->verifier()->verify(['pairs' => $this->goodPairs(5), 'min_sample_size' => 5]);
        $this->assertSame(5, $r['sample_size']);
    }

    // ── AC3: rejection conditions ─────────────────────────────────────────────

    public function test_insufficient_sample_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'           => $this->goodPairs(3),
            'min_sample_size' => 5,
        ]);
        $this->assertFalse($r['verified']);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertContains('insufficient_sample', $reasons);
    }

    public function test_missing_required_dimension_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(5),
            'required_dimensions' => ['accuracy', 'fluency'],
            'min_sample_size'     => 5,
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertContains('missing_required_dimension', $reasons);
        $this->assertFalse($r['verified']);
    }

    public function test_lift_below_threshold_rejected(): void
    {
        // Lift = 0.01, threshold default 0.05.
        $pairs = array_map(
            fn ($i) => $this->pair("c$i", ['accuracy' => 0.60], ['accuracy' => 0.61]),
            range(0, 4),
        );
        $r = $this->verifier()->verify([
            'pairs'               => $pairs,
            'required_dimensions' => ['accuracy'],
            'min_sample_size'     => 5,
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertContains('lift_below_threshold', $reasons);
        $this->assertFalse($r['verified']);
    }

    // ── Lift calculation ──────────────────────────────────────────────────────

    public function test_lift_by_dimension_computed_correctly(): void
    {
        // baseline=0.5, scaffolded=0.8 → lift=0.3 exactly.
        $r = $this->verifier()->verify([
            'pairs'           => $this->goodPairs(5, 0.5, 0.8),
            'min_sample_size' => 5,
        ]);
        $this->assertArrayHasKey('accuracy', $r['lift_by_dimension']);
        $dim = $r['lift_by_dimension']['accuracy'];
        $this->assertEqualsWithDelta(0.3, $dim['average_lift'], 0.0001);
        $this->assertTrue($dim['is_lifted']);
        $this->assertSame(5, $dim['sample_count']);
    }

    public function test_negative_lift_reported_as_not_lifted(): void
    {
        $pairs = array_map(
            fn ($i) => $this->pair("c$i", ['accuracy' => 0.8], ['accuracy' => 0.5]),
            range(0, 4),
        );
        $r = $this->verifier()->verify(['pairs' => $pairs, 'min_sample_size' => 5]);
        $this->assertFalse($r['lift_by_dimension']['accuracy']['is_lifted']);
    }

    public function test_multiple_dimensions_computed_independently(): void
    {
        $pairs = array_map(fn ($i) => $this->pair("c$i",
            ['accuracy' => 0.5, 'fluency' => 0.6],
            ['accuracy' => 0.8, 'fluency' => 0.65],
        ), range(0, 4));

        $r = $this->verifier()->verify(['pairs' => $pairs, 'min_sample_size' => 5]);
        $this->assertArrayHasKey('accuracy', $r['lift_by_dimension']);
        $this->assertArrayHasKey('fluency',  $r['lift_by_dimension']);
        $this->assertEqualsWithDelta(0.3,  $r['lift_by_dimension']['accuracy']['average_lift'], 0.0001);
        $this->assertEqualsWithDelta(0.05, $r['lift_by_dimension']['fluency']['average_lift'],  0.0001);
    }

    // ── verified=true path ────────────────────────────────────────────────────

    public function test_verified_true_when_all_conditions_met(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(5, 0.5, 0.8),
            'required_dimensions' => ['accuracy'],
            'min_sample_size'     => 5,
            'min_lift_threshold'  => 0.05,
        ]);
        $this->assertTrue($r['verified']);
        $this->assertEmpty($r['rejected_claims']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'pairs'               => $this->goodPairs(6, 0.5, 0.75),
            'required_dimensions' => ['accuracy'],
            'min_sample_size'     => 5,
        ];
        $a = $this->verifier()->verify($facts);
        $b = $this->verifier()->verify($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
