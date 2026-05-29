<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use Tests\TestCase;

final class BacklogDepthGovernorServiceTest extends TestCase
{
    private function service(): BacklogDepthGovernorService
    {
        return app(BacklogDepthGovernorService::class);
    }

    /**
     * Build N well-formed admissible bounded packets.
     *
     * @return list<array<string,mixed>>
     */
    private function packets(int $count, string $risk = 'medium'): array
    {
        $packets = [];
        for ($i = 1; $i <= $count; $i++) {
            $packets[] = [
                'packet_id' => "slice-{$i}",
                'parent_finding_id' => 'AAEOS-001',
                'active_slice_id' => "slice-{$i}",
                'risk_level' => $risk,
                'status' => 'planned',
                'origin_type' => 'self_construction_admission_packet',
            ];
        }

        return $packets;
    }

    /**
     * A healthy backlog with depth >= floor (12 packets, floor 10).
     *
     * @return array<string,mixed>
     */
    private function okFixture(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'floor' => 10,
            'canonical_parents' => [
                ['finding_id' => 'AAEOS-001', 'origin_type' => 'canonical_backlog'],
                ['finding_id' => 'AAEOS-002', 'origin_type' => 'canonical_backlog'],
            ],
            'self_construction_packets' => $this->packets(12),
        ];
    }

    public function test_depth_at_or_above_floor_is_ok_and_does_not_block_24h(): void
    {
        $report = $this->service()->assess($this->okFixture());

        $this->assertSame(BacklogDepthGovernorService::STATUS_OK, $report['status']);
        $this->assertFalse($report['blocks_24h']);
        $this->assertSame(12, $report['packets_count']);
        $this->assertSame(2, $report['parents_count']);
        $this->assertSame(10, $report['floor']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertSame(12, $report['estimated_useful_cycles']);
    }

    public function test_depth_below_floor_is_below_floor_status_and_blocks_24h(): void
    {
        $input = $this->okFixture();
        $input['self_construction_packets'] = $this->packets(3); // below floor of 10

        $report = $this->service()->assess($input);

        $this->assertSame(BacklogDepthGovernorService::STATUS_BELOW_FLOOR, $report['status']);
        $this->assertTrue($report['blocks_24h']);
        $this->assertSame(3, $report['packets_count']);
        $this->assertContains('packet_depth_below_floor', $report['blockers']);
        $this->assertSame('stop_24h_until_backlog_replenished', $report['next_action']);
    }

    public function test_filler_and_recovery_packets_are_excluded_from_depth(): void
    {
        // 9 real packets + 5 filler/recovery == below floor (filler must NOT count).
        $packets = $this->packets(9);
        $packets[] = ['packet_id' => 'f1', 'origin_type' => 'starvation_recovery', 'risk_level' => 'low'];
        $packets[] = ['packet_id' => 'f2', 'is_recovery' => true, 'risk_level' => 'low'];
        $packets[] = ['packet_id' => 'f3', 'kind' => 'filler', 'risk_level' => 'low'];
        $packets[] = ['packet_id' => 'f4', 'is_missing_test_filler' => true, 'risk_level' => 'low'];
        $packets[] = ['packet_id' => 'f5', 'kind' => 'missing_test', 'strategic_value' => false, 'risk_level' => 'low'];

        $input = $this->okFixture();
        $input['self_construction_packets'] = $packets;

        $report = $this->service()->assess($input);

        // Only the 9 real packets count — below the floor of 10.
        $this->assertSame(9, $report['packets_count']);
        $this->assertSame(5, $report['excluded_filler_recovery_count']);
        $this->assertSame(BacklogDepthGovernorService::STATUS_BELOW_FLOOR, $report['status']);
        $this->assertTrue($report['blocks_24h']);
        $this->assertContains('filler_or_recovery_excluded_from_depth', $report['warnings']);
    }

    public function test_unpromoted_factory_proposals_are_not_counted(): void
    {
        // 8 real packets + 4 un-promoted factory proposals must stay below floor.
        $input = $this->okFixture();
        $input['self_construction_packets'] = $this->packets(8);
        $input['factory_proposals'] = [
            ['proposal_id' => 'p1', 'promoted_to_canonical' => false, 'risk_level' => 'high'],
            ['proposal_id' => 'p2', 'promoted' => false, 'risk_level' => 'medium'],
            ['proposal_id' => 'p3', 'promotion_state' => 'draft', 'risk_level' => 'medium'],
            ['proposal_id' => 'p4', 'risk_level' => 'low'], // no promotion flag => not promoted
        ];

        $report = $this->service()->assess($input);

        $this->assertSame(8, $report['packets_count'], 'un-promoted proposals must not add to depth');
        $this->assertSame(4, $report['unpromoted_proposals_count']);
        $this->assertSame(BacklogDepthGovernorService::STATUS_BELOW_FLOOR, $report['status']);
        $this->assertTrue($report['blocks_24h']);
        $this->assertContains('unpromoted_factory_proposals_excluded_from_depth', $report['warnings']);
    }

    public function test_promoted_factory_proposals_are_counted_toward_depth(): void
    {
        $input = $this->okFixture();
        $input['self_construction_packets'] = $this->packets(8);
        $input['factory_proposals'] = [
            // promoted with explicit packets => contributes 2 packets + 1 parent
            ['proposal_id' => 'p1', 'promoted_to_canonical' => true, 'risk_level' => 'high', 'packets' => [
                ['packet_id' => 'pp1'],
                ['packet_id' => 'pp2'],
            ]],
        ];

        $report = $this->service()->assess($input);

        $this->assertSame(10, $report['packets_count'], 'promoted proposal packets count toward depth');
        $this->assertSame(BacklogDepthGovernorService::STATUS_OK, $report['status']);
        $this->assertFalse($report['blocks_24h']);
        // promoted high-risk packets reflected in the distribution
        $this->assertSame(2, $report['risk_distribution']['high']);
    }

    public function test_locked_and_quarantined_packets_are_counted_and_excluded_from_runway(): void
    {
        $packets = $this->packets(10);
        $packets[] = ['packet_id' => 'lk', 'review_locked' => true, 'risk_level' => 'medium'];
        $packets[] = ['packet_id' => 'qz', 'quarantined' => true, 'risk_level' => 'medium'];

        $input = $this->okFixture();
        $input['self_construction_packets'] = $packets;

        $report = $this->service()->assess($input);

        $this->assertSame(10, $report['packets_count'], 'locked/quarantined packets are not executable runway');
        $this->assertSame(1, $report['locked_count']);
        $this->assertSame(1, $report['quarantined_count']);
        $this->assertContains('review_locked_packets_excluded_from_runway', $report['warnings']);
        $this->assertContains('quarantined_packets_excluded_from_runway', $report['warnings']);
    }

    public function test_locked_and_quarantined_counts_also_read_from_blocker_reports(): void
    {
        $input = $this->okFixture();
        $input['blocker_reports'] = [
            ['blocked' => [
                ['finding_id' => 'x1', 'state' => 'review_locked'],
                ['finding_id' => 'x2', 'state' => 'quarantined'],
                ['finding_id' => 'x3', 'status' => 'locked'],
            ]],
        ];

        $report = $this->service()->assess($input);

        $this->assertSame(2, $report['locked_count']);
        $this->assertSame(1, $report['quarantined_count']);
    }

    public function test_risk_distribution_buckets_admissible_packets(): void
    {
        $input = $this->okFixture();
        $input['self_construction_packets'] = array_merge(
            $this->packets(4, 'low'),
            $this->packets(3, 'high'),
            $this->packets(3, 'critical'),
        );

        $report = $this->service()->assess($input);

        $this->assertSame(['low' => 4, 'medium' => 0, 'high' => 3, 'critical' => 3], $report['risk_distribution']);
        $this->assertSame(10, $report['packets_count']);
    }

    public function test_runtime_gap_matrix_items_add_canonical_parent_depth(): void
    {
        $input = $this->okFixture();
        $input['runtime_gap_matrix'] = [
            ['gap_id' => 'g1', 'origin_type' => 'runtime_gap'],
            ['gap_id' => 'g2', 'origin_type' => 'runtime_gap'],
            ['gap_id' => 'gf', 'origin_type' => 'starvation_recovery'], // filler excluded
        ];

        $report = $this->service()->assess($input);

        // 2 canonical parents + 2 admissible gaps; filler gap excluded.
        $this->assertSame(4, $report['parents_count']);
    }

    public function test_accepts_input_via_fixture_seam(): void
    {
        $report = $this->service()->assess(['fixture' => $this->okFixture()]);

        $this->assertSame(BacklogDepthGovernorService::STATUS_OK, $report['status']);
        $this->assertSame(12, $report['packets_count']);
    }

    public function test_custom_floor_is_respected(): void
    {
        $input = $this->okFixture();
        $input['floor'] = 15; // 12 packets now below a higher floor

        $report = $this->service()->assess($input);

        $this->assertSame(15, $report['floor']);
        $this->assertSame(BacklogDepthGovernorService::STATUS_BELOW_FLOOR, $report['status']);
        $this->assertTrue($report['blocks_24h']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = $this->okFixture();

        $first = $this->service()->assess($input);
        $second = $this->service()->assess($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A below-floor input must hash stably too AND differ from the ok hash.
        $blocked = $input;
        $blocked['self_construction_packets'] = $this->packets(2);
        $b1 = $this->service()->assess($blocked);
        $b2 = $this->service()->assess($blocked);
        $this->assertSame($b1['report_hash'], $b2['report_hash']);
        $this->assertNotSame($first['report_hash'], $b1['report_hash']);
    }

    public function test_default_empty_input_does_not_crash_and_blocks_honestly(): void
    {
        // Diagnostic default: an empty backlog is, honestly, below the floor.
        $report = $this->service()->assess([]);

        $this->assertSame(BacklogDepthGovernorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(BacklogDepthGovernorService::STATUS_BELOW_FLOOR, $report['status']);
        $this->assertTrue($report['blocks_24h']);
        $this->assertSame(0, $report['packets_count']);
        $this->assertSame(0, $report['parents_count']);
        $this->assertSame(BacklogDepthGovernorService::DEFAULT_FLOOR, $report['floor']);
        $this->assertSame('LHL-09', $report['slice_id']);
        $this->assertSame(['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0], $report['risk_distribution']);
        $this->assertFalse($report['claim_policy']['filler_recovery_counts_as_depth']);
        $this->assertFalse($report['claim_policy']['unpromoted_proposals_count_as_depth']);
    }
}
