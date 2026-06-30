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
        $this->assertArrayHasKey('promotion_readiness', $r);
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

    // ── AC2/AC3: evidence_type gate — accepted/confidence/missing_evidence ─────

    public function test_accepted_true_when_verified_and_evidence_type_is_before_after_benchmark(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(5, 0.5, 0.8),
            'required_dimensions' => ['accuracy'],
            'evidence_type'       => 'before_after_benchmark',
        ]);

        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['missing_evidence']);
        $this->assertContains($r['confidence'], ['medium', 'high']);
    }

    public function test_accepted_true_for_heldout_case_delta_and_repeated_outcome_improvement(): void
    {
        foreach (['heldout_case_delta', 'repeated_outcome_improvement'] as $type) {
            $r = $this->verifier()->verify([
                'pairs'               => $this->goodPairs(5, 0.5, 0.8),
                'required_dimensions' => ['accuracy'],
                'evidence_type'       => $type,
            ]);
            $this->assertTrue($r['accepted'], "expected accepted=true for evidence_type={$type}");
        }
    }

    public function test_self_declared_evidence_type_is_rejected_even_when_benchmark_verified(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(5, 0.5, 0.8),
            'required_dimensions' => ['accuracy'],
            'evidence_type'       => 'self_declared',
        ]);

        $this->assertTrue($r['verified']);
        $this->assertFalse($r['accepted']);
        $this->assertSame('none', $r['confidence']);
        $this->assertNotEmpty($r['missing_evidence']);
    }

    public function test_anecdotal_and_single_unverified_result_are_rejected(): void
    {
        foreach (['anecdotal', 'single_unverified_result'] as $type) {
            $r = $this->verifier()->verify([
                'pairs'               => $this->goodPairs(5, 0.5, 0.8),
                'required_dimensions' => ['accuracy'],
                'evidence_type'       => $type,
            ]);
            $this->assertFalse($r['accepted'], "expected accepted=false for evidence_type={$type}");
        }
    }

    public function test_unrecognized_evidence_type_is_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(5, 0.5, 0.8),
            'required_dimensions' => ['accuracy'],
            'evidence_type'       => 'a_prompt_said_it_felt_smarter',
        ]);

        $this->assertFalse($r['accepted']);
    }

    public function test_missing_evidence_lists_rejected_claim_details_when_not_verified(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(2),
            'required_dimensions' => ['accuracy'],
            'min_sample_size'     => 5,
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertNotEmpty($r['missing_evidence']);
    }

    public function test_evidence_type_defaults_to_before_after_benchmark_when_omitted(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(5, 0.5, 0.8),
            'required_dimensions' => ['accuracy'],
        ]);

        $this->assertSame('before_after_benchmark', $r['evidence_type']);
        $this->assertTrue($r['accepted']);
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

    // ── promotion_readiness ────────────────────────────────────────────────────

    public function test_promotion_readiness_true_when_verified(): void
    {
        $r = $this->verifier()->verify([
            'pairs'               => $this->goodPairs(5, 0.5, 0.8),
            'required_dimensions' => ['accuracy'],
            'min_sample_size'     => 5,
            'min_lift_threshold'  => 0.05,
        ]);
        $this->assertTrue($r['promotion_readiness']);
        $this->assertTrue($r['verified']);
    }

    public function test_promotion_readiness_false_when_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'           => $this->goodPairs(2),
            'min_sample_size' => 5,
        ]);
        $this->assertFalse($r['promotion_readiness']);
    }

    // ── worse_give_back_delta ─────────────────────────────────────────────────

    public function test_worse_give_back_delta_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'            => $this->goodPairs(5, 0.5, 0.8),
            'min_sample_size'  => 5,
            'give_back_delta'  => 0.10,  // positive → worse
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertContains('worse_give_back_delta', $reasons);
        $this->assertFalse($r['verified']);
    }

    public function test_neutral_give_back_delta_not_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'           => $this->goodPairs(5, 0.5, 0.8),
            'min_sample_size' => 5,
            'give_back_delta' => -0.05,  // negative → improved
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertNotContains('worse_give_back_delta', $reasons);
    }

    // ── worse_poison_delta ────────────────────────────────────────────────────

    public function test_worse_poison_delta_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'           => $this->goodPairs(5, 0.5, 0.8),
            'min_sample_size' => 5,
            'poison_delta'    => 0.05,
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertContains('worse_poison_delta', $reasons);
    }

    public function test_improved_poison_delta_not_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'           => $this->goodPairs(5, 0.5, 0.8),
            'min_sample_size' => 5,
            'poison_delta'    => -0.10,
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertNotContains('worse_poison_delta', $reasons);
    }

    // ── cost_above_ceiling ────────────────────────────────────────────────────

    public function test_cost_above_ceiling_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'                  => $this->goodPairs(5, 0.5, 0.8),
            'min_sample_size'        => 5,
            'cost_ceiling'           => 2.0,
            'token_cost_baseline'    => 100.0,
            'token_cost_scaffolded'  => 250.0,   // ratio = 2.5 > 2.0
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertContains('cost_above_ceiling', $reasons);
    }

    public function test_cost_within_ceiling_not_rejected(): void
    {
        $r = $this->verifier()->verify([
            'pairs'                  => $this->goodPairs(5, 0.5, 0.8),
            'min_sample_size'        => 5,
            'cost_ceiling'           => 2.0,
            'token_cost_baseline'    => 100.0,
            'token_cost_scaffolded'  => 150.0,   // ratio = 1.5 < 2.0
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertNotContains('cost_above_ceiling', $reasons);
    }

    // ── proxy_only_lift ───────────────────────────────────────────────────────

    public function test_proxy_only_lift_rejected_when_only_gate_pass_rate_lifted(): void
    {
        // gate_pass_rate is the only required dim and it shows lift → proxy_only_lift
        $pairs = array_map(
            fn (int $i): array => $this->pair("c$i",
                ['gate_pass_rate' => 0.50],
                ['gate_pass_rate' => 0.80],
            ),
            range(0, 4),
        );
        $r = $this->verifier()->verify([
            'pairs'               => $pairs,
            'required_dimensions' => ['gate_pass_rate'],
            'min_sample_size'     => 5,
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertContains('proxy_only_lift', $reasons);
    }

    public function test_real_dimension_lift_does_not_fire_proxy_only(): void
    {
        // commit_success_prediction is a real dim → should not fire proxy_only_lift
        $pairs = array_map(
            fn (int $i): array => $this->pair("c$i",
                ['commit_success_prediction' => 0.50],
                ['commit_success_prediction' => 0.80],
            ),
            range(0, 4),
        );
        $r = $this->verifier()->verify([
            'pairs'               => $pairs,
            'required_dimensions' => ['commit_success_prediction'],
            'min_sample_size'     => 5,
        ]);
        $reasons = array_column($r['rejected_claims'], 'reason');
        $this->assertNotContains('proxy_only_lift', $reasons);
    }
}
