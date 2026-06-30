<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueDecayMonitor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueDecayMonitorTest extends TestCase
{
    private function monitor(): AtlasExternalBrainValueDecayMonitor
    {
        return new AtlasExternalBrainValueDecayMonitor;
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'id'                    => 't1',
            'queued_at_days_ago'    => 5,
            'prerequisites_changed' => false,
            'landscape_shifted'     => false,
            'has_value_proof'       => true,
            'blocking_count'        => 0,
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->monitor()->monitor([]);
        $this->assertSame(AtlasExternalBrainValueDecayMonitor::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('recommendations',   $r);
        $this->assertArrayHasKey('retire_candidates', $r);
        $this->assertArrayHasKey('respec_candidates', $r);
        $this->assertArrayHasKey('keep_tasks',        $r);
        $this->assertArrayHasKey('monitor_summary',   $r);
        $this->assertArrayHasKey('per_task',          $r);
        $this->assertArrayHasKey('batch_decay_summary', $r);
    }

    public function test_per_task_entry_has_required_fields(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [$this->task()]]);
        $entry = $r['per_task'][0];

        foreach (['task_id', 'value_status', 'decay_score', 'reasons', 'recommended_action'] as $k) {
            $this->assertArrayHasKey($k, $entry);
        }
        $this->assertSame('fresh', $entry['value_status']);
        $this->assertSame('retain', $entry['recommended_action']);
        $this->assertSame(0.0, $entry['decay_score']);
    }

    // ── AC3: stale evidence + repeated give_back → refresh or consolidate, never retain ──

    public function test_stale_evidence_with_repeated_give_back_recommends_refresh_not_retain(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['stale_evidence_age' => 20, 'give_back_count' => 3]),
        ]]);

        $rec = $r['recommendations'][0];
        $this->assertContains($rec['recommendation'], ['refresh', 'consolidate']);
        $this->assertNotSame('keep', $rec['recommendation']);
        $this->assertSame('refresh', $r['per_task'][0]['recommended_action']);
    }

    public function test_stale_evidence_repeated_give_back_and_low_muscle_success_recommends_consolidate(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['stale_evidence_age' => 20, 'give_back_count' => 4, 'muscle_success_rate' => 0.1]),
        ]]);

        $this->assertSame('consolidate', $r['recommendations'][0]['recommendation']);
        $this->assertSame('consolidate', $r['per_task'][0]['recommended_action']);
        $this->assertSame('decaying', $r['per_task'][0]['value_status']);
    }

    // ── AC4: superseded target / duplicate family saturation → retire, isolated per-task ──

    public function test_superseded_target_recommends_retire_without_affecting_unrelated_fresh_task(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['id' => 'superseded', 'superseded_target' => true]),
            $this->task(['id' => 'fresh_high_value', 'current_value_score' => 0.9]),
        ]]);

        $this->assertContains('superseded', $r['retire_candidates']);
        $this->assertContains('fresh_high_value', $r['keep_tasks']);
        $fresh = array_values(array_filter($r['per_task'], fn ($t) => $t['task_id'] === 'fresh_high_value'))[0];
        $this->assertSame('fresh', $fresh['value_status']);
    }

    public function test_duplicate_family_saturation_recommends_retire_without_affecting_unrelated_fresh_task(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['id' => 'dup', 'duplicate_family_count' => 6]),
            $this->task(['id' => 'unrelated']),
        ]]);

        $this->assertContains('dup', $r['retire_candidates']);
        $this->assertContains('unrelated', $r['keep_tasks']);
        $this->assertSame('retire', $r['per_task'][0]['recommended_action']);
    }

    // ── AC2: no direct cancellation — only recommendations ────────────────────

    public function test_stable_task_gets_keep(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [$this->task()]]);
        $this->assertContains('t1', $r['keep_tasks']);
        $this->assertEmpty($r['retire_candidates']);
        $this->assertEmpty($r['respec_candidates']);
        $this->assertSame('keep', $r['recommendations'][0]['recommendation']);
    }

    // ── Priority 1: load-bearing forces keep ──────────────────────────────────

    public function test_high_blocking_count_forces_keep_even_if_old(): void
    {
        $r = $this->monitor()->monitor([
            'tasks'            => [$this->task([
                'queued_at_days_ago'    => 60,
                'has_value_proof'       => false,
                'blocking_count'        => 5,
            ])],
            'min_blocking_keep' => 3,
        ]);
        $this->assertContains('t1', $r['keep_tasks']);
        $this->assertSame('high_blocking_count_load_bearing', $r['recommendations'][0]['reason']);
    }

    // ── Priority 2: age decay + no value proof → retire ──────────────────────

    public function test_aged_task_without_value_proof_is_retire_candidate(): void
    {
        $r = $this->monitor()->monitor([
            'tasks'        => [$this->task(['queued_at_days_ago' => 35, 'has_value_proof' => false])],
            'max_age_days' => 30,
        ]);
        $this->assertContains('t1', $r['retire_candidates']);
        $this->assertSame('age_decay_no_value_proof', $r['recommendations'][0]['reason']);
    }

    public function test_aged_task_with_value_proof_not_retired_for_age_alone(): void
    {
        $r = $this->monitor()->monitor([
            'tasks'        => [$this->task(['queued_at_days_ago' => 35, 'has_value_proof' => true])],
            'max_age_days' => 30,
        ]);
        $this->assertNotContains('t1', $r['retire_candidates']);
    }

    // ── Priority 3: both context signals + no proof → retire ─────────────────

    public function test_both_context_shifts_no_proof_is_retire(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [$this->task([
            'prerequisites_changed' => true,
            'landscape_shifted'     => true,
            'has_value_proof'       => false,
        ])]]);
        $this->assertContains('t1', $r['retire_candidates']);
    }

    // ── Priority 4: single context shift → respec ─────────────────────────────

    public function test_prerequisites_changed_triggers_respec(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [$this->task([
            'prerequisites_changed' => true,
        ])]]);
        $this->assertContains('t1', $r['respec_candidates']);
        $this->assertSame('context_shift_requires_rethink', $r['recommendations'][0]['reason']);
    }

    public function test_landscape_shifted_triggers_respec(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [$this->task([
            'landscape_shifted' => true,
        ])]]);
        $this->assertContains('t1', $r['respec_candidates']);
    }

    // ── decay_signals ─────────────────────────────────────────────────────────

    public function test_decay_signals_reported_per_task(): void
    {
        $r = $this->monitor()->monitor([
            'tasks'          => [$this->task([
                'queued_at_days_ago'    => 20,
                'prerequisites_changed' => true,
                'has_value_proof'       => false,
            ])],
            'stale_age_days' => 14,
        ]);
        $signals = $r['recommendations'][0]['decay_signals'];
        $this->assertContains('age_decay',         $signals);
        $this->assertContains('prerequisite_drift', $signals);
        $this->assertContains('no_value_proof',    $signals);
    }

    public function test_stable_task_has_no_decay_signals(): void
    {
        $r = $this->monitor()->monitor([
            'tasks'          => [$this->task(['queued_at_days_ago' => 5])],
            'stale_age_days' => 14,
        ]);
        $signals = $r['recommendations'][0]['decay_signals'];
        $this->assertNotContains('age_decay', $signals);
    }

    // ── monitor_summary ───────────────────────────────────────────────────────

    public function test_summary_totals_correct(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['id' => 'a']),                                              // keep
            $this->task(['id' => 'b', 'prerequisites_changed' => true]),             // respec
            $this->task(['id' => 'c', 'queued_at_days_ago' => 60, 'has_value_proof' => false]), // retire
        ], 'max_age_days' => 30]);
        $s = $r['monitor_summary'];
        $this->assertSame(3, $s['total']);
        $this->assertSame(1, $s['retire']);
        $this->assertSame(1, $s['respec']);
        $this->assertSame(1, $s['keep']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['tasks' => [
            $this->task(['id' => 'x', 'prerequisites_changed' => true]),
            $this->task(['id' => 'y', 'blocking_count' => 5]),
        ]];
        $a = $this->monitor()->monitor($facts);
        $b = $this->monitor()->monitor($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC1: new decay signals ────────────────────────────────────────────────

    public function test_stale_evidence_signal_emitted_when_evidence_age_exceeds_stale_threshold(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['stale_evidence_age' => 20]),
        ]]);

        $this->assertContains('stale_evidence', $r['recommendations'][0]['decay_signals']);
    }

    public function test_changed_scope_signal_emitted_when_changed_allowed_files(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['changed_allowed_files' => true]),
        ]]);

        $this->assertContains('changed_scope', $r['recommendations'][0]['decay_signals']);
    }

    public function test_blocked_dependency_signal_emitted(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['blocked_dependency' => true]),
        ]]);

        $this->assertContains('blocked_dependency', $r['recommendations'][0]['decay_signals']);
    }

    public function test_prerequisite_drift_high_signal_when_drift_above_threshold(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['prerequisite_drift' => 0.8]),
        ]]);

        $this->assertContains('prerequisite_drift_high', $r['recommendations'][0]['decay_signals']);
    }

    // ── AC2: respec preferred over retire when capability still valuable ──────

    public function test_respec_preferred_when_both_shifted_but_high_current_value(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task([
                'prerequisites_changed' => true,
                'landscape_shifted'     => true,
                'has_value_proof'       => false,
                'current_value_score'   => 0.8,
            ]),
        ]]);

        $rec = $r['recommendations'][0];
        $this->assertSame('respec', $rec['recommendation']);
        $this->assertSame('valuable_capability_scope_drifted_respec_preferred', $rec['reason']);
    }

    public function test_retire_still_used_when_both_shifted_and_low_value(): void
    {
        // current_value_score defaults to 0.0 → existing rule applies
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task([
                'prerequisites_changed' => true,
                'landscape_shifted'     => true,
                'has_value_proof'       => false,
            ]),
        ]]);

        $this->assertSame('retire', $r['recommendations'][0]['recommendation']);
    }

    public function test_respec_when_scope_changed_and_capability_valuable(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task([
                'changed_allowed_files' => true,
                'current_value_score'   => 0.9,
            ]),
        ]]);

        $this->assertSame('respec', $r['recommendations'][0]['recommendation']);
        $this->assertSame('scope_changed_capability_still_valuable', $r['recommendations'][0]['reason']);
    }

    public function test_blocked_dependency_gives_respec(): void
    {
        $r = $this->monitor()->monitor(['tasks' => [
            $this->task(['blocked_dependency' => true]),
        ]]);

        $this->assertSame('respec', $r['recommendations'][0]['recommendation']);
        $this->assertSame('blocked_dependency_requires_rethink', $r['recommendations'][0]['reason']);
    }
}
