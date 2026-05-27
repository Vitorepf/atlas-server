<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidencePackService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalOrchestratorService;
use Tests\TestCase;

/**
 * AP-722 contract tests for the Area Focus Loop Operational Orchestrator.
 *
 * The orchestrator composes the AP-716..AP-720 owners into one read-only cycle.
 * Tests inject findings through the `findings` input seam (bypassing the AP-717
 * deep scan) so the cycle is deterministic, and let the real AP-716 core read
 * model resolve `agentic_engineering_os` from the repo.
 */
class AreaFocusLoopOperationalOrchestratorServiceTest extends TestCase
{
    private function service(): AreaFocusLoopOperationalOrchestratorService
    {
        return app(AreaFocusLoopOperationalOrchestratorService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function finding(string $hash, array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_finding.v1',
            'finding_hash' => 'sha256:'.$hash,
            'finding_id' => 'aef_'.$hash,
            'area_id' => 'agentic_engineering_os',
            'finding_type' => 'missing_test',
            'title' => 'finding '.$hash,
            'detail' => 'detail '.$hash,
            'severity' => 'medium',
            'risk_level' => 'medium',
            'confidence' => 'high',
            'route_hint' => 'atlas_dev',
            'blast_radius' => 'local',
            'priority_score' => 250,
            'evidence_refs' => ['ev:'.$hash],
        ], $overrides);
    }

    public function test_full_operational_cycle_passes_certification(): void
    {
        $report = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'findings' => [
                $this->finding('op1', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test', 'severity' => 'medium', 'blast_radius' => 'local']),
                $this->finding('op2', ['route_hint' => 'forge', 'finding_type' => 'weak_handoff', 'severity' => 'high', 'blast_radius' => 'cross_system']),
            ],
        ]);

        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::STATUS_READY, $report['status']);

        $cert = $report['operational_certification'];
        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::CERT_SCHEMA, $cert['schema_version']);
        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::CERT_PASSED, $cert['status'], 'failing: '.implode(',', $cert['failing_checks']));
        $this->assertTrue($cert['operational']);
        $this->assertSame([], $cert['failing_checks']);
    }

    public function test_wires_finding_engine_and_work_order_router_into_the_cycle(): void
    {
        $report = $this->service()->run([
            'findings' => [
                $this->finding('w1', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test', 'blast_radius' => 'local']),
                $this->finding('w2', ['route_hint' => 'forge', 'finding_type' => 'duplicate_runtime_risk', 'severity' => 'high', 'blast_radius' => 'cross_system']),
            ],
        ]);

        // AP-717 findings flowed through; AP-719 produced work orders for them.
        $this->assertSame(2, $report['counts']['finding_count']);
        $this->assertSame(2, $report['counts']['work_order_count']);
        $this->assertArrayHasKey('atlas_dev', $report['routing_summary']);
        $this->assertArrayHasKey('forge', $report['routing_summary']);

        // Stage reports are embedded for the operator.
        $this->assertArrayHasKey('work_orders', $report['stages']);
        $this->assertSame(
            \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeRouterService::REPORT_SCHEMA,
            $report['stages']['work_orders']['schema_version'],
        );
    }

    public function test_router_budgets_come_from_the_area_contract(): void
    {
        $report = $this->service()->run([
            'findings' => [$this->finding('b1', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test'])],
        ]);

        // The orchestrator must pass the contract's governed budgets to the router,
        // surfaced in the work order plan's budget_state.
        $this->assertArrayHasKey('budget_state', $report);
        $this->assertArrayHasKey('dev_cap', $report['stages']['work_orders']['budget_state']);
        $this->assertSame($report['area_contract']['wip_limit'], $report['stages']['work_orders']['governed_mode']['wip_limit']);
    }

    public function test_evidence_pack_is_complete_and_morning_inbox_ready(): void
    {
        $report = $this->service()->run([
            'findings' => [$this->finding('e1', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test'])],
        ]);

        $pack = $report['stages']['evidence_pack'];
        $this->assertSame(AreaFocusEvidencePackService::PACK_SCHEMA, $pack['schema_version']);
        $this->assertTrue($pack['completeness']['complete'], 'missing: '.implode(',', $pack['completeness']['missing_fields']));
        $this->assertTrue($report['morning_inbox_ready']);
    }

    public function test_empty_findings_still_certifies_operational(): void
    {
        $report = $this->service()->run([
            'findings' => [],
        ]);

        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::STATUS_READY, $report['status']);
        $this->assertSame(0, $report['counts']['finding_count']);
        $this->assertSame(0, $report['counts']['work_order_count']);
        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::CERT_PASSED, $report['operational_certification']['status']);
    }

    public function test_blocked_when_area_not_registered(): void
    {
        $report = $this->service()->run([
            'area_id' => 'blackink',
            'core_report' => [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_loop.v1',
                'status' => 'blocked',
                'area_contract' => null,
            ],
        ]);

        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('core_not_ready', $report['reason']);
        $this->assertSame(AreaFocusLoopOperationalOrchestratorService::CERT_BLOCKED, $report['operational_certification']['status']);
        $this->assertFalse($report['operational_certification']['operational']);
    }

    public function test_deterministic_report_hash_for_same_input(): void
    {
        $input = [
            'findings' => [
                $this->finding('d1', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test']),
                $this->finding('d2', ['route_hint' => 'forge', 'finding_type' => 'weak_handoff', 'blast_radius' => 'cross_system']),
            ],
        ];

        $a = $this->service()->run($input);
        $b = $this->service()->run($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
        $this->assertSame($a['cycle_id'], $b['cycle_id']);
    }

    public function test_governance_read_only_no_execution(): void
    {
        $report = $this->service()->run([
            'findings' => [$this->finding('g1', ['route_hint' => 'forge', 'finding_type' => 'weak_handoff', 'blast_radius' => 'cross_system'])],
        ]);

        $policy = $report['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertTrue($policy['orchestrator_only']);
        $this->assertFalse($policy['execution_performed']);
        $this->assertFalse($policy['dev_invoked']);
        $this->assertFalse($policy['forge_invoked']);
        $this->assertFalse($policy['work_dispatched']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['drafts_spec']);
        $this->assertFalse($policy['merge_without_operator']);
        $this->assertFalse($policy['deploy_without_operator']);
        $this->assertFalse($policy['secret_access']);
        $this->assertFalse($policy['destructive_change']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['new_os_created']);

        $cert = $report['operational_certification'];
        $this->assertTrue($cert['checks']['governance_read_only']);
        $this->assertFalse($cert['invariants']['dispatches_work']);
    }

    public function test_stewardship_stack_envelope_declares_no_new_os(): void
    {
        $report = $this->service()->run(['findings' => [$this->finding('s1')]]);

        $stack = $report['stewardship_stack'];
        $this->assertFalse($stack['new_os_created']);
        $this->assertFalse($stack['parallel_runtime_created']);
        $this->assertStringContainsString('stack/capability family', $stack['canonical_statement']);
        $this->assertContains('AP-722', $stack['aps']);

        // All five owners are declared as reused (no re-implementation).
        $this->assertCount(5, $report['reused_owners']);
        $this->assertSame('AP-717', $report['reused_owners']['finding_engine']['ap']);
        $this->assertSame('AP-719', $report['reused_owners']['work_order_router']['ap']);
    }
}
