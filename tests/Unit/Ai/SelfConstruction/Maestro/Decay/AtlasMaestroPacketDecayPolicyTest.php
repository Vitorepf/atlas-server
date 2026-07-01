<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Decay;

use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketAgeFactReporter;
use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketDecayPolicy;
use Tests\TestCase;

final class AtlasMaestroPacketDecayPolicyTest extends TestCase
{
    private function reporter(array $packets, string $observedAt = '2026-06-25T15:00:00Z', bool $master = true): AtlasMaestroPacketAgeFactReporter
    {
        return new AtlasMaestroPacketAgeFactReporter(
            fn () => $packets,
            fn () => $observedAt,
            fn () => $master,
        );
    }

    // ── AC: stale low-value packets produce retire proposals ──

    public function test_stale_low_value_packet_produces_retire_proposal(): void
    {
        $packets = [
            ['task_packet_id' => 'low-value-old', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting'],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600, // 6h
            fn () => true,
            fn (string $id) => ($byId[$id] ?? []) + ['value' => 0.1],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('low-value-old', $p['task_packet_id']);
        $this->assertSame('retire', $p['proposed_action']);
        $this->assertStringContainsString('retire_due_to_stale_low_value', $p['reason']);
    }

    // ── AC: stale high-value packets with weak proof produce refresh proposals ──

    public function test_stale_high_value_weak_proof_packet_produces_refresh_proposal(): void
    {
        $packets = [
            ['task_packet_id' => 'high-value-weak-proof', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting'],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
            fn (string $id) => ($byId[$id] ?? []) + ['value' => 0.9, 'proof_strength' => 0.2],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('high-value-weak-proof', $p['task_packet_id']);
        $this->assertSame('refresh', $p['proposed_action']);
        $this->assertStringContainsString('refresh_due_to_stale_high_value_weak_proof', $p['reason']);
    }

    public function test_stale_high_value_strong_proof_packet_still_parks(): void
    {
        $packets = [
            ['task_packet_id' => 'high-value-strong-proof', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting'],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
            fn (string $id) => ($byId[$id] ?? []) + ['value' => 0.9, 'proof_strength' => 0.9],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $this->assertSame('park', $proposals[0]['proposed_action']);
    }

    // ── AC: fresh strong-proof packets are kept ──

    public function test_fresh_strong_proof_packet_is_kept(): void
    {
        $packets = [
            ['task_packet_id' => 'fresh-strong-proof', 'enqueued_at' => '2026-06-25T14:00:00Z', 'queue_status' => 'waiting'],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
            fn (string $id) => ($byId[$id] ?? []) + ['proof_strength' => 0.95],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('fresh-strong-proof', $p['task_packet_id']);
        $this->assertSame('keep', $p['proposed_action']);
        $this->assertSame('keep_fresh_strong_proof', $p['reason']);
    }

    public function test_fresh_packet_without_proof_signal_produces_no_proposal(): void
    {
        $packets = [
            ['task_packet_id' => 'fresh-no-signal', 'enqueued_at' => '2026-06-25T14:00:00Z', 'queue_status' => 'waiting'],
        ];
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
        );

        $this->assertSame([], $policy->propose());
    }

    // ── AC: dependency freshness — critical + stale + weak proof/stale dependency ⇒ rescue ──

    public function test_critical_stale_weak_proof_packet_is_rescued_not_kept(): void
    {
        $packets = [
            ['task_packet_id' => 'critical-weak-proof', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting', 'dependency_critical' => true],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
            fn (string $id) => ($byId[$id] ?? []) + ['proof_strength' => 0.1],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('rescue', $p['proposed_action']);
        $this->assertStringContainsString('rescue_due_to_critical_dependency', $p['reason']);
    }

    public function test_critical_stale_stale_dependency_packet_is_rescued(): void
    {
        $packets = [
            ['task_packet_id' => 'critical-stale-dep', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting', 'dependency_critical' => true],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
            fn (string $id) => ($byId[$id] ?? []) + ['dependency_fresh' => false],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $this->assertSame('rescue', $proposals[0]['proposed_action']);
    }

    public function test_critical_stale_without_new_signals_still_keeps_legacy_behavior(): void
    {
        $packets = [
            ['task_packet_id' => 'critical-old', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting', 'dependency_critical' => true],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
            fn (string $id) => $byId[$id] ?? [],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('keep', $p['proposed_action']);
        $this->assertSame('keep_due_to_critical_dependency', $p['reason']);
    }

    // ── Legacy contract sanity: no new meta ⇒ byte-identical old behavior ──

    public function test_three_packets_only_oldest_unclaimed_above_threshold_is_proposed(): void
    {
        $packets = [
            ['task_packet_id' => 'one-hour', 'enqueued_at' => '2026-06-25T14:00:00Z', 'queue_status' => 'waiting'],
            ['task_packet_id' => 'five-hour', 'enqueued_at' => '2026-06-25T10:00:00Z', 'queue_status' => 'waiting'],
            ['task_packet_id' => 'nine-hour', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting'],
        ];
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $this->assertSame('park', $proposals[0]['proposed_action']);
    }
}
