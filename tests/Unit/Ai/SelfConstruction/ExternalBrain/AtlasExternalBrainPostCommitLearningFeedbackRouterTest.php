<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitLearningFeedbackRouter;
use Tests\TestCase;

final class AtlasExternalBrainPostCommitLearningFeedbackRouterTest extends TestCase
{
    private function svc(): AtlasExternalBrainPostCommitLearningFeedbackRouter
    {
        return new AtlasExternalBrainPostCommitLearningFeedbackRouter;
    }

    private function commit(string $capability, array $overrides = []): array
    {
        return $overrides + [
            'changed_capability' => $capability,
            'implementation_evidence' => 'commit:abc123',
            'test_evidence' => '12/12 green',
            'compounding_value' => 5,
            'test_strength' => 7,
            'scope_size' => 1,
            'duplicate_detected' => false,
        ];
    }

    private function route(array $commits): array
    {
        return $this->svc()->route(['commits' => $commits]);
    }

    // ── evidence gating ───────────────────────────────────────────────────────

    public function test_commit_missing_capability_is_ignored(): void
    {
        $r = $this->route([['implementation_evidence' => 'x', 'test_evidence' => 'y']]);

        $this->assertSame([], $r['promoted_lessons']);
        $this->assertCount(1, $r['ignored_low_evidence_commits']);
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

    // ── promoted lessons ──────────────────────────────────────────────────────

    public function test_high_compounding_value_produces_lesson(): void
    {
        $r = $this->route([$this->commit('stall-detector', ['compounding_value' => 9])]);

        $lessons = array_column($r['promoted_lessons'], 'lesson');
        $this->assertContains('high_compounding_value', $lessons);
    }

    public function test_strong_test_coverage_produces_lesson(): void
    {
        $r = $this->route([$this->commit('stall-detector', ['test_strength' => 8])]);

        $lessons = array_column($r['promoted_lessons'], 'lesson');
        $this->assertContains('strong_test_coverage', $lessons);
    }

    public function test_single_file_scope_produces_focused_scope_lesson(): void
    {
        $r = $this->route([$this->commit('stall-detector', ['scope_size' => 1])]);

        $lessons = array_column($r['promoted_lessons'], 'lesson');
        $this->assertContains('focused_scope', $lessons);
    }

    public function test_below_threshold_compounding_does_not_produce_high_lesson(): void
    {
        $r = $this->route([$this->commit('stall-detector', ['compounding_value' => 4, 'test_strength' => 8])]);

        $lessons = array_column($r['promoted_lessons'], 'lesson');
        $this->assertNotContains('high_compounding_value', $lessons);
    }

    // ── warnings ──────────────────────────────────────────────────────────────

    public function test_excessive_scope_produces_warning(): void
    {
        $r = $this->route([$this->commit('large-refactor', ['scope_size' => 5])]);

        $warnTypes = array_column($r['warnings'], 'warning');
        $this->assertContains('excessive_scope', $warnTypes);
    }

    public function test_weak_tests_produces_warning(): void
    {
        $r = $this->route([$this->commit('fragile-impl', ['test_strength' => 2])]);

        $warnTypes = array_column($r['warnings'], 'warning');
        $this->assertContains('weak_tests', $warnTypes);
    }

    public function test_duplicate_detected_produces_warning(): void
    {
        $r = $this->route([$this->commit('dup-cap', ['duplicate_detected' => true])]);

        $warnTypes = array_column($r['warnings'], 'warning');
        $this->assertContains('duplicate_capability', $warnTypes);
    }

    public function test_low_compounding_value_produces_warning(): void
    {
        $r = $this->route([$this->commit('low-value', ['compounding_value' => 1])]);

        $warnTypes = array_column($r['warnings'], 'warning');
        $this->assertContains('low_compounding_value', $warnTypes);
    }

    // ── next_batch_constraints ────────────────────────────────────────────────

    public function test_excessive_scope_warning_emits_constraint(): void
    {
        $r = $this->route([$this->commit('cap', ['scope_size' => 5])]);

        $constraints = array_column($r['next_batch_constraints'], 'constraint');
        $this->assertContains('limit_scope_to_single_capability', $constraints);
    }

    public function test_constraints_deduplicated_across_commits(): void
    {
        $r = $this->route([
            $this->commit('cap-a', ['scope_size' => 5]),
            $this->commit('cap-b', ['scope_size' => 6]),
        ]);

        $constraints = array_column($r['next_batch_constraints'], 'constraint');
        $this->assertSame(array_unique($constraints), $constraints);
        $this->assertCount(1, array_filter($constraints, static fn ($c) => $c === 'limit_scope_to_single_capability'));
    }

    // ── clean commit ─────────────────────────────────────────────────────────

    public function test_clean_commit_produces_no_warnings(): void
    {
        $r = $this->route([$this->commit('clean-cap', [
            'compounding_value' => 8,
            'test_strength' => 9,
            'scope_size' => 1,
            'duplicate_detected' => false,
        ])]);

        $this->assertSame([], $r['warnings']);
        $this->assertSame([], $r['next_batch_constraints']);
        $this->assertNotEmpty($r['promoted_lessons']);
    }

    // ── AC1: positive_lessons (AC2 suppression) ──────────────────────────────

    public function test_positive_lessons_populated_for_clean_commit(): void
    {
        $r = $this->route([$this->commit('clean', ['compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertNotEmpty($r['positive_lessons']);
        $lessons = array_column($r['positive_lessons'], 'lesson');
        $this->assertContains('high_compounding_value', $lessons);
    }

    public function test_positive_lessons_empty_when_weak_tests(): void
    {
        // AC2: weak_tests blocks positive_lessons even if compounding is high.
        $r = $this->route([$this->commit('fragile', ['test_strength' => 2, 'compounding_value' => 9])]);

        $this->assertSame([], $r['positive_lessons']);
        // promoted_lessons still has the lesson (backward compat).
        $this->assertNotEmpty($r['promoted_lessons']);
    }

    public function test_positive_lessons_empty_when_capability_delta_zero(): void
    {
        // AC2: explicit capability_delta=0 blocks positive_lessons.
        $r = $this->route([$this->commit('noop', ['capability_delta' => 0, 'compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertSame([], $r['positive_lessons']);
        $this->assertNotEmpty($r['promoted_lessons']);
    }

    public function test_capability_delta_warning_when_zero(): void
    {
        $r = $this->route([$this->commit('noop', ['capability_delta' => 0])]);

        $warnTypes = array_column($r['warnings'], 'warning');
        $this->assertContains('no_capability_delta', $warnTypes);
    }

    public function test_no_capability_delta_warning_when_field_absent(): void
    {
        // Field absent → no warning (opt-in, backward compat).
        $r = $this->route([$this->commit('real-cap')]);

        $warnTypes = array_column($r['warnings'], 'warning');
        $this->assertNotContains('no_capability_delta', $warnTypes);
    }

    public function test_positive_lessons_not_blocked_by_nonzero_capability_delta(): void
    {
        $r = $this->route([$this->commit('good', ['capability_delta' => 1, 'compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertNotEmpty($r['positive_lessons']);
    }

    // ── AC1: negative_constraints ─────────────────────────────────────────────

    public function test_negative_constraints_mirrors_next_batch_constraints(): void
    {
        $r = $this->route([$this->commit('cap', ['scope_size' => 5])]);

        $this->assertSame($r['next_batch_constraints'], $r['negative_constraints']);
    }

    public function test_negative_constraints_empty_for_clean_commit(): void
    {
        $r = $this->route([$this->commit('clean', ['compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertSame([], $r['negative_constraints']);
    }

    // ── AC1: worker_affinity_updates ──────────────────────────────────────────

    public function test_worker_affinity_boost_for_high_compounding_and_strong_tests(): void
    {
        $r = $this->route([$this->commit('cap', ['worker_id' => 'w-001', 'compounding_value' => 8, 'test_strength' => 9])]);

        $this->assertCount(1, $r['worker_affinity_updates']);
        $this->assertSame('boost', $r['worker_affinity_updates'][0]['affinity']);
        $this->assertSame('w-001', $r['worker_affinity_updates'][0]['worker_id']);
    }

    public function test_worker_affinity_suppress_for_low_compounding(): void
    {
        $r = $this->route([$this->commit('cap', ['worker_id' => 'w-002', 'compounding_value' => 2])]);

        $this->assertCount(1, $r['worker_affinity_updates']);
        $this->assertSame('suppress', $r['worker_affinity_updates'][0]['affinity']);
    }

    public function test_worker_affinity_empty_when_no_worker_id(): void
    {
        $r = $this->route([$this->commit('cap')]);

        $this->assertSame([], $r['worker_affinity_updates']);
    }

    // ── AC1: task_family_policy_updates ───────────────────────────────────────

    public function test_task_family_policy_promote_for_high_compounding(): void
    {
        $r = $this->route([$this->commit('cap', ['task_family' => 'refactor', 'compounding_value' => 8])]);

        $this->assertCount(1, $r['task_family_policy_updates']);
        $this->assertSame('promote', $r['task_family_policy_updates'][0]['policy']);
        $this->assertSame('refactor', $r['task_family_policy_updates'][0]['task_family']);
    }

    public function test_task_family_policy_deprioritize_for_duplicate(): void
    {
        $r = $this->route([$this->commit('cap', ['task_family' => 'test-add', 'duplicate_detected' => true])]);

        $this->assertSame('deprioritize', $r['task_family_policy_updates'][0]['policy']);
    }

    public function test_task_family_policy_deprioritize_for_low_compounding(): void
    {
        $r = $this->route([$this->commit('cap', ['task_family' => 'cleanup', 'compounding_value' => 1])]);

        $this->assertSame('deprioritize', $r['task_family_policy_updates'][0]['policy']);
    }

    public function test_task_family_policy_watch_for_moderate_commit(): void
    {
        // compounding=5 (< HIGH, >= LOW), no duplicate → watch.
        $r = $this->route([$this->commit('cap', ['task_family' => 'misc', 'compounding_value' => 5])]);

        $this->assertSame('watch', $r['task_family_policy_updates'][0]['policy']);
    }

    public function test_task_family_policy_empty_when_no_task_family(): void
    {
        $r = $this->route([$this->commit('cap')]);

        $this->assertSame([], $r['task_family_policy_updates']);
    }

    // ── schema + empty ────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->route([]);

        $this->assertSame(AtlasExternalBrainPostCommitLearningFeedbackRouter::SCHEMA, $r['schema_version']);
    }

    public function test_empty_input_returns_empty_output(): void
    {
        $r = $this->svc()->route([]);

        $this->assertSame([], $r['promoted_lessons']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame([], $r['next_batch_constraints']);
        $this->assertSame([], $r['ignored_low_evidence_commits']);
        $this->assertSame([], $r['context_quality_feedback']);
    }

    // ── context_quality_feedback ─────────────────────────────────────────────────

    public function test_hostile_context_emits_context_quality_feedback_and_constraint(): void
    {
        $r = $this->route([$this->commit('foo', ['hostile_context' => true])]);

        $issues = array_column($r['context_quality_feedback'], 'issue');
        $this->assertContains('hostile_context', $issues);

        $constraints = array_column($r['next_batch_constraints'], 'constraint');
        $this->assertContains('sanitize_hostile_context_before_injection', $constraints);
        $this->assertSame($r['next_batch_constraints'], $r['negative_constraints']);
    }

    public function test_noisy_context_emits_context_quality_feedback_and_constraint(): void
    {
        $r = $this->route([$this->commit('foo', ['noisy_context' => true])]);

        $issues = array_column($r['context_quality_feedback'], 'issue');
        $this->assertContains('noisy_context', $issues);

        $constraints = array_column($r['next_batch_constraints'], 'constraint');
        $this->assertContains('filter_noisy_context_sources', $constraints);
    }

    public function test_weak_tests_appears_in_context_quality_feedback(): void
    {
        $r = $this->route([$this->commit('foo', ['test_strength' => 2])]);

        $issues = array_column($r['context_quality_feedback'], 'issue');
        $this->assertContains('weak_tests', $issues);
    }

    public function test_duplicate_detected_appears_in_context_quality_feedback(): void
    {
        $r = $this->route([$this->commit('foo', ['duplicate_detected' => true])]);

        $issues = array_column($r['context_quality_feedback'], 'issue');
        $this->assertContains('duplicate_capability', $issues);
    }

    public function test_excessive_scope_appears_in_context_quality_feedback(): void
    {
        $r = $this->route([$this->commit('foo', ['scope_size' => 5])]);

        $issues = array_column($r['context_quality_feedback'], 'issue');
        $this->assertContains('excessive_scope', $issues);
    }

    public function test_no_capability_delta_appears_in_context_quality_feedback(): void
    {
        $r = $this->route([$this->commit('foo', ['capability_delta' => 0])]);

        $issues = array_column($r['context_quality_feedback'], 'issue');
        $this->assertContains('no_capability_delta', $issues);
    }

    public function test_context_quality_feedback_entries_carry_constraint_and_severity(): void
    {
        $r = $this->route([$this->commit('foo', ['hostile_context' => true])]);

        $entry = $r['context_quality_feedback'][0];
        $this->assertArrayHasKey('issue', $entry);
        $this->assertArrayHasKey('capability', $entry);
        $this->assertArrayHasKey('constraint', $entry);
        $this->assertArrayHasKey('severity', $entry);
        $this->assertSame('foo', $entry['capability']);
    }

    public function test_clean_commit_has_no_context_quality_feedback(): void
    {
        $r = $this->route([$this->commit('foo')]);

        $this->assertSame([], $r['context_quality_feedback']);
    }

    // ── AC2: positive lessons suppressed for weak-green commits ────────────────

    public function test_positive_lessons_suppressed_when_context_quality_issue_present_with_weak_tests(): void
    {
        $r = $this->route([$this->commit('foo', [
            'compounding_value' => 9,
            'test_strength'     => 2,
            'hostile_context'   => true,
        ])]);

        $this->assertContains(['lesson' => 'high_compounding_value', 'capability' => 'foo', 'compounding_value' => 9], $r['promoted_lessons']);
        $this->assertSame([], $r['positive_lessons'], 'weak tests must suppress positive lessons even with a hostile_context flag present');
    }

    public function test_hostile_context_alone_does_not_suppress_positive_lessons_when_tests_are_strong(): void
    {
        // hostile_context is a context-quality constraint, not itself a positive-lesson blocker —
        // only weak_tests / no_capability_delta gate positive lessons per the existing contract.
        $r = $this->route([$this->commit('foo', [
            'compounding_value' => 9,
            'test_strength'     => 9,
            'hostile_context'   => true,
        ])]);

        $this->assertNotEmpty($r['positive_lessons']);
    }

    public function test_context_quality_feedback_is_deterministic(): void
    {
        $commits = [$this->commit('foo', ['hostile_context' => true, 'noisy_context' => true])];

        $a = $this->route($commits);
        $b = $this->route($commits);

        $this->assertSame(
            json_encode($a['context_quality_feedback'], JSON_UNESCAPED_SLASHES),
            json_encode($b['context_quality_feedback'], JSON_UNESCAPED_SLASHES),
        );
    }
}
