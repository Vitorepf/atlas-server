<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogFreshnessStopGoPolicy;
use Tests\TestCase;

final class AtlasExternalBrainBacklogFreshnessStopGoPolicyTest extends TestCase
{
    private function svc(): AtlasExternalBrainBacklogFreshnessStopGoPolicy
    {
        return new AtlasExternalBrainBacklogFreshnessStopGoPolicy;
    }

    private function bottleneckFacts(array $overrides = []): array
    {
        return array_merge([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'queue_age_histogram' => ['claimable_depth' => 50, 'oldest_age_p95_seconds' => 7200, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'replenish_urgency' => ['urgency_score' => 0.2],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false],
        ], $overrides);
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts());

        foreach (['decision', 'confidence', 'reasons', 'allowed_next_actions', 'blocked_next_actions'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::SCHEMA, $r['schema']);
    }

    // ── AC3: bottleneck shape → drain_existing or repair_queue, never create_more ──

    public function test_bottleneck_shape_without_batch_fix_chooses_drain_existing(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts());

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_DRAIN_EXISTING, $r['decision']);
        $this->assertNotSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
        $this->assertContains(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['blocked_next_actions']);
    }

    public function test_bottleneck_shape_with_sickness_chooses_repair_queue(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.5, 'give_back_rate' => 0.0],
        ]));

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_REPAIR_QUEUE, $r['decision']);
    }

    public function test_bottleneck_shape_with_batch_that_fixes_it_allows_create_more(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts([
            'proposed_batch_leverage' => ['fixes_bottleneck' => true],
        ]));

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
    }

    public function test_dry_queue_disables_bottleneck_shape_even_with_deep_old_backlog(): void
    {
        // dry_queue=true is contradictory with claimable_depth>0 in real systems, but per the
        // input contract dry_queue is an authoritative health signal that must short-circuit.
        $r = $this->svc()->decide($this->bottleneckFacts([
            'health_snapshot' => ['dry_queue' => true, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'replenish_urgency' => ['urgency_score' => 0.9],
        ]));

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
    }

    public function test_fresh_deep_backlog_is_not_bottleneck_shape(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts([
            'queue_age_histogram' => ['claimable_depth' => 50, 'oldest_age_p95_seconds' => 60, 'stale_threshold_seconds' => 3600],
        ]));

        $this->assertNotSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_DRAIN_EXISTING, $r['decision']);
    }

    public function test_observed_consumption_present_disables_bottleneck_shape(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts([
            'worker_idle_prediction' => ['observed_consumption_count' => 5],
        ]));

        $this->assertNotSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_DRAIN_EXISTING, $r['decision']);
    }

    // ── pause_origination: deep backlog but no consumption evidence at all ─────

    public function test_deep_backlog_with_missing_consumption_evidence_pauses_origination(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'queue_age_histogram' => ['claimable_depth' => 20, 'oldest_age_p95_seconds' => 60, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => [],
            'replenish_urgency' => ['urgency_score' => 0.2],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_PAUSE_ORIGINATION, $r['decision']);
        $this->assertContains(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['blocked_next_actions']);
    }

    // ── dry queue + high urgency → create_more ──────────────────────────────────

    public function test_dry_queue_with_high_urgency_creates_more(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => true, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'queue_age_histogram' => ['claimable_depth' => 0, 'oldest_age_p95_seconds' => 0, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'replenish_urgency' => ['urgency_score' => 0.9],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
    }

    // ── consolidate: empty backlog, zero consumption, not dry ───────────────────

    public function test_empty_backlog_zero_consumption_not_dry_consolidates(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'queue_age_histogram' => ['claimable_depth' => 0, 'oldest_age_p95_seconds' => 0, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'replenish_urgency' => ['urgency_score' => 0.1],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CONSOLIDATE, $r['decision']);
    }

    // ── healthy origination ──────────────────────────────────────────────────────

    public function test_healthy_conditions_with_active_consumption_create_more(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'queue_age_histogram' => ['claimable_depth' => 3, 'oldest_age_p95_seconds' => 60, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 5],
            'replenish_urgency' => ['urgency_score' => 0.3],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
    }

    // ── allowed/blocked next actions are mutually exclusive and complete ───────

    public function test_allowed_and_blocked_actions_partition_all_five_decisions(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts());

        $union = array_merge($r['allowed_next_actions'], $r['blocked_next_actions']);
        sort($union, SORT_STRING);
        $all = [
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE,
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_PAUSE_ORIGINATION,
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_DRAIN_EXISTING,
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_REPAIR_QUEUE,
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CONSOLIDATE,
        ];
        sort($all, SORT_STRING);

        $this->assertSame($all, $union);
        $this->assertSame([], array_intersect($r['allowed_next_actions'], $r['blocked_next_actions']));
    }

    // ── AC4: pure, deterministic, no side effects ────────────────────────────────

    public function test_decide_does_not_touch_the_filesystem(): void
    {
        $dir = sys_get_temp_dir();
        $countBefore = count(scandir($dir) ?: []);

        $this->svc()->decide($this->bottleneckFacts());

        $countAfter = count(scandir($dir) ?: []);
        $this->assertSame($countBefore, $countAfter);
    }

    public function test_decide_is_deterministic(): void
    {
        $facts = $this->bottleneckFacts();

        $a = $this->svc()->decide($facts);
        $b = $this->svc()->decide($facts);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    public function test_confidence_is_a_float_between_zero_and_one(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts());

        $this->assertIsFloat($r['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $r['confidence']);
        $this->assertLessThanOrEqual(1.0, $r['confidence']);
    }
}
