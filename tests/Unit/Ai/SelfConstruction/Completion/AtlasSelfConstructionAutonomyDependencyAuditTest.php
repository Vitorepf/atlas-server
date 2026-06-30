<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAutonomyDependencyAudit;
use Tests\TestCase;

final class AtlasSelfConstructionAutonomyDependencyAuditTest extends TestCase
{
    public function test_clean_atlas_native_steady_state_passes(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
            ['step_id' => 'verify', 'kind' => 'steady_state', 'role' => 'atlas_native'],
            ['step_id' => 'merge', 'kind' => 'steady_state', 'role' => 'atlas_native'],
        ]);

        $this->assertTrue($verdict['atlas_native']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame([], $verdict['steady_state_dependencies']);
    }

    public function test_bootstrap_visibility_with_operator_is_allowed_when_steady_state_remains_atlas(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'bootstrap_open_dashboard', 'kind' => 'bootstrap', 'role' => 'operator'],
            ['step_id' => 'emergency_killswitch', 'kind' => 'emergency', 'role' => 'human'],
            ['step_id' => 'steady_observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
        ]);

        $this->assertTrue($verdict['atlas_native']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertNotEmpty($verdict['allowed_visibility']);
    }

    public function test_steady_state_operator_dependency_blocks(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
            ['step_id' => 'verify', 'kind' => 'steady_state', 'role' => 'operator'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('steady_state_non_atlas_dependency:verify:operator', $verdict['blockers']);
        $this->assertSame([['step_id' => 'verify', 'role' => 'operator']], $verdict['steady_state_dependencies']);
    }

    public function test_steady_state_human_dependency_blocks(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'review', 'kind' => 'steady_state', 'role' => 'human'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('steady_state_non_atlas_dependency:review:human', $verdict['blockers']);
    }

    public function test_steady_state_provider_dependency_blocks(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'reason', 'kind' => 'steady_state', 'role' => 'provider'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('steady_state_non_atlas_dependency:reason:provider', $verdict['blockers']);
    }

    public function test_unknown_kind_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'mystery', 'kind' => 'magical', 'role' => 'atlas_native'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('unknown_kind:mystery:magical', $verdict['blockers']);
    }

    public function test_empty_evidence_blocks_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('empty_evidence', $verdict['blockers']);
    }

    public function test_unknown_role_blocks_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'ghost_worker'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('unknown_role:observe:ghost_worker', $verdict['blockers']);
    }

    public function test_duplicate_step_id_blocks_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('duplicate_step_id:observe', $verdict['blockers']);
    }

    public function test_missing_required_steady_state_phases_blocks_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit(
            [['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native']],
            ['observe', 'verify', 'merge'],
        );

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('missing_steady_state_phase:verify', $verdict['blockers']);
        $this->assertContains('missing_steady_state_phase:merge', $verdict['blockers']);
    }

    public function test_bootstrap_and_emergency_with_non_atlas_remain_non_blocking_when_steady_state_phases_complete(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit(
            [
                ['step_id' => 'boot_open', 'kind' => 'bootstrap', 'role' => 'operator'],
                ['step_id' => 'kill_switch', 'kind' => 'emergency', 'role' => 'human'],
                ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
                ['step_id' => 'verify', 'kind' => 'steady_state', 'role' => 'atlas_native'],
            ],
            ['observe', 'verify'],
        );

        $this->assertTrue($verdict['atlas_native']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertCount(2, $verdict['allowed_visibility']);
    }

    // ── projection_status checks ──────────────────────────────────────────────

    public function test_stale_projection_blocks_steady_state_phase(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native', 'projection_status' => 'stale'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('stale_projection:observe', $verdict['blockers']);
    }

    public function test_unavailable_projection_blocks_steady_state_phase(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'verify', 'kind' => 'steady_state', 'role' => 'atlas_native', 'projection_status' => 'unavailable'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('unavailable_projection:verify', $verdict['blockers']);
    }

    public function test_fresh_projection_does_not_block(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native', 'projection_status' => 'fresh'],
        ]);

        $this->assertTrue($verdict['atlas_native']);
        $this->assertSame([], $verdict['blockers']);
    }

    // ── queue_evidence / runtime_evidence checks ──────────────────────────────

    public function test_unavailable_queue_evidence_blocks_steady_state_phase(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'replenish', 'kind' => 'steady_state', 'role' => 'atlas_native', 'queue_evidence' => 'unavailable'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('unavailable_queue_evidence:replenish', $verdict['blockers']);
    }

    public function test_unavailable_runtime_evidence_blocks_steady_state_phase(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'execute', 'kind' => 'steady_state', 'role' => 'atlas_native', 'runtime_evidence' => 'unavailable'],
        ]);

        $this->assertFalse($verdict['atlas_native']);
        $this->assertContains('unavailable_runtime_evidence:execute', $verdict['blockers']);
    }

    public function test_bootstrap_stale_projection_does_not_block(): void
    {
        // Only steady_state phases trigger projection/queue/runtime checks.
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'boot', 'kind' => 'bootstrap', 'role' => 'operator', 'projection_status' => 'stale'],
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
        ]);

        $this->assertTrue($verdict['atlas_native']);
        $this->assertSame([], $verdict['blockers']);
    }

    // ── remediation_hints ─────────────────────────────────────────────────────

    public function test_remediation_hints_present_in_output(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
        ]);

        $this->assertArrayHasKey('remediation_hints', $verdict);
        $this->assertIsArray($verdict['remediation_hints']);
    }

    public function test_remediation_hints_contains_phase_specific_hint_for_non_native_role(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'verify', 'kind' => 'steady_state', 'role' => 'operator'],
        ]);

        $hints = $verdict['remediation_hints'];
        $this->assertArrayHasKey('steady_state_non_atlas_dependency:verify:operator', $hints);
        $this->assertStringContainsString('verify', $hints['steady_state_non_atlas_dependency:verify:operator']);
    }

    public function test_remediation_hints_contains_stale_projection_hint(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native', 'projection_status' => 'stale'],
        ]);

        $this->assertArrayHasKey('stale_projection:observe', $verdict['remediation_hints']);
        $this->assertStringContainsString('observe', $verdict['remediation_hints']['stale_projection:observe']);
    }

    public function test_remediation_hints_contains_missing_phase_hint(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit(
            [['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native']],
            ['observe', 'verify'],
        );

        $this->assertArrayHasKey('missing_steady_state_phase:verify', $verdict['remediation_hints']);
        $this->assertStringContainsString('verify', $verdict['remediation_hints']['missing_steady_state_phase:verify']);
    }

    public function test_all_evidence_checks_pass_when_fresh_and_available(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDependencyAudit)->audit([
            [
                'step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native',
                'projection_status' => 'fresh', 'queue_evidence' => 'available', 'runtime_evidence' => 'available',
            ],
        ]);

        $this->assertTrue($verdict['atlas_native']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame([], $verdict['remediation_hints']);
    }
}
