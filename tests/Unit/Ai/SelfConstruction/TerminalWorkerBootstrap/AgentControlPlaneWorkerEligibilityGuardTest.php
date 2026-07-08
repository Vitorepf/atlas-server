<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TerminalWorkerBootstrap;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneWorkerEligibilityGuard;
use Tests\TestCase;

/**
 * Proves workerEligibilityGuardForPacket() rejects workers missing required tags,
 * allowed scope, test capability, evidence reporting, or risk tolerance for the packet.
 */
final class AgentControlPlaneWorkerEligibilityGuardTest extends TestCase
{
    private function guard(): AgentControlPlaneWorkerEligibilityGuard
    {
        return new AgentControlPlaneWorkerEligibilityGuard(
            new AgentControlPlaneTaskPacketQueueRepository,
        );
    }

    private function workerProfile(array $overrides = []): array
    {
        return array_merge([
            'tags' => ['php', 'atlas'],
            'allowed_scope' => ['app/Services/', 'tests/'],
            'can_run_tests' => true,
            'can_report_evidence' => true,
            'risk_tolerance' => 'medium',
        ], $overrides);
    }

    private function packet(array $overrides = []): array
    {
        return array_merge([
            'queue_tags' => ['php', 'atlas'],
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['Runnable gate: php artisan test exits 0.'],
            'required_evidence' => ['tests_or_gates_result'],
            'risk_level' => 'low',
        ], $overrides);
    }

    // --- AC2: rejects workers missing required tags, scope, test, evidence, risk ---

    public function test_rejects_worker_missing_required_tags(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => ['php']]),
            $this->packet(['queue_tags' => ['php', 'atlas', 'critical']]),
        );

        self::assertFalse($result['eligible']);
        self::assertContains('atlas', $result['missing_tags']);
        self::assertContains('critical', $result['missing_tags']);
        self::assertSame('add_tags', $result['next_unblock_action']);
    }

    public function test_rejects_worker_missing_allowed_scope(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['allowed_scope' => ['app/Other/']]),
            $this->packet(['allowed_files' => ['app/Services/Foo.php']]),
        );

        self::assertFalse($result['eligible']);
        self::assertTrue(
            count(array_filter($result['blockers'], fn (string $b): bool => str_contains($b, 'outside_worker_scope'))) > 0,
            'should have a scope blocker',
        );
        self::assertSame('grant_scope', $result['next_unblock_action']);
    }

    public function test_rejects_worker_missing_test_capability(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['can_run_tests' => false]),
            $this->packet(),
        );

        self::assertFalse($result['eligible']);
        self::assertTrue(
            count(array_filter($result['blockers'], fn (string $b): bool => str_contains($b, 'cannot_run_tests'))) > 0,
        );
    }

    public function test_rejects_worker_missing_evidence_reporting(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['can_report_evidence' => false]),
            $this->packet(),
        );

        self::assertFalse($result['eligible']);
        self::assertTrue(
            count(array_filter($result['blockers'], fn (string $b): bool => str_contains($b, 'cannot_report_evidence'))) > 0,
        );
    }

    public function test_rejects_worker_with_insufficient_risk_tolerance(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['risk_tolerance' => 'low']),
            $this->packet(['risk_level' => 'high']),
        );

        self::assertFalse($result['eligible']);
        self::assertTrue(
            count(array_filter($result['blockers'], fn (string $b): bool => str_contains($b, 'risk_level'))) > 0,
        );
        self::assertSame('accept_risk', $result['next_unblock_action']);
    }

    // --- AC3: accepts only when worker tags match queue tags and worker can run gates ---

    public function test_accepts_when_all_criteria_met(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(),
            $this->packet(),
        );

        self::assertTrue($result['eligible']);
        self::assertSame([], $result['blockers']);
        self::assertSame('none', $result['next_unblock_action']);
    }

    public function test_accepts_when_worker_tags_superset_of_packet_tags(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => ['php', 'atlas', 'extra']]),
            $this->packet(['queue_tags' => ['php', 'atlas']]),
        );

        self::assertTrue($result['eligible']);
        self::assertSame(['php', 'atlas'], $result['matched_tags']);
        self::assertSame([], $result['missing_tags']);
    }

    public function test_rejects_when_worker_tags_do_not_match_queue_tags(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => ['python', 'django']]),
            $this->packet(['queue_tags' => ['php', 'atlas']]),
        );

        self::assertFalse($result['eligible']);
        self::assertSame([], $result['matched_tags']);
        self::assertSame(['php', 'atlas'], $result['missing_tags']);
    }

    public function test_rejects_when_worker_cannot_run_required_test_gate(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['can_run_tests' => false]),
            $this->packet(['acceptance_criteria' => ['Runnable gate: php artisan test exits 0.']]),
        );

        self::assertFalse($result['eligible']);
    }

    // --- AC4: returns eligible, blockers, matched_tags, missing_tags, next_unblock_action deterministically ---

    public function test_returns_all_required_fields_deterministically(): void
    {
        $worker = $this->workerProfile();
        $packet = $this->packet();

        $a = $this->guard()->workerEligibilityGuardForPacket($worker, $packet);
        $b = $this->guard()->workerEligibilityGuardForPacket($worker, $packet);

        self::assertArrayHasKey('eligible', $a);
        self::assertArrayHasKey('blockers', $a);
        self::assertArrayHasKey('matched_tags', $a);
        self::assertArrayHasKey('missing_tags', $a);
        self::assertArrayHasKey('next_unblock_action', $a);

        self::assertSame($a, $b, 'output must be deterministic');
    }

    public function test_matched_tags_are_intersection_of_worker_and_packet_tags(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => ['php', 'atlas', 'shared']]),
            $this->packet(['queue_tags' => ['php', 'atlas', 'other']]),
        );

        self::assertSame(['php', 'atlas'], $result['matched_tags']);
    }

    public function test_next_unblock_action_is_add_tags_when_tags_missing(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => []]),
            $this->packet(['queue_tags' => ['php']]),
        );

        self::assertSame('add_tags', $result['next_unblock_action']);
    }

    public function test_next_unblock_action_is_enable_gates_when_test_capability_missing(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => ['php', 'atlas'], 'can_run_tests' => false]),
            $this->packet(),
        );

        self::assertSame('enable_gates', $result['next_unblock_action']);
    }

    public function test_next_unblock_action_is_grant_scope_when_scope_missing(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => ['php', 'atlas'], 'allowed_scope' => ['app/Other/']]),
            $this->packet(),
        );

        self::assertSame('grant_scope', $result['next_unblock_action']);
    }

    public function test_next_unblock_action_is_accept_risk_when_risk_exceeds_tolerance(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => ['php', 'atlas'], 'risk_tolerance' => 'low']),
            $this->packet(['risk_level' => 'high']),
        );

        self::assertSame('accept_risk', $result['next_unblock_action']);
    }

    public function test_packet_with_no_tags_is_eligible_regardless_of_worker_tags(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['tags' => []]),
            $this->packet(['queue_tags' => []]),
        );

        self::assertTrue($result['eligible']);
        self::assertSame([], $result['missing_tags']);
    }

    public function test_packet_with_no_test_gate_does_not_require_test_capability(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['can_run_tests' => false]),
            $this->packet(['acceptance_criteria' => ['Implement the public API.']]),
        );

        self::assertTrue($result['eligible']);
    }

    public function test_packet_with_no_required_evidence_does_not_require_evidence_reporting(): void
    {
        $result = $this->guard()->workerEligibilityGuardForPacket(
            $this->workerProfile(['can_report_evidence' => false]),
            $this->packet(['required_evidence' => []]),
        );

        self::assertTrue($result['eligible']);
    }
}
