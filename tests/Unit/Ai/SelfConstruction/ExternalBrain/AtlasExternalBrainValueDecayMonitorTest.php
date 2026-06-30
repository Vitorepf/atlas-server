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
}
