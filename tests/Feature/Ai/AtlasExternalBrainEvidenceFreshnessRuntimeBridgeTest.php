<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvidenceFreshnessRuntimeBridge;
use Tests\TestCase;

final class AtlasExternalBrainEvidenceFreshnessRuntimeBridgeTest extends TestCase
{
    private function bridge(): AtlasExternalBrainEvidenceFreshnessRuntimeBridge
    {
        return new AtlasExternalBrainEvidenceFreshnessRuntimeBridge;
    }

    private function freshItem(): array
    {
        return ['timestamp' => '2026-06-30T12:00:00Z'];
    }

    public function test_fresh_requires_minimum_fresh_channels_and_all_required_channels_fresh(): void
    {
        $onlyOneFresh = $this->bridge()->assess([
            'commits' => [$this->freshItem()],
            'worker_reports' => [],
            'evidence_intake' => [],
        ]);
        $this->assertFalse($onlyOneFresh['fresh']);

        $twoFresh = $this->bridge()->assess([
            'commits' => [$this->freshItem()],
            'worker_reports' => [$this->freshItem()],
            'evidence_intake' => [],
        ]);
        $this->assertTrue($twoFresh['fresh']);

        $requiredChannelMissing = $this->bridge()->assess([
            'commits' => [$this->freshItem()],
            'worker_reports' => [$this->freshItem()],
            'evidence_intake' => [],
            'required_channels' => ['evidence_intake'],
        ]);
        $this->assertFalse($requiredChannelMissing['fresh']);
    }

    public function test_channel_classification_distinguishes_missing_self_declared_stale_and_fresh(): void
    {
        $result = $this->bridge()->assess([
            'commits' => [],
            'worker_reports' => [['timestamp' => '2026-06-30T00:00:00Z', 'source_type' => 'self_declared', 'verified_by_runtime' => false]],
            'evidence_intake' => [$this->freshItem()],
            'max_age_seconds' => 3600,
            'now_iso' => '2026-06-30T12:00:00Z',
        ]);

        $this->assertSame('missing', $result['channel_classifications']['commits']);
        $this->assertSame('self_declared', $result['channel_classifications']['worker_reports']);
        $this->assertSame('fresh', $result['channel_classifications']['evidence_intake']);
    }

    public function test_stale_classification_and_critical_channel_blocks_admission(): void
    {
        $result = $this->bridge()->assess([
            'commits' => [['timestamp' => '2026-06-30T00:00:00Z']],
            'worker_reports' => [$this->freshItem()],
            'evidence_intake' => [$this->freshItem()],
            'max_age_seconds' => 3600,
            'now_iso' => '2026-06-30T12:00:00Z',
        ]);

        $this->assertSame('stale', $result['channel_classifications']['commits']);
        $this->assertTrue($result['admission_blocked']);
        $this->assertSame('critical_evidence_commits_stale', $result['blocking_reason']);
        $this->assertSame('stale', $result['freshness_status']);
    }

    public function test_missing_self_declared_or_stale_critical_channel_blocks_admission(): void
    {
        $missingCommits = $this->bridge()->assess([
            'commits' => [],
            'worker_reports' => [$this->freshItem()],
            'evidence_intake' => [$this->freshItem()],
        ]);
        $this->assertTrue($missingCommits['admission_blocked']);
        $this->assertSame('critical_evidence_commits_missing', $missingCommits['blocking_reason']);

        $selfDeclaredWorkerReports = $this->bridge()->assess([
            'commits' => [$this->freshItem()],
            'worker_reports' => [['timestamp' => '2026-06-30T12:00:00Z', 'source_type' => 'self_declared', 'verified_by_runtime' => false]],
            'evidence_intake' => [$this->freshItem()],
        ]);
        $this->assertTrue($selfDeclaredWorkerReports['admission_blocked']);
        $this->assertSame('critical_evidence_worker_reports_self_declared', $selfDeclaredWorkerReports['blocking_reason']);

        $allFreshCritical = $this->bridge()->assess([
            'commits' => [$this->freshItem()],
            'worker_reports' => [$this->freshItem()],
            'evidence_intake' => [],
        ]);
        $this->assertFalse($allFreshCritical['admission_blocked']);
        $this->assertNull($allFreshCritical['blocking_reason']);
        $this->assertNull($allFreshCritical['refresh_hint']);
    }

    public function test_newest_seen_at_oldest_seen_at_freshness_status_and_refresh_hint_are_deterministic(): void
    {
        $input = [
            'commits' => [['timestamp' => '2026-06-01T00:00:00Z']],
            'worker_reports' => [['timestamp' => '2026-06-30T12:00:00Z']],
            'evidence_intake' => [$this->freshItem()],
            'max_age_seconds' => 3600,
            'now_iso' => '2026-06-30T12:00:00Z',
        ];

        $first = $this->bridge()->assess($input);
        $second = $this->bridge()->assess($input);

        $this->assertSame($first, $second);
        $this->assertSame('2026-06-30T12:00:00Z', $first['newest_seen_at']);
        $this->assertSame('2026-06-01T00:00:00Z', $first['oldest_seen_at']);
        $this->assertSame('stale', $first['freshness_status']);
        $this->assertSame('refresh_commits_with_current_runtime_proof', $first['refresh_hint']);
    }
}
