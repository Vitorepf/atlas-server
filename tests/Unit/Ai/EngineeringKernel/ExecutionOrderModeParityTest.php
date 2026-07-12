<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrderModeParity;
use Tests\TestCase;

final class ExecutionOrderModeParityTest extends TestCase
{
    public function test_equivalent_orders_have_one_common_quality_contract(): void
    {
        $base = $this->order();
        $orders = [];
        foreach (['dev', 'forge', 'autonomos'] as $mode) {
            $orders[$mode] = array_replace($base, [
                'mode' => $mode,
                'run_id' => $mode.'-run',
                'delivery_id' => $mode.'-delivery',
                'duration_regime' => $mode === 'dev' ? 'interactive' : 'durable_task',
                'work_topology' => $mode === 'forge' ? 'DAG' : 'single',
                'experiment_ref' => $mode.'-experiment',
                'idempotency_key' => $mode.'-idempotency',
                'operator_contract' => ['presence' => $mode],
                'provider_route' => ['provider' => $mode, 'model' => 'shared-quality-foundry'],
            ]);
        }

        $report = (new ExecutionOrderModeParity)->compareOrders($orders);

        self::assertTrue($report['parity']);
        self::assertSame([], $report['mismatches']);
        self::assertSame(64, strlen($report['parity_hash']));

        $reversed = array_reverse($orders, true);
        self::assertSame($report['parity_hash'], (new ExecutionOrderModeParity)->compareOrders($reversed)['parity_hash']);
    }

    public function test_changed_shared_spec_binding_is_not_mode_parity(): void
    {
        $orders = array_fill_keys(['dev', 'forge', 'autonomos'], $this->order());
        $orders['forge']['spec_hash'] = hash('sha256', 'changed-spec');

        $report = (new ExecutionOrderModeParity)->compareOrders($orders);

        self::assertFalse($report['parity']);
        self::assertArrayHasKey('spec_hash', $report['mismatches']);
    }

    public function test_missing_mode_is_a_hard_parity_failure(): void
    {
        $report = (new ExecutionOrderModeParity)->compareOrders([
            'dev' => $this->order(), 'forge' => array_replace($this->order(), ['mode' => 'forge']),
        ]);

        self::assertFalse($report['parity']);
        self::assertSame(['autonomos'], $report['missing_modes']);
    }

    public function test_terminal_failure_semantics_must_match_and_claims_stay_false(): void
    {
        $outcomes = array_fill_keys(['dev', 'forge', 'autonomos'], [
            'applicability' => 'applicable', 'disposition' => 'block', 'verdict' => 'hold',
            'authorization' => 'refused', 'canary' => 'not_run', 'status' => 'blocked', 'claim_eligible' => false,
        ]);
        $report = (new ExecutionOrderModeParity)->compareTerminalOutcomes($outcomes);
        self::assertTrue($report['parity']);
        self::assertTrue($report['claim_eligible_all_false']);

        $reversed = array_reverse($outcomes, true);
        self::assertSame($report['parity_hash'], (new ExecutionOrderModeParity)->compareTerminalOutcomes($reversed)['parity_hash']);

        $different = $outcomes;
        $different['dev']['disposition'] = 'hold';
        self::assertNotSame($report['parity_hash'], (new ExecutionOrderModeParity)->compareTerminalOutcomes($different)['parity_hash']);

        $outcomes['autonomos']['claim_eligible'] = true;
        $report = (new ExecutionOrderModeParity)->compareTerminalOutcomes($outcomes);
        self::assertFalse($report['parity']);
        self::assertArrayHasKey('claim_eligible', $report['mismatches']);
    }

    /** @return array<string,mixed> */
    private function order(): array
    {
        $roles = [];
        foreach (EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $roles[$role] = ['depth' => 'standard', 'independent' => true];
        }

        return [
            'schema_version' => 'atlas.execution_order.v2', 'run_id' => 'run', 'delivery_id' => 'delivery',
            'mode' => 'dev', 'risk_class' => 'R3', 'complexity_band' => 'C2', 'duration_regime' => 'interactive',
            'work_topology' => 'single', 'product_intent_verdict_hash' => hash('sha256', 'intent'),
            'spec_hash' => hash('sha256', 'spec'), 'world_model_snapshot_hash' => hash('sha256', 'world'),
            'workspace' => 'atlas-server', 'base_commit' => str_repeat('a', 40),
            'allowed_scope' => ['app'], 'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'shared', 'authority_hash' => hash('sha256', 'authority')],
            'decision_receipt' => ['decision_event_id' => 'decision'], 'operator_contract' => ['presence' => 'confirmed'],
            'role_roster' => $roles, 'provider_route' => ['provider' => 'shared', 'model' => 'quality'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => ['acceptance_event_id' => 'acceptance', 'role_disposition_event_ids' => array_fill_keys(array_keys($roles), 'role-event')],
            'release_policy' => ['kind' => 'shared'], 'rollback_policy' => ['kind' => 'shared'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment', 'idempotency_key' => 'idempotency', 'budget_posture' => 'unbounded_quality_first',
            'market_decision_hash' => hash('sha256', 'market'),
        ];
    }
}
