<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasCodeAttentionControlPlaneService as Plane;
use App\Services\Ai\Programming\Support\CodeAttentionClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Pure Support peel for attention classification — no I/O, no orchestrator, no DB.
 */
final class CodeAttentionClassifierTest extends TestCase
{
    public function test_classify_completed_without_human_approval_is_final_acceptance(): void
    {
        [$kind, $severity, $question, $whyNow, $recommended, $allowed, $risk, $panel] =
            CodeAttentionClassifier::classify('completed', ['human_approved' => false]);

        $this->assertSame(Plane::KIND_FINAL_ACCEPTANCE, $kind);
        $this->assertSame(Plane::SEVERITY_HIGH, $severity);
        $this->assertSame(Plane::ACTION_APPROVE, $recommended);
        $this->assertContains(Plane::ACTION_APPROVE, $allowed);
        $this->assertContains(Plane::ACTION_ROLLBACK, $allowed);
        $this->assertSame('review', $panel);
        $this->assertNotSame('', $question);
        $this->assertNotSame('', $whyNow);
        $this->assertNotSame('', $risk);
    }

    public function test_classify_final_allowed_with_approved_review_is_final_acceptance(): void
    {
        [$kind, $severity] = CodeAttentionClassifier::classify('running', [
            'final_completion_allowed' => true,
            'review_status' => 'approved',
            'human_approved' => false,
        ]);

        $this->assertSame(Plane::KIND_FINAL_ACCEPTANCE, $kind);
        $this->assertSame(Plane::SEVERITY_HIGH, $severity);
    }

    public function test_classify_waiting_review_is_review_needed(): void
    {
        [$kind, $severity, , , $recommended, $allowed, , $panel] =
            CodeAttentionClassifier::classify('waiting_review', []);

        $this->assertSame(Plane::KIND_REVIEW_NEEDED, $kind);
        $this->assertSame(Plane::SEVERITY_MEDIUM, $severity);
        $this->assertSame(Plane::ACTION_APPROVE, $recommended);
        $this->assertContains(Plane::ACTION_REQUEST_REPAIR, $allowed);
        $this->assertSame('review', $panel);
    }

    public function test_classify_review_required_signal_without_settled_status(): void
    {
        [$kind] = CodeAttentionClassifier::classify('running', [
            'review_required' => true,
            'review_status' => 'pending',
        ]);

        $this->assertSame(Plane::KIND_REVIEW_NEEDED, $kind);
    }

    public function test_classify_repair_required(): void
    {
        [$kind, $severity, , , $recommended] =
            CodeAttentionClassifier::classify('repair_required', []);

        $this->assertSame(Plane::KIND_REPAIR_DECISION, $kind);
        $this->assertSame(Plane::SEVERITY_HIGH, $severity);
        $this->assertSame(Plane::ACTION_REQUEST_REPAIR, $recommended);
    }

    public function test_classify_blocked_scope_includes_file_detail(): void
    {
        [$kind, $severity, , $whyNow, $recommended, $allowed] =
            CodeAttentionClassifier::classify('blocked_scope', [
                'files_out_of_scope' => ['app/Secret.php'],
            ]);

        $this->assertSame(Plane::KIND_SCOPE_DECISION, $kind);
        $this->assertSame(Plane::SEVERITY_MEDIUM, $severity);
        $this->assertSame(Plane::ACTION_DENY_SCOPE_CHANGE, $recommended);
        $this->assertStringContainsString('app/Secret.php', $whyNow);
        $this->assertContains(Plane::ACTION_APPROVE_SCOPE_CHANGE, $allowed);
    }

    public function test_classify_blocked_scope_without_files_uses_generic_detail(): void
    {
        [, , , $whyNow] = CodeAttentionClassifier::classify('blocked_scope', []);

        $this->assertStringContainsString('fora do escopo', $whyNow);
    }

    public function test_classify_provider_and_budget_and_runtime_approvals(): void
    {
        [$kindP, $sevP, , , $recP] =
            CodeAttentionClassifier::classify('waiting_provider_confirmation', []);
        $this->assertSame(Plane::KIND_PROVIDER_APPROVAL, $kindP);
        $this->assertSame(Plane::SEVERITY_HIGH, $sevP);
        $this->assertSame(Plane::ACTION_APPROVE_PROVIDER, $recP);

        [$kindB] = CodeAttentionClassifier::classify('waiting_budget_confirmation', []);
        $this->assertSame(Plane::KIND_PROVIDER_APPROVAL, $kindB);

        [$kindR, $sevR, , , $recR] =
            CodeAttentionClassifier::classify('waiting_runtime_dispatch_confirmation', []);
        $this->assertSame(Plane::KIND_RUNTIME_APPROVAL, $kindR);
        $this->assertSame(Plane::SEVERITY_MEDIUM, $sevR);
        $this->assertSame(Plane::ACTION_APPROVE_RUNTIME, $recR);
    }

    public function test_classify_intake_states(): void
    {
        foreach (['blocked_definition', 'intake_required', 'ready_to_define'] as $state) {
            [$kind, $severity, , , $recommended] =
                CodeAttentionClassifier::classify($state, []);
            $this->assertSame(Plane::KIND_INTAKE_NEEDED, $kind, $state);
            $this->assertSame(Plane::SEVERITY_LOW, $severity, $state);
            $this->assertSame(Plane::ACTION_REFINE_INTAKE, $recommended, $state);
        }
    }

    public function test_classify_blocked_governance_family(): void
    {
        foreach (['blocked_provider', 'blocked_driver', 'blocked_capacity', 'blocked_governance'] as $state) {
            [$kind, $severity] = CodeAttentionClassifier::classify($state, []);
            $this->assertSame(Plane::KIND_BLOCKED_ATTENTION, $kind, $state);
            $this->assertSame(Plane::SEVERITY_HIGH, $severity, $state);
        }
    }

    public function test_classify_idle_or_running_returns_null_kind(): void
    {
        [$kind, $severity, , , $recommended, $allowed, , $panel] =
            CodeAttentionClassifier::classify('idle', []);

        $this->assertNull($kind);
        $this->assertSame(Plane::SEVERITY_LOW, $severity);
        $this->assertSame(Plane::ACTION_OPEN_OBRA, $recommended);
        $this->assertSame([], $allowed);
        $this->assertSame('overview', $panel);
    }

    public function test_severity_rank_orders_high_medium_low(): void
    {
        $this->assertSame(0, CodeAttentionClassifier::severityRank(Plane::SEVERITY_HIGH));
        $this->assertSame(1, CodeAttentionClassifier::severityRank(Plane::SEVERITY_MEDIUM));
        $this->assertSame(2, CodeAttentionClassifier::severityRank(Plane::SEVERITY_LOW));
        $this->assertSame(2, CodeAttentionClassifier::severityRank('unknown'));
    }

    public function test_action_mutates_only_non_open_obra(): void
    {
        $this->assertFalse(CodeAttentionClassifier::actionMutates(Plane::ACTION_OPEN_OBRA));
        $this->assertTrue(CodeAttentionClassifier::actionMutates(Plane::ACTION_APPROVE));
        $this->assertTrue(CodeAttentionClassifier::actionMutates(Plane::ACTION_DISMISS_WITH_REASON));
        $this->assertTrue(CodeAttentionClassifier::actionMutates(Plane::ACTION_PAUSE));
    }

    public function test_phase_for_state_mapping(): void
    {
        $this->assertSame('intake', CodeAttentionClassifier::phaseForState('intake_required'));
        $this->assertSame('intake', CodeAttentionClassifier::phaseForState('blocked_definition'));
        $this->assertSame('build', CodeAttentionClassifier::phaseForState('blocked_scope'));
        $this->assertSame('forge_prep', CodeAttentionClassifier::phaseForState('waiting_provider_confirmation'));
        $this->assertSame('forge_prep', CodeAttentionClassifier::phaseForState('waiting_budget_confirmation'));
        $this->assertSame('forge_prep', CodeAttentionClassifier::phaseForState('waiting_runtime_dispatch_confirmation'));
        $this->assertSame('review', CodeAttentionClassifier::phaseForState('waiting_review'));
        $this->assertSame('build', CodeAttentionClassifier::phaseForState('repair_required'));
        $this->assertSame('decision', CodeAttentionClassifier::phaseForState('completed'));
        $this->assertSame('build', CodeAttentionClassifier::phaseForState('blocked_provider'));
        $this->assertSame('overview', CodeAttentionClassifier::phaseForState('idle'));
        $this->assertSame('overview', CodeAttentionClassifier::phaseForState('running'));
    }

    public function test_item_key_is_stable_and_state_scoped(): void
    {
        $a = CodeAttentionClassifier::itemKey('obra-1', Plane::KIND_REVIEW_NEEDED, 'waiting_review');
        $b = CodeAttentionClassifier::itemKey('obra-1', Plane::KIND_REVIEW_NEEDED, 'waiting_review');
        $c = CodeAttentionClassifier::itemKey('obra-1', Plane::KIND_REVIEW_NEEDED, 'completed');
        $d = CodeAttentionClassifier::itemKey('obra-2', Plane::KIND_REVIEW_NEEDED, 'waiting_review');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($a, $d);
        $this->assertStringStartsWith('attn_', $a);
        $this->assertSame(21, strlen($a)); // attn_ + 16 hex
        $this->assertMatchesRegularExpression('/^attn_[a-f0-9]{16}$/', $a);
    }
}
