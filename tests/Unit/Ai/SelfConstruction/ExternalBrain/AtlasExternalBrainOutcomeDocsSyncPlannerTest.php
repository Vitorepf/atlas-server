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
}
