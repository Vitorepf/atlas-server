<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesMeshReconciler;
use Tests\TestCase;

class HermesMeshReconcilerTest extends TestCase
{
    private function reconciler(): HermesMeshReconciler
    {
        return new HermesMeshReconciler();
    }

    /**
     * @param  array<int,array<string,mixed>>  $children
     * @return array<string,mixed>
     */
    private function plan(bool $meshEnabled = true, array $children = []): array
    {
        return [
            'schema_version' => 'atlas.hermes.mesh_plan.v1',
            'mesh_plan_id' => 'mesh_plan_abc',
            'mission_id' => 'mission_root',
            'mission_hash' => 'hash_root',
            'mesh_enabled' => $meshEnabled,
            'children' => $children,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function completedPacket(string $id = 'child_a', int $memoryCandidates = 0): array
    {
        return [
            'schema_version' => 'atlas.hermes.result_packet.v1',
            'result_id' => 'hermes_result_'.$id,
            'mission_id' => $id,
            'status' => 'succeeded',
            'result_hash' => 'rh_'.$id,
            'output' => [
                'response_hash' => hash('sha256', 'out_'.$id),
                'response_bytes' => 128,
            ],
            'evidence_packet' => [
                'schema_version' => 'atlas.hermes.evidence_packet.v1',
                'evidence_required' => true,
                'child' => $id,
            ],
            'memory_gate' => [
                'schema_version' => 'atlas.hermes.memory_gate.v1',
                'candidate_count' => $memoryCandidates,
                'candidates' => array_fill(0, $memoryCandidates, ['claim' => 'x']),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failedPacket(string $id = 'child_f'): array
    {
        return [
            'schema_version' => 'atlas.hermes.result_packet.v1',
            'result_id' => 'hermes_result_'.$id,
            'mission_id' => $id,
            'status' => 'failed',
            'result_hash' => 'rh_'.$id,
            'output' => [
                'response_hash' => null,
                'response_bytes' => 0,
            ],
            'evidence_packet' => [
                'schema_version' => 'atlas.hermes.evidence_packet.v1',
                'evidence_required' => true,
                'child' => $id,
            ],
            'memory_gate' => [
                'candidate_count' => 0,
            ],
        ];
    }

    public function test_all_completed_happy_path_is_sealed_and_allowed(): void
    {
        $plan = $this->plan(true, [
            ['child_id' => 'c1'],
            ['child_id' => 'c2'],
        ]);
        $packets = [
            $this->completedPacket('c1', 2),
            $this->completedPacket('c2', 1),
        ];

        $receipt = $this->reconciler()->reconcile($plan, $packets);

        $this->assertSame('schema_version', array_key_first($receipt));
        $this->assertSame('atlas.hermes.mesh_reconciliation.v1', $receipt['schema_version']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertSame('atlas', $receipt['reconciliation_authority']);
        $this->assertFalse($receipt['hermes_mesh_can_decide']);
        $this->assertTrue($receipt['mesh_enabled']);
        $this->assertTrue($receipt['reconciliation_allowed_now']);
        $this->assertSame('all_completed', $receipt['aggregate_status']);
        $this->assertSame(2, $receipt['children_total']);
        $this->assertSame(2, $receipt['children_completed']);
        $this->assertSame(0, $receipt['children_failed']);
        $this->assertSame(0, $receipt['children_missing']);
        $this->assertSame(3, $receipt['memory_candidate_count']);
        $this->assertFalse($receipt['memory_promotion_allowed_now']);
        $this->assertCount(2, $receipt['evidence_refs']);
        $this->assertNull($receipt['blocked_reason']);
        $this->assertArrayHasKey('receipt_hash', $receipt);

        // receipt_hash is the LAST key.
        $keys = array_keys($receipt);
        $this->assertSame('receipt_hash', end($keys));

        // Per-child mapping by plan child index.
        $this->assertSame('c1', $receipt['children'][0]['child_id']);
        $this->assertSame('completed', $receipt['children'][0]['status']);
        $this->assertTrue($receipt['children'][0]['has_packet']);
    }

    public function test_disabled_plan_is_fail_closed_empty_and_not_allowed(): void
    {
        $plan = $this->plan(false, [['child_id' => 'c1']]);
        $packets = [$this->completedPacket('c1')];

        $receipt = $this->reconciler()->reconcile($plan, $packets);

        $this->assertFalse($receipt['mesh_enabled']);
        $this->assertFalse($receipt['reconciliation_allowed_now']);
        $this->assertSame('empty', $receipt['aggregate_status']);
        $this->assertSame('mesh_plan_not_enabled', $receipt['blocked_reason']);
        $this->assertFalse($receipt['memory_promotion_allowed_now']);
        $this->assertArrayHasKey('receipt_hash', $receipt);
    }

    public function test_missing_mesh_enabled_key_defaults_off(): void
    {
        $plan = [
            'schema_version' => 'atlas.hermes.mesh_plan.v1',
            'mesh_plan_id' => 'p',
            'children' => [['child_id' => 'c1']],
        ];

        $receipt = $this->reconciler()->reconcile($plan, [$this->completedPacket('c1')]);

        $this->assertFalse($receipt['reconciliation_allowed_now']);
        $this->assertSame('empty', $receipt['aggregate_status']);
        $this->assertSame('mesh_plan_not_enabled', $receipt['blocked_reason']);
    }

    public function test_empty_packets_is_blocked_even_when_enabled(): void
    {
        $receipt = $this->reconciler()->reconcile($this->plan(true, [['child_id' => 'c1']]), []);

        $this->assertTrue($receipt['mesh_enabled']);
        $this->assertFalse($receipt['reconciliation_allowed_now']);
        $this->assertSame('empty', $receipt['aggregate_status']);
        $this->assertSame('no_result_packets', $receipt['blocked_reason']);
        $this->assertSame(0, $receipt['memory_candidate_count']);
    }

    public function test_partial_status_with_missing_and_failed_children(): void
    {
        // Plan declares 3 children; only 2 packets arrive (one completed, one
        // failed) => the third is 'missing' and the aggregate is 'partial'.
        $plan = $this->plan(true, [
            ['child_id' => 'c1'],
            ['child_id' => 'c2'],
            ['child_id' => 'c3'],
        ]);
        $packets = [
            $this->completedPacket('c1', 4),
            $this->failedPacket('c2'),
        ];

        $receipt = $this->reconciler()->reconcile($plan, $packets);

        $this->assertTrue($receipt['reconciliation_allowed_now']);
        $this->assertSame('partial', $receipt['aggregate_status']);
        $this->assertSame(3, $receipt['children_total']);
        $this->assertSame(1, $receipt['children_completed']);
        $this->assertSame(1, $receipt['children_failed']);
        $this->assertSame(1, $receipt['children_missing']);
        $this->assertSame(4, $receipt['memory_candidate_count']);

        $this->assertSame('completed', $receipt['children'][0]['status']);
        $this->assertSame('failed', $receipt['children'][1]['status']);
        $this->assertSame('missing', $receipt['children'][2]['status']);
        $this->assertFalse($receipt['children'][2]['has_packet']);
    }

    public function test_all_failed_status(): void
    {
        $plan = $this->plan(true, [['child_id' => 'c1'], ['child_id' => 'c2']]);
        $packets = [$this->failedPacket('c1'), $this->failedPacket('c2')];

        $receipt = $this->reconciler()->reconcile($plan, $packets);

        $this->assertSame('all_failed', $receipt['aggregate_status']);
        $this->assertSame(0, $receipt['children_completed']);
        $this->assertSame(2, $receipt['children_failed']);
        $this->assertTrue($receipt['reconciliation_allowed_now']);
    }

    public function test_extra_packet_beyond_plan_is_not_dropped(): void
    {
        // Plan declares 1 child but 2 packets arrive: slot count grows so the
        // extra packet is reconciled rather than silently discarded.
        $plan = $this->plan(true, [['child_id' => 'c1']]);
        $packets = [$this->completedPacket('c1'), $this->completedPacket('c2')];

        $receipt = $this->reconciler()->reconcile($plan, $packets);

        $this->assertSame(2, $receipt['children_total']);
        $this->assertSame(2, $receipt['children_completed']);
        $this->assertSame('all_completed', $receipt['aggregate_status']);
        // Second child has no plan entry => id falls back to the packet result_id.
        $this->assertSame('hermes_result_c2', $receipt['children'][1]['child_id']);
    }

    public function test_invalid_input_clamped_and_does_not_throw(): void
    {
        // Non-array packets are filtered; clamping never throws.
        $plan = $this->plan(true, [['child_id' => 'c1'], 'not-an-array']);
        $packets = ['garbage', $this->completedPacket('c1'), 42, null];

        $receipt = $this->reconciler()->reconcile($plan, $packets);

        $this->assertTrue($receipt['reconciliation_allowed_now']);
        $this->assertSame(1, $receipt['children_total']);
        $this->assertSame('completed', $receipt['children'][0]['status']);
        $this->assertSame('all_completed', $receipt['aggregate_status']);
    }

    public function test_receipt_hash_is_deterministic_and_off_path_flag_false(): void
    {
        $plan = $this->plan(true, [['child_id' => 'c1']]);
        $packets = [$this->completedPacket('c1', 1)];

        $a = $this->reconciler()->reconcile($plan, $packets);
        $b = $this->reconciler()->reconcile($plan, $packets);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);

        // When policy is off, the allowed-now flag must be false and the hash
        // must differ from the enabled path.
        $off = $this->reconciler()->reconcile($this->plan(false, [['child_id' => 'c1']]), $packets);
        $this->assertFalse($off['reconciliation_allowed_now']);
        $this->assertNotSame($a['receipt_hash'], $off['receipt_hash']);

        // receipt_hash matches a re-hash of the receipt minus the hash key.
        $rehash = hash('sha256', json_encode(
            array_diff_key($a, ['receipt_hash' => true]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        $this->assertSame($a['receipt_hash'], $rehash);
    }
}
