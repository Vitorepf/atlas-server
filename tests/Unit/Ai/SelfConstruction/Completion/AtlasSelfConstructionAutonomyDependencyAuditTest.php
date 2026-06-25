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
}
