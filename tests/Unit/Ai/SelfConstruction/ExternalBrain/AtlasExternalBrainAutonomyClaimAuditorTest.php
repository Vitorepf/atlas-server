<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyClaimAuditor;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyClaimAuditorTest extends TestCase
{
    private function svc(): AtlasExternalBrainAutonomyClaimAuditor
    {
        return new AtlasExternalBrainAutonomyClaimAuditor;
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->audit(['claim' => 'queue healthy']);

        foreach (['claim', 'status', 'confidence', 'evidence_gaps', 'proxy_signals',
                  'accepted_evidence_kinds', 'rejected_proxy_kinds', 'evidence_freshness', 'next_evidence_task'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::SCHEMA, $r['schema']);
    }

    public function test_proven_status_has_full_confidence_and_two_accepted_kinds(): void
    {
        $r = $this->svc()->audit([
            'claim' => '24/7 autonomous operation',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
        $this->assertSame(1.0, $r['confidence']);
        $this->assertCount(2, $r['accepted_evidence_kinds']);
    }

    public function test_queue_count_and_task_count_are_rejected_proxy_kinds(): void
    {
        $r = $this->svc()->audit([
            'claim' => '95 percent readiness',
            'proxy_signals' => ['queue_count', 'task_count'],
        ]);

        $this->assertContains('queue_count', $r['rejected_proxy_kinds']);
        $this->assertContains('task_count', $r['rejected_proxy_kinds']);
        $this->assertNotSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
    }

    public function test_evidence_freshness_is_stale_beyond_threshold(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'model amplifier quality',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'evidence_age_seconds' => 700000,
        ]);

        $this->assertSame('stale', $r['evidence_freshness']);
        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_STALE, $r['status']);
    }

    public function test_evidence_freshness_unknown_when_no_age_supplied(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'queue healthy',
            'evidence_refs' => ['runnable_end_to_end_replay'],
        ]);

        $this->assertSame('unknown', $r['evidence_freshness']);
    }

    public function test_claim_text_is_echoed_back(): void
    {
        $r = $this->svc()->audit(['claim' => '95 percent complete']);

        $this->assertSame('95 percent complete', $r['claim']);
    }

    // ── AC1: claim backed only by queue_count → proxy, not proven ──────────────

    public function test_claim_backed_only_by_queue_count_is_classified_proxy(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'queue healthy',
            'proxy_signals' => ['queue_count'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROXY, $r['status']);
        $this->assertNotSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
    }

    public function test_green_self_report_only_is_classified_proxy(): void
    {
        $r = $this->svc()->audit([
            'claim' => '95 percent complete',
            'proxy_signals' => ['green_self_report'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROXY, $r['status']);
    }

    public function test_novelty_only_is_classified_proxy(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'model amplifier works',
            'proxy_signals' => ['novelty'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROXY, $r['status']);
    }

    public function test_proxy_signals_are_present_in_output(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'queue healthy',
            'proxy_signals' => ['queue_count', 'task_count'],
        ]);

        $this->assertSame(['queue_count', 'task_count'], $r['proxy_signals']);
    }

    // ── AC2: runnable end-to-end replay + fresh outcome learning → proven ──────

    public function test_runnable_replay_and_fresh_outcome_learning_is_proven(): void
    {
        $r = $this->svc()->audit([
            'claim' => '24/7 autonomous',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
        $this->assertSame([], $r['evidence_gaps']);
        $this->assertNull($r['next_evidence_task']);
    }

    public function test_server_side_test_receipt_and_live_metric_snapshot_is_proven(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'queue healthy',
            'evidence_refs' => ['server_side_test_receipt', 'live_metric_snapshot'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
    }

    public function test_single_server_side_test_receipt_is_only_partial(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'queue healthy',
            'evidence_refs' => ['server_side_test_receipt'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PARTIAL, $r['status']);
    }

    public function test_single_strong_evidence_kind_is_only_partial(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'model amplifier works',
            'evidence_refs' => ['runnable_end_to_end_replay'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PARTIAL, $r['status']);
        $this->assertNotNull($r['next_evidence_task']);
    }

    // ── AC3: stale or missing evidence → next_evidence_task recommendation ─────

    public function test_stale_evidence_produces_next_evidence_task(): void
    {
        $r = $this->svc()->audit([
            'claim' => '95 percent complete',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'evidence_age_seconds' => 999999999,
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_STALE, $r['status']);
        $this->assertNotNull($r['next_evidence_task']);
        $this->assertNotEmpty($r['evidence_gaps']);
    }

    public function test_missing_evidence_produces_next_evidence_task(): void
    {
        $r = $this->svc()->audit(['claim' => '24/7 autonomous']);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_UNSUPPORTED, $r['status']);
        $this->assertNotNull($r['next_evidence_task']);
        $this->assertStringContainsString('24/7 autonomous', $r['next_evidence_task']);
    }

    public function test_fresh_evidence_within_threshold_is_not_stale(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'model amplifier works',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'evidence_age_seconds' => 60,
        ]);

        $this->assertNotSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_STALE, $r['status']);
        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
    }

    // ── proxy + strong evidence together is NOT proxy (real evidence wins) ─────

    public function test_strong_evidence_present_with_proxy_signals_is_not_classified_proxy(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'queue healthy',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'proxy_signals' => ['queue_count'],
        ]);

        $this->assertNotSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROXY, $r['status']);
    }

    // ── completely empty input ──────────────────────────────────────────────────

    public function test_no_evidence_and_no_proxy_signals_is_unsupported(): void
    {
        $r = $this->svc()->audit(['claim' => 'something is true']);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_UNSUPPORTED, $r['status']);
        $this->assertContains('no_evidence_refs_provided', $r['evidence_gaps']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_audit_is_deterministic(): void
    {
        $claim = [
            'claim' => 'queue healthy',
            'evidence_refs' => ['runnable_end_to_end_replay'],
            'proxy_signals' => ['queue_count'],
        ];

        $a = $this->svc()->audit($claim);
        $b = $this->svc()->audit($claim);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    public function test_partial_claim_next_proof_chain_lists_all_missing_kinds(): void
    {
        $result = $this->svc()->audit([
            'claim' => 'atlas is 95% autonomous',
            'evidence_refs' => ['runnable_end_to_end_replay'],
        ]);

        $this->assertSame('partial', $result['status']);
        $this->assertCount(3, $result['next_proof_chain']);
        $this->assertSame(['fresh_outcome_learning', 'server_side_test_receipt', 'live_metric_snapshot'], $result['missing_strong_evidence_kinds']);
        $this->assertSame('fresh_outcome_learning', $result['proof_priority']);
    }

    public function test_proven_claim_has_empty_proof_chain_and_null_priority(): void
    {
        $result = $this->svc()->audit([
            'claim' => 'atlas is 95% autonomous',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
        ]);

        $this->assertSame([], $result['next_proof_chain']);
        $this->assertNull($result['proof_priority']);
    }

    public function test_proxy_claim_has_proxy_only_reasons(): void
    {
        $result = $this->svc()->audit([
            'claim' => 'queue is healthy',
            'proxy_signals' => ['queue_count', 'task_count'],
        ]);

        $this->assertSame('proxy', $result['status']);
        $this->assertNotEmpty($result['proxy_only_reasons']);
        $this->assertContains('proxy_only_signal:queue_count', $result['proxy_only_reasons']);
    }

    public function test_proxy_claim_never_upgrades_to_partial_or_proven(): void
    {
        foreach (['queue_count', 'task_count', 'novelty', 'green_self_report'] as $signal) {
            $result = $this->svc()->audit(['claim' => 'x', 'proxy_signals' => [$signal]]);
            $this->assertSame('proxy', $result['status']);
        }
    }

    // ── AC2/AC3/AC4: absolute autonomy claims require queue health + soak duration + fresh evidence ──

    public function test_hundred_percent_autonomous_claim_with_only_generic_strong_evidence_is_overclaim(): void
    {
        $r = $this->svc()->audit([
            'claim' => '100 percent autonomous',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_OVERCLAIM, $r['status']);
        $this->assertNotSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
        $this->assertSame(0.0, $r['confidence']);
        $this->assertTrue($r['is_strong_claim']);
        $this->assertNotEmpty($r['evidence_gaps']);
        $this->assertNotNull($r['next_evidence_task']);
        $this->assertNotEmpty($r['next_proof_chain']);
    }

    public function test_no_human_dependency_claim_requires_soak_and_queue_health(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'no human dependency',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'queue_health_evidence' => true,
            'evidence_age_seconds' => 60,
            // soak_duration_seconds omitted → below minimum
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_OVERCLAIM, $r['status']);
        $this->assertStringContainsString('soak_duration_seconds', implode(' ', $r['evidence_gaps']));
    }

    public function test_final_brain_readiness_claim_requires_queue_health_evidence(): void
    {
        $r = $this->svc()->audit([
            'claim' => 'final brain readiness',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'evidence_age_seconds' => 60,
            'soak_duration_seconds' => 90000,
            // queue_health_evidence omitted
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_OVERCLAIM, $r['status']);
        $this->assertContains('queue_health_evidence_missing', $r['evidence_gaps']);
    }

    public function test_strong_claim_fully_backed_by_soak_and_queue_health_and_fresh_evidence_is_proven(): void
    {
        $r = $this->svc()->audit([
            'claim' => '100 percent autonomous',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'evidence_age_seconds' => 60,
            'queue_health_evidence' => true,
            'soak_duration_seconds' => 90000,
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
        $this->assertSame(1.0, $r['confidence']);
        $this->assertTrue($r['is_strong_claim']);
    }

    public function test_ordinary_claim_mentioning_autonomous_is_not_treated_as_strong_claim(): void
    {
        $r = $this->svc()->audit([
            'claim' => '24/7 autonomous operation',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
        ]);

        $this->assertFalse($r['is_strong_claim']);
        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_PROVEN, $r['status']);
    }

    public function test_overclaim_status_never_produced_for_unsupported_or_stale_strong_claims(): void
    {
        // No evidence at all — stays unsupported, not "upgraded" into overclaim.
        $unsupported = $this->svc()->audit(['claim' => '100 percent autonomous']);
        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_UNSUPPORTED, $unsupported['status']);

        // Stale strong evidence — stays stale, not overclaim.
        $stale = $this->svc()->audit([
            'claim' => '100 percent autonomous',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'evidence_age_seconds' => 999999999,
        ]);
        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_STALE, $stale['status']);
    }

    // ── AC4: stale evidence refresh in proof chain ───────────────────────────

    public function test_stale_evidence_proof_chain_includes_refresh_task(): void
    {
        $r = $this->svc()->audit([
            'claim' => '95 percent complete',
            'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning'],
            'evidence_age_seconds' => 999999999,
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_STALE, $r['status']);
        $this->assertStringContainsString('Refresh stale evidence', $r['next_evidence_task']);
        $this->assertStringContainsString('Refresh stale evidence', $r['next_proof_chain'][0]);
        $this->assertSame('refresh_stale_evidence', $r['proof_priority']);
    }

    public function test_stale_evidence_with_missing_kinds_has_both_refresh_and_evidence_tasks(): void
    {
        // Only one strong evidence kind + stale
        $r = $this->svc()->audit([
            'claim' => 'queue healthy',
            'evidence_refs' => ['runnable_end_to_end_replay'],
            'evidence_age_seconds' => 999999999,
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::STATUS_STALE, $r['status']);
        $this->assertStringContainsString('Refresh stale evidence', $r['next_proof_chain'][0]);
        // Should also mention the missing strong evidence kind
        $this->assertStringContainsString('fresh_outcome_learning', implode(' ', $r['next_proof_chain']));
    }
}
