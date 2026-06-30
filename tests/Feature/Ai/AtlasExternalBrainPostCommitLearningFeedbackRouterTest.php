<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitLearningFeedbackRouter;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasExternalBrainPostCommitLearningFeedbackRouter.
 *
 * Verifies evidence gating, positive_lesson suppression on weak tests or
 * capability_delta=0, and worker_affinity/task_family_policy emission.
 */
final class AtlasExternalBrainPostCommitLearningFeedbackRouterTest extends TestCase
{
    private function svc(): AtlasExternalBrainPostCommitLearningFeedbackRouter
    {
        return new AtlasExternalBrainPostCommitLearningFeedbackRouter;
    }

    private function commit(string $capability, array $overrides = []): array
    {
        return $overrides + [
            'changed_capability'     => $capability,
            'implementation_evidence' => 'commit:abc123',
            'test_evidence'          => '12/12 green',
            'compounding_value'      => 5,
            'test_strength'          => 7,
            'scope_size'             => 1,
            'duplicate_detected'     => false,
        ];
    }

    private function route(array $commits): array
    {
        return $this->svc()->route(['commits' => $commits]);
    }

    // ── AC1: commits missing evidence routed to ignored_low_evidence_commits ─

    public function test_commit_missing_capability_is_ignored(): void
    {
        $r = $this->route([['implementation_evidence' => 'x', 'test_evidence' => 'y']]);

        $this->assertCount(1, $r['ignored_low_evidence_commits']);
        $this->assertSame([], $r['promoted_lessons']);
    }

    public function test_commit_missing_impl_evidence_is_ignored(): void
    {
        $r = $this->route([['changed_capability' => 'foo', 'test_evidence' => 'y']]);

        $this->assertCount(1, $r['ignored_low_evidence_commits']);
    }

    public function test_commit_missing_test_evidence_is_ignored(): void
    {
        $r = $this->route([['changed_capability' => 'foo', 'implementation_evidence' => 'x']]);

        $this->assertCount(1, $r['ignored_low_evidence_commits']);
    }

    // ── AC2: weak tests or capability_delta=0 suppress positive_lessons ──────

    public function test_weak_tests_suppress_positive_lessons_and_emit_constraints(): void
    {
        $r = $this->route([$this->commit('fragile', ['test_strength' => 2, 'compounding_value' => 9])]);

        $this->assertSame([], $r['positive_lessons']);
        $constraints = array_column($r['next_batch_constraints'], 'constraint');
        $this->assertContains('require_minimum_test_strength_7', $constraints);
    }

    public function test_capability_delta_zero_suppresses_positive_lessons(): void
    {
        $r = $this->route([$this->commit('noop', ['capability_delta' => 0, 'compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertSame([], $r['positive_lessons']);
        $constraints = array_column($r['next_batch_constraints'], 'constraint');
        $this->assertContains('require_measurable_capability_delta', $constraints);
    }

    public function test_clean_commit_produces_positive_lessons(): void
    {
        $r = $this->route([$this->commit('good', ['compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertNotEmpty($r['positive_lessons']);
        $this->assertSame([], $r['next_batch_constraints']);
    }

    // ── AC3: worker_affinity and task_family_policy for proven commits ───────

    public function test_worker_affinity_boost_emitted_for_proven_commit(): void
    {
        $r = $this->route([$this->commit('cap', ['worker_id' => 'w-001', 'compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertCount(1, $r['worker_affinity_updates']);
        $this->assertSame('boost', $r['worker_affinity_updates'][0]['affinity']);
    }

    public function test_task_family_policy_emitted_for_proven_commit(): void
    {
        $r = $this->route([$this->commit('cap', ['task_family' => 'refactor', 'compounding_value' => 8])]);

        $this->assertCount(1, $r['task_family_policy_updates']);
        $this->assertSame('promote', $r['task_family_policy_updates'][0]['policy']);
    }
}
