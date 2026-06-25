<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Federation;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationConsensusObserver;
use Tests\TestCase;

final class AtlasLoopFederationConsensusObserverTest extends TestCase
{
    private function report(string $peer, string $factId, string $hash, string $observedAt): array
    {
        return compact('observedAt') + [
            'peer_id' => $peer,
            'fact_id' => $factId,
            'content_hash' => $hash,
            'observed_at' => $observedAt,
        ];
    }

    public function test_two_independent_peers_with_matching_hash_emit_consensus(): void
    {
        $observer = new AtlasLoopFederationConsensusObserver(peerThreshold: 2, stalenessSeconds: 600);
        $facts = $observer->observe([
            $this->report('peer-a', 'fact-1', 'hash-1', '2026-06-25T05:55:00+00:00'),
            $this->report('peer-b', 'fact-1', 'hash-1', '2026-06-25T05:56:00+00:00'),
        ], '2026-06-25T06:00:00+00:00');

        $this->assertCount(1, $facts);
        $this->assertSame(AtlasLoopFederationConsensusObserver::FACT_KIND, $facts[0]['kind']);
        $this->assertSame('fact-1', $facts[0]['fact_id']);
        $this->assertSame('hash-1', $facts[0]['content_hash']);
        $this->assertSame(['peer-a', 'peer-b'], $facts[0]['participating_peer_ids']);
    }

    public function test_single_peer_reporting_twice_does_not_trigger(): void
    {
        $observer = new AtlasLoopFederationConsensusObserver(peerThreshold: 2);
        $facts = $observer->observe([
            $this->report('peer-a', 'fact-1', 'hash-1', '2026-06-25T05:55:00+00:00'),
            $this->report('peer-a', 'fact-1', 'hash-1', '2026-06-25T05:56:00+00:00'),
        ], '2026-06-25T06:00:00+00:00');

        $this->assertSame([], $facts, 'one peer cannot self-consensus by reporting twice');
    }

    public function test_mismatching_content_hashes_do_not_form_consensus(): void
    {
        $observer = new AtlasLoopFederationConsensusObserver(peerThreshold: 2);
        $facts = $observer->observe([
            $this->report('peer-a', 'fact-1', 'hash-a', '2026-06-25T05:55:00+00:00'),
            $this->report('peer-b', 'fact-1', 'hash-b', '2026-06-25T05:56:00+00:00'),
        ], '2026-06-25T06:00:00+00:00');

        $this->assertSame([], $facts);
    }

    public function test_stale_reports_are_ignored(): void
    {
        $observer = new AtlasLoopFederationConsensusObserver(peerThreshold: 2, stalenessSeconds: 60);
        $facts = $observer->observe([
            // peer-a observed 2 hours ago — stale.
            $this->report('peer-a', 'fact-1', 'hash-1', '2026-06-25T04:00:00+00:00'),
            $this->report('peer-b', 'fact-1', 'hash-1', '2026-06-25T05:59:30+00:00'),
        ], '2026-06-25T06:00:00+00:00');

        $this->assertSame([], $facts, 'stale peer-a should be ignored — only one fresh peer remains');
    }

    public function test_emitted_fact_is_audit_only_no_score_or_grade(): void
    {
        $observer = new AtlasLoopFederationConsensusObserver(peerThreshold: 2);
        $facts = $observer->observe([
            $this->report('peer-a', 'fact-1', 'hash-1', '2026-06-25T05:55:00+00:00'),
            $this->report('peer-b', 'fact-1', 'hash-1', '2026-06-25T05:56:00+00:00'),
            $this->report('peer-c', 'fact-1', 'hash-1', '2026-06-25T05:57:00+00:00'),
        ], '2026-06-25T06:00:00+00:00');

        $this->assertCount(1, $facts);
        $row = $facts[0];
        foreach (['score', 'grade', 'rank', 'severity', 'verdict', 'pass', 'fail', 'block'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "FACT must not carry {$forbidden}");
        }
        $this->assertCount(3, $row['participating_peer_ids']);
    }

    public function test_threshold_three_requires_three_independent_peers(): void
    {
        $observer = new AtlasLoopFederationConsensusObserver(peerThreshold: 3);
        $facts = $observer->observe([
            $this->report('peer-a', 'fact-1', 'hash-1', '2026-06-25T05:55:00+00:00'),
            $this->report('peer-b', 'fact-1', 'hash-1', '2026-06-25T05:56:00+00:00'),
        ], '2026-06-25T06:00:00+00:00');

        $this->assertSame([], $facts);
    }
}
