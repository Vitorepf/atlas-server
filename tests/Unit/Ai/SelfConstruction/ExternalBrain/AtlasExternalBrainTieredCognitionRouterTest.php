<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTieredCognitionRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTieredCognitionRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainTieredCognitionRouter
    {
        return new AtlasExternalBrainTieredCognitionRouter;
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->router()->route([]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('assigned_tier', $r);
        $this->assertArrayHasKey('escalation_reason', $r);
        $this->assertArrayHasKey('fallback_to_scaffolded_small_model', $r);
        $this->assertArrayHasKey('frontier_unavailable', $r);
        $this->assertArrayHasKey('routing_explanation', $r);
    }

    // ── AC2: small-model / scaffolded for low-risk tasks ─────────────────────

    public function test_extraction_with_strong_scaffold_routes_to_small_model(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.85,
            'ambiguity_score'         => 0.1,
            'is_conflicting_evidence' => false,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SMALL, $r['assigned_tier']);
        $this->assertNull($r['escalation_reason']);
        $this->assertFalse($r['fallback_to_scaffolded_small_model']);
    }

    public function test_validation_with_strong_scaffold_routes_to_small_model(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'validation',
            'scaffold_evidence_strength' => 0.90,
            'ambiguity_score'         => 0.0,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SMALL, $r['assigned_tier']);
    }

    public function test_extraction_with_moderate_scaffold_routes_to_scaffolded(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.55,
            'ambiguity_score'         => 0.2,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SCAFFOLDED, $r['assigned_tier']);
    }

    public function test_unknown_type_with_moderate_scaffold_routes_to_scaffolded(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'enhancement',
            'scaffold_evidence_strength' => 0.5,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SCAFFOLDED, $r['assigned_tier']);
    }

    // ── AC3: frontier escalation ──────────────────────────────────────────────

    public function test_conflicting_evidence_escalates_to_frontier(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.9,
            'is_conflicting_evidence' => true,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
        $this->assertSame('conflicting_evidence_requires_frontier_resolution', $r['escalation_reason']);
    }

    public function test_architecture_tradeoff_type_escalates_to_frontier(): void
    {
        $r = $this->router()->route([
            'origination_type' => 'architecture_tradeoff',
            'ambiguity_score'  => 0.1,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
        $this->assertStringContainsString('architecture_tradeoff', $r['escalation_reason']);
    }

    public function test_novel_research_type_escalates_to_frontier(): void
    {
        $r = $this->router()->route(['origination_type' => 'novel_research']);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
    }

    public function test_high_ambiguity_score_escalates_to_frontier(): void
    {
        $r = $this->router()->route([
            'origination_type' => 'extraction',
            'scaffold_evidence_strength' => 0.8,
            'ambiguity_score'  => 0.75,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
        $this->assertSame('ambiguity_score_exceeds_threshold', $r['escalation_reason']);
    }

    public function test_conflicting_evidence_takes_priority_over_simple_type(): void
    {
        // Even simple extraction with strong scaffold → frontier if conflicting.
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.9,
            'is_conflicting_evidence' => true,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
    }

    // ── AC4: frontier fallback ────────────────────────────────────────────────

    public function test_fallback_to_scaffolded_when_frontier_unavailable(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'architecture_tradeoff',
            'frontier_available'      => false,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SCAFFOLDED, $r['assigned_tier']);
        $this->assertTrue($r['fallback_to_scaffolded_small_model']);
        $this->assertTrue($r['frontier_unavailable']);
    }

    public function test_no_fallback_when_frontier_available(): void
    {
        $r = $this->router()->route([
            'origination_type'   => 'architecture_tradeoff',
            'frontier_available' => true,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
        $this->assertFalse($r['fallback_to_scaffolded_small_model']);
        $this->assertFalse($r['frontier_unavailable']);
    }

    public function test_no_fallback_when_tier_is_not_frontier(): void
    {
        // Small model doesn't need frontier → no fallback regardless of frontier_available.
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.9,
            'frontier_available'      => false,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SMALL, $r['assigned_tier']);
        $this->assertFalse($r['fallback_to_scaffolded_small_model']);
    }

    // ── Anti-over-escalation (AC4) ────────────────────────────────────────────

    public function test_simple_extraction_with_strong_scaffold_never_reaches_frontier(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 1.0,
            'ambiguity_score'         => 0.0,
            'is_conflicting_evidence' => false,
            'frontier_available'      => true,
        ]);
        $this->assertNotSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
    }

    // ── risk_class ────────────────────────────────────────────────────────────

    public function test_critical_risk_class_escalates_to_frontier(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.9,
            'ambiguity_score'         => 0.0,
            'is_conflicting_evidence' => false,
            'risk_class'              => 'critical',
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
        $this->assertStringContainsString('risk_class_critical', $r['escalation_reason']);
    }

    public function test_low_risk_class_does_not_escalate(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.9,
            'risk_class'              => 'low',
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SMALL, $r['assigned_tier']);
    }

    // ── leverage_score ────────────────────────────────────────────────────────

    public function test_high_leverage_with_low_scaffold_escalates_to_frontier(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.30,
            'ambiguity_score'         => 0.10,
            'is_conflicting_evidence' => false,
            'leverage_score'          => 0.90,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
        $this->assertStringContainsString('high_leverage', $r['escalation_reason']);
    }

    public function test_high_leverage_with_strong_scaffold_does_not_escalate(): void
    {
        // Strong scaffold means we already know what to do — frontier unnecessary.
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.85,
            'ambiguity_score'         => 0.0,
            'is_conflicting_evidence' => false,
            'leverage_score'          => 0.95,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SMALL, $r['assigned_tier']);
    }

    public function test_low_leverage_does_not_escalate(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.30,
            'leverage_score'          => 0.50,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SCAFFOLDED, $r['assigned_tier']);
    }

    // ── expected_quality_delta ────────────────────────────────────────────────

    public function test_high_expected_quality_delta_escalates_to_frontier(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.6,
            'ambiguity_score'         => 0.2,
            'expected_quality_delta'  => 0.50,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
        $this->assertStringContainsString('quality_delta', $r['escalation_reason']);
    }

    public function test_low_expected_quality_delta_does_not_escalate(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.6,
            'expected_quality_delta'  => 0.20,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_SCAFFOLDED, $r['assigned_tier']);
    }

    public function test_quality_delta_at_threshold_escalates(): void
    {
        $r = $this->router()->route([
            'origination_type'        => 'extraction',
            'scaffold_evidence_strength' => 0.5,
            'expected_quality_delta'  => 0.40,
        ]);
        $this->assertSame(AtlasExternalBrainTieredCognitionRouter::TIER_FRONTIER, $r['assigned_tier']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'origination_type'        => 'novel_research',
            'scaffold_evidence_strength' => 0.5,
            'ambiguity_score'         => 0.4,
            'frontier_available'      => true,
        ];
        $a = $this->router()->route($facts);
        $b = $this->router()->route($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
