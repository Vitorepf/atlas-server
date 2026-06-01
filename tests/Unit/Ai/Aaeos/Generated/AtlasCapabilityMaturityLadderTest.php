<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCapabilityMaturityLadderService;
use Tests\TestCase;

/**
 * Pins the documented Self-Construction Capability Maturity Ladder contract:
 * the nine named levels L0..L8, the contiguous promotion-proof chain (a missing
 * lower proof caps the level and names the blocking promotion, higher proofs
 * cannot skip the gap), the "never complete" decision, and the Anti-Confusion
 * Rule (readiness claims must carry a maturity level). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md
 */
class AtlasCapabilityMaturityLadderTest extends TestCase
{
    private function service(): AtlasCapabilityMaturityLadderService
    {
        return new AtlasCapabilityMaturityLadderService();
    }

    /**
     * Doc "Levels": the ladder has exactly nine ordered, named levels, and the
     * top is L8 "Strategic Self-Construction".
     */
    public function test_levels_table_is_the_nine_named_doc_levels(): void
    {
        $this->assertSame('Named', AtlasCapabilityMaturityLadderService::LEVELS[0]);
        $this->assertSame('Documented', AtlasCapabilityMaturityLadderService::LEVELS[1]);
        $this->assertSame('Executable Manual', AtlasCapabilityMaturityLadderService::LEVELS[4]);
        $this->assertSame('Agent Executable', AtlasCapabilityMaturityLadderService::LEVELS[5]);
        $this->assertSame('Strategic Self-Construction', AtlasCapabilityMaturityLadderService::LEVELS[8]);
        $this->assertSame(8, AtlasCapabilityMaturityLadderService::MAX_LEVEL);
        $this->assertCount(9, AtlasCapabilityMaturityLadderService::LEVELS);
    }

    /**
     * Doc "Promotion Requirements": a capability with only the L1 proof (doc +
     * owner) reaches exactly L1 (Documented) and the blocking promotion is the
     * L1 -> L2 step that needs AP/spec. Never "complete".
     */
    public function test_doc_and_owner_only_reaches_l1_and_blocks_on_spec(): void
    {
        $r = $this->service()->classify([
            'capability' => 'spec-operating-system',
            'proofs' => ['canonical_doc_and_owner' => true],
        ]);

        $this->assertSame(1, $r['level']);
        $this->assertSame('Documented', $r['level_name']);
        $this->assertFalse($r['complete']);
        $this->assertSame('L2', $r['next_level']);
        $this->assertSame('L1', $r['blocking_promotion']['from']);
        $this->assertSame('L2', $r['blocking_promotion']['to']);
        $this->assertSame('ap_spec_acceptance_risk_nongoals', $r['blocking_promotion']['missing_proof']);
    }

    /**
     * The chain is contiguous: a high proof (agent-executable, the L5 proof)
     * present while a middle proof (the L2 spec) is missing must NOT skip the
     * gap. The capability is capped at L1 and still blocks on L1 -> L2.
     */
    public function test_contiguous_chain_does_not_skip_a_missing_lower_proof(): void
    {
        $r = $this->service()->classify([
            'capability' => 'leaky-claim',
            'proofs' => [
                'canonical_doc_and_owner' => true,
                // L2 spec proof intentionally absent.
                'scaffold_with_tests_or_marker' => true,
                'passing_manual_command_or_test' => true,
                'agent_executes_with_receipt_and_gates' => true,
            ],
        ]);

        $this->assertSame(1, $r['level']);
        $this->assertSame('ap_spec_acceptance_risk_nongoals', $r['blocking_promotion']['missing_proof']);
        // The skipped higher proofs are present but do not count toward the level.
        $l5 = collect($r['checklist'])->firstWhere('level', 'L5');
        $this->assertTrue($l5['present']);
        $this->assertFalse($l5['counts']);
    }

    /**
     * Full proof chain reaches the L8 top "Strategic Self-Construction", is the
     * max, has no further promotion to block, and is STILL not "complete"
     * (the doc never grants completeness, only a maturity level).
     */
    public function test_full_proof_chain_reaches_l8_strategic_self_construction(): void
    {
        $r = $this->service()->classify([
            'capability' => 'self-construction-os',
            'proofs' => [
                'canonical_doc_and_owner' => true,
                'ap_spec_acceptance_risk_nongoals' => true,
                'scaffold_with_tests_or_marker' => true,
                'passing_manual_command_or_test' => true,
                'agent_executes_with_receipt_and_gates' => true,
                'repeated_runs_rollback_and_drift' => true,
                'learning_proposals_without_unsafe_mutation' => true,
                'priority_engine_build_graph_and_metrics' => true,
            ],
        ]);

        $this->assertSame(8, $r['level']);
        $this->assertSame('Strategic Self-Construction', $r['level_name']);
        $this->assertTrue($r['is_max']);
        $this->assertNull($r['blocking_promotion']);
        $this->assertNull($r['next_level']);
        $this->assertFalse($r['complete']);
    }

    /**
     * Evidence gate (doc forbidden_changes): a proof that is present but falsey
     * does not count. A capability with doc+owner true but the spec proof set
     * to false stays at L1, exactly as if it were absent.
     */
    public function test_falsey_proof_never_counts(): void
    {
        $r = $this->service()->classify([
            'capability' => 'silent-inflation-attempt',
            'proofs' => [
                'canonical_doc_and_owner' => true,
                'ap_spec_acceptance_risk_nongoals' => false,
            ],
        ]);

        $this->assertSame(1, $r['level']);
        $this->assertSame('ap_spec_acceptance_risk_nongoals', $r['blocking_promotion']['missing_proof']);
    }

    /**
     * Anti-Confusion Rule (doc Good/Bad examples): the Good claim carries a
     * level (L1/L2 ... not yet L5) and is valid; the Bad claim ("... is
     * complete.") asserts readiness without a level and is a violation.
     */
    public function test_anti_confusion_rule_matches_doc_good_and_bad_examples(): void
    {
        $good = $this->service()->checkReadinessClaim(
            'Spec Operating System is L1/L2 documented and specified; runtime is not yet L5.'
        );
        $this->assertTrue($good['valid']);
        $this->assertTrue($good['has_level']);

        $bad = $this->service()->checkReadinessClaim('Spec Operating System is complete.');
        $this->assertFalse($bad['valid']);
        $this->assertTrue($bad['readiness_claim']);
        $this->assertFalse($bad['has_level']);
        $this->assertContains('readiness_claim_without_maturity_level', $bad['reasons']);
    }
}
