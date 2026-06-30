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

        foreach (['claim', 'status', 'evidence_gaps', 'proxy_signals', 'next_evidence_task'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainAutonomyClaimAuditor::SCHEMA, $r['schema']);
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
}
