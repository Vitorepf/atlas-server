<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\VerifiedMutativeCandidate;
use Tests\TestCase;

/**
 * P1b.1: pre-effect authority replay — caller-authored fake decision ids cannot
 * open provider/sandbox/mutation boundaries.
 */
final class AaeosEffectAuthorityProtocolTest extends TestCase
{
    public function test_factory_does_not_synthesize_mode_decision_fallbacks(): void
    {
        try {
            (new EngineeringModeExecutionOrderFactory)->make([
                'mode' => 'dev',
                'risk_class' => 'R1',
                'work_topology' => 'single',
                'run_hash' => str_repeat('1', 64),
                'run_id' => 'run-p1b1',
                'delivery_id' => 'delivery-p1b1',
                'duration_regime' => 'interactive',
                'product_intent_verdict_hash' => str_repeat('2', 64),
                'spec_hash' => str_repeat('3', 64),
                'world_model_snapshot_hash' => str_repeat('4', 64),
                'workspace' => base_path(),
                'base_commit' => str_repeat('a', 40),
                'allowed_scope' => ['app/Example.php'],
                'forbidden_scope' => ['.env'],
                'authority_envelope' => ['kind' => 'test', 'authority_hash' => str_repeat('b', 64)],
                'mutate' => true,
            ]);
            $this->fail('factory must refuse missing decision_event_id');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('engineering_order_decision_event_id_required', $e->getMessage());
        }
    }

    public function test_mutative_candidate_refuses_fake_decision_id_before_provider(): void
    {
        $order = ExecutionOrder::fromArray($this->mutativeOrderData([
            'decision_receipt' => ['decision_event_id' => 'dev-decision-fake-not-in-ledger'],
        ]));

        $candidate = app(EliteExecutorKernel::class)->prepareMutativeCandidate($order);

        $this->assertInstanceOf(VerifiedMutativeCandidate::class, $candidate);
        $this->assertSame('blocked', $candidate->status);
        $this->assertContains('pre_effect_decision_authority_missing', $candidate->blockers);
    }

    public function test_mutative_candidate_refuses_missing_decision_event_id(): void
    {
        $data = $this->mutativeOrderData();
        $data['decision_receipt'] = ['decision_event_id' => ''];

        $this->expectException(\InvalidArgumentException::class);
        ExecutionOrder::fromArray($data);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function mutativeOrderData(array $overrides = []): array
    {
        $roles = [];
        foreach (\App\Services\Ai\EngineeringKernel\EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $roles[$role] = [
                'depth' => 'standard_review_contracts_integration',
                'risk_band' => 'R2',
                'independent_context' => false,
            ];
        }

        $base = [
            'schema_version' => 'atlas.execution_order.v2',
            'run_id' => 'run-p1b1-mut',
            'delivery_id' => 'delivery-p1b1-mut',
            'mode' => 'dev',
            'risk_class' => 'R2',
            'complexity_band' => 'C1',
            'duration_regime' => 'interactive',
            'work_topology' => 'single',
            'product_intent_verdict_hash' => str_repeat('1', 64),
            'spec_hash' => str_repeat('2', 64),
            'world_model_snapshot_hash' => str_repeat('3', 64),
            'workspace' => base_path(),
            'base_commit' => str_repeat('c', 40),
            'allowed_scope' => ['app/Example.php'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'test', 'authority_hash' => str_repeat('d', 64)],
            'decision_receipt' => ['decision_event_id' => 'must-exist-in-ledger'],
            'operator_contract' => ['presence' => 'confirmed'],
            'role_roster' => $roles,
            'provider_route' => ['provider' => 'codex_cli', 'model' => 'gpt-5.5'],
            'tool_permissions' => ['read' => true, 'mutate' => true],
            'evidence_policy' => [
                'acceptance_event_id' => 'accept-1',
                'role_disposition_event_ids' => array_fill_keys(
                    \App\Services\Ai\EngineeringKernel\EngineeringRoleRoster::OFFICIAL_ROLES,
                    'role-event'
                ),
            ],
            'release_policy' => ['kind' => 'canonical_commit_with_canary'],
            'rollback_policy' => ['kind' => 'canonical_revert_with_settlement'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'p1b1/test',
            'idempotency_key' => 'p1b1:test',
            'budget_posture' => 'unbounded_quality_first',
        ];

        return array_replace_recursive($base, $overrides);
    }
}
