<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvidenceFreshnessRuntimeBridge;
use Tests\TestCase;

final class AtlasExternalBrainEvidenceFreshnessRuntimeBridgeTest extends TestCase
{
    private function svc(): AtlasExternalBrainEvidenceFreshnessRuntimeBridge
    {
        return new AtlasExternalBrainEvidenceFreshnessRuntimeBridge;
    }

    private function item(string $timestamp = '2026-06-30T10:00:00Z'): array
    {
        return ['timestamp' => $timestamp];
    }

    // ── fresh=false ───────────────────────────────────────────────────────────

    public function test_no_channels_gives_fresh_false_with_all_stale_reasons(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertFalse($r['fresh']);
        $this->assertContains('no_recent_commits', $r['stale_or_missing_evidence']);
        $this->assertContains('no_recent_worker_reports', $r['stale_or_missing_evidence']);
        $this->assertContains('no_recent_evidence_intake', $r['stale_or_missing_evidence']);
        $this->assertSame([], $r['fresh_channels']);
    }

    public function test_one_channel_only_gives_fresh_false(): void
    {
        $r = $this->svc()->assess([
            'commits' => [$this->item()],
        ]);

        $this->assertFalse($r['fresh']);
        $this->assertContains('no_recent_worker_reports', $r['stale_or_missing_evidence']);
        $this->assertContains('no_recent_evidence_intake', $r['stale_or_missing_evidence']);
        $this->assertNotContains('no_recent_commits', $r['stale_or_missing_evidence']);
    }

    // ── fresh=true ────────────────────────────────────────────────────────────

    public function test_two_independent_channels_gives_fresh_true(): void
    {
        $r = $this->svc()->assess([
            'commits' => [$this->item()],
            'worker_reports' => [$this->item()],
        ]);

        $this->assertTrue($r['fresh']);
        $this->assertContains('commits', $r['fresh_channels']);
        $this->assertContains('worker_reports', $r['fresh_channels']);
    }

    public function test_all_three_channels_gives_fresh_true_no_stale(): void
    {
        $r = $this->svc()->assess([
            'commits' => [$this->item()],
            'worker_reports' => [$this->item()],
            'evidence_intake' => [$this->item()],
        ]);

        $this->assertTrue($r['fresh']);
        $this->assertSame([], $r['stale_or_missing_evidence']);
        $this->assertCount(3, $r['fresh_channels']);
    }

    // ── source_counts ─────────────────────────────────────────────────────────

    public function test_source_counts_reflect_actual_counts(): void
    {
        $r = $this->svc()->assess([
            'commits' => [$this->item(), $this->item()],
            'worker_reports' => [$this->item()],
        ]);

        $this->assertSame(2, $r['source_counts']['commits']);
        $this->assertSame(1, $r['source_counts']['worker_reports']);
        $this->assertSame(0, $r['source_counts']['evidence_intake']);
    }

    // ── newest_seen_at ────────────────────────────────────────────────────────

    public function test_newest_seen_at_is_max_timestamp(): void
    {
        $r = $this->svc()->assess([
            'commits' => [['timestamp' => '2026-06-29T08:00:00Z']],
            'worker_reports' => [['timestamp' => '2026-06-30T14:00:00Z']],
            'evidence_intake' => [['timestamp' => '2026-06-28T12:00:00Z']],
        ]);

        $this->assertSame('2026-06-30T14:00:00Z', $r['newest_seen_at']);
    }

    public function test_newest_seen_at_null_when_no_timestamps(): void
    {
        $r = $this->svc()->assess([
            'commits' => [['sha' => 'abc']],
        ]);

        $this->assertNull($r['newest_seen_at']);
    }

    public function test_created_at_field_also_used_for_timestamp(): void
    {
        $r = $this->svc()->assess([
            'commits' => [['created_at' => '2026-06-30T12:00:00Z']],
            'worker_reports' => [['created_at' => '2026-06-30T10:00:00Z']],
        ]);

        $this->assertSame('2026-06-30T12:00:00Z', $r['newest_seen_at']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_always_present(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertSame(AtlasExternalBrainEvidenceFreshnessRuntimeBridge::SCHEMA, $r['schema_version']);
    }

    // ── AC3: oldest_seen_at ───────────────────────────────────────────────────

    public function test_oldest_seen_at_is_min_timestamp(): void
    {
        $r = $this->svc()->assess([
            'commits'         => [['timestamp' => '2026-06-29T08:00:00Z']],
            'worker_reports'  => [['timestamp' => '2026-06-30T14:00:00Z']],
            'evidence_intake' => [['timestamp' => '2026-06-28T12:00:00Z']],
        ]);

        $this->assertSame('2026-06-28T12:00:00Z', $r['oldest_seen_at']);
    }

    public function test_oldest_seen_at_null_when_no_timestamps(): void
    {
        $r = $this->svc()->assess([
            'commits' => [['sha' => 'abc']],
        ]);

        $this->assertNull($r['oldest_seen_at']);
    }

    public function test_oldest_and_newest_same_when_one_item(): void
    {
        $r = $this->svc()->assess([
            'commits'        => [['timestamp' => '2026-06-30T10:00:00Z']],
            'worker_reports' => [['timestamp' => '2026-06-30T10:00:00Z']],
        ]);

        $this->assertSame($r['newest_seen_at'], $r['oldest_seen_at']);
    }

    // ── AC2: configurable min_fresh_channels ──────────────────────────────────

    public function test_min_fresh_channels_1_makes_single_channel_fresh(): void
    {
        $r = $this->svc()->assess([
            'commits'           => [$this->item()],
            'min_fresh_channels' => 1,
        ]);

        $this->assertTrue($r['fresh']);
    }

    public function test_min_fresh_channels_3_requires_all_three(): void
    {
        $r = $this->svc()->assess([
            'commits'           => [$this->item()],
            'worker_reports'    => [$this->item()],
            'min_fresh_channels' => 3,
        ]);

        $this->assertFalse($r['fresh']); // only 2 channels; need 3
    }

    // ── AC2: required_channels ────────────────────────────────────────────────

    public function test_required_channel_missing_forces_fresh_false(): void
    {
        $r = $this->svc()->assess([
            'commits'           => [$this->item()],
            'worker_reports'    => [$this->item()],
            'required_channels' => ['evidence_intake'], // not provided → stale
        ]);

        $this->assertFalse($r['fresh']);
    }

    public function test_all_required_channels_fresh_allows_fresh_true(): void
    {
        $r = $this->svc()->assess([
            'commits'           => [$this->item()],
            'worker_reports'    => [$this->item()],
            'required_channels' => ['commits', 'worker_reports'],
        ]);

        $this->assertTrue($r['fresh']);
    }

    // ── AC4: stale channel by timestamp age ──────────────────────────────────

    public function test_age_stale_channel_removed_from_fresh_channels(): void
    {
        $r = $this->svc()->assess([
            'commits'         => [['timestamp' => '2026-06-01T00:00:00Z']], // old
            'worker_reports'  => [$this->item('2026-06-30T10:00:00Z')],     // recent
            'max_age_seconds' => 86400,                                      // 1 day
            'now_iso'         => '2026-06-30T10:00:00Z',
        ]);

        $this->assertNotContains('commits', $r['fresh_channels']);
        $this->assertContains('stale_commits', $r['stale_or_missing_evidence']);
    }

    public function test_age_stale_channel_can_cause_fresh_false(): void
    {
        // 2 channels but one is age-stale → only 1 truly fresh → below default min of 2.
        $r = $this->svc()->assess([
            'commits'         => [['timestamp' => '2026-06-01T00:00:00Z']], // age-stale
            'worker_reports'  => [$this->item('2026-06-30T10:00:00Z')],
            'max_age_seconds' => 86400,
            'now_iso'         => '2026-06-30T10:00:00Z',
        ]);

        $this->assertFalse($r['fresh']);
    }

    public function test_without_max_age_seconds_age_check_skipped(): void
    {
        // Old timestamp, but no max_age_seconds → channel still counts as fresh.
        $r = $this->svc()->assess([
            'commits'        => [['timestamp' => '2020-01-01T00:00:00Z']],
            'worker_reports' => [$this->item()],
        ]);

        $this->assertContains('commits', $r['fresh_channels']);
    }

    // ── AC5: per-channel classification + admission blocking ──────────────────

    public function test_fully_fresh_critical_channels_are_not_blocked(): void
    {
        $r = $this->svc()->assess([
            'commits'         => [$this->item()],
            'worker_reports'  => [$this->item()],
            'evidence_intake' => [$this->item()],
        ]);

        $this->assertSame('fresh', $r['freshness_status']);
        $this->assertFalse($r['admission_blocked']);
        $this->assertNull($r['blocking_reason']);
        $this->assertNull($r['refresh_hint']);
        $this->assertSame('fresh', $r['channel_classifications']['commits']);
    }

    public function test_missing_critical_channel_blocks_admission(): void
    {
        $r = $this->svc()->assess([
            'worker_reports' => [$this->item()],
        ]);

        $this->assertSame('missing', $r['channel_classifications']['commits']);
        $this->assertSame('missing', $r['freshness_status']);
        $this->assertTrue($r['admission_blocked']);
        $this->assertSame('critical_evidence_commits_missing', $r['blocking_reason']);
        $this->assertSame('collect_runtime_evidence_for_commits', $r['refresh_hint']);
    }

    public function test_self_declared_without_runtime_verification_blocks_admission(): void
    {
        $r = $this->svc()->assess([
            'commits' => [['timestamp' => '2026-06-30T10:00:00Z', 'source_type' => 'self_declared', 'verified_by_runtime' => false]],
            'worker_reports' => [$this->item()],
        ]);

        $this->assertSame('self_declared', $r['channel_classifications']['commits']);
        $this->assertSame('self_declared', $r['freshness_status']);
        $this->assertTrue($r['admission_blocked']);
        $this->assertSame('critical_evidence_commits_self_declared', $r['blocking_reason']);
        $this->assertSame('verify_commits_with_runtime_confirmation_not_self_report', $r['refresh_hint']);
    }

    public function test_self_declared_with_runtime_verification_is_fresh(): void
    {
        $r = $this->svc()->assess([
            'commits' => [['timestamp' => '2026-06-30T10:00:00Z', 'source_type' => 'self_declared', 'verified_by_runtime' => true]],
            'worker_reports' => [$this->item()],
        ]);

        $this->assertSame('fresh', $r['channel_classifications']['commits']);
        $this->assertFalse($r['admission_blocked']);
    }

    public function test_stale_critical_channel_blocks_admission(): void
    {
        $r = $this->svc()->assess([
            'commits'         => [['timestamp' => '2026-06-01T00:00:00Z']],
            'worker_reports'  => [$this->item('2026-06-30T10:00:00Z')],
            'max_age_seconds' => 86400,
            'now_iso'         => '2026-06-30T10:00:00Z',
        ]);

        $this->assertSame('stale', $r['channel_classifications']['commits']);
        $this->assertSame('stale', $r['freshness_status']);
        $this->assertTrue($r['admission_blocked']);
        $this->assertSame('critical_evidence_commits_stale', $r['blocking_reason']);
        $this->assertSame('refresh_commits_with_current_runtime_proof', $r['refresh_hint']);
    }

    public function test_non_critical_channel_staleness_does_not_block_admission(): void
    {
        $r = $this->svc()->assess([
            'commits'         => [$this->item('2026-06-30T10:00:00Z')],
            'worker_reports'  => [$this->item('2026-06-30T10:00:00Z')],
            'evidence_intake' => [['timestamp' => '2020-01-01T00:00:00Z']],
            'max_age_seconds' => 86400,
            'now_iso'         => '2026-06-30T10:00:00Z',
        ]);

        $this->assertSame('stale', $r['channel_classifications']['evidence_intake']);
        $this->assertFalse($r['admission_blocked']);
    }

    public function test_custom_critical_channels_can_include_evidence_intake(): void
    {
        $r = $this->svc()->assess([
            'commits'           => [$this->item()],
            'worker_reports'    => [$this->item()],
            'critical_channels' => ['evidence_intake'],
        ]);

        $this->assertSame('missing', $r['channel_classifications']['evidence_intake']);
        $this->assertTrue($r['admission_blocked']);
        $this->assertSame('critical_evidence_evidence_intake_missing', $r['blocking_reason']);
    }
}
