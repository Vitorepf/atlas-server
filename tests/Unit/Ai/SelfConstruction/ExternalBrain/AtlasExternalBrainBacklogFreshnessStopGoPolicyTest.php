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

    // ── AC: required_evidence ─────────────────────────────────────────────────

    public function test_output_has_required_evidence_key(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts());
        $this->assertArrayHasKey('required_evidence', $r);
        $this->assertIsArray($r['required_evidence']);
    }

    public function test_pause_origination_required_evidence_names_missing_consumption_signal(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 10, 'oldest_age_p95_seconds' => 100, 'stale_threshold_seconds' => 3600],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_PAUSE_ORIGINATION, $r['decision']);
        $this->assertContains('worker_idle_prediction.observed_consumption_count', $r['required_evidence']);
    }

    public function test_drain_existing_required_evidence_names_age_and_consumption_signals(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts());

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_DRAIN_EXISTING, $r['decision']);
        $this->assertContains('queue_age_histogram.oldest_age_p95_seconds', $r['required_evidence']);
        $this->assertContains('worker_idle_prediction.observed_consumption_count', $r['required_evidence']);
    }

    // ── blocked/quarantined debt is not usable claimable depth ─────────────────

    public function test_high_blocked_debt_with_worker_floor_breach_refuses_wait(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'queue_age_histogram' => ['claimable_depth' => 0, 'oldest_age_p95_seconds' => 60, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'replenish_urgency' => ['urgency_score' => 0.1],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false],
            'backlog_composition' => ['blocked_count' => 40, 'quarantined_count' => 10],
            'worker_feed' => ['active_worker_count' => 6, 'claimable_per_active_worker' => 0.5, 'floor' => 2.0],
        ]);

        $this->assertNotSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CONSOLIDATE, $r['decision']);
        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_REPAIR_QUEUE, $r['decision']);
    }

    public function test_sufficient_worker_feed_and_fresh_backlog_allows_wait(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.0, 'give_back_rate' => 0.0],
            'queue_age_histogram' => ['claimable_depth' => 0, 'oldest_age_p95_seconds' => 60, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'replenish_urgency' => ['urgency_score' => 0.1],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false],
            'backlog_composition' => ['blocked_count' => 2, 'quarantined_count' => 0],
            'worker_feed' => ['active_worker_count' => 6, 'claimable_per_active_worker' => 5.0, 'floor' => 2.0],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CONSOLIDATE, $r['decision']);
    }

    // ── new AC: stale evidence with no downstream unlock never creates_more ──

    public function test_stale_evidence_with_no_unlock_produces_non_create_decision_with_stale_reasons(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 5, 'oldest_age_p95_seconds' => 7200, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
        ]);

        $this->assertNotSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
        $this->assertContains($r['decision'], [
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_PAUSE_ORIGINATION,
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_DRAIN_EXISTING,
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_REPAIR_QUEUE,
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CONSOLIDATE,
        ]);
        $this->assertNotEmpty($r['stale_reasons']);
        $this->assertTrue($r['refresh_required']);
    }

    // ── new AC: fresh evidence with low claimable-per-worker + bottleneck-fixing batch goes create_more ──

    public function test_fresh_evidence_low_claimable_per_worker_with_bottleneck_fixing_batch_creates_more(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 5, 'oldest_age_p95_seconds' => 7200, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'proposed_batch_leverage' => ['fixes_bottleneck' => true],
            'worker_feed' => ['active_worker_count' => 4, 'claimable_per_active_worker' => 1.0, 'floor' => 2.0],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
        $this->assertNotEmpty($r['reasons']);
        // refresh_required is false because the proposed batch already fixes the stale bottleneck —
        // stale_reasons still names the underlying staleness for transparency.
        $this->assertFalse($r['refresh_required']);
    }

    // ── new AC: blocked/quarantined debt is not usable supply; output has full field set ──

    public function test_blocked_quarantined_debt_stale_output_includes_all_required_fields(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 0, 'oldest_age_p95_seconds' => 7200, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'backlog_composition' => ['blocked_count' => 5, 'quarantined_count' => 1],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_REPAIR_QUEUE, $r['decision']);
        $this->assertNotEmpty($r['stale_reasons']);
        $this->assertTrue($r['refresh_required']);
        $this->assertNotEmpty($r['required_evidence']);
        $this->assertArrayHasKey('blocked_next_actions', $r);
        $this->assertArrayHasKey('allowed_next_actions', $r);
        $this->assertContains(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['blocked_next_actions']);
    }

    // ── AC1: high-leverage batch overrides a stale/positive-depth wait ─────────

    public function test_stale_positive_depth_with_high_leverage_batch_allows_create_more(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts([
            'proposed_batch_leverage' => ['fixes_bottleneck' => false, 'improves' => ['queue_self_healing']],
        ]));

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE, $r['decision']);
    }

    public function test_stale_positive_depth_without_high_leverage_improves_does_not_force_create_more(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts([
            'proposed_batch_leverage' => ['fixes_bottleneck' => false, 'improves' => ['unrelated_category']],
        ]));

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_DRAIN_EXISTING, $r['decision']);
    }

    // ── AC2: wait_is_not_progress reason on pure-wait decisions ────────────────

    public function test_pause_origination_includes_wait_is_not_progress_reason(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 10, 'oldest_age_p95_seconds' => 100, 'stale_threshold_seconds' => 3600],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_PAUSE_ORIGINATION, $r['decision']);
        $reasonBlob = implode('|', $r['reasons']);
        $this->assertStringContainsString('wait_is_not_progress', $reasonBlob);
    }

    public function test_consolidate_includes_wait_is_not_progress_reason(): void
    {
        $r = $this->svc()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 0, 'oldest_age_p95_seconds' => 100, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
        ]);

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CONSOLIDATE, $r['decision']);
        $reasonBlob = implode('|', $r['reasons']);
        $this->assertStringContainsString('wait_is_not_progress', $reasonBlob);
    }

    // ── AC3: sickness and blocked debt still block creation even with high-leverage batch ──

    public function test_sickness_still_blocks_creation_despite_high_leverage_batch(): void
    {
        $r = $this->svc()->decide($this->bottleneckFacts([
            'health_snapshot' => ['dry_queue' => false, 'malformed_rate' => 0.5, 'give_back_rate' => 0.0],
            'proposed_batch_leverage' => ['fixes_bottleneck' => false, 'improves' => ['task_fabric']],
        ]));

        $this->assertSame(AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_REPAIR_QUEUE, $r['decision']);
    }
}
