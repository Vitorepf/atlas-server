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
}
