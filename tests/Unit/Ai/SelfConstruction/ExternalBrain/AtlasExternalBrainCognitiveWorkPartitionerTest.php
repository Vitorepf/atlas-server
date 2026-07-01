<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCognitiveWorkPartitioner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCognitiveWorkPartitionerTest extends TestCase
{
    private AtlasExternalBrainCognitiveWorkPartitioner $partitioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partitioner = new AtlasExternalBrainCognitiveWorkPartitioner();
    }

    // AC 2: low-risk phases with sufficient evidence get small tier, not frontier
    public function test_low_risk_extraction_with_evidence_gets_small_tier(): void
    {
        $result = $this->partitioner->partition([
            ['phase_id' => 'p1', 'phase_type' => 'extraction', 'evidence_sufficient' => true],
        ]);

        $a = $result['assignments'][0];
        $this->assertSame('small', $a['tier']);
        $this->assertLessThan(8000, $a['budget_tokens']);
    }

    public function test_verification_with_evidence_gets_small_tier(): void
    {
        $result = $this->partitioner->partition([
            ['phase_id' => 'p1', 'phase_type' => 'verification', 'evidence_sufficient' => true],
        ]);

        $this->assertSame('small', $result['assignments'][0]['tier']);
    }

    // AC 3: frontier escalation includes explicit reason codes
    public function test_frontier_with_ambiguity_has_reason_codes(): void
    {
        $result = $this->partitioner->partition([
            ['phase_id' => 'p1', 'phase_type' => 'frontier', 'ambiguity_level' => 0.8, 'conflicting_evidence' => true, 'blast_radius' => 'high'],
        ]);

        $a = $result['assignments'][0];
        $this->assertSame('frontier', $a['tier']);
        $this->assertNotEmpty($a['reason_codes']);
        $this->assertTrue(count(array_filter($a['reason_codes'], fn ($r) => str_contains($r, 'ambiguity'))) > 0);
    }

    public function test_frontier_without_reasons_downgraded_to_mid(): void
    {
        $result = $this->partitioner->partition([
            ['phase_id' => 'p1', 'phase_type' => 'frontier', 'ambiguity_level' => 0.1, 'conflicting_evidence' => false, 'blast_radius' => 'low'],
        ]);

        $this->assertSame('mid', $result['assignments'][0]['tier']);
    }

    // AC 4: fallback_plan includes downgrade_tier and acceptable_quality_loss
    public function test_frontier_has_fallback_plan(): void
    {
        $result = $this->partitioner->partition([
            ['phase_id' => 'p1', 'phase_type' => 'frontier', 'ambiguity_level' => 0.7],
        ]);

        $a = $result['assignments'][0];
        $this->assertSame('frontier', $a['tier']);
        $this->assertArrayHasKey('fallback_plan', $a);
        $this->assertSame('mid', $a['fallback_plan']['downgrade_tier']);
        $this->assertGreaterThan(0, $a['fallback_plan']['acceptable_quality_loss']);
    }

    public function test_mid_tier_has_fallback_to_small(): void
    {
        $result = $this->partitioner->partition([
            ['phase_id' => 'p1', 'phase_type' => 'extraction', 'evidence_sufficient' => false],
        ]);

        $a = $result['assignments'][0];
        $this->assertSame('mid', $a['tier']);
        $this->assertSame('small', $a['fallback_plan']['downgrade_tier']);
    }
}
