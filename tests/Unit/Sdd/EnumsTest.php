<?php

namespace Tests\Unit\Sdd;

use App\Services\Ai\Programming\Sdd\Enums\AutonomyLevel;
use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;
use App\Services\Ai\Programming\Sdd\Enums\SpecStatus;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_autonomy_levels_match_canonical_six_values(): void
    {
        $values = array_map(fn (AutonomyLevel $a) => $a->value, AutonomyLevel::cases());
        $this->assertSame([
            'L0_manual', 'L1_assisted', 'L2_auto_patch',
            'L3_auto_pr', 'L4_restricted_merge', 'L5_proposal_only',
        ], $values);
    }

    public function test_l0_and_l5_do_not_allow_implementation(): void
    {
        $this->assertFalse(AutonomyLevel::L0Manual->allowsImplementation());
        $this->assertFalse(AutonomyLevel::L5ProposalOnly->allowsImplementation());
        $this->assertTrue(AutonomyLevel::L2AutoPatch->allowsImplementation());
    }

    public function test_only_l4_allows_auto_merge(): void
    {
        $this->assertTrue(AutonomyLevel::L4RestrictedMerge->allowsAutoMerge());
        foreach ([AutonomyLevel::L0Manual, AutonomyLevel::L1Assisted, AutonomyLevel::L2AutoPatch, AutonomyLevel::L3AutoPr, AutonomyLevel::L5ProposalOnly] as $a) {
            $this->assertFalse($a->allowsAutoMerge());
        }
    }

    public function test_l3_and_l4_allow_pull_request(): void
    {
        $this->assertTrue(AutonomyLevel::L3AutoPr->allowsPullRequest());
        $this->assertTrue(AutonomyLevel::L4RestrictedMerge->allowsPullRequest());
        $this->assertFalse(AutonomyLevel::L2AutoPatch->allowsPullRequest());
    }

    public function test_confidence_class_blocking_only_for_blocking_ambiguity(): void
    {
        $this->assertTrue(ConfidenceClass::BlockingAmbiguity->isBlocking());
        $this->assertFalse(ConfidenceClass::ConfirmedFact->isBlocking());
        $this->assertFalse(ConfidenceClass::Hypothesis->isBlocking());
    }

    public function test_confidence_rank_descending(): void
    {
        $this->assertSame(4, ConfidenceClass::ConfirmedFact->rank());
        $this->assertSame(3, ConfidenceClass::StrongInference->rank());
        $this->assertSame(2, ConfidenceClass::Hypothesis->rank());
        $this->assertSame(1, ConfidenceClass::BlockingAmbiguity->rank());
    }

    public function test_spec_status_only_approved_is_executable(): void
    {
        $this->assertTrue(SpecStatus::Approved->isExecutable());
        $this->assertFalse(SpecStatus::Draft->isExecutable());
        $this->assertFalse(SpecStatus::Implemented->isExecutable());
    }

    public function test_spec_status_can_be_replaced_only_in_terminal_states(): void
    {
        $this->assertTrue(SpecStatus::Implemented->canBeReplaced());
        $this->assertTrue(SpecStatus::Superseded->canBeReplaced());
        $this->assertTrue(SpecStatus::Rejected->canBeReplaced());
        $this->assertFalse(SpecStatus::Draft->canBeReplaced());
        $this->assertFalse(SpecStatus::Approved->canBeReplaced());
    }
}
