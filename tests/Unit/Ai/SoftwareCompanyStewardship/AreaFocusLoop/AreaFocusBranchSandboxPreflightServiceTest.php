<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxPreflightService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AP-726 branch sandbox preflight + handoff.
 *
 * Dry-run only: the preflight must create no branch, touch no target code and
 * never merge/deploy/push/secrets. All inputs are injected so the projection is
 * deterministic and side-effect free.
 */
class AreaFocusBranchSandboxPreflightServiceTest extends TestCase
{
    private function service(): AreaFocusBranchSandboxPreflightService
    {
        return app(AreaFocusBranchSandboxPreflightService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function decision(array $overrides = []): array
    {
        return array_merge([
            'decision' => 'accept',
            'executed' => false,
            'decision_id' => 'afod_abc123',
            'decision_hash' => 'sha256:dh',
            'finding_hash' => 'sha256:fh',
            'work_order_id' => 'awo_1',
            'area_id' => 'agentic_engineering_os',
            'risk_level' => 'medium',
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function workOrder(array $overrides = []): array
    {
        return array_merge([
            'work_order_id' => 'awo_1',
            'work_order_hash' => 'sha256:woh',
            'area_id' => 'agentic_engineering_os',
            'route' => 'atlas_dev',
            'status' => 'emitted',
            'title' => 'Fix flaky test',
            'risk_level' => 'medium',
            'evidence_refs' => ['ev1'],
            'recommended_action' => 'add regression test',
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function gate(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_focus_gate_report.v1',
            'decision' => 'allow',
            'blocked_when' => [],
            'warnings' => [],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function project(array $extra = []): array
    {
        return $this->service()->project(array_merge([
            'operator_decision' => $this->decision(),
            'work_order' => $this->workOrder(),
            'gate_report' => $this->gate(),
        ], $extra));
    }

    public function test_accept_emitted_dev_work_order_prepares_ready_dry_run_plan(): void
    {
        $r = $this->project();

        $this->assertSame(AreaFocusBranchSandboxPreflightService::REPORT_SCHEMA, $r['schema_version']);
        $this->assertSame('ready', $r['status']);
        $this->assertSame('dry_run_preflight', $r['mode']);
        $this->assertStringStartsWith('atlas/area-focus/agentic-engineering-os/atlas_dev/', $r['branch_plan']['branch_name']);
        $this->assertFalse($r['branch_plan']['branch_created']);
        $this->assertFalse($r['branch_plan']['worktree_created']);
        $this->assertFalse($r['branch_plan']['target_code_touched']);
        $this->assertTrue($r['branch_plan']['branch_creation_receipt_required']);
        $this->assertSame('atlas_dev', $r['handoff_packet']['target_owner']);
        $this->assertSame(AtlasDevRuntimeService::class, $r['handoff_packet']['target_owner_service']);
        $this->assertFalse($r['handoff_packet']['dispatched']);
        $this->assertFalse($r['handoff_packet']['execution_performed']);
    }

    public function test_forge_route_hands_off_to_forge(): void
    {
        $r = $this->project(['work_order' => $this->workOrder(['route' => 'forge'])]);

        $this->assertSame('ready', $r['status']);
        $this->assertSame('forge', $r['handoff_packet']['target_owner']);
        $this->assertSame(AtlasForgeParallelDurableCoordinatorService::class, $r['handoff_packet']['target_owner_service']);
        $this->assertStringContainsString('/forge/', $r['branch_plan']['branch_name']);
    }

    public function test_isolation_policy_forbids_unsafe_actions(): void
    {
        $iso = $this->project()['branch_plan']['isolation_policy'];

        $this->assertTrue($iso['sandbox_only']);
        $this->assertTrue($iso['no_push']);
        $this->assertTrue($iso['no_merge']);
        $this->assertTrue($iso['no_deploy']);
        $this->assertTrue($iso['no_secrets']);
        $this->assertTrue($iso['no_destructive_change']);
        $this->assertTrue($iso['no_force_operations']);
    }

    public function test_non_accept_decision_is_blocked(): void
    {
        $r = $this->project(['operator_decision' => $this->decision(['decision' => 'defer'])]);

        $this->assertSame('blocked', $r['status']);
        $this->assertSame('decision_not_accept', $r['reason']);
        $this->assertNull($r['branch_plan']);
        $this->assertNull($r['handoff_packet']);
    }

    public function test_already_executed_decision_is_blocked(): void
    {
        $r = $this->project(['operator_decision' => $this->decision(['executed' => true])]);

        $this->assertSame('blocked', $r['status']);
        $this->assertSame('decision_already_executed', $r['reason']);
    }

    public function test_work_order_mismatch_is_blocked(): void
    {
        $r = $this->project(['operator_decision' => $this->decision(['work_order_id' => 'awo_OTHER'])]);

        $this->assertSame('blocked', $r['status']);
        $this->assertSame('decision_work_order_mismatch', $r['reason']);
    }

    public function test_non_executable_route_is_blocked(): void
    {
        foreach (['self_directed_evolution', 'operator_review'] as $route) {
            $r = $this->project(['work_order' => $this->workOrder(['route' => $route])]);
            $this->assertSame('blocked', $r['status'], "route {$route} must block");
            $this->assertSame('route_not_executable', $r['reason']);
        }
    }

    public function test_blocked_work_order_is_blocked(): void
    {
        $r = $this->project(['work_order' => $this->workOrder(['status' => 'blocked'])]);

        $this->assertSame('blocked', $r['status']);
        $this->assertSame('work_order_blocked', $r['reason']);
    }

    public function test_safety_gate_block_stops_handoff(): void
    {
        $r = $this->project(['gate_report' => $this->gate([
            'decision' => 'block',
            'blocked_when' => [['gate' => 'no_secrets_requested', 'reason' => 'secret access requested']],
        ])]);

        $this->assertSame('blocked', $r['status']);
        $this->assertSame('safety_gate_blocked', $r['reason']);
        $this->assertNotEmpty($r['safety']['blocked_when']);
    }

    public function test_claim_policy_proves_no_mutation(): void
    {
        $policy = $this->project()['claim_policy'];

        foreach (['branch_created', 'worktree_created', 'target_code_touched', 'merges', 'deploys', 'pushes_external', 'touches_secrets', 'destructive_change', 'provider_invoked', 'dispatched_to_dev_or_forge', 'execution_performed', 'auto_approved', 'parallel_runtime_created', 'new_os_created', 'mutates_target_repo'] as $key) {
            $this->assertFalse($policy[$key], "claim_policy.{$key} must be false");
        }
        $this->assertTrue($policy['read_only_plan']);
    }

    public function test_governance_declares_tier_3_branch_sandbox_no_execution(): void
    {
        $gov = $this->project()['governance'];

        $this->assertSame('tier_3_branch_sandbox', $gov['enforced_autonomy_tier']);
        $this->assertFalse($gov['execution_enabled']);
        $this->assertTrue($gov['branch_isolation_required']);
        $this->assertTrue($gov['branch_creation_receipt_required']);
    }

    public function test_preflight_hash_is_deterministic(): void
    {
        $a = $this->project();
        $b = $this->project();

        $this->assertSame($a['preflight_hash'], $b['preflight_hash']);
        $this->assertSame($a['branch_plan'], $b['branch_plan']);
        $this->assertSame($a['handoff_packet'], $b['handoff_packet']);
    }

    public function test_missing_inputs_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->project(['work_order' => $this->workOrder()]);
    }

    public function test_gate_evaluator_is_reused_when_no_override(): void
    {
        // No gate_report override → the injected AP-723 evaluator runs. We assert
        // the path produces a valid report (reuse, not re-derivation).
        $r = $this->service()->project([
            'operator_decision' => $this->decision(),
            'work_order' => $this->workOrder(),
        ]);

        $this->assertContains($r['status'], ['ready', 'blocked']);
        $this->assertSame(AreaFocusBranchSandboxPreflightService::REPORT_SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('gate_decision', $r['safety']);
    }
}
