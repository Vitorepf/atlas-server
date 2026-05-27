<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use Tests\TestCase;

/**
 * AP-726 contract tests for the branch sandbox preflight + Dev/Forge handoff.
 *
 * Tests feed AP-719 work orders and AP-724 receipts directly and override the
 * AP-723 gate report (`gate_report`) so routing/approval/gating is deterministic
 * and decoupled from the upstream owners. The hard invariant under test: this
 * slice PREPARES branch metadata + handoffs but NEVER creates a branch, applies
 * a fix, dispatches work or mutates anything.
 */
class AreaFocusBranchSandboxHandoffServiceTest extends TestCase
{
    private function service(): AreaFocusBranchSandboxHandoffService
    {
        return app(AreaFocusBranchSandboxHandoffService::class);
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array<string,mixed>
     */
    private function workOrder(string $hash, string $route, array $o = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_work_order.v1',
            'work_order_id' => 'awo_'.$hash,
            'source_ref' => 'sha256:'.$hash,
            'route' => $route,
            'title' => 'work '.$hash,
            'risk_level' => 'medium',
            'evidence_refs' => ['ev:'.$hash],
        ], $o);
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(string $hash, string $decision, ?string $workOrderId = null): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_operator_decision_receipt.v1',
            'finding_hash' => 'sha256:'.$hash,
            'work_order_id' => $workOrderId,
            'decision' => $decision,
            'decision_id' => 'afod_'.$hash,
            'decision_hash' => 'sha256:dec'.$hash,
            'operator_actor' => 'vitor',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function allowGate(): array
    {
        return ['decision' => 'allow', 'blocking_gates' => []];
    }

    public function test_accepted_dev_work_order_is_ready_for_handoff_with_branch_metadata_only(): void
    {
        $report = $this->service()->preflight([
            'work_orders' => [$this->workOrder('a1', 'atlas_dev')],
            'operator_receipts' => [$this->receipt('a1', 'accept', 'awo_a1')],
            'gate_report' => $this->allowGate(),
        ]);

        $this->assertSame(AreaFocusBranchSandboxHandoffService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaFocusBranchSandboxHandoffService::STATUS_READY, $report['status']);

        $h = $report['handoffs'][0];
        $this->assertSame(AreaFocusBranchSandboxHandoffService::HO_READY, $h['handoff_status']);
        $this->assertSame('atlas_dev', $h['target_owner']);
        $this->assertNotNull($h['branch_plan']);
        $this->assertStringStartsWith('area-focus/agentic-engineering-os/atlas-dev/', $h['branch_plan']['proposed_branch_name']);
        // Branch metadata only — nothing created, nothing applied.
        $this->assertFalse($h['branch_plan']['branch_created']);
        $this->assertFalse($h['branch_plan']['worktree_created']);
        $this->assertFalse($h['branch_plan']['fix_applied']);
        $this->assertFalse($h['branch_plan']['target_code_modified']);
        $this->assertFalse($h['dispatched']);
        $this->assertFalse($h['execution_performed']);
    }

    public function test_accepted_forge_work_order_hands_off_to_forge(): void
    {
        $report = $this->service()->preflight([
            'work_orders' => [$this->workOrder('f1', 'forge')],
            'operator_receipts' => [$this->receipt('f1', 'accept')],
            'gate_report' => $this->allowGate(),
        ]);

        $h = $report['handoffs'][0];
        $this->assertSame(AreaFocusBranchSandboxHandoffService::HO_READY, $h['handoff_status']);
        $this->assertSame('forge', $h['target_owner']);
        $this->assertNotNull($h['branch_plan']);
    }

    public function test_no_receipt_is_awaiting_operator_approval_with_no_branch(): void
    {
        $report = $this->service()->preflight([
            'work_orders' => [$this->workOrder('n1', 'atlas_dev')],
            'operator_receipts' => [],
            'gate_report' => $this->allowGate(),
        ]);

        $h = $report['handoffs'][0];
        $this->assertSame(AreaFocusBranchSandboxHandoffService::HO_AWAITING, $h['handoff_status']);
        $this->assertNull($h['branch_plan']);
        $this->assertSame(AreaFocusBranchSandboxHandoffService::STATUS_PARTIAL, $report['status']);
    }

    public function test_reject_defer_request_changes_never_prepare_a_branch(): void
    {
        foreach ([
            ['reject', AreaFocusBranchSandboxHandoffService::HO_REJECTED],
            ['defer', AreaFocusBranchSandboxHandoffService::HO_DEFERRED],
            ['request_changes', AreaFocusBranchSandboxHandoffService::HO_CHANGES],
        ] as [$decision, $expected]) {
            $report = $this->service()->preflight([
                'work_orders' => [$this->workOrder('r1', 'atlas_dev')],
                'operator_receipts' => [$this->receipt('r1', $decision)],
                'gate_report' => $this->allowGate(),
            ]);
            $h = $report['handoffs'][0];
            $this->assertSame($expected, $h['handoff_status'], "decision {$decision}");
            $this->assertNull($h['branch_plan'], "decision {$decision} must not prepare a branch");
        }
    }

    public function test_operator_review_and_sde_routes_are_not_dev_forge_handoffs(): void
    {
        $report = $this->service()->preflight([
            'work_orders' => [
                $this->workOrder('o1', 'operator_review'),
                $this->workOrder('s1', 'self_directed_evolution'),
            ],
            'operator_receipts' => [$this->receipt('o1', 'accept'), $this->receipt('s1', 'accept')],
            'gate_report' => $this->allowGate(),
        ]);

        $byRef = [];
        foreach ($report['handoffs'] as $h) {
            $byRef[$h['source_ref']] = $h;
        }
        $this->assertSame(AreaFocusBranchSandboxHandoffService::HO_OPERATOR_REVIEW, $byRef['sha256:o1']['handoff_status']);
        $this->assertNull($byRef['sha256:o1']['branch_plan']);
        $this->assertSame(AreaFocusBranchSandboxHandoffService::HO_SDE, $byRef['sha256:s1']['handoff_status']);
        $this->assertNull($byRef['sha256:s1']['branch_plan']);
    }

    public function test_blocking_safety_gate_blocks_the_whole_preflight(): void
    {
        $report = $this->service()->preflight([
            'work_orders' => [$this->workOrder('b1', 'atlas_dev')],
            'operator_receipts' => [$this->receipt('b1', 'accept')],
            'gate_report' => ['decision' => 'block', 'blocking_gates' => ['no_secrets_requested']],
        ]);

        $this->assertSame(AreaFocusBranchSandboxHandoffService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('safety_gate_blocked', $report['reason']);
        $this->assertSame([], $report['handoffs']);
        $this->assertContains('no_secrets_requested', $report['blocking_gates']);
    }

    public function test_blocked_when_no_work_orders(): void
    {
        $report = $this->service()->preflight(['gate_report' => $this->allowGate()]);

        $this->assertSame(AreaFocusBranchSandboxHandoffService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('work_orders_required', $report['reason']);
    }

    public function test_claim_policy_never_mutates_and_is_preflight_only(): void
    {
        $report = $this->service()->preflight([
            'work_orders' => [$this->workOrder('c1', 'atlas_dev')],
            'operator_receipts' => [$this->receipt('c1', 'accept')],
            'gate_report' => $this->allowGate(),
        ]);

        $policy = $report['claim_policy'];
        $this->assertSame(AreaFocusBranchSandboxHandoffService::MODE, $policy['mode']);
        $this->assertTrue($policy['preflight_only']);
        $this->assertFalse($policy['branch_created']);
        $this->assertFalse($policy['fix_applied']);
        $this->assertFalse($policy['target_code_modified']);
        $this->assertFalse($policy['work_dispatched']);
        $this->assertFalse($policy['execution_performed']);
        $this->assertFalse($policy['merge_performed']);
        $this->assertFalse($policy['deploy_performed']);
        $this->assertFalse($policy['pushed_external']);
        $this->assertFalse($policy['secret_access']);
        $this->assertFalse($policy['destructive_change']);
        $this->assertTrue($policy['requires_operator_accept_receipt']);
    }

    public function test_deterministic_report_hash_for_same_input(): void
    {
        $input = [
            'work_orders' => [$this->workOrder('d1', 'atlas_dev'), $this->workOrder('d2', 'forge')],
            'operator_receipts' => [$this->receipt('d1', 'accept'), $this->receipt('d2', 'accept')],
            'gate_report' => $this->allowGate(),
        ];

        $a = $this->service()->preflight($input);
        $b = $this->service()->preflight($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
        $this->assertSame($a['handoffs'], $b['handoffs']);
    }
}
