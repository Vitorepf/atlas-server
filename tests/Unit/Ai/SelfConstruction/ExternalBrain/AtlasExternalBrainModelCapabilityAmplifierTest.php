<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelCapabilityAmplifier;
use Tests\TestCase;

final class AtlasExternalBrainModelCapabilityAmplifierTest extends TestCase
{
    private function svc(): AtlasExternalBrainModelCapabilityAmplifier
    {
        return new AtlasExternalBrainModelCapabilityAmplifier;
    }

    private function amplify(string $modelSize): array
    {
        return $this->svc()->amplify(['model_size' => $modelSize]);
    }

    // ── model_profile ─────────────────────────────────────────────────────────

    public function test_model_profile_reflects_input_size(): void
    {
        foreach (['frontier', 'mid', 'small'] as $size) {
            $this->assertSame($size, $this->amplify($size)['model_profile']);
        }
    }

    public function test_unknown_model_size_defaults_to_mid_profile(): void
    {
        $r = $this->svc()->amplify(['model_size' => 'unknown_xyz']);

        $mid = $this->amplify('mid');
        $this->assertSame($mid['scaffold_steps'], $r['scaffold_steps']);
        $this->assertSame($mid['mandatory_evidence'], $r['mandatory_evidence']);
    }

    // ── strictness ordering ───────────────────────────────────────────────────

    public function test_small_has_more_scaffold_steps_than_frontier(): void
    {
        $small = $this->amplify('small');
        $frontier = $this->amplify('frontier');

        $this->assertGreaterThan(count($frontier['scaffold_steps']), count($small['scaffold_steps']));
    }

    public function test_small_has_more_mandatory_evidence_than_frontier(): void
    {
        $small = $this->amplify('small');
        $frontier = $this->amplify('frontier');

        $this->assertGreaterThan(count($frontier['mandatory_evidence']), count($small['mandatory_evidence']));
    }

    public function test_small_has_more_critique_passes_than_frontier(): void
    {
        $small = $this->amplify('small');
        $frontier = $this->amplify('frontier');

        $this->assertGreaterThan(count($frontier['critique_passes']), count($small['critique_passes']));
    }

    public function test_mid_strictness_between_frontier_and_small(): void
    {
        $frontier = count($this->amplify('frontier')['scaffold_steps']);
        $mid = count($this->amplify('mid')['scaffold_steps']);
        $small = count($this->amplify('small')['scaffold_steps']);

        $this->assertGreaterThan($frontier, $mid);
        $this->assertGreaterThanOrEqual($mid, $small);
    }

    // ── frontier hooks ────────────────────────────────────────────────────────

    public function test_small_model_has_no_frontier_hooks(): void
    {
        $r = $this->amplify('small');

        $this->assertSame([], $r['frontier_multiplier_hooks']);
    }

    public function test_frontier_model_has_frontier_expansion_hooks(): void
    {
        $r = $this->amplify('frontier');

        $this->assertNotEmpty($r['frontier_multiplier_hooks']);
    }

    public function test_mid_model_has_optional_hook_but_not_full_frontier_set(): void
    {
        $mid = $this->amplify('mid');
        $frontier = $this->amplify('frontier');

        $this->assertNotEmpty($mid['frontier_multiplier_hooks']);
        $this->assertLessThan(count($frontier['frontier_multiplier_hooks']), count($mid['frontier_multiplier_hooks']));
    }

    // ── required output keys ──────────────────────────────────────────────────

    public function test_all_required_output_keys_present(): void
    {
        $r = $this->amplify('mid');

        foreach (['model_profile', 'scaffold_steps', 'mandatory_evidence', 'critique_passes', 'frontier_multiplier_hooks'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }

    // ── critique passes content ───────────────────────────────────────────────

    public function test_small_critique_passes_include_all_five_lenses(): void
    {
        $r = $this->amplify('small');

        foreach (['proxy_risk', 'operator_dependency', 'duplicate_target', 'low_leverage', 'false_green_acceptance'] as $lens) {
            $this->assertContains($lens, $r['critique_passes'], "Missing lens: {$lens}");
        }
    }

    public function test_frontier_critique_passes_include_proxy_risk(): void
    {
        $r = $this->amplify('frontier');

        $this->assertContains('proxy_risk', $r['critique_passes']);
    }

    // ── AC1: proxy_guard_strength ─────────────────────────────────────────────

    public function test_proxy_guard_strength_high_for_small(): void
    {
        $this->assertSame('high', $this->amplify('small')['proxy_guard_strength']);
    }

    public function test_proxy_guard_strength_medium_for_mid(): void
    {
        $this->assertSame('medium', $this->amplify('mid')['proxy_guard_strength']);
    }

    public function test_proxy_guard_strength_low_for_frontier(): void
    {
        $this->assertSame('low', $this->amplify('frontier')['proxy_guard_strength']);
    }

    // ── AC1: evidence_confidence_floor ────────────────────────────────────────

    public function test_small_evidence_confidence_floor_higher_than_frontier(): void
    {
        $this->assertGreaterThan(
            $this->amplify('frontier')['evidence_confidence_floor'],
            $this->amplify('small')['evidence_confidence_floor']
        );
    }

    public function test_mid_evidence_confidence_floor_between_frontier_and_small(): void
    {
        $frontier = $this->amplify('frontier')['evidence_confidence_floor'];
        $mid      = $this->amplify('mid')['evidence_confidence_floor'];
        $small    = $this->amplify('small')['evidence_confidence_floor'];

        $this->assertGreaterThan($frontier, $mid);
        $this->assertLessThan($small, $mid);
    }

    // ── AC1: critique_passes_minimum ──────────────────────────────────────────

    public function test_small_critique_passes_minimum_greater_than_frontier(): void
    {
        $this->assertGreaterThan(
            $this->amplify('frontier')['critique_passes_minimum'],
            $this->amplify('small')['critique_passes_minimum']
        );
    }

    public function test_critique_passes_minimum_matches_critique_passes_count_for_small(): void
    {
        $r = $this->amplify('small');
        $this->assertSame(count($r['critique_passes']), $r['critique_passes_minimum']);
    }

    // ── AC2: numeric strictness ordering ─────────────────────────────────────

    public function test_evidence_confidence_floor_strictly_ordered_small_gt_mid_gt_frontier(): void
    {
        $frontier = $this->amplify('frontier')['evidence_confidence_floor'];
        $mid      = $this->amplify('mid')['evidence_confidence_floor'];
        $small    = $this->amplify('small')['evidence_confidence_floor'];

        $this->assertGreaterThan($frontier, $mid);
        $this->assertGreaterThan($mid, $small - 0.001); // small > mid
    }

    public function test_critique_passes_minimum_strictly_ordered(): void
    {
        $frontier = $this->amplify('frontier')['critique_passes_minimum'];
        $mid      = $this->amplify('mid')['critique_passes_minimum'];
        $small    = $this->amplify('small')['critique_passes_minimum'];

        $this->assertGreaterThan($frontier, $mid);   // mid > frontier
        $this->assertGreaterThan($mid, $small);      // small > mid
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->amplify([]);

        $this->assertSame(AtlasExternalBrainModelCapabilityAmplifier::SCHEMA, $r['schema_version']);
    }

    // ── heldout_lift_proof ───────────────────────────────────────────────────────

    public function test_output_has_heldout_lift_proof_with_required_fields(): void
    {
        $r = $this->svc()->amplify([]);

        $this->assertArrayHasKey('heldout_lift_proof', $r);
        foreach (['baseline_pass_rate', 'scaffolded_pass_rate', 'lift_delta', 'cost_delta', 'confidence', 'proof_status'] as $key) {
            $this->assertArrayHasKey($key, $r['heldout_lift_proof'], "heldout_lift_proof missing key: {$key}");
        }
    }

    public function test_heldout_lift_proof_computes_positive_lift_delta(): void
    {
        $r = $this->svc()->amplify([
            'baseline_pass_rate'   => 0.4,
            'scaffolded_pass_rate' => 0.8,
            'heldout_sample_size'  => 20,
        ]);

        $this->assertEqualsWithDelta(0.4, $r['heldout_lift_proof']['lift_delta'], 0.0001);
        $this->assertSame('proven_positive_lift', $r['heldout_lift_proof']['proof_status']);
    }

    public function test_heldout_lift_proof_negative_lift(): void
    {
        $r = $this->svc()->amplify([
            'baseline_pass_rate'   => 0.8,
            'scaffolded_pass_rate' => 0.4,
            'heldout_sample_size'  => 20,
        ]);

        $this->assertLessThan(0.0, $r['heldout_lift_proof']['lift_delta']);
        $this->assertSame('negative_lift', $r['heldout_lift_proof']['proof_status']);
    }

    public function test_heldout_lift_proof_insufficient_sample(): void
    {
        $r = $this->svc()->amplify([
            'baseline_pass_rate'   => 0.4,
            'scaffolded_pass_rate' => 0.9,
            'heldout_sample_size'  => 1,
        ]);

        $this->assertSame('insufficient_sample', $r['heldout_lift_proof']['proof_status']);
    }

    public function test_heldout_lift_proof_cost_delta_reflects_cost_difference(): void
    {
        $r = $this->svc()->amplify([
            'baseline_cost'   => 100.0,
            'scaffolded_cost' => 150.0,
        ]);

        $this->assertEqualsWithDelta(50.0, $r['heldout_lift_proof']['cost_delta'], 0.0001);
    }

    // ── AC3: autonomous_execution_allowed gated on lift + proxy + confidence ───

    public function test_small_model_autonomous_execution_allowed_when_all_three_conditions_met(): void
    {
        $r = $this->svc()->amplify([
            'model_size'            => 'small',
            'baseline_pass_rate'    => 0.4,
            'scaffolded_pass_rate'  => 0.9,
            'heldout_sample_size'   => 20,
            'proxy_leak_rate'       => 0.0,
            'confidence'            => 0.9,
        ]);

        $this->assertTrue($r['autonomous_execution_allowed']);
    }

    public function test_small_model_autonomous_execution_not_allowed_when_lift_not_positive(): void
    {
        $r = $this->svc()->amplify([
            'model_size'            => 'small',
            'baseline_pass_rate'    => 0.9,
            'scaffolded_pass_rate'  => 0.9,
            'heldout_sample_size'   => 20,
            'proxy_leak_rate'       => 0.0,
            'confidence'            => 0.9,
        ]);

        $this->assertFalse($r['autonomous_execution_allowed']);
    }

    public function test_small_model_autonomous_execution_not_allowed_when_proxy_leakage_above_ceiling(): void
    {
        $r = $this->svc()->amplify([
            'model_size'            => 'small',
            'baseline_pass_rate'    => 0.4,
            'scaffolded_pass_rate'  => 0.9,
            'heldout_sample_size'   => 20,
            'proxy_leak_rate'       => 0.5,
            'confidence'            => 0.9,
        ]);

        $this->assertFalse($r['autonomous_execution_allowed']);
    }

    public function test_small_model_autonomous_execution_not_allowed_when_confidence_below_profile_floor(): void
    {
        // small profile floor = 0.80
        $r = $this->svc()->amplify([
            'model_size'            => 'small',
            'baseline_pass_rate'    => 0.4,
            'scaffolded_pass_rate'  => 0.9,
            'heldout_sample_size'   => 20,
            'proxy_leak_rate'       => 0.0,
            'confidence'            => 0.5,
        ]);

        $this->assertFalse($r['autonomous_execution_allowed']);
    }

    public function test_autonomous_execution_not_allowed_when_proxy_leak_detected_boolean(): void
    {
        $r = $this->svc()->amplify([
            'model_size'            => 'small',
            'baseline_pass_rate'    => 0.4,
            'scaffolded_pass_rate'  => 0.9,
            'heldout_sample_size'   => 20,
            'proxy_leak_detected'   => true,
            'confidence'            => 0.9,
        ]);

        $this->assertFalse($r['autonomous_execution_allowed']);
    }

    // ── AC4: frontier is accelerator/escalation only; steady-state is provider-agnostic ──

    public function test_steady_state_output_is_provider_agnostic_for_every_profile(): void
    {
        foreach (['frontier', 'mid', 'small'] as $size) {
            $r = $this->amplify($size);
            $this->assertSame('none_provider_agnostic', $r['steady_state_provider_requirement']);
        }
    }

    public function test_frontier_only_reachable_via_escalation_recommendation_for_small_profile(): void
    {
        $r = $this->svc()->amplify([
            'model_size'          => 'small',
            'proxy_leak_detected' => true,
        ]);

        $this->assertTrue($r['escalation_recommendation']['escalate']);
        $this->assertStringContainsString('frontier_or_human_review', $r['escalation_recommendation']['reason']);
        // Escalation offers human review as an alternative — never strictly requires frontier.
        $this->assertStringContainsString('human_review', $r['escalation_recommendation']['reason']);
    }

    public function test_heldout_lift_proof_is_deterministic(): void
    {
        $input = [
            'model_size'            => 'mid',
            'baseline_pass_rate'    => 0.4,
            'scaffolded_pass_rate'  => 0.7,
            'heldout_sample_size'   => 12,
        ];

        $a = $this->svc()->amplify($input);
        $b = $this->svc()->amplify($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
