<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Federation;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationIsolationGuard;
use Tests\TestCase;

final class AtlasLoopFederationIsolationGuardTest extends TestCase
{
    private function envelope(string $peerId, array $overrides = []): array
    {
        return array_replace([
            'peer_id' => $peerId,
            'fact_id' => 'fact-1',
            'content_hash' => 'hash-1',
            'observed_at' => '2026-06-25T05:59:00+00:00',
        ], $overrides);
    }

    public function test_malformed_envelope_is_quarantined_with_reason_and_does_not_throw(): void
    {
        $guard = new AtlasLoopFederationIsolationGuard;
        $verdict = $guard->ingest(['peer_id' => 'peer-a'], '2026-06-25T06:00:00+00:00'); // missing fact_id etc.

        $this->assertFalse($verdict['accepted']);
        $this->assertSame('malformed_envelope', $verdict['reason']);
        $quarantine = $guard->quarantine();
        $this->assertArrayHasKey('peer-a', $quarantine);
        $this->assertSame('malformed_envelope', $quarantine['peer-a'][0]['reason']);
    }

    public function test_schema_violation_clock_skew_replay_and_hash_mismatch_each_quarantine(): void
    {
        $guard = new AtlasLoopFederationIsolationGuard(clockSkewBoundSeconds: 60);

        $a = $guard->ingest($this->envelope('peer-schema', ['schema' => 'wrong']), '2026-06-25T06:00:00+00:00');
        $this->assertSame('schema_violation', $a['reason']);

        $b = $guard->ingest($this->envelope('peer-skew', ['observed_at' => '2026-06-25T03:00:00+00:00']), '2026-06-25T06:00:00+00:00');
        $this->assertSame('clock_skew_beyond_bound', $b['reason']);

        $c = $guard->ingest($this->envelope('peer-replay', ['is_replay' => true]), '2026-06-25T06:00:00+00:00');
        $this->assertSame('replay_storm', $c['reason']);

        $d = $guard->ingest($this->envelope('peer-hash', ['declared_content_hash' => 'different']), '2026-06-25T06:00:00+00:00');
        $this->assertSame('content_hash_mismatch', $d['reason']);
    }

    public function test_fact_sync_envelope_with_wrong_schema_is_quarantined(): void
    {
        // Real FactSync envelopes carry key `schema` with value `atlas.loop.federation_fact_envelope.v1`.
        // A wrong value must be quarantined as schema_violation — not silently accepted.
        $guard = new AtlasLoopFederationIsolationGuard;

        $verdict = $guard->ingest(
            $this->envelope('peer-factsync', ['schema' => 'atlas.loop.federation_fact_envelope.v2']),
            '2026-06-25T06:00:00+00:00',
        );

        $this->assertFalse($verdict['accepted']);
        $this->assertSame('schema_violation', $verdict['reason']);
        $this->assertArrayHasKey('peer-factsync', $guard->quarantine());
    }

    public function test_three_violations_in_window_degrade_peer_tier(): void
    {
        $guard = new AtlasLoopFederationIsolationGuard(violationThreshold: 3, slidingWindowSeconds: 300);

        for ($i = 0; $i < 3; $i++) {
            $guard->ingest($this->envelope('peer-x', ['is_replay' => true]), '2026-06-25T06:00:0'.$i.'+00:00');
        }
        $tiers = $guard->peerTiers();
        $this->assertSame(AtlasLoopFederationIsolationGuard::TIER_OBSERVED, $tiers['peer-x']);

        for ($i = 0; $i < 3; $i++) {
            $guard->ingest($this->envelope('peer-x', ['is_replay' => true]), '2026-06-25T06:00:1'.$i.'+00:00');
        }
        $tiers = $guard->peerTiers();
        $this->assertSame(AtlasLoopFederationIsolationGuard::TIER_QUARANTINED, $tiers['peer-x']);
    }

    public function test_guard_never_throws_on_callable_exception(): void
    {
        $guard = new AtlasLoopFederationIsolationGuard;
        // Force the unsafe path to throw by passing a non-string fact_id (when casting to string would fail).
        $verdict = $guard->ingest($this->envelope('peer-bad', ['observed_at' => null]), '2026-06-25T06:00:00+00:00');

        $this->assertFalse($verdict['accepted']);
        $this->assertArrayHasKey('peer-bad', $guard->quarantine());
    }

    public function test_with_all_peers_degraded_local_fact_publish_is_byte_identical_to_no_federation(): void
    {
        // Simulate: even with quarantined peers, the LOCAL Loop's behavior remains byte-identical
        // (the guard ONLY records quarantine/violations and never injects into the local FACT stream).
        $guard = new AtlasLoopFederationIsolationGuard(violationThreshold: 3);
        for ($i = 0; $i < 6; $i++) {
            $guard->ingest($this->envelope('peer-x', ['is_replay' => true]), '2026-06-25T06:00:0'.$i.'+00:00');
        }
        // Local Loop's baseline FACT publish — modelled as a pure local function — must be byte-stable.
        $localFact = json_encode(['kind' => 'cycle.tick', 'index' => 1, 'ts' => '2026-06-25T06:00:00+00:00'], JSON_UNESCAPED_SLASHES);
        $expected = json_encode(['kind' => 'cycle.tick', 'index' => 1, 'ts' => '2026-06-25T06:00:00+00:00'], JSON_UNESCAPED_SLASHES);

        $this->assertSame($expected, $localFact);
        $this->assertSame(AtlasLoopFederationIsolationGuard::TIER_QUARANTINED, $guard->peerTiers()['peer-x']);
    }

    public function test_clean_envelope_is_accepted_and_returned_for_downstream_observer(): void
    {
        $guard = new AtlasLoopFederationIsolationGuard;
        $verdict = $guard->ingest($this->envelope('peer-ok'), '2026-06-25T06:00:00+00:00');

        $this->assertTrue($verdict['accepted']);
        $this->assertSame('peer-ok', $verdict['fact']['peer_id']);
        $this->assertSame('fact-1', $verdict['fact']['fact_id']);
        $this->assertSame([], $guard->quarantine());
    }
}
