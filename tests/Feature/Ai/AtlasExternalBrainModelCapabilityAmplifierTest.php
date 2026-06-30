<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelCapabilityAmplifier;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainModelCapabilityAmplifierTest extends TestCase
{
    private function amplifier(): AtlasExternalBrainModelCapabilityAmplifier
    {
        return new AtlasExternalBrainModelCapabilityAmplifier;
    }

    // ── AC1: low-risk small-model work gets scaffold, examples, verification_checklist ─

    public function test_small_model_low_risk_produces_scaffold(): void
    {
        $r = $this->amplifier()->amplify([
            'model_size'         => 'small',
            'confidence'         => 0.90,
            'proxy_leak_detected' => false,
            'architectural_risk' => 'low',
        ]);

        $this->assertNotEmpty($r['scaffold_steps']);
        $this->assertContains('write_spec', $r['scaffold_steps']);
    }

    public function test_small_model_output_includes_examples(): void
    {
        $r = $this->amplifier()->amplify(['model_size' => 'small', 'confidence' => 0.90]);

        $this->assertArrayHasKey('examples', $r);
        $this->assertNotEmpty($r['examples']);
        $this->assertIsArray($r['examples']);
    }

    public function test_small_model_output_includes_verification_checklist(): void
    {
        $r = $this->amplifier()->amplify(['model_size' => 'small', 'confidence' => 0.90]);

        $this->assertArrayHasKey('verification_checklist', $r);
        $this->assertNotEmpty($r['verification_checklist']);
    }

    public function test_small_model_checklist_covers_all_critique_lenses(): void
    {
        $r = $this->amplifier()->amplify(['model_size' => 'small', 'confidence' => 0.90]);

        $checklist = implode(' ', $r['verification_checklist']);
        $this->assertStringContainsString('critique', $checklist);
    }

    public function test_small_model_has_no_frontier_hooks(): void
    {
        $r = $this->amplifier()->amplify(['model_size' => 'small']);

        $this->assertSame([], $r['frontier_multiplier_hooks']);
    }

    public function test_small_model_no_escalation_on_clean_low_risk(): void
    {
        $r = $this->amplifier()->amplify([
            'model_size'          => 'small',
            'confidence'          => 0.90,
            'proxy_leak_detected' => false,
            'architectural_risk'  => 'low',
        ]);

        $this->assertFalse($r['escalation_recommendation']['escalate']);
    }

    // ── AC2: proxy leak / low confidence / high arch risk → escalation ────────

    public function test_proxy_leak_triggers_escalation(): void
    {
        $r = $this->amplifier()->amplify([
            'model_size'          => 'small',
            'confidence'          => 0.90,
            'proxy_leak_detected' => true,
        ]);

        $this->assertTrue($r['escalation_recommendation']['escalate']);
        $this->assertStringContainsString('proxy_leak', $r['escalation_recommendation']['reason']);
    }

    public function test_low_confidence_below_floor_triggers_escalation(): void
    {
        // small model floor = 0.80; confidence=0.50 → escalate
        $r = $this->amplifier()->amplify([
            'model_size'  => 'small',
            'confidence'  => 0.50,
        ]);

        $this->assertTrue($r['escalation_recommendation']['escalate']);
        $reason = $r['escalation_recommendation']['reason'];
        $this->assertStringContainsString('confidence', $reason);
        $this->assertStringContainsString('floor', $reason);
    }

    public function test_high_architectural_risk_triggers_escalation(): void
    {
        $r = $this->amplifier()->amplify([
            'model_size'         => 'mid',
            'confidence'         => 0.95,
            'architectural_risk' => 'high',
        ]);

        $this->assertTrue($r['escalation_recommendation']['escalate']);
        $this->assertStringContainsString('architectural_risk', $r['escalation_recommendation']['reason']);
    }

    public function test_mid_model_confidence_above_floor_no_escalation(): void
    {
        // mid floor = 0.60; confidence=0.75 → no escalate
        $r = $this->amplifier()->amplify([
            'model_size'  => 'mid',
            'confidence'  => 0.75,
        ]);

        $this->assertFalse($r['escalation_recommendation']['escalate']);
    }

    public function test_proxy_leak_takes_priority_over_confidence(): void
    {
        // Even high confidence, proxy leak still escalates
        $r = $this->amplifier()->amplify([
            'model_size'          => 'frontier',
            'confidence'          => 1.0,
            'proxy_leak_detected' => true,
        ]);

        $this->assertTrue($r['escalation_recommendation']['escalate']);
    }

    // ── AC3: output includes expected_lift, guardrails, proof_required ────────

    public function test_output_includes_expected_lift(): void
    {
        foreach (['frontier', 'mid', 'small'] as $size) {
            $r = $this->amplifier()->amplify(['model_size' => $size]);
            $this->assertArrayHasKey('expected_lift', $r, "Missing expected_lift for {$size}");
            $this->assertNotEmpty($r['expected_lift']);
        }
    }

    public function test_output_includes_guardrails(): void
    {
        foreach (['frontier', 'mid', 'small'] as $size) {
            $r = $this->amplifier()->amplify(['model_size' => $size]);
            $this->assertArrayHasKey('guardrails', $r, "Missing guardrails for {$size}");
            $this->assertNotEmpty($r['guardrails']);
        }
    }

    public function test_output_includes_proof_required(): void
    {
        foreach (['frontier', 'mid', 'small'] as $size) {
            $r = $this->amplifier()->amplify(['model_size' => $size]);
            $this->assertArrayHasKey('proof_required', $r, "Missing proof_required for {$size}");
            $this->assertNotEmpty($r['proof_required']);
        }
    }

    public function test_small_guardrails_more_strict_than_frontier(): void
    {
        $frontier = $this->amplifier()->amplify(['model_size' => 'frontier']);
        $small    = $this->amplifier()->amplify(['model_size' => 'small']);

        $this->assertGreaterThan(count($frontier['guardrails']), count($small['guardrails']));
    }

    // ── AC4: provider-agnostic and deterministic ──────────────────────────────

    public function test_amplify_is_deterministic(): void
    {
        $input = [
            'model_size'          => 'small',
            'confidence'          => 0.85,
            'proxy_leak_detected' => false,
            'architectural_risk'  => 'medium',
        ];

        $a = $this->amplifier()->amplify($input);
        $b = $this->amplifier()->amplify($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_output_contains_no_provider_specific_model_names(): void
    {
        $r       = $this->amplifier()->amplify(['model_size' => 'small']);
        $encoded = json_encode($r);

        foreach (['claude', 'gpt', 'gemini', 'anthropic', 'openai'] as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $encoded);
        }
    }
}
