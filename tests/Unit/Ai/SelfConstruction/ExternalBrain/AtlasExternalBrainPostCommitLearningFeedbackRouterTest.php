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
    }
}
