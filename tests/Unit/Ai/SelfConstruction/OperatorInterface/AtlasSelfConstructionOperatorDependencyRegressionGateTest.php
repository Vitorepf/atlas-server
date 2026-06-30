<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorInterface;

use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionOperatorDependencyRegressionGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionOperatorDependencyRegressionGate: a visibility-only plan ⇒ allowed=true;
 * a plan that requires per-cycle operator approval ⇒ blocked; a plan that names emergency stop as a
 * required per-plan button ⇒ blocked; a plan that mentions kill switch only as escape hatch ⇒ allowed.
 */
final class AtlasSelfConstructionOperatorDependencyRegressionGateTest extends TestCase
{
    public function test_visibility_only_plan_is_allowed(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-1',
            'title' => 'Add operator dashboard',
            'description' => 'Surfaces self-construction status for visibility — no approval required.',
            'fields' => ['interface_role' => 'read_only_with_emergency_stop'],
        ]);
        $this->assertTrue($r['allowed']);
        $this->assertSame([], $r['blocking_facts']);
    }

    public function test_plan_requiring_operator_approval_per_cycle_is_blocked(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-2',
            'description' => 'Each cycle requires operator review before promotion.',
        ]);
        $this->assertFalse($r['allowed']);
        $kinds = array_column($r['blocking_facts'], 'kind');
        $this->assertContains('dependency_regression:cycle_requires_operator_review', $kinds);
    }

    public function test_plan_requiring_operator_approval_in_any_field_is_blocked(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-3',
            'fields' => ['merge_policy' => 'requires operator approval before merge'],
        ]);
        $this->assertFalse($r['allowed']);
        $kinds = array_column($r['blocking_facts'], 'kind');
        $this->assertContains('dependency_regression:requires_operator_approval', $kinds);
    }

    public function test_plan_with_emergency_button_per_plan_is_blocked(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-4',
            'description' => 'Every plan must show an emergency stop button to the operator.',
        ]);
        $this->assertFalse($r['allowed']);
        $kinds = array_column($r['blocking_facts'], 'kind');
        $this->assertContains('dependency_regression:emergency_button_as_normal_path', $kinds);
    }

    public function test_plan_mentioning_kill_switch_only_as_escape_hatch_is_allowed(): void
    {
        // The text mentions kill switch but doesn't require it per-plan — should pass.
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-5',
            'description' => 'The operator retains a global kill switch as an escape hatch — not invoked by this plan.',
        ]);
        $this->assertTrue($r['allowed']);
    }

    public function test_manual_sign_off_phrase_is_blocked(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-7',
            'description' => 'This plan requires a manual sign-off before proceeding.',
        ]);
        $this->assertFalse($r['allowed']);
        $this->assertContains('dependency_regression:requires_operator_approval', array_column($r['blocking_facts'], 'kind'));
    }

    public function test_wait_for_operator_phrase_is_blocked(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-8',
            'description' => 'Execution pauses while waiting for operator.',
        ]);
        $this->assertFalse($r['allowed']);
        $this->assertContains('dependency_regression:requires_operator_approval', array_column($r['blocking_facts'], 'kind'));
    }

    public function test_human_confirmation_phrase_is_blocked(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-9',
            'description' => 'Human confirmation is required at each stage.',
        ]);
        $this->assertFalse($r['allowed']);
        $this->assertContains('dependency_regression:requires_operator_approval', array_column($r['blocking_facts'], 'kind'));
    }

    public function test_external_assistant_dependency_phrase_is_blocked(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-10',
            'description' => 'Each merge requires Claude Code to review the diff.',
        ]);
        $this->assertFalse($r['allowed']);
        $this->assertContains('dependency_regression:external_assistant_dependency', array_column($r['blocking_facts'], 'kind'));
    }

    public function test_blocking_facts_are_deterministically_sorted(): void
    {
        $r = (new AtlasSelfConstructionOperatorDependencyRegressionGate)->evaluate([
            'plan_id' => 'p-6',
            'title' => 'requires operator approval',
            'description' => 'each cycle operator review',
        ]);
        $sorted = $r['blocking_facts'];
        $copy = $sorted;
        usort($copy, static fn (array $a, array $b): int => strcmp($a['kind'].'|'.$a['field'], $b['kind'].'|'.$b['field']));
        $this->assertSame($copy, $sorted);
    }
}
