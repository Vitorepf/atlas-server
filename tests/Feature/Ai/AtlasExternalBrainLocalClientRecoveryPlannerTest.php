<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientRecoveryPlanner;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientRecoveryPlannerTest extends TestCase
{
    private AtlasExternalBrainLocalClientRecoveryPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new AtlasExternalBrainLocalClientRecoveryPlanner;
    }

    private function input(string $failureType, array $overrides = []): array
    {
        return array_merge([
            'failure_type' => $failureType,
            'task_packet_id' => 'codex-meta-fixture-task',
            'lease_id' => 'lease_fixture',
            'allowed_files' => ['app/Foo.php'],
            'evidence_status' => 'partial',
        ], $overrides);
    }

    public function test_stall_decides_retry_local(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertSame(AtlasExternalBrainLocalClientRecoveryPlanner::SCHEMA, $result['schema']);
        $this->assertSame('retry_local', $result['decision']);
    }

    public function test_malformed_output_decides_retry_local(): void
    {
        $result = $this->planner->plan($this->input('malformed_output'));

        $this->assertSame('retry_local', $result['decision']);
    }

    public function test_lost_login_decides_fallback_atlas_native(): void
    {
        $result = $this->planner->plan($this->input('lost_login'));

        $this->assertSame('fallback_atlas_native', $result['decision']);
    }

    public function test_quota_exhausted_decides_fallback_atlas_native(): void
    {
        $result = $this->planner->plan($this->input('quota_exhausted'));

        $this->assertSame('fallback_atlas_native', $result['decision']);
    }

    public function test_ui_brittle_decides_fallback_manual_muscle(): void
    {
        $result = $this->planner->plan($this->input('ui_brittle'));

        $this->assertSame('fallback_manual_muscle', $result['decision']);
    }

    public function test_scope_violation_decides_give_back_task(): void
    {
        $result = $this->planner->plan($this->input('scope_violation'));

        $this->assertSame('give_back_task', $result['decision']);
    }

    public function test_missing_evidence_decides_give_back_task(): void
    {
        $result = $this->planner->plan($this->input('missing_evidence'));

        $this->assertSame('give_back_task', $result['decision']);
    }

    public function test_unknown_failure_type_defaults_to_give_back_task(): void
    {
        $result = $this->planner->plan($this->input('something_unexpected'));

        $this->assertSame('give_back_task', $result['decision']);
    }

    public function test_plan_preserves_task_identity_and_scope_without_mutation(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertSame('codex-meta-fixture-task', $result['task_packet_id']);
        $this->assertSame('lease_fixture', $result['lease_id']);
        $this->assertSame(['app/Foo.php'], $result['allowed_files']);
        $this->assertSame('partial', $result['evidence_status']);
        $this->assertNotEmpty($result['next_safe_action']);
    }

    public function test_no_loss_recovery_plan_true_when_ids_preserved(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertTrue($result['no_loss_recovery_plan']);
    }

    public function test_no_loss_recovery_plan_false_when_lease_id_missing(): void
    {
        $result = $this->planner->plan($this->input('stall', ['lease_id' => '']));

        $this->assertFalse($result['no_loss_recovery_plan']);
    }

    public function test_no_loss_recovery_plan_false_when_task_packet_id_missing(): void
    {
        $result = $this->planner->plan($this->input('stall', ['task_packet_id' => '']));

        $this->assertFalse($result['no_loss_recovery_plan']);
    }
}
