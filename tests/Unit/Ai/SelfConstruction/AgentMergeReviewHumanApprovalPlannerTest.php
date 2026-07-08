<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewHumanApprovalPlanner;
use Tests\TestCase;

final class AgentMergeReviewHumanApprovalPlannerTest extends TestCase
{
    private function planner(): AgentMergeReviewHumanApprovalPlanner
    {
        return new AgentMergeReviewHumanApprovalPlanner();
    }

    private function cleanScopeVerification(): array
    {
        return [
            'verification' => [
                'forbidden_violation_count' => 0,
                'cross_axis_violation_count' => 0,
                'unsafe_path_violation_count' => 0,
            ],
        ];
    }

    private function lowRisk(): array
    {
        return ['risk' => ['overall_band' => 'low', 'blockers' => []]];
    }

    private function packet(array $overrides = []): array
    {
        return array_merge([
            'packet' => [
                'packet_id' => 'pkt-1',
                'allowed_files' => ['app/Services/Foo.php'],
                'changes' => [['path' => 'app/Services/Foo.php']],
            ],
        ], $overrides);
    }

    // ── AC: low-risk clean-scope packets with fresh executable proof use autonomous_approval ──

    public function test_low_risk_clean_scope_with_fresh_proof_uses_autonomous_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => true],
        );

        $this->assertSame('autonomous_approval', $r['plan']['approval_mode']);
        $this->assertSame('autonomous_approval_ready', $r['status']);
    }

    public function test_low_risk_without_fresh_proof_does_not_use_autonomous(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => false],
        );

        $this->assertNotSame('autonomous_approval', $r['plan']['approval_mode']);
    }

    public function test_medium_risk_does_not_use_autonomous_even_with_proof(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            ['risk' => ['overall_band' => 'medium', 'blockers' => []]],
            ['has_fresh_executable_proof' => true],
        );

        $this->assertNotSame('autonomous_approval', $r['plan']['approval_mode']);
    }

    // ── AC: migrations, destructive deletes, unsafe prefixes or stale evidence require human approval ──

    public function test_migration_in_allowed_files_requires_human_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(['packet' => [
                'packet_id' => 'pkt-mig',
                'allowed_files' => ['database/migrations/2026_01_01_create_foo.php'],
                'changes' => [],
            ]]),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => true],
        );

        $this->assertNotSame('autonomous_approval', $r['plan']['approval_mode']);
        $this->assertContains('migration_detected_in_allowed_files', $r['plan']['human_approval_reasons']);
    }

    public function test_destructive_delete_requires_human_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => true, 'contains_destructive_delete' => true],
        );

        $this->assertNotSame('autonomous_approval', $r['plan']['approval_mode']);
        $this->assertContains('destructive_delete_detected', $r['plan']['human_approval_reasons']);
    }

    public function test_unsafe_path_prefix_requires_human_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(['packet' => [
                'packet_id' => 'pkt-unsafe',
                'allowed_files' => ['app/Services/Foo.php'],
                'changes' => [['path' => 'config/app.php']],
            ]]),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => true],
        );

        $this->assertNotSame('autonomous_approval', $r['plan']['approval_mode']);
        $this->assertNotEmpty($r['plan']['human_approval_reasons']);
    }

    public function test_stale_evidence_requires_human_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => true, 'stale_evidence' => true],
        );

        $this->assertNotSame('autonomous_approval', $r['plan']['approval_mode']);
        $this->assertContains('stale_evidence_detected', $r['plan']['human_approval_reasons']);
    }

    // ── AC: approval modes remain single, double or quorum only when escalation is necessary ──

    public function test_medium_risk_uses_double_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            ['risk' => ['overall_band' => 'medium', 'blockers' => []]],
            ['has_fresh_executable_proof' => true],
        );

        $this->assertSame('double', $r['plan']['approval_mode']);
    }

    public function test_high_risk_uses_double_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            ['risk' => ['overall_band' => 'high', 'blockers' => []]],
            ['has_fresh_executable_proof' => true],
        );

        $this->assertSame('double', $r['plan']['approval_mode']);
    }

    public function test_critical_risk_uses_quorum_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            ['risk' => ['overall_band' => 'critical', 'blockers' => []]],
            ['has_fresh_executable_proof' => true],
        );

        $this->assertSame('quorum', $r['plan']['approval_mode']);
    }

    public function test_low_risk_without_proof_uses_single_approval(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => false],
        );

        $this->assertSame('single', $r['plan']['approval_mode']);
    }

    public function test_approval_modes_are_only_autonomous_single_double_quorum(): void
    {
        $this->assertSame(
            ['autonomous_approval', 'single', 'double', 'quorum'],
            AgentMergeReviewHumanApprovalPlanner::APPROVAL_MODES,
        );
    }

    // ── schema and structure ──

    public function test_output_has_required_keys(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => true],
        );

        $this->assertSame(AgentMergeReviewHumanApprovalPlanner::SCHEMA_VERSION, $r['schema_version']);
        $this->assertArrayHasKey('plan', $r);
        $this->assertArrayHasKey('approval_mode', $r['plan']);
        $this->assertArrayHasKey('human_approval_reasons', $r['plan']);
        $this->assertArrayHasKey('blocking_conditions', $r['plan']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $r = $this->planner()->plan(
            $this->packet(),
            $this->cleanScopeVerification(),
            $this->lowRisk(),
            ['has_fresh_executable_proof' => true],
        );

        $this->assertNotEmpty($r['non_execution_guarantees']);
        $this->assertFalse($r['approval_granted']);
        $this->assertFalse($r['apply_patch_allowed']);
    }

    public function test_plan_is_deterministic(): void
    {
        $packet = $this->packet();
        $scope = $this->cleanScopeVerification();
        $risk = $this->lowRisk();
        $opts = ['has_fresh_executable_proof' => true];

        $a = $this->planner()->plan($packet, $scope, $risk, $opts);
        $b = $this->planner()->plan($packet, $scope, $risk, $opts);

        $this->assertSame($a['plan_hash'], $b['plan_hash']);
    }
}
