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

    private function allPassVotes(): array
    {
        return [
            'scope' => ['status' => 'pass'],
            'evidence' => ['status' => 'pass'],
            'isolation' => ['status' => 'pass'],
            'rollback' => ['status' => 'pass'],
        ];
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
            'votes' => $this->allPassVotes(),
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
            'votes' => $this->allPassVotes(),
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
            'votes' => $this->allPassVotes(),
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
            'votes' => $this->allPassVotes(),
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
            'votes' => $this->allPassVotes(),
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
            'votes' => $this->allPassVotes(),
        ]);

        $this->assertSame('blocked', $result['verdict']);
    }

    // ── AC: missing scope, evidence, isolation or rollback vote prevents verdict=pass ──

    public function test_missing_scope_vote_prevents_pass(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
            'votes' => [
                'evidence' => ['status' => 'pass'],
                'isolation' => ['status' => 'pass'],
                'rollback' => ['status' => 'pass'],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('missing_vote:scope', $result['blockers']);
    }

    public function test_missing_evidence_vote_prevents_pass(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
            'votes' => [
                'scope' => ['status' => 'pass'],
                'isolation' => ['status' => 'pass'],
                'rollback' => ['status' => 'pass'],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('missing_vote:evidence', $result['blockers']);
    }

    public function test_missing_isolation_vote_prevents_pass(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
            'votes' => [
                'scope' => ['status' => 'pass'],
                'evidence' => ['status' => 'pass'],
                'rollback' => ['status' => 'pass'],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('missing_vote:isolation', $result['blockers']);
    }

    public function test_missing_rollback_vote_prevents_pass(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
            'votes' => [
                'scope' => ['status' => 'pass'],
                'evidence' => ['status' => 'pass'],
                'isolation' => ['status' => 'pass'],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('missing_vote:rollback', $result['blockers']);
    }

    // ── AC: blocked votes dominate hold votes in adjudication ──

    public function test_blocked_votes_dominate_hold_votes(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
            'votes' => [
                'scope' => ['status' => 'pass'],
                'evidence' => ['status' => 'hold'],
                'isolation' => ['status' => 'blocked'],
                'rollback' => ['status' => 'pass'],
            ],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('vote_blocked:isolation', $result['blockers']);
        $this->assertContains('blocked_votes_dominate:1', $result['blockers']);
    }

    // ── AC: all required pass votes produce verdict=pass with provider-safe vote summary ──

    public function test_all_pass_votes_produce_verdict_pass_with_provider_safe_summary(): void
    {
        $result = $this->court->verify([
            'project_id' => 'proj-1',
            'lane_root' => 'app/Brain',
            'quorum_floor' => 1,
            'evidence' => [
                ['hash' => 'h1', 'project_id' => 'proj-1', 'lane_root' => 'app/Brain', 'gate_id' => 'g1', 'freshness_iso' => $this->nowPlus(0)],
            ],
            'votes' => $this->allPassVotes(),
        ]);

        $this->assertSame('passed', $result['verdict']);
        $this->assertEmpty($result['blockers']);
        $this->assertArrayHasKey('vote_summary', $result);
        $this->assertTrue($result['provider_safe']);
        $this->assertSame('pass', $result['vote_summary']['scope']);
        $this->assertSame('pass', $result['vote_summary']['evidence']);
        $this->assertSame('pass', $result['vote_summary']['isolation']);
        $this->assertSame('pass', $result['vote_summary']['rollback']);
    }
}
