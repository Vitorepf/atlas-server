<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAgeAwareAdmissionPenalty;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricAgeAwareAdmissionPenaltyTest extends TestCase
{
    private function gate(): AtlasTaskFabricAgeAwareAdmissionPenalty
    {
        return new AtlasTaskFabricAgeAwareAdmissionPenalty;
    }

    private function batch(array $overrides = []): array
    {
        return array_merge([
            'batch_id' => 'b-1',
            'leverage_score' => 5.0,
        ], $overrides);
    }

    private function queueFacts(array $overrides = []): array
    {
        return array_merge([
            'queue_age' => ['p95_age_hours' => 5.0],
            'claimable_depth' => 10,
            'servable_now' => 8,
            'worker_consumption' => ['active_workers' => 3],
        ], $overrides);
    }

    private function deepStale(array $overrides = []): array
    {
        return $this->queueFacts(array_merge([
            'queue_age' => ['p95_age_hours' => 48.0],
            'claimable_depth' => 60,
        ], $overrides));
    }

    // ── AC: output shape ───────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->gate()->evaluate($this->batch(), $this->queueFacts());

        foreach (['decision', 'penalty_score', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }

    // ── AC: backlog not deep/stale → admit, no penalty ────────────────────────

    public function test_shallow_fresh_queue_admits_with_no_penalty(): void
    {
        $r = $this->gate()->evaluate($this->batch(), $this->queueFacts());

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
        $this->assertSame(0.0, $r['penalty_score']);
    }

    // ── AC: batch directly repairs stale conditions → always admit ───────────

    public function test_batch_repairing_queue_health_always_admits_despite_deep_stale_backlog(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['repairs_queue_health' => true, 'leverage_score' => 0.0]),
            $this->deepStale(),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
        $this->assertSame(0.0, $r['penalty_score']);
    }

    public function test_batch_repairing_blocked_backlog_always_admits(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['repairs_blocked_backlog' => true, 'leverage_score' => 0.0]),
            $this->deepStale(),
        );
        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
    }

    public function test_batch_repairing_lease_parity_always_admits(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['repairs_lease_parity' => true, 'leverage_score' => 0.0]),
            $this->deepStale(),
        );
        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
    }

    public function test_batch_repairing_stale_drain_routing_always_admits(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['repairs_stale_drain' => true, 'leverage_score' => 0.0]),
            $this->deepStale(),
        );
        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
    }

    // ── AC: medium-value batches deferred when backlog deep+stale ────────────

    public function test_medium_leverage_batch_deferred_during_deep_stale_backlog(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 5.0]),
            $this->deepStale(),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_DEFER, $r['decision']);
        $this->assertGreaterThan(0.0, $r['penalty_score']);
    }

    // ── high leverage → admit_with_penalty ────────────────────────────────────

    public function test_high_leverage_batch_admitted_with_penalty_during_deep_stale_backlog(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0]),
            $this->deepStale(),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT_WITH_PENALTY, $r['decision']);
    }

    public function test_high_leverage_batch_under_saturation_names_claimable_per_active_worker_pressure(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0]),
            $this->deepStale(['worker_consumption' => ['active_workers' => 3]]), // 60/3 = 20 >= threshold
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT_WITH_PENALTY, $r['decision']);
        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'claimable_per_active_worker')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected a reason naming claimable_per_active_worker pressure');
    }

    public function test_high_leverage_batch_under_saturation_names_worker_drain_slope_pressure(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0]),
            $this->deepStale(['worker_consumption' => ['active_workers' => 20, 'drain_slope' => 0.1]]),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT_WITH_PENALTY, $r['decision']);
        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'worker_drain_slope')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected a reason naming worker_drain_slope pressure');
    }

    public function test_no_saturation_signal_when_workers_are_ample(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0]),
            $this->deepStale(['worker_consumption' => ['active_workers' => 60, 'drain_slope' => 0.9]]),
        );

        foreach ($r['reasons'] as $reason) {
            $this->assertStringNotContainsString('saturation', $reason);
        }
    }

    // ── low leverage → reject_padding ─────────────────────────────────────────

    public function test_low_leverage_batch_rejected_as_padding_during_deep_stale_backlog(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 1.0]),
            $this->deepStale(),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_REJECT_PADDING, $r['decision']);
        $this->assertGreaterThan(0.0, $r['penalty_score']);
    }

    // ── boundary: deep backlog alone (not stale) does not trigger penalty ────

    public function test_deep_but_fresh_backlog_does_not_trigger_penalty(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 1.0]),
            $this->queueFacts(['claimable_depth' => 60, 'queue_age' => ['p95_age_hours' => 1.0]]),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
    }

    public function test_stale_but_shallow_backlog_does_not_trigger_penalty(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 1.0]),
            $this->queueFacts(['claimable_depth' => 5, 'queue_age' => ['p95_age_hours' => 100.0]]),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
    }

    // ── purity ──────────────────────────────────────────────────────────────

    public function test_gate_source_has_no_io_calls(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricAgeAwareAdmissionPenalty.php');
        foreach (['DB::', 'Http::', 'file_put_contents', 'exec(', 'shell_exec', 'Process::', 'Artisan::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "gate must not call {$forbidden}");
        }
    }

    public function test_evaluate_is_deterministic(): void
    {
        $batch = $this->batch();
        $facts = $this->deepStale();
        $a = $this->gate()->evaluate($batch, $facts);
        $b = $this->gate()->evaluate($batch, $facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC: required_refresh_action always present ──────────────────────────

    public function test_output_has_required_refresh_action_key(): void
    {
        $r = $this->gate()->evaluate($this->batch(), $this->queueFacts());

        $this->assertArrayHasKey('required_refresh_action', $r);
        $this->assertIsString($r['required_refresh_action']);
    }

    public function test_required_refresh_action_is_none_for_clean_admit(): void
    {
        $r = $this->gate()->evaluate($this->batch(), $this->queueFacts());

        $this->assertSame('none', $r['required_refresh_action']);
    }

    public function test_required_refresh_action_is_none_when_repairs_stale_backlog(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['repairs_queue_health' => true]),
            $this->deepStale(),
        );

        $this->assertSame('none', $r['required_refresh_action']);
    }

    // ── AC: stale context penalizes ─────────────────────────────────────────

    public function test_stale_context_penalizes_batch(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'context_age_hours' => 72.0]),
            $this->queueFacts(),
        );

        $this->assertGreaterThan(0.0, $r['penalty_score']);
        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'stale_context')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected stale_context reason');
        $this->assertSame('refresh_context_before_admission', $r['required_refresh_action']);
    }

    public function test_old_queued_siblings_penalizes_batch(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'queued_sibling_count' => 10]),
            $this->queueFacts(),
        );

        $this->assertGreaterThan(0.0, $r['penalty_score']);
        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'old_queued_siblings')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected old_queued_siblings reason');
        $this->assertSame('drain_or_consolidate_queued_siblings', $r['required_refresh_action']);
    }

    public function test_repeated_requeues_penalizes_batch(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'requeue_count' => 5]),
            $this->queueFacts(),
        );

        $this->assertGreaterThan(0.0, $r['penalty_score']);
        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'repeated_requeues')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected repeated_requeues reason');
        $this->assertSame('resolve_root_cause_of_repeated_requeues', $r['required_refresh_action']);
    }

    public function test_stale_evidence_penalizes_batch(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'evidence_age_hours' => 96.0]),
            $this->queueFacts(),
        );

        $this->assertGreaterThan(0.0, $r['penalty_score']);
        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'stale_evidence')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected stale_evidence reason');
        $this->assertSame('refresh_evidence_before_admission', $r['required_refresh_action']);
    }

    public function test_low_claimable_per_worker_during_deep_stale_names_saturation(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0]),
            $this->deepStale(['worker_consumption' => ['active_workers' => 1]]), // 60/1 = 60
        );

        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'claimable_per_active_worker')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected claimable_per_active_worker reason');
        $this->assertSame('add_workers_or_reduce_backlog_depth', $r['required_refresh_action']);
    }

    // ── AC: revalidation bypass — old but revalidated candidates not penalized ──

    public function test_freshly_revalidated_high_leverage_candidate_not_penalized_for_age(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch([
                'leverage_score' => 9.0,
                'context_age_hours' => 100.0,
                'evidence_age_hours' => 100.0,
                'queued_sibling_count' => 10,
                'requeue_count' => 5,
                'freshly_revalidated' => true,
                'has_current_proof' => true,
                'has_collision' => false,
            ]),
            $this->queueFacts(),
        );

        // age factors bypassed → no penalty, clean admit
        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT, $r['decision']);
        $this->assertSame(0.0, $r['penalty_score']);
        $this->assertSame('none', $r['required_refresh_action']);
    }

    public function test_revalidation_bypass_requires_current_proof(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch([
                'leverage_score' => 9.0,
                'context_age_hours' => 100.0,
                'freshly_revalidated' => true,
                'has_current_proof' => false,  // missing proof → bypass does NOT apply
                'has_collision' => false,
            ]),
            $this->queueFacts(),
        );

        // no current proof → bypass does not apply → age penalty remains
        $this->assertGreaterThan(0.0, $r['penalty_score']);
        $this->assertNotSame('none', $r['required_refresh_action']);
    }

    public function test_revalidation_bypass_requires_no_collision(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch([
                'leverage_score' => 9.0,
                'context_age_hours' => 100.0,
                'freshly_revalidated' => true,
                'has_current_proof' => true,
                'has_collision' => true,  // collision → bypass does NOT apply
            ]),
            $this->queueFacts(),
        );

        $this->assertGreaterThan(0.0, $r['penalty_score']);
        $this->assertNotSame('none', $r['required_refresh_action']);
    }

    public function test_revalidation_bypass_does_not_suppress_saturation(): void
    {
        // Even with revalidation bypass, deep+stale backlog + saturation still applies
        $r = $this->gate()->evaluate(
            $this->batch([
                'leverage_score' => 9.0,
                'context_age_hours' => 100.0,
                'freshly_revalidated' => true,
                'has_current_proof' => true,
                'has_collision' => false,
            ]),
            $this->deepStale(['worker_consumption' => ['active_workers' => 3]]), // 60/3 = 20 >= threshold
        );

        // Saturation reason present, penalty applied, refresh action is worker capacity
        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT_WITH_PENALTY, $r['decision']);
        $found = false;
        foreach ($r['reasons'] as $reason) {
            if (str_contains($reason, 'claimable_per_active_worker')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'saturation should survive revalidation bypass');
        $this->assertSame('add_workers_or_reduce_backlog_depth', $r['required_refresh_action']);
    }

    // ── AC: age factors without deep+stale backlog still carry penalty ──────

    public function test_stale_context_alone_admits_with_penalty(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'context_age_hours' => 72.0]),
            $this->queueFacts(), // not deep+stale
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT_WITH_PENALTY, $r['decision']);
        $this->assertGreaterThan(0.0, $r['penalty_score']);
    }

    public function test_age_factors_do_not_change_decision_leverage_thresholds(): void
    {
        // Age factors don't push a high-leverage batch below admit_with_penalty
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'context_age_hours' => 72.0, 'requeue_count' => 5]),
            $this->deepStale(),
        );

        $this->assertSame(AtlasTaskFabricAgeAwareAdmissionPenalty::DECISION_ADMIT_WITH_PENALTY, $r['decision']);
    }

    // ── AC: threshold boundaries ─────────────────────────────────────────────

    public function test_context_age_at_threshold_does_not_trigger_stale_context(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'context_age_hours' => 48.0]), // == threshold, not >
            $this->queueFacts(),
        );

        $this->assertSame(0.0, $r['penalty_score']);
        foreach ($r['reasons'] as $reason) {
            $this->assertStringNotContainsString('stale_context', $reason);
        }
    }

    public function test_zero_requeues_does_not_trigger_repeated_requeues(): void
    {
        $r = $this->gate()->evaluate(
            $this->batch(['leverage_score' => 9.0, 'requeue_count' => 0]),
            $this->queueFacts(),
        );

        foreach ($r['reasons'] as $reason) {
            $this->assertStringNotContainsString('repeated_requeues', $reason);
        }
    }
}
