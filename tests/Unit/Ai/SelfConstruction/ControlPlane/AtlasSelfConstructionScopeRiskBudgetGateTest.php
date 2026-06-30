<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionScopeRiskBudgetGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionScopeRiskBudgetGate: safe Atlas-lane request ⇒ allowed=true with
 * normalized_scope; scope outside lane roots ⇒ scope_outside_lane:<path>; forbidden organ touched ⇒
 * forbidden_organ_touched:<organ>; high-risk without rollback_ready ⇒
 * high_risk_requires_rollback_ready; zero budget ⇒ empty_task_budget AND empty_cost_budget.
 */
final class AtlasSelfConstructionScopeRiskBudgetGateTest extends TestCase
{
    private function safeFacts(): array
    {
        return [
            'requested_scope' => ['app/Demo/Foo.php'],
            'risk_class' => 'medium',
            'task_budget' => 10,
            'cost_budget_units' => 100,
            'project_lane' => ['project_id' => 'atlas', 'allowed_scope_roots' => ['app/']],
            'forbidden_organs' => ['Constitution', 'MasterSwitch'],
            'touched_organs' => ['Demo'],
            'rollback_ready' => true,
        ];
    }

    public function test_safe_atlas_lane_yields_allowed_true_with_normalized_scope(): void
    {
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($this->safeFacts());
        $this->assertTrue($r['allowed']);
        $this->assertSame(['app/Demo/Foo.php'], $r['normalized_scope']);
        $this->assertSame(10, $r['max_tasks']);
        $this->assertSame(100, $r['max_cost_units']);
    }

    public function test_scope_outside_lane_yields_named_blocker(): void
    {
        $f = $this->safeFacts();
        $f['requested_scope'] = ['/etc/passwd'];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('scope_outside_lane:/etc/passwd', $r['blockers']);
    }

    public function test_forbidden_organ_touched_yields_blocker(): void
    {
        $f = $this->safeFacts();
        $f['touched_organs'] = ['Constitution', 'Demo'];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('forbidden_organ_touched:Constitution', $r['blockers']);
    }

    public function test_high_risk_without_rollback_ready_is_blocked(): void
    {
        $f = $this->safeFacts();
        $f['risk_class'] = 'high';
        $f['rollback_ready'] = false;
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('high_risk_requires_rollback_ready', $r['blockers']);
    }

    public function test_zero_budget_yields_both_budget_blockers(): void
    {
        $f = $this->safeFacts();
        $f['task_budget'] = 0;
        $f['cost_budget_units'] = 0;
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertContains('empty_task_budget', $r['blockers']);
        $this->assertContains('empty_cost_budget', $r['blockers']);
    }

    public function test_invalid_risk_class_is_blocked(): void
    {
        $f = $this->safeFacts();
        $f['risk_class'] = 'apocalypse';
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertContains('invalid_risk_class:apocalypse', $r['blockers']);
    }

    public function test_missing_project_lane_scope_roots_is_blocked(): void
    {
        $f = $this->safeFacts();
        $f['project_lane'] = ['project_id' => 'atlas'];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertContains('missing_project_lane_scope_roots', $r['blockers']);
    }

    public function test_empty_requested_scope_yields_empty_requested_scope_blocker(): void
    {
        $f = $this->safeFacts();
        $f['requested_scope'] = [];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('empty_requested_scope', $r['blockers']);
    }

    public function test_missing_project_lane_project_id_yields_project_lane_id_missing_blocker(): void
    {
        $f = $this->safeFacts();
        $f['project_lane'] = ['allowed_scope_roots' => ['app/']]; // no project_id
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('project_lane_id_missing', $r['blockers']);
    }

    public function test_blank_project_lane_project_id_yields_project_lane_id_missing_blocker(): void
    {
        $f = $this->safeFacts();
        $f['project_lane'] = ['project_id' => '   ', 'allowed_scope_roots' => ['app/']];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('project_lane_id_missing', $r['blockers']);
    }

    // --- burn-rate checks ---

    public function test_failure_rate_above_policy_yields_burn_rate_blocker(): void
    {
        $f = $this->safeFacts();
        $f['failure_rate'] = 0.31; // above MAX_FAILURE_RATE (0.30)
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('burn_rate_failure_rate_exceeded', $r['blockers']);
    }

    public function test_give_back_rate_above_policy_yields_burn_rate_blocker(): void
    {
        $f = $this->safeFacts();
        $f['give_back_rate'] = 0.51; // above MAX_GIVE_BACK_RATE (0.50)
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('burn_rate_give_back_rate_exceeded', $r['blockers']);
    }

    public function test_both_rates_at_or_below_policy_do_not_block(): void
    {
        $f = $this->safeFacts();
        $f['failure_rate'] = 0.30;
        $f['give_back_rate'] = 0.50;
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertNotContains('burn_rate_failure_rate_exceeded', $r['blockers']);
        $this->assertNotContains('burn_rate_give_back_rate_exceeded', $r['blockers']);
    }

    public function test_both_burn_rate_blockers_emitted_when_both_exceeded(): void
    {
        $f = $this->safeFacts();
        $f['failure_rate'] = 0.5;
        $f['give_back_rate'] = 0.9;
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertContains('burn_rate_failure_rate_exceeded', $r['blockers']);
        $this->assertContains('burn_rate_give_back_rate_exceeded', $r['blockers']);
    }

    // --- cycle window checks ---

    public function test_high_risk_without_cycle_window_yields_blocker_even_with_rollback_ready(): void
    {
        $f = $this->safeFacts();
        $f['risk_class'] = 'high';
        $f['rollback_ready'] = true;
        // no cycle_window key
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('high_risk_requires_cycle_window', $r['blockers']);
    }

    public function test_hardest_risk_exhausted_cycle_window_yields_blocker(): void
    {
        $f = $this->safeFacts();
        $f['risk_class'] = 'hardest';
        $f['rollback_ready'] = true;
        $f['cycle_window'] = ['remaining_cycles' => 0];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertFalse($r['allowed']);
        $this->assertContains('cycle_window_exhausted', $r['blockers']);
    }

    public function test_high_risk_with_valid_cycle_window_and_rollback_ready_is_allowed(): void
    {
        $f = $this->safeFacts();
        $f['risk_class'] = 'high';
        $f['rollback_ready'] = true;
        $f['cycle_window'] = ['remaining_cycles' => 3];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertTrue($r['allowed']);
        $this->assertNotContains('high_risk_requires_cycle_window', $r['blockers']);
        $this->assertNotContains('cycle_window_exhausted', $r['blockers']);
    }

    public function test_low_risk_does_not_require_cycle_window(): void
    {
        $f = $this->safeFacts();
        $f['risk_class'] = 'low';
        // no cycle_window
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertNotContains('high_risk_requires_cycle_window', $r['blockers']);
        $this->assertNotContains('cycle_window_exhausted', $r['blockers']);
    }

    public function test_duplicate_scope_paths_normalize_without_duplicates(): void
    {
        $f = $this->safeFacts();
        $f['requested_scope'] = ['app/Demo/Foo.php', 'app/Demo/Foo.php', 'app/Demo/Bar.php'];
        $r = (new AtlasSelfConstructionScopeRiskBudgetGate)->evaluate($f);
        $this->assertTrue($r['allowed']);
        $this->assertSame(array_unique($r['normalized_scope']), $r['normalized_scope'], 'normalized_scope must not contain duplicates');
        $this->assertCount(2, $r['normalized_scope']);
    }
}
