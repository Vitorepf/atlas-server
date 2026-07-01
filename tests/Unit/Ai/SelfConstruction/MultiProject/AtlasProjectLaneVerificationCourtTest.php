<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneVerificationCourt;
use PHPUnit\Framework\TestCase;

final class AtlasProjectLaneVerificationCourtTest extends TestCase
{
    private AtlasProjectLaneVerificationCourt $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->court = new AtlasProjectLaneVerificationCourt();
    }

    private function nowPlus(int $seconds): string
    {
        return date('c', time() + $seconds);
    }

    public function test_quorum_below_floor_blocked(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 3,
            'required_rerun_gates' => [],
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertTrue(count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'quorum_below_floor'))) > 0);
    }

    public function test_stale_evidence_blocked(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(-7200), 'stale_after_seconds' => 3600],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertTrue(count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'quorum_below_floor'))) > 0);
    }

    public function test_outside_lane_roots_blocked(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Cortex', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
    }

    public function test_missing_required_gate_blocked(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'required_rerun_gates' => ['lint', 'test'],
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'lint', 'freshness_iso' => $this->nowPlus(0)],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertTrue(count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'missing_gate_evidence:test'))) > 0);
    }

    public function test_full_quorum_all_gates_passes(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 2,
            'required_rerun_gates' => ['lint', 'test'],
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'lint', 'freshness_iso' => $this->nowPlus(0)],
                ['hash' => 'h2', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'test', 'freshness_iso' => $this->nowPlus(0)],
            ],
        ]);

        $this->assertSame('passed', $result['verdict']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_wrong_project_id_blocked(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-2', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
    }
}
