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
}
