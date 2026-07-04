<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketAgeFactReporter;
use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketDecayPolicy;
use ReflectionClass;
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

    public function test_three_packets_only_oldest_unclaimed_above_threshold_is_proposed(): void
    {
        $packets = [
            ['task_packet_id' => 'one-hour', 'enqueued_at' => '2026-06-25T14:00:00Z', 'queue_status' => 'waiting'],
            ['task_packet_id' => 'five-hour', 'enqueued_at' => '2026-06-25T10:00:00Z', 'queue_status' => 'waiting'],
            ['task_packet_id' => 'nine-hour', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting'],
        ];
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600, // 6h
            fn () => true,
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('nine-hour', $p['task_packet_id']);
        $this->assertSame('park', $p['proposed_action']);
        $this->assertSame(32400, $p['age_seconds']);
        $this->assertSame(21600, $p['threshold_seconds']);
        $this->assertStringContainsString('age_seconds=32400', $p['reason']);
        $this->assertStringContainsString('threshold_seconds=21600', $p['reason']);
    }

    public function test_policy_does_not_accept_queue_repository_injection(): void
    {
        $ctor = (new ReflectionClass(AtlasMaestroPacketDecayPolicy::class))->getConstructor();
        $this->assertNotNull($ctor);
        foreach ($ctor->getParameters() as $p) {
            $type = $p->getType();
            $name = $type ? (string) $type : '';
            $this->assertStringNotContainsString('AgentControlPlaneTaskPacketQueueRepository', $name);
        }
    }

    public function test_threshold_le_zero_returns_empty(): void
    {
        $packets = [['task_packet_id' => 'p', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting']];
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 0,
            fn () => true,
        );
        $this->assertSame([], $policy->propose());
    }

    public function test_master_off_returns_empty(): void
    {
        $packets = [['task_packet_id' => 'p', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'waiting']];
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets, master: false),
            fn () => 21600,
            fn () => false,
        );
        $this->assertSame([], $policy->propose());
    }

    public function test_claimed_status_is_not_proposed_even_when_aged(): void
    {
        $packets = [
            ['task_packet_id' => 'served-old', 'enqueued_at' => '2026-06-25T06:00:00Z', 'queue_status' => 'served'],
        ];
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600,
            fn () => true,
        );
        $this->assertSame([], $policy->propose());
    }

    public function test_dependency_critical_packet_proposes_keep_not_park_when_aged(): void
    {
        // 9h old, threshold 6h, dependency_critical=true → must propose keep, not park
        $packets = [
            [
                'task_packet_id' => 'critical-old',
                'enqueued_at' => '2026-06-25T06:00:00Z',
                'queue_status' => 'waiting',
                'dependency_critical' => true,
            ],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600, // 6h
            fn () => true,
            fn (string $id) => $byId[$id] ?? [],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('critical-old', $p['task_packet_id']);
        $this->assertSame('keep', $p['proposed_action']);
        $this->assertSame('keep_due_to_critical_dependency', $p['reason']);
    }

    public function test_poison_family_packet_parks_below_normal_threshold_with_named_reason(): void
    {
        // give_back_count=2, age=4h, normal threshold=6h → poison threshold=3h (50%) → must park
        $packets = [
            [
                'task_packet_id' => 'poison-packet',
                'enqueued_at' => '2026-06-25T11:00:00Z', // 4h before observedAt 15:00
                'queue_status' => 'waiting',
                'give_back_count' => 2,
            ],
        ];
        $byId = array_column($packets, null, 'task_packet_id');
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter($packets),
            fn () => 21600, // 6h normal
            fn () => true,
            fn (string $id) => $byId[$id] ?? [],
        );

        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertSame('poison-packet', $p['task_packet_id']);
        $this->assertSame('park', $p['proposed_action']);
        $this->assertStringContainsString('park_due_to_poison_age', $p['reason']);
        $this->assertStringContainsString('poison_threshold_seconds=10800', $p['reason']); // 21600 * 0.5
    }

    // ── decay_action, value_evidence_status, refresh_reason, retirement_reason ──

    public function test_proposal_has_required_new_fields(): void
    {
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter([['task_packet_id' => 't1', 'queue_status' => 'waiting', 'time_in_queue_seconds' => 30000]]),
            fn () => 21600, fn () => true,
        );
        $proposals = $policy->propose();
        $this->assertCount(1, $proposals);
        $p = $proposals[0];
        $this->assertArrayHasKey('decay_action', $p);
        $this->assertArrayHasKey('value_evidence_status', $p);
        $this->assertArrayHasKey('refresh_reason', $p);
        $this->assertArrayHasKey('retirement_reason', $p);
    }

    public function test_retire_proposal_has_retirement_reason(): void
    {
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter([['task_packet_id' => 'low', 'queue_status' => 'waiting', 'time_in_queue_seconds' => 30000]]),
            fn () => 21600, fn () => true,
            fn () => ['value' => 0.1],
        );
        $proposals = $policy->propose();
        $p = $proposals[0];
        $this->assertSame('retire', $p['proposed_action']);
        $this->assertSame('retire', $p['decay_action']);
        $this->assertNotEmpty($p['retirement_reason']);
        $this->assertStringContainsString('stale_low_value', $p['retirement_reason']);
    }

    public function test_refresh_proposal_has_refresh_reason(): void
    {
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter([['task_packet_id' => 'high-weak', 'queue_status' => 'waiting', 'time_in_queue_seconds' => 30000]]),
            fn () => 21600, fn () => true,
            fn () => ['value' => 0.8, 'proof_strength' => 0.3],
        );
        $proposals = $policy->propose();
        $p = $proposals[0];
        $this->assertSame('refresh', $p['proposed_action']);
        $this->assertSame('refresh', $p['decay_action']);
        $this->assertNotEmpty($p['refresh_reason']);
        $this->assertStringContainsString('stale_high_value_weak_proof', $p['refresh_reason']);
    }

    public function test_value_evidence_status_strong_when_value_and_proof_high(): void
    {
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter([['task_packet_id' => 'strong', 'queue_status' => 'waiting', 'time_in_queue_seconds' => 30000]]),
            fn () => 21600, fn () => true,
            fn () => ['value' => 0.9, 'proof_strength' => 0.8],
        );
        $proposals = $policy->propose();
        $p = $proposals[0];
        $this->assertSame('strong_value_evidence', $p['value_evidence_status']);
    }

    public function test_value_evidence_status_no_evidence_when_no_signals(): void
    {
        $policy = new AtlasMaestroPacketDecayPolicy(
            $this->reporter([['task_packet_id' => 'none', 'queue_status' => 'waiting', 'time_in_queue_seconds' => 30000]]),
            fn () => 21600, fn () => true,
            fn () => [],
        );
        $proposals = $policy->propose();
        $p = $proposals[0];
        $this->assertSame('no_evidence', $p['value_evidence_status']);
    }
}
