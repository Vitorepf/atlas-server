<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiSkillSystemService;
use Tests\TestCase;

/**
 * Pins the executable governance rules from the Atlas AI Skill System doc:
 *  - Lifecycle gate: a skill CANNOT reach `default` without eval/evidence
 *    ("Skill sem eval/evidence nao vira default"), and cannot jump states.
 *  - Fronteira: only a versioned + output-contract + eval concept is a `skill`;
 *    a temporary role is an `agent` with no authority of its own.
 *  - Invariantes: conflict resolves Output Governor -> Kernel policy -> flow owner.
 *  - External skill is a software dependency: needs review + pinning + rollback.
 * Pure, no DB, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-skill-system.md
 */
class AtlasAiSkillSystemTest extends TestCase
{
    private function service(): AtlasAiSkillSystemService
    {
        return new AtlasAiSkillSystemService;
    }

    public function test_default_is_forbidden_without_eval_evidence(): void
    {
        // ready -> default but evidence/review/safety all missing.
        $decision = $this->service()->decideTransition([
            'current_state' => AtlasAiSkillSystemService::STATE_READY,
            'requested_state' => AtlasAiSkillSystemService::STATE_DEFAULT,
            'evidence_of_gain_vs_baseline' => false,
            'human_review_passed' => false,
            'safety_approved' => false,
        ]);

        $this->assertFalse($decision['allowed']);
        // Stays put — does not become default.
        $this->assertSame(AtlasAiSkillSystemService::STATE_READY, $decision['resulting_state']);
        $this->assertContains(
            'default forbidden without eval/evidence (skill sem evidence nao vira default)',
            $decision['blocking_reasons'],
        );
    }

    public function test_default_allowed_only_with_evidence_review_and_safety(): void
    {
        $decision = $this->service()->decideTransition([
            'current_state' => AtlasAiSkillSystemService::STATE_READY,
            'requested_state' => AtlasAiSkillSystemService::STATE_DEFAULT,
            'evidence_of_gain_vs_baseline' => true,
            'human_review_passed' => true,
            'safety_approved' => true,
        ]);

        $this->assertTrue($decision['allowed']);
        $this->assertTrue($decision['is_promotion']);
        $this->assertSame(AtlasAiSkillSystemService::STATE_DEFAULT, $decision['resulting_state']);
        $this->assertSame([], $decision['blocking_reasons']);
    }

    public function test_lifecycle_forbids_skipping_states(): void
    {
        // draft -> default is two ranks ahead; must be rejected as an illegal jump.
        $decision = $this->service()->decideTransition([
            'current_state' => AtlasAiSkillSystemService::STATE_DRAFT,
            'requested_state' => AtlasAiSkillSystemService::STATE_DEFAULT,
            'evidence_of_gain_vs_baseline' => true,
            'human_review_passed' => true,
            'safety_approved' => true,
        ]);

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString('illegal jump', $decision['blocking_reasons'][0]);
    }

    public function test_ready_requires_evidence_of_gain_vs_baseline(): void
    {
        // candidate -> ready with traces but NO evidence of gain -> blocked.
        $blocked = $this->service()->decideTransition([
            'current_state' => AtlasAiSkillSystemService::STATE_CANDIDATE,
            'requested_state' => AtlasAiSkillSystemService::STATE_READY,
            'has_comparable_traces' => true,
            'evidence_of_gain_vs_baseline' => false,
        ]);
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('ready requires evidence of gain against baseline', $blocked['blocking_reasons']);

        // With both -> allowed.
        $ok = $this->service()->decideTransition([
            'current_state' => AtlasAiSkillSystemService::STATE_CANDIDATE,
            'requested_state' => AtlasAiSkillSystemService::STATE_READY,
            'has_comparable_traces' => true,
            'evidence_of_gain_vs_baseline' => true,
        ]);
        $this->assertTrue($ok['allowed']);
    }

    public function test_deprecate_is_always_allowed_as_regression(): void
    {
        $decision = $this->service()->decideTransition([
            'current_state' => AtlasAiSkillSystemService::STATE_DEFAULT,
            'requested_state' => AtlasAiSkillSystemService::STATE_DEPRECATED,
            'regressed' => true,
        ]);

        $this->assertTrue($decision['allowed']);
        $this->assertTrue($decision['is_regression']);
        $this->assertFalse($decision['is_promotion']);
        $this->assertSame(AtlasAiSkillSystemService::STATE_DEPRECATED, $decision['resulting_state']);
    }

    public function test_fronteira_classifies_skill_vs_agent(): void
    {
        $skill = $this->service()->classifyConcept([
            'is_versioned' => true,
            'has_output_contract' => true,
            'has_eval' => true,
        ]);
        $this->assertSame(AtlasAiSkillSystemService::KIND_SKILL, $skill['kind']);
        $this->assertTrue($skill['is_skill']);
        $this->assertTrue($skill['authority_of_its_own']);

        // A temporary role is an agent — NOT an authority of its own.
        $agent = $this->service()->classifyConcept([
            'is_temporary_role' => true,
        ]);
        $this->assertSame(AtlasAiSkillSystemService::KIND_AGENT, $agent['kind']);
        $this->assertFalse($agent['is_skill']);
        $this->assertFalse($agent['authority_of_its_own']);
    }

    public function test_conflict_resolution_follows_fixed_authority_order(): void
    {
        // Output Governor is silent; kernel policy decides before flow owner.
        $decision = $this->service()->resolveConflict([
            'output_governor' => null,
            'kernel_policy' => 'atlas.skill.core.formatter',
            'flow_owner' => 'atlas.skill.user.formatter',
        ]);

        $this->assertTrue($decision['resolved']);
        $this->assertSame('kernel_policy', $decision['decided_by']);
        $this->assertSame('atlas.skill.core.formatter', $decision['winner']);
        // Flow owner was never consulted because a higher authority decided.
        $this->assertNotContains('flow_owner', $decision['consulted']);

        // Output Governor outranks everything when it speaks.
        $governorWins = $this->service()->resolveConflict([
            'output_governor' => 'atlas.skill.core.governor_choice',
            'kernel_policy' => 'atlas.skill.core.formatter',
        ]);
        $this->assertSame('output_governor', $governorWins['decided_by']);
    }

    public function test_external_skill_requires_review_pinning_and_rollback(): void
    {
        $missing = $this->service()->admitExternalSkill([
            'reviewed' => true,
            'pinned_version' => null,
            'pinned_hash' => null,
            'rollback_plan' => true,
        ]);
        $this->assertFalse($missing['admissible']);
        $this->assertContains('pinning', $missing['missing_controls']);

        $admitted = $this->service()->admitExternalSkill([
            'reviewed' => true,
            'pinned_hash' => 'abc123',
            'rollback_plan' => true,
        ]);
        $this->assertTrue($admitted['admissible']);
        $this->assertSame([], $admitted['missing_controls']);
    }
}
