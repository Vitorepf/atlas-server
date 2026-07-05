<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneRollbackPlanBuilder;
use Tests\TestCase;

final class AgentControlPlaneRollbackPlanBuilderTest extends TestCase
{
    private function builder(): AgentControlPlaneRollbackPlanBuilder
    {
        return new AgentControlPlaneRollbackPlanBuilder;
    }

    private function workspacePlan(): array
    {
        return ['workspace_plan_id' => 'ws-001'];
    }

    // ── AC: pure additive test/code changes receive rollback_class=safe_revert with verification command ──

    public function test_additive_test_file_gets_safe_revert(): void
    {
        $diff = ['artifacts' => [
            ['path' => 'tests/Unit/Foo/NewTest.php', 'change_type' => 'added'],
        ]];

        $plan = $this->builder()->build($this->workspacePlan(), $diff);

        $this->assertSame('safe_revert', $plan['rollback_class']);
        $this->assertSame('rollback_plan_ready', $plan['status']);
        $this->assertCount(1, $plan['steps']);
        $this->assertSame('safe_revert', $plan['steps'][0]['rollback_class']);
        $this->assertNotEmpty($plan['steps'][0]['verification_command']);
    }

    public function test_additive_code_file_gets_safe_revert(): void
    {
        $diff = ['artifacts' => [
            ['path' => 'app/Services/NewService.php', 'change_type' => 'added'],
        ]];

        $plan = $this->builder()->build($this->workspacePlan(), $diff);

        $this->assertSame('safe_revert', $plan['rollback_class']);
    }

    // ── AC: behavior-changing service edits require behavior_parity_checks ──

    public function test_behavior_changing_edit_requires_parity_checks(): void
    {
        $diff = ['artifacts' => [
            ['path' => 'app/Services/ExistingService.php', 'change_type' => 'modified'],
        ]];

        $plan = $this->builder()->build($this->workspacePlan(), $diff);

        $this->assertSame('behavior_parity', $plan['rollback_class']);
        $this->assertNotEmpty($plan['behavior_parity_checks']);
        $this->assertSame('behavior_parity_test_must_pass_before_release', $plan['behavior_parity_checks'][0]['required_check']);
    }

    // ── AC: destructive or missing-preview changes are classified manual_escalation ──

    public function test_destructive_change_classified_manual_escalation(): void
    {
        $diff = ['artifacts' => [
            ['path' => 'app/Services/DeletedService.php', 'change_type' => 'deleted'],
        ]];

        $plan = $this->builder()->build($this->workspacePlan(), $diff);

        $this->assertSame('manual_escalation', $plan['rollback_class']);
        $this->assertNotEmpty($plan['rollback_reason']);
    }

    public function test_missing_preview_classified_manual_escalation(): void
    {
        $plan = $this->builder()->build($this->workspacePlan(), []);

        $this->assertSame('manual_escalation', $plan['rollback_class']);
        $this->assertSame('no_diff_preview_artifacts_provided', $plan['rollback_reason']);
    }

    // ── mixed scenarios ──

    public function test_mixed_safe_and_behavior_upgrades_to_behavior_parity(): void
    {
        $diff = ['artifacts' => [
            ['path' => 'tests/Unit/NewTest.php', 'change_type' => 'added'],
            ['path' => 'app/Services/ExistingService.php', 'change_type' => 'modified'],
        ]];

        $plan = $this->builder()->build($this->workspacePlan(), $diff);

        $this->assertSame('behavior_parity', $plan['rollback_class']);
    }

    public function test_mixed_with_destructive_upgrades_to_manual_escalation(): void
    {
        $diff = ['artifacts' => [
            ['path' => 'tests/Unit/NewTest.php', 'change_type' => 'added'],
            ['path' => 'app/Services/DeletedService.php', 'change_type' => 'deleted'],
        ]];

        $plan = $this->builder()->build($this->workspacePlan(), $diff);

        $this->assertSame('manual_escalation', $plan['rollback_class']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $diff = ['artifacts' => [['path' => 'tests/Unit/Foo.php', 'change_type' => 'added']]];

        $plan = $this->builder()->build($this->workspacePlan(), $diff);

        foreach (['schema_version', 'status', 'rollback_class', 'rollback_reason', 'steps', 'behavior_parity_checks'] as $key) {
            $this->assertArrayHasKey($key, $plan, "missing key: {$key}");
        }
    }

    public function test_plan_hash_is_deterministic(): void
    {
        $diff = ['artifacts' => [['path' => 'tests/Unit/Foo.php', 'change_type' => 'added']]];

        $a = $this->builder()->build($this->workspacePlan(), $diff);
        $b = $this->builder()->build($this->workspacePlan(), $diff);

        $this->assertSame($a['rollback_plan_hash'], $b['rollback_plan_hash']);
    }
}
