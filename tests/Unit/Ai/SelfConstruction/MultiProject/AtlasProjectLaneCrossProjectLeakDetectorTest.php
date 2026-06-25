<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneCrossProjectLeakDetector;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasProjectLaneCrossProjectLeakDetector: clean two-lane input passes; namespaced packet_id
 * pointing at another lane yields task_packet_id_namespace_mismatch; allowed_files outside the lane roots
 * yields allowed_files_escape_lane; receipt filed under a different project yields receipt_project_mismatch;
 * release.target_project_id ≠ release.project_id yields release_target_mismatch; leak samples are bounded.
 */
final class AtlasProjectLaneCrossProjectLeakDetectorTest extends TestCase
{
    private function lanes(): array
    {
        return [
            'lane-a' => [
                'project_id' => 'lane-a',
                'namespace' => 'lane.lane-a.aaaaaaaa.main',
                'allowed_scope_roots' => ['/repo/lane-a/app'],
            ],
            'lane-b' => [
                'project_id' => 'lane-b',
                'namespace' => 'lane.lane-b.bbbbbbbb.main',
                'allowed_scope_roots' => ['/repo/lane-b/app'],
            ],
        ];
    }

    public function test_clean_two_lane_input_passes_with_zero_leaks(): void
    {
        $inspected = [
            'packets' => [
                ['project_id' => 'lane-a', 'task_packet_id' => 'lane.lane-a.aaaaaaaa.main:p1', 'allowed_files' => ['/repo/lane-a/app/foo.php']],
                ['project_id' => 'lane-b', 'task_packet_id' => 'lane.lane-b.bbbbbbbb.main:q1', 'allowed_files' => ['/repo/lane-b/app/bar.php']],
            ],
            'receipts' => [
                ['filed_under_project_id' => 'lane-a', 'project_id' => 'lane-a', 'envelope_hash' => 'h-1'],
            ],
            'releases' => [
                ['project_id' => 'lane-a', 'target_project_id' => 'lane-a'],
            ],
        ];

        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), $inspected);
        $this->assertTrue($r['passed']);
        $this->assertSame('clean', $r['status']);
        $this->assertSame([], $r['leaks']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame(4, $r['inspected_count']);
    }

    public function test_namespace_mismatch_in_packet_id_is_caught(): void
    {
        $inspected = [
            'packets' => [
                ['project_id' => 'lane-a', 'task_packet_id' => 'lane.lane-b.bbbbbbbb.main:cross-1', 'allowed_files' => ['/repo/lane-a/app/foo.php']],
            ],
        ];
        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), $inspected);
        $this->assertFalse($r['passed']);
        $this->assertContains('task_packet_id_namespace_mismatch', $r['blockers']);
    }

    public function test_allowed_files_escaping_lane_root_is_caught(): void
    {
        $inspected = [
            'packets' => [
                ['project_id' => 'lane-a', 'task_packet_id' => 'lane.lane-a.aaaaaaaa.main:p1', 'allowed_files' => ['/etc/passwd']],
            ],
        ];
        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), $inspected);
        $this->assertContains('allowed_files_escape_lane', $r['blockers']);
    }

    public function test_scope_in_escaping_lane_root_is_caught(): void
    {
        $inspected = [
            'packets' => [
                ['project_id' => 'lane-a', 'task_packet_id' => 'lane.lane-a.aaaaaaaa.main:p1',
                 'allowed_files' => ['/repo/lane-a/app/foo.php'], 'scope_in' => ['/repo/lane-b/app/secret.php']],
            ],
        ];
        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), $inspected);
        $this->assertContains('scope_in_escape_lane', $r['blockers']);
    }

    public function test_receipt_project_mismatch_is_caught(): void
    {
        $inspected = [
            'receipts' => [
                ['filed_under_project_id' => 'lane-a', 'project_id' => 'lane-b', 'envelope_hash' => 'h-x'],
            ],
        ];
        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), $inspected);
        $this->assertContains('receipt_project_mismatch', $r['blockers']);
    }

    public function test_release_target_mismatch_is_caught(): void
    {
        $inspected = [
            'releases' => [
                ['project_id' => 'lane-a', 'target_project_id' => 'lane-b'],
            ],
        ];
        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), $inspected);
        $this->assertContains('release_target_mismatch', $r['blockers']);
    }

    public function test_packet_project_id_not_in_any_lane_is_caught(): void
    {
        $inspected = [
            'packets' => [['project_id' => 'phantom-lane', 'task_packet_id' => 'x', 'allowed_files' => ['/etc/y']]],
        ];
        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), $inspected);
        $this->assertContains('packet_project_id_mismatch', $r['blockers']);
    }

    public function test_leak_samples_are_bounded_at_max_samples(): void
    {
        $packets = [];
        for ($i = 0; $i < 200; $i++) {
            $packets[] = ['project_id' => 'phantom-lane', 'task_packet_id' => 'x'.$i, 'allowed_files' => []];
        }
        $r = (new AtlasProjectLaneCrossProjectLeakDetector)->detect($this->lanes(), ['packets' => $packets]);
        $this->assertLessThanOrEqual(AtlasProjectLaneCrossProjectLeakDetector::MAX_SAMPLES, count($r['leaks']));
    }
}
