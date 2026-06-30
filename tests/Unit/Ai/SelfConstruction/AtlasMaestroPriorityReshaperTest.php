<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityReshaper;
use Tests\TestCase;

final class AtlasMaestroPriorityReshaperTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-priority-reshaper-'.bin2hex(random_bytes(6));
        @mkdir($this->sandbox, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->sandbox.'/*') as $entry) {
            @unlink((string) $entry);
        }
        @rmdir($this->sandbox);
        parent::tearDown();
    }

    private function reshaper(bool $masterOn = true): AtlasMaestroPriorityReshaper
    {
        return new AtlasMaestroPriorityReshaper(
            sequencePath: $this->sandbox,
            masterGate: static fn (): bool => $masterOn,
        );
    }

    public function test_higher_criticality_packet_precedes_older_low_criticality_packet(): void
    {
        $snapshot = [
            'facts' => [
                'dependency_criticality_by_task_id' => [
                    'pkt-A' => 3,
                    'pkt-B' => 0,
                ],
            ],
        ];
        $packets = [
            ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];

        $ordered = $this->reshaper()->reshape($packets, $snapshot, 'loop');
        $this->assertSame(['pkt-A', 'pkt-B'], array_column($ordered, 'task_packet_id'));
    }

    public function test_fifo_tiebreak_when_criticality_equal(): void
    {
        $snapshot = ['facts' => ['dependency_criticality_by_task_id' => []]];
        $packets = [
            ['task_packet_id' => 'pkt-X', 'enqueued_at' => '2026-06-25T05:00:00Z'],
            ['task_packet_id' => 'pkt-Y', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];
        $ordered = $this->reshaper()->reshape($packets, $snapshot, 'loop');
        $this->assertSame(['pkt-Y', 'pkt-X'], array_column($ordered, 'task_packet_id'));
    }

    public function test_lexical_id_is_final_deterministic_tiebreak(): void
    {
        $snapshot = ['facts' => ['dependency_criticality_by_task_id' => []]];
        $packets = [
            ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T00:00:00Z'],
        ];
        $ordered = $this->reshaper()->reshape($packets, $snapshot, 'loop');
        $this->assertSame(['pkt-A', 'pkt-B'], array_column($ordered, 'task_packet_id'));
    }

    public function test_reshape_is_idempotent_byte_identical_serving_sequence_rows(): void
    {
        $snapshot = ['facts' => ['dependency_criticality_by_task_id' => ['pkt-A' => 5]]];
        $packets = [
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];

        $reshaper = $this->reshaper();
        $reshaper->reshape($packets, $snapshot, 'loop');
        $digest1 = $reshaper->sequenceDigest('loop');

        $reshaper->reshape($packets, $snapshot, 'loop');
        $digest2 = $reshaper->sequenceDigest('loop');

        $this->assertNotSame('', $digest1);
        $this->assertSame($digest1, $digest2);
    }

    public function test_forbidden_scope_tag_throws_contract_violation_and_writes_nothing(): void
    {
        $reshaper = $this->reshaper();
        $thrown = false;
        try {
            $reshaper->reshape([], [], 'marketing');
        } catch (\Throwable) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'forbidden scope must throw');
        $this->assertSame([], glob($this->sandbox.'/*') ?: []);
    }

    public function test_master_off_returns_input_order_and_writes_nothing(): void
    {
        $snapshot = ['facts' => ['dependency_criticality_by_task_id' => ['pkt-Z' => 99]]];
        $packets = [
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-Z', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];

        $ordered = $this->reshaper(masterOn: false)->reshape($packets, $snapshot, 'loop');

        // Input order preserved exactly (no reshape).
        $this->assertSame(['pkt-A', 'pkt-Z'], array_column($ordered, 'task_packet_id'));
        $this->assertSame([], glob($this->sandbox.'/*') ?: []);
    }

    public function test_poison_family_pushes_task_below_clean_peer_with_equal_criticality(): void
    {
        $snapshot = [
            'facts' => [
                'dependency_criticality_by_task_id' => [],
                'poison_family_by_task_id' => ['pkt-A' => true], // pkt-A is poison
            ],
        ];
        $packets = [
            // pkt-A is older but poisoned; pkt-B is clean.
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];

        $ordered = $this->reshaper()->reshape($packets, $snapshot, 'loop');

        // Clean pkt-B must precede poisoned pkt-A despite pkt-A being older (FIFO would pick A first).
        $this->assertSame(['pkt-B', 'pkt-A'], array_column($ordered, 'task_packet_id'));
    }

    public function test_worker_starvation_unblock_raises_task_above_fifo_peers_master_off_is_no_op(): void
    {
        $packets = [
            ['task_packet_id' => 'pkt-old', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-new', 'enqueued_at' => '2026-06-25T05:00:00Z'],
        ];
        $snapshot = [
            'facts' => [
                'dependency_criticality_by_task_id' => [],
                'worker_starvation_unblock_by_task_id' => ['pkt-new' => true], // newer but unblocks idle worker
            ],
        ];

        // Master-off: input order unchanged regardless of starvation fact.
        $offOrder = $this->reshaper(masterOn: false)->reshape($packets, $snapshot, 'loop');
        $this->assertSame(['pkt-old', 'pkt-new'], array_column($offOrder, 'task_packet_id'), 'master-off must be a no-op');

        // Master-on: pkt-new rises above pkt-old because it unblocks an idle worker.
        $onOrder = $this->reshaper(masterOn: true)->reshape($packets, $snapshot, 'loop');
        $this->assertSame(['pkt-new', 'pkt-old'], array_column($onOrder, 'task_packet_id'), 'starvation-unblock must precede FIFO peers');
    }

    public function test_high_give_back_risk_sinks_below_clean_peer_with_equal_criticality(): void
    {
        $snapshot = [
            'facts' => [
                'dependency_criticality_by_task_id' => [],
                'high_give_back_risk_by_task_id' => ['pkt-A' => true],
            ],
        ];
        $packets = [
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];

        $ordered = $this->reshaper()->reshape($packets, $snapshot, 'loop');

        $this->assertSame(['pkt-B', 'pkt-A'], array_column($ordered, 'task_packet_id'));
    }

    public function test_high_give_back_risk_is_weaker_than_poison(): void
    {
        $snapshot = [
            'facts' => [
                'dependency_criticality_by_task_id' => [],
                'poison_family_by_task_id' => ['pkt-A' => true],
                'high_give_back_risk_by_task_id' => ['pkt-B' => true],
            ],
        ];
        $packets = [
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];

        $ordered = $this->reshaper()->reshape($packets, $snapshot, 'loop');

        // pkt-B (give_back risk) before pkt-A (poison) — poison sinks lower than give_back risk.
        $this->assertSame(['pkt-B', 'pkt-A'], array_column($ordered, 'task_packet_id'));
    }

    public function test_dependency_criticality_overrides_high_give_back_risk(): void
    {
        $snapshot = [
            'facts' => [
                'dependency_criticality_by_task_id' => ['pkt-A' => 5],
                'high_give_back_risk_by_task_id' => ['pkt-A' => true],
            ],
        ];
        $packets = [
            ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T00:00:00Z'],
            ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T01:00:00Z'],
        ];

        $ordered = $this->reshaper()->reshape($packets, $snapshot, 'loop');

        // pkt-A still rises despite give_back risk because it's explicitly dependency-critical.
        $this->assertSame(['pkt-A', 'pkt-B'], array_column($ordered, 'task_packet_id'));
    }
}
