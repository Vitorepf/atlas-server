<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConsolidationFirstCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainConsolidationFirstCircuitBreakerSprawlTest extends TestCase
{
    private function breaker(): AtlasExternalBrainConsolidationFirstCircuitBreaker
    {
        return new AtlasExternalBrainConsolidationFirstCircuitBreaker();
    }

    public function test_new_organ_with_duplicate_responsibility_is_blocked_with_sprawl_reason(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.9,
            'proposed_task' => ['kind' => 'new_organ'],
        ]);

        $this->assertTrue($result['blocked']);
        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::BLOCKED_REASON_CONSOLIDATION_FIRST_SPRAWL, $result['reason']);
        $this->assertTrue($result['duplicate_responsibility_high']);
    }

    public function test_organ_sprawl_score_also_blocks_new_organ_proposal(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'organ_sprawl_score' => 0.9,
            'proposed_task' => ['kind' => 'new_organ'],
        ]);

        $this->assertTrue($result['blocked']);
        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::BLOCKED_REASON_CONSOLIDATION_FIRST_SPRAWL, $result['reason']);
    }

    // Since c275231c6 an exempt kind alone is a claim, not proof: exemption also requires
    // parity_proof_present or removes_blocker. These tests keep the original allowed-path
    // coverage while carrying the proof the hardened floor demands.
    public function test_consolidation_proposal_in_same_sprawl_area_is_allowed(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.9,
            'organ_sprawl_score' => 0.9,
            'parity_proof_present' => true,
            'proposed_task' => ['kind' => 'consolidation'],
        ]);

        $this->assertFalse($result['blocked']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['proposal_kind_exempt']);
        $this->assertTrue($result['proposal_kind_exemption_proven']);
    }

    public function test_deletion_proposal_is_allowed(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.9,
            'parity_proof_present' => true,
            'proposed_task' => ['kind' => 'deletion'],
        ]);

        $this->assertFalse($result['blocked']);
    }

    public function test_integration_proposal_is_allowed(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.9,
            'proposed_task' => ['kind' => 'integration', 'removes_blocker' => true],
        ]);

        $this->assertFalse($result['blocked']);
    }

    public function test_exempt_kind_without_proof_is_still_blocked(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.9,
            'proposed_task' => ['kind' => 'consolidation'],
        ]);

        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['proposal_kind_exempt']);
        $this->assertFalse($result['proposal_kind_exemption_proven']);
    }

    public function test_keeps_existing_safety_metadata_when_allowed(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.9,
            'proposed_task' => ['kind' => 'consolidation'],
        ]);

        $this->assertArrayHasKey('schema_version', $result);
        $this->assertArrayHasKey('recommendation', $result);
        $this->assertArrayHasKey('circuit_open', $result);
        $this->assertArrayHasKey('enqueue_permitted', $result);
    }

    public function test_healthy_state_with_no_signals_is_never_blocked(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'proposed_task' => ['kind' => 'new_organ'],
        ]);

        $this->assertFalse($result['blocked']);
        $this->assertNull($result['reason']);
        $this->assertFalse($result['duplicate_responsibility_high']);
    }

    public function test_unlocks_consolidation_flag_still_exempts_ordinary_kind(): void
    {
        $result = $this->breaker()->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.9,
            'proposed_task' => ['kind' => 'new_organ', 'unlocks_consolidation' => true],
        ]);

        $this->assertFalse($result['blocked']);
    }
}
