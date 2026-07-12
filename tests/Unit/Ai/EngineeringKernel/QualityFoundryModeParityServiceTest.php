<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\QualityFoundryModeParityService;
use PHPUnit\Framework\TestCase;

final class QualityFoundryModeParityServiceTest extends TestCase
{
    public function test_r0_r3_and_r5_fixtures_share_frozen_chain_across_all_modes(): void
    {
        foreach (['R0', 'R3', 'R5'] as $risk) {
            $base = $this->receipt($risk);
            $receipts = [];
            foreach (['dev', 'forge', 'autonomos'] as $mode) {
                $receipts[$mode] = array_replace_recursive($base, [
                    'mode' => $mode,
                    'execution_order' => array_replace($base['execution_order'], [
                        'mode' => $mode,
                        'duration_regime' => $mode === 'dev' ? 'interactive' : 'durable_task',
                        'work_topology' => $mode === 'forge' ? 'DAG' : 'single',
                        'run_id' => $mode.'-'.$risk,
                        'delivery_id' => $mode.'-'.$risk,
                        'idempotency_key' => $mode.'-'.$risk,
                        'experiment_ref' => $mode.'-'.$risk,
                    ]),
                ]);
            }
            $report = (new QualityFoundryModeParityService)->compare($receipts);
            self::assertTrue($report['parity'], $risk.': '.json_encode($report['mismatches']));
            self::assertTrue($report['enforcement_allowed']);
        }
    }

    public function test_spec_drift_ownership_collision_and_terminal_disagreement_fail_closed(): void
    {
        $receipts = array_fill_keys(['dev', 'forge', 'autonomos'], $this->receipt('R3'));
        $receipts['forge']['spec_hash'] = hash('sha256', 'drifted-spec');
        $receipts['autonomos']['terminal_outcome']['disposition'] = 'hold';
        $report = (new QualityFoundryModeParityService)->compare($receipts);

        self::assertFalse($report['parity']);
        self::assertFalse($report['enforcement_allowed']);
        self::assertArrayHasKey('spec_hash', $report['mismatches']);
        self::assertArrayHasKey('terminal_outcome', $report['mismatches']);
    }

    public function test_missing_mode_is_not_allowed_to_enforce(): void
    {
        $report = (new QualityFoundryModeParityService)->compare([
            'dev' => $this->receipt('R0'), 'forge' => $this->receipt('R0'),
        ]);
        self::assertFalse($report['parity']);
        self::assertSame(['autonomos'], $report['missing_modes']);
    }

    public function test_failure_matrix_keeps_contradiction_drift_collision_outage_and_crash_fail_closed(): void
    {
        foreach (['contradiction', 'spec_drift', 'ownership_collision', 'stale_graph', 'graph_conflict', 'outcome_lag', 'provider_outage', 'verifier_outage', 'revoked_authority', 'calibration_loss', 'crash_between_act_and_settle'] as $fixture) {
            $blocked = ['applicability' => 'blocked', 'disposition' => 'block', 'verdict' => 'hold', 'authorization' => 'refused', 'canary' => 'not_run', 'status' => 'blocked', 'claim_eligible' => false, 'failure_fixture' => $fixture];
            $receipts = [];
            foreach (['dev', 'forge', 'autonomos'] as $mode) {
                $receipts[$mode] = array_replace($this->receipt('R3'), ['terminal_outcome' => $blocked]);
            }
            $report = (new QualityFoundryModeParityService)->compare($receipts);
            self::assertTrue($report['parity'], $fixture);
            self::assertTrue($report['terminal_parity']['claim_eligible_all_false']);
        }
    }

    public function test_missing_market_decision_hash_blocks_cross_mode_enforcement(): void
    {
        $receipts = array_fill_keys(['dev', 'forge', 'autonomos'], $this->receipt('R3'));
        unset($receipts['forge']['execution_order']['market_decision_hash']);

        $report = (new QualityFoundryModeParityService)->compare($receipts);

        self::assertFalse($report['parity']);
        self::assertArrayHasKey('market_decision_hash', $report['mismatches']);
    }

    public function test_interrupted_decision_reconciliation_is_idempotent_and_never_reinvokes_provider(): void
    {
        $service = new QualityFoundryModeParityService;
        $input = [
            'workspace_id' => 'atlas-server', 'decision_hash' => str_repeat('a', 64),
            'order_hash' => str_repeat('b', 64), 'idempotency_key' => 'decision-1',
            'provider_invocations' => ['provider-receipt-1'], 'crash_stage' => 'after_provider_before_settle',
        ];

        $first = $service->reconcileInterruptedDecision($input);
        $second = $service->reconcileInterruptedDecision($input);

        self::assertSame('held', $first['status']);
        self::assertContains('terminal_outcome_required', $first['blockers']);
        self::assertSame($first['reconciliation_hash'], $second['reconciliation_hash']);
        self::assertSame(0, $first['provider_invocations_added']);
        self::assertFalse($first['claim_eligible']);
    }

    public function test_interrupted_decision_with_existing_terminal_receipt_replays_without_new_event(): void
    {
        $receipt = [
            'status' => 'blocked', 'disposition' => 'block', 'verdict' => 'hold',
            'authorization' => 'refused', 'claim_eligible' => false,
        ];
        $result = (new QualityFoundryModeParityService)->reconcileInterruptedDecision([
            'workspace_id' => 'atlas-server', 'decision_hash' => str_repeat('a', 64),
            'order_hash' => str_repeat('b', 64), 'idempotency_key' => 'decision-2',
            'provider_invocations' => ['provider-receipt-1'], 'terminal_outcome' => $receipt,
        ]);

        self::assertSame('replayed', $result['status']);
        self::assertSame($receipt, $result['terminal_outcome']);
        self::assertSame(0, $result['events_added']);
        self::assertSame(0, $result['provider_invocations_added']);
    }

    /** @return array<string,mixed> */
    private function receipt(string $risk): array
    {
        $roles = [];
        foreach (EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $roles[$role] = ['depth' => $risk === 'R5' ? 'competing' : 'standard', 'independent' => true];
        }
        $order = [
            'schema_version' => 'atlas.execution_order.v2', 'run_id' => 'run', 'delivery_id' => 'delivery',
            'mode' => 'dev', 'risk_class' => $risk, 'complexity_band' => 'C3', 'duration_regime' => 'interactive',
            'work_topology' => 'single', 'product_intent_verdict_hash' => hash('sha256', 'intent'),
            'spec_hash' => hash('sha256', 'spec'), 'world_model_snapshot_hash' => hash('sha256', 'world'),
            'workspace' => 'atlas-server', 'base_commit' => str_repeat('a', 40), 'allowed_scope' => ['app'], 'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'shared', 'authority_hash' => hash('sha256', 'authority')],
            'decision_receipt' => ['decision_event_id' => 'decision'], 'operator_contract' => ['presence' => 'confirmed'],
            'role_roster' => $roles, 'provider_route' => ['provider' => 'shared', 'model' => 'quality'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => ['acceptance_event_id' => 'acceptance', 'role_disposition_event_ids' => array_fill_keys(array_keys($roles), 'role-event')],
            'release_policy' => ['kind' => 'shared'], 'rollback_policy' => ['kind' => 'shared'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']], 'experiment_ref' => 'experiment',
            'idempotency_key' => 'idempotency', 'budget_posture' => 'unbounded_quality_first',
            'market_decision_hash' => hash('sha256', 'market'),
        ];

        return [
            'product_intent_verdict_hash' => hash('sha256', 'intent'), 'spec_hash' => hash('sha256', 'spec'),
            'world_model_snapshot_hash' => hash('sha256', 'world'), 'risk_class' => $risk,
            'required_depth' => EngineeringRoleRoster::depthProfile($risk), 'allowed_scope' => ['app'], 'forbidden_scope' => ['.env'],
            'role_roster' => $roles, 'evidence_policy' => ['acceptance_event_id' => 'acceptance'],
            'execution_order' => $order,
            'terminal_outcome' => ['applicability' => 'applicable', 'disposition' => 'pass', 'verdict' => 'ready', 'authorization' => 'held', 'canary' => 'not_run', 'status' => 'blocked', 'claim_eligible' => false],
        ];
    }
}
