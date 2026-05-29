<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeRouterService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TheRoutingDecisionRationaleContract;
use Tests\TestCase;

/**
 * AP-719 contract tests for the Area Focus Dev/Forge Work Order Router.
 *
 * The router is a pure, deterministic, read-only transformer: findings +
 * inbox items + governed budgets/WIP -> work orders, with no execution. Tests
 * feed findings directly (decoupled from the AP-717 finding engine) and assert
 * routing, budget/WIP gating, determinism and the no-execution invariants.
 */
class AreaFocusDevForgeRouterServiceTest extends TestCase
{
    private function service(): AreaFocusDevForgeRouterService
    {
        return app(AreaFocusDevForgeRouterService::class);
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

    public function test_routes_small_local_to_atlas_dev(): void
    {
        $report = $this->service()->project([
            'findings' => [$this->finding('a', [
                'finding_type' => 'missing_test',
                'severity' => 'medium',
                'blast_radius' => 'local',
                'route_hint' => 'atlas_dev',
            ])],
            'dev_budget' => 3,
            'forge_budget' => 1,
            'wip_limit' => 5,
        ]);

        $this->assertSame(AreaFocusDevForgeRouterService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(1, $report['work_order_count']);
        $wo = $report['work_orders'][0];
        $this->assertSame(AreaFocusDevForgeRouterService::WORK_ORDER_SCHEMA, $wo['schema_version']);
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV, $wo['route']);
        $this->assertSame('dev', $wo['lane']);
        $this->assertSame(AreaFocusDevForgeRouterService::WO_EMITTED, $wo['status']);
        $this->assertSame(1, $report['budget_state']['dev_used']);
        $this->assertFalse($wo['execution_performed']);
        $this->assertTrue($wo['requires_operator_review']);
    }

    public function test_routes_cross_system_to_forge(): void
    {
        $report = $this->service()->project([
            'findings' => [$this->finding('b', [
                'finding_type' => 'duplicate_runtime_risk',
                'severity' => 'high',
                'blast_radius' => 'cross_system',
                'route_hint' => 'forge',
            ])],
            'forge_budget' => 2,
        ]);

        $wo = $report['work_orders'][0];
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_FORGE, $wo['route']);
        $this->assertSame('forge', $wo['lane']);
        $this->assertSame(AreaFocusDevForgeRouterService::WO_EMITTED, $wo['status']);
        $this->assertSame(1, $report['budget_state']['forge_used']);
    }

    public function test_routes_gap_without_spec_to_self_directed_evolution(): void
    {
        $report = $this->service()->project([
            'findings' => [$this->finding('c', [
                'finding_type' => 'self_directed_spec_gap',
                'severity' => 'medium',
                'route_hint' => 'self_directed_evolution',
                'has_spec' => false,
                'blast_radius' => 'subsystem',
            ])],
        ]);

        $wo = $report['work_orders'][0];
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_SELF_DIRECTED_EVOLUTION, $wo['route']);
        $this->assertSame('sde', $wo['lane']);
        // SDE consumes WIP but neither Dev nor Forge budget.
        $this->assertSame(0, $report['budget_state']['dev_used']);
        $this->assertSame(0, $report['budget_state']['forge_used']);
        $this->assertSame(1, $report['budget_state']['wip_used']);
    }

    public function test_routes_high_risk_to_operator_review(): void
    {
        // Critical severity.
        $critical = $this->service()->project([
            'findings' => [$this->finding('d', [
                'finding_type' => 'missing_test',
                'severity' => 'critical',
                'route_hint' => 'atlas_dev',
            ])],
        ]);
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_OPERATOR_REVIEW, $critical['work_orders'][0]['route']);

        // Sensitive token (deploy/secrets/merge) forces operator_review even with a dev hint.
        $sensitive = $this->service()->project([
            'findings' => [$this->finding('e', [
                'finding_type' => 'missing_test',
                'severity' => 'medium',
                'title' => 'rotate deploy secrets and merge to main',
                'route_hint' => 'atlas_dev',
            ])],
        ]);
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_OPERATOR_REVIEW, $sensitive['work_orders'][0]['route']);

        // Missing owner doc → operator_review.
        $missingDoc = $this->service()->project([
            'findings' => [$this->finding('f', [
                'finding_type' => 'missing_owner_doc',
                'severity' => 'high',
                'route_hint' => 'self_directed_evolution',
            ])],
        ]);
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_OPERATOR_REVIEW, $missingDoc['work_orders'][0]['route']);

        // risk_policy custom sensitive domain.
        $policy = $this->service()->project([
            'findings' => [$this->finding('g', [
                'finding_type' => 'missing_test',
                'severity' => 'low',
                'title' => 'touch the healthcare module',
                'route_hint' => 'atlas_dev',
            ])],
            'risk_policy' => ['sensitive_domains' => ['healthcare']],
        ]);
        $this->assertSame(AreaFocusDevForgeRouterService::ROUTE_OPERATOR_REVIEW, $policy['work_orders'][0]['route']);
    }

    public function test_budget_exhausted_blocks_extra_dev_work_orders(): void
    {
        $report = $this->service()->project([
            'findings' => [
                $this->finding('dev1', ['route_hint' => 'atlas_dev', 'severity' => 'high', 'priority_score' => 400, 'blast_radius' => 'local', 'finding_type' => 'missing_test']),
                $this->finding('dev2', ['route_hint' => 'atlas_dev', 'severity' => 'medium', 'priority_score' => 200, 'blast_radius' => 'local', 'finding_type' => 'missing_test']),
            ],
            'dev_budget' => 1,
            'forge_budget' => 1,
            'wip_limit' => 10,
        ]);

        $this->assertSame(2, $report['work_order_count']);
        $this->assertSame(1, $report['emitted_count']);
        $this->assertSame(1, $report['blocked_count']);

        $statuses = array_column($report['work_orders'], 'status');
        $this->assertContains(AreaFocusDevForgeRouterService::WO_EMITTED, $statuses);
        $this->assertContains(AreaFocusDevForgeRouterService::WO_BLOCKED, $statuses);

        $blocked = array_values(array_filter($report['work_orders'], static fn (array $w): bool => $w['status'] === AreaFocusDevForgeRouterService::WO_BLOCKED))[0];
        $this->assertSame(AreaFocusDevForgeRouterService::BLOCK_BUDGET_EXHAUSTED, $blocked['block_reason']);
        // Higher-priority finding wins the single budget unit.
        $this->assertSame('sha256:dev1', array_values(array_filter($report['work_orders'], static fn (array $w): bool => $w['status'] === AreaFocusDevForgeRouterService::WO_EMITTED))[0]['source_ref']);
    }

    public function test_wip_limit_exceeded_blocks_extra_work_orders(): void
    {
        $report = $this->service()->project([
            'findings' => [
                $this->finding('w1', ['route_hint' => 'atlas_dev', 'severity' => 'high', 'priority_score' => 400, 'finding_type' => 'missing_test']),
                $this->finding('w2', ['route_hint' => 'forge', 'severity' => 'medium', 'priority_score' => 200, 'finding_type' => 'weak_handoff']),
            ],
            'dev_budget' => 5,
            'forge_budget' => 5,
            'wip_limit' => 1,
        ]);

        $this->assertSame(1, $report['emitted_count']);
        $this->assertSame(1, $report['blocked_count']);
        $blocked = array_values(array_filter($report['work_orders'], static fn (array $w): bool => $w['status'] === AreaFocusDevForgeRouterService::WO_BLOCKED))[0];
        $this->assertSame(AreaFocusDevForgeRouterService::BLOCK_WIP_LIMIT_REACHED, $blocked['block_reason']);
        $this->assertSame(1, $report['budget_state']['wip_used']);
    }

    public function test_deterministic_hash_for_same_input(): void
    {
        $input = [
            'findings' => [
                $this->finding('h1', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test']),
                $this->finding('h2', ['route_hint' => 'forge', 'finding_type' => 'weak_handoff', 'blast_radius' => 'cross_system']),
            ],
            'dev_budget' => 2,
            'forge_budget' => 2,
            'wip_limit' => 5,
        ];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertSame($a['work_orders'], $b['work_orders']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
    }

    public function test_blocked_when_no_inputs(): void
    {
        $report = $this->service()->project(['dev_budget' => 3]);

        $this->assertSame(AreaFocusDevForgeRouterService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('inputs_required', $report['reason']);
        $this->assertSame(0, $report['work_order_count']);
    }

    public function test_dedupes_finding_and_inbox_item_by_finding_hash(): void
    {
        $report = $this->service()->project([
            'findings' => [$this->finding('dup', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test'])],
            'inbox_items' => [[
                'schema_version' => 'atlas.software_company_stewardship.area_focus_inbox_item.v1',
                'item_id' => 'afib_dup',
                'finding_hash' => 'sha256:dup',
                'route_hint' => 'atlas_dev',
                'risk' => 'medium',
            ]],
            'dev_budget' => 5,
            'wip_limit' => 5,
        ]);

        // The same finding_hash must produce a single work order (finding wins).
        $this->assertSame(1, $report['work_order_count']);
        $this->assertSame('finding', $report['work_orders'][0]['source']);
    }

    public function test_never_executes_and_reuses_finding_route_classification(): void
    {
        $report = $this->service()->project([
            'findings' => [$this->finding('x', ['route_hint' => 'forge', 'finding_type' => 'weak_handoff', 'blast_radius' => 'cross_system'])],
        ]);

        $policy = $report['claim_policy'];
        $this->assertFalse($policy['execution_performed']);
        $this->assertFalse($policy['dev_invoked']);
        $this->assertFalse($policy['forge_invoked']);
        $this->assertFalse($policy['work_dispatched']);
        $this->assertFalse($policy['drafts_spec']);
        $this->assertFalse($policy['merge_without_operator']);
        $this->assertFalse($policy['deploy_without_operator']);
        $this->assertFalse($policy['secret_access']);
        $this->assertFalse($policy['destructive_change']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['new_os_created']);
        $this->assertTrue($policy['reuses_finding_route_classification']);
        $this->assertFalse($report['work_orders'][0]['dispatched']);
    }

    public function test_governed_mode_and_stewardship_stack_envelope(): void
    {
        $report = $this->service()->project([
            'findings' => [$this->finding('z', ['route_hint' => 'atlas_dev', 'finding_type' => 'missing_test'])],
            'dev_budget' => 4,
            'forge_budget' => 2,
            'wip_limit' => 6,
        ]);

        $this->assertSame('max_governed', $report['governed_mode']['mode']);
        $this->assertSame(4, $report['governed_mode']['dev_budget']);
        $this->assertSame(2, $report['governed_mode']['forge_budget']);
        $this->assertSame(6, $report['governed_mode']['wip_limit']);
        $this->assertFalse($report['governed_mode']['invariants']['executes_dev']);
        $this->assertFalse($report['governed_mode']['invariants']['executes_forge']);

        $stack = $report['stewardship_stack'];
        $this->assertFalse($stack['new_os_created']);
        $this->assertFalse($stack['parallel_runtime_created']);
        $this->assertStringContainsString('stack/capability family', $stack['canonical_statement']);
        $this->assertContains('AP-719', $stack['aps']);
    }

    public function test_routing_decision_rationale_contract_lives_outside_router_service(): void
    {
        $routerPath = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php');
        $contractPath = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheRoutingDecisionRationaleContract.php');

        $this->assertFileExists($contractPath);
        $this->assertStringNotContainsString(
            'class TheRoutingDecisionRationaleContract',
            (string) file_get_contents($routerPath),
        );
        $this->assertTrue(class_exists(TheRoutingDecisionRationaleContract::class));
    }

    public function test_the_routing_decision_rationale_entry_empty_input_returns_default_contract(): void
    {
        $result = $this->service()->theRoutingDecisionRationale([]);

        $this->assertSame(
            TheRoutingDecisionRationaleContract::defaults()->toArray(),
            $result,
        );
        $this->assertSame(TheRoutingDecisionRationaleContract::SCHEMA, $result['schema_version']);
        $this->assertSame('routing_decision_rationale', $result['contract_id']);
        $this->assertSame('aaeos_dev_forge_router_decision_rationale_contract', $result['finding_id']);
        $this->assertSame([
            'owner' => '',
            'risk' => 'unknown',
            'authority_available' => false,
            'route' => '',
        ], $result['inputs']);
        $this->assertFalse($result['outputs']['routing_rationale_auditable']);
    }
}
