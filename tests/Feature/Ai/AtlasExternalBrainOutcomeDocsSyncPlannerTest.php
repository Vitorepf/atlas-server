<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeDocsSyncPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutcomeDocsSyncPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainOutcomeDocsSyncPlanner
    {
        return new AtlasExternalBrainOutcomeDocsSyncPlanner;
    }

    private function outcome(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'task-1',
            'capability_delta' => 'none',
            'docs_touched' => [],
            'memory_relevant' => false,
            'operator_facing_significance' => 'trivial',
            'future_task_impact' => 'none',
            'is_architecture_decision' => false,
            'is_new_operating_policy' => false,
            'is_repeated_failure_learning' => false,
            'is_trivial_commit' => false,
        ], $overrides);
    }

    // ── output shape ───────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome()]);

        foreach (['docs_sync_needed', 'memory_sync_needed', 'recommended_docs', 'sync_reason', 'do_not_sync_reason'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
    }

    // ── AC3: trivial commits avoid documentation churn ────────────────────

    public function test_trivial_commit_with_no_signals_does_not_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome(['is_trivial_commit' => true])]);

        $this->assertFalse($result['docs_sync_needed']);
        $this->assertFalse($result['memory_sync_needed']);
        $this->assertSame([], $result['recommended_docs']);
        $this->assertNotEmpty($result['do_not_sync_reason']);
    }

    public function test_trivial_commit_with_medium_operator_significance_still_does_not_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'is_trivial_commit' => true,
            'operator_facing_significance' => 'medium',
        ])]);

        $this->assertFalse($result['docs_sync_needed']);
    }

    // ── AC3: architecture decisions require sync, even on a trivial-flagged commit ──

    public function test_architecture_decision_requires_sync_even_if_flagged_trivial(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'is_trivial_commit' => true,
            'is_architecture_decision' => true,
        ])]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertContains('architecture_decision', $result['sync_reason']);
    }

    // ── AC3: new operating policy requires sync ───────────────────────────

    public function test_new_operating_policy_requires_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome(['is_new_operating_policy' => true])]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertContains('new_operating_policy', $result['sync_reason']);
    }

    // ── AC3: repeated failure learnings require sync ──────────────────────

    public function test_repeated_failure_learning_requires_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome(['is_repeated_failure_learning' => true])]);

        $this->assertTrue($result['docs_sync_needed']);
        $this->assertContains('repeated_failure_learning', $result['sync_reason']);
    }

    // ── high operator significance (non-trivial) requires sync ────────────

    public function test_high_operator_facing_significance_requires_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome(['operator_facing_significance' => 'high'])]);

        $this->assertTrue($result['docs_sync_needed']);
    }

    public function test_low_operator_facing_significance_does_not_require_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome(['operator_facing_significance' => 'low'])]);

        $this->assertFalse($result['docs_sync_needed']);
    }

    // ── future task impact + capability delta combo ───────────────────────

    public function test_high_future_impact_and_capability_delta_requires_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'future_task_impact' => 'high',
            'capability_delta' => 'high',
        ])]);

        $this->assertTrue($result['docs_sync_needed']);
    }

    public function test_high_future_impact_without_capability_delta_does_not_require_sync(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'future_task_impact' => 'high',
            'capability_delta' => 'none',
        ])]);

        $this->assertFalse($result['docs_sync_needed']);
    }

    // ── recommended_docs ───────────────────────────────────────────────────

    public function test_recommended_docs_uses_docs_touched_when_provided(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'is_architecture_decision' => true,
            'docs_touched' => ['docs/foo.md', 'docs/bar.md'],
        ])]);

        $this->assertSame(['docs/foo.md', 'docs/bar.md'], $result['recommended_docs']);
    }

    public function test_recommended_docs_falls_back_when_sync_needed_but_no_docs_touched(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome(['is_architecture_decision' => true])]);

        $this->assertNotEmpty($result['recommended_docs']);
    }

    // ── memory_sync_needed: stricter bar than docs ─────────────────────────

    public function test_memory_sync_needed_requires_memory_relevant_and_hard_trigger(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'memory_relevant' => true,
            'is_repeated_failure_learning' => true,
        ])]);

        $this->assertTrue($result['memory_sync_needed']);
    }

    public function test_memory_sync_not_needed_when_memory_relevant_but_no_hard_trigger_and_low_significance(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'memory_relevant' => true,
            'operator_facing_significance' => 'medium',
        ])]);

        $this->assertFalse($result['memory_sync_needed']);
    }

    public function test_memory_sync_needed_for_high_operator_significance_even_without_hard_trigger(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'memory_relevant' => true,
            'operator_facing_significance' => 'high',
        ])]);

        $this->assertTrue($result['memory_sync_needed']);
    }

    public function test_memory_sync_not_needed_when_not_memory_relevant_even_with_hard_trigger(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome([
            'memory_relevant' => false,
            'is_architecture_decision' => true,
        ])]);

        $this->assertFalse($result['memory_sync_needed']);
    }

    // ── do_not_sync_reason populated only when nothing syncs ───────────────

    public function test_do_not_sync_reason_empty_when_sync_is_needed(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome(['is_architecture_decision' => true])]);
        $this->assertSame([], $result['do_not_sync_reason']);
    }

    // ── determinism ────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $facts = ['outcome' => $this->outcome(['is_new_operating_policy' => true])];

        $a = $this->planner()->plan($facts);
        $b = $this->planner()->plan($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_plan_never_mutates_docs_or_memory(): void
    {
        $result = $this->planner()->plan(['outcome' => $this->outcome()]);
        $this->assertFalse($result['mutates_docs_or_memory']);
    }
}
