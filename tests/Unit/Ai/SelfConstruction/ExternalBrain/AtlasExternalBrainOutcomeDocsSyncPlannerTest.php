<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeDocsSyncPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutcomeDocsSyncPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainOutcomeDocsSyncPlanner
    {
        return new AtlasExternalBrainOutcomeDocsSyncPlanner;
    }

    public function test_plan_is_deterministic_for_identical_input_and_never_mutates_it(): void
    {
        $planner = $this->planner();
        $facts = ['outcome' => [
            'task_id' => 't1',
            'is_architecture_decision' => true,
            'memory_relevant' => true,
            'operator_facing_significance' => 'high',
        ]];
        $before = $facts;

        $a = $planner->plan($facts);
        $b = $planner->plan($facts);

        $this->assertSame($a, $b);
        $this->assertSame($before, $facts, 'plan must not mutate its input');
    }

    public function test_repeated_trivial_commits_exhaust_noise_budget_and_docs_sync_stays_false(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'is_trivial_commit' => true,
            'recent_trivial_commit_count' => 5,
        ]]);

        $this->assertFalse($result['docs_sync_needed']);
        $this->assertTrue($result['noise_budget']['exhausted']);
        $this->assertSame(0, $result['noise_budget']['remaining']);
        $this->assertContains('trivial_commit_noise_budget_exhausted', $result['do_not_sync_reason']);
    }

    public function test_architecture_decision_bypasses_trivial_noise_budget(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'is_trivial_commit' => true,
            'recent_trivial_commit_count' => 10,
            'is_architecture_decision' => true,
        ]]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertTrue($result['noise_budget']['bypassed_by_hard_trigger']);
        $this->assertContains('architecture_decision', $result['sync_reason']);
    }

    public function test_new_operating_policy_bypasses_trivial_noise_budget(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'is_trivial_commit' => true,
            'recent_trivial_commit_count' => 10,
            'is_new_operating_policy' => true,
        ]]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertContains('new_operating_policy', $result['sync_reason']);
    }

    public function test_repeated_failure_learning_bypasses_trivial_noise_budget(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'is_trivial_commit' => true,
            'recent_trivial_commit_count' => 10,
            'is_repeated_failure_learning' => true,
        ]]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertContains('repeated_failure_learning', $result['sync_reason']);
    }

    public function test_memory_sync_needed_is_stricter_than_docs_sync_needed(): void
    {
        // operator_facing_significance=medium alone qualifies docs, but not memory (needs 'high' or hard trigger).
        $result = $this->planner()->plan(['outcome' => [
            'memory_relevant' => true,
            'operator_facing_significance' => 'medium',
        ]]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertFalse($result['memory_sync_needed']);
    }

    public function test_memory_sync_needed_true_only_with_relevance_and_high_significance(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'memory_relevant' => true,
            'operator_facing_significance' => 'high',
        ]]);

        $this->assertTrue($result['memory_sync_needed']);
    }

    public function test_memory_sync_needed_false_without_memory_relevance_even_with_hard_trigger(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'memory_relevant' => false,
            'is_architecture_decision' => true,
        ]]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertFalse($result['memory_sync_needed']);
    }

    public function test_noise_budget_not_exhausted_below_threshold(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'is_trivial_commit' => true,
            'recent_trivial_commit_count' => 1,
        ]]);

        $this->assertFalse($result['noise_budget']['exhausted']);
        $this->assertGreaterThan(0, $result['noise_budget']['remaining']);
    }

    public function test_default_outcome_has_no_sync_needed(): void
    {
        $result = $this->planner()->plan(['outcome' => []]);

        $this->assertFalse($result['docs_sync_needed']);
        $this->assertFalse($result['memory_sync_needed']);
    }

    public function test_output_never_mutates_docs_or_memory(): void
    {
        $result = $this->planner()->plan(['outcome' => ['is_architecture_decision' => true]]);

        $this->assertFalse($result['mutates_docs_or_memory']);
    }

    // ── domain map maturity / ownership / next-leverage triggers ─────────────

    public function test_domain_map_changed_with_maturity_delta_emits_docs_sync_needed(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'domain_map_changed' => true,
            'maturity_delta' => true,
        ]]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertContains('domain_map_maturity_changed', $result['sync_reason']);
    }

    public function test_domain_map_changed_without_maturity_delta_does_not_trigger(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'domain_map_changed' => true,
        ]]);

        $this->assertFalse($result['docs_sync_needed']);
    }

    public function test_owner_changed_emits_memory_sync_needed_when_memory_relevant(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'memory_relevant' => true,
            'owner_changed' => true,
        ]]);

        $this->assertTrue($result['memory_sync_needed']);
        $this->assertContains('memory_relevant_owner_or_next_leverage_changed', $result['sync_reason']);
    }

    public function test_next_leverage_changed_emits_memory_sync_needed_when_memory_relevant(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'memory_relevant' => true,
            'next_leverage_changed' => true,
        ]]);

        $this->assertTrue($result['memory_sync_needed']);
    }

    public function test_owner_changed_without_memory_relevant_does_not_trigger_memory_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'memory_relevant' => false,
            'owner_changed' => true,
        ]]);

        $this->assertFalse($result['memory_sync_needed']);
    }

    public function test_trivial_commit_without_domain_map_changes_still_does_not_churn_docs(): void
    {
        $result = $this->planner()->plan(['outcome' => [
            'is_trivial_commit' => true,
            'operator_facing_significance' => 'medium',
        ]]);

        $this->assertFalse($result['docs_sync_needed']);
    }
}
