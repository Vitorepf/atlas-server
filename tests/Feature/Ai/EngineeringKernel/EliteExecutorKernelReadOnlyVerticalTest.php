<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\OutcomeObservation;
use InvalidArgumentException;
use Tests\TestCase;

final class EliteExecutorKernelReadOnlyVerticalTest extends TestCase
{
    private const ROLE_IDS = [
        'product_strategy', 'product_management', 'domain_research', 'ux_research',
        'interaction_design', 'visual_design', 'architecture', 'backend', 'frontend',
        'mobile', 'data', 'qa_testing', 'appsec_privacy', 'performance_resilience',
        'devops_sre', 'observability', 'release', 'documentation_dx',
        'maintenance_simplification', 'outcome_analysis', 'evidence_audit', 'final_certification',
    ];

    public function test_read_only_order_executes_idempotently_with_correlated_evidence(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $order = ExecutionOrder::fromArray($this->orderData());

        $first = $kernel->execute($order);
        $replay = $kernel->execute(ExecutionOrder::fromArray($order->toArray()));

        $this->assertSame('completed_read_only', $first->status);
        $this->assertSame($first->outcomeHash, $replay->outcomeHash);
        $this->assertSame($order->productIntentVerdictHash, $first->correlatedHashes['intent']);
        $this->assertSame($order->specHash, $first->correlatedHashes['spec']);
        $this->assertSame($this->evidenceHash(), $first->correlatedHashes['evidence']);
        $this->assertFalse($first->claimEligible);
    }

    public function test_reusing_idempotency_key_with_changed_order_hash_is_refused(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $kernel->execute(ExecutionOrder::fromArray($this->orderData()));
        $changed = $this->orderData();
        $changed['spec_hash'] = hash('sha256', 'changed-spec');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key_reused_with_changed_order');
        $kernel->execute(ExecutionOrder::fromArray($changed));
    }

    public function test_missing_or_stale_evidence_never_completes_read_only(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $data = $this->orderData();
        $data['evidence_policy']['fresh'] = false;

        $outcome = $kernel->execute(ExecutionOrder::fromArray($data));

        $this->assertSame('held', $outcome->status);
        $this->assertNotEmpty($outcome->uncertainties);
    }

    public function test_dispositions_for_a_different_roster_are_held_not_implicitly_accepted(): void
    {
        $data = $this->orderData();
        $dispositions = $data['evidence_policy']['role_dispositions'];
        $first = array_key_first($dispositions);
        $entry = $dispositions[$first];
        unset($dispositions[$first]);
        $dispositions['foreign_role'] = $entry;
        $data['evidence_policy']['role_dispositions'] = $dispositions;

        $outcome = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));

        $this->assertSame('held', $outcome->status);
        $this->assertContains('role_dispositions_do_not_match_order_roster', $outcome->uncertainties);
    }

    public function test_observe_outcome_returns_typed_non_claiming_learning_receipt(): void
    {
        $receipt = app(EliteExecutorKernel::class)->observeOutcome(OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => 'run-read-only',
            'delivery_id' => 'delivery-read-only',
            'release_hash' => hash('sha256', 'read-only-release'),
            'window' => '0h',
            'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['status' => 'read_only'],
            'provenance' => ['source' => 'kernel_test'],
        ]));

        $this->assertSame('held_for_causal_adjudication', $receipt->status);
    }

    /** @return array<string,mixed> */
    private function orderData(): array
    {
        $roles = [];
        $dispositions = [];
        foreach (self::ROLE_IDS as $role) {
            $roles[$role] = ['depth' => 'standard', 'independent' => true];
            $dispositions[$role] = [
                'status' => 'pass',
                'evidence_hash' => hash('sha256', $role),
                'signature' => hash('sha256', 'signature-'.$role),
            ];
        }

        return [
            'schema_version' => 'atlas.execution_order.v2',
            'run_id' => 'run-read-only',
            'delivery_id' => 'delivery-read-only',
            'mode' => 'dev',
            'risk_class' => 'R2',
            'complexity_band' => 'C2',
            'duration_regime' => 'interactive',
            'work_topology' => 'single',
            'product_intent_verdict_hash' => hash('sha256', 'read-only-intent'),
            'spec_hash' => hash('sha256', 'read-only-spec'),
            'world_model_snapshot_hash' => hash('sha256', 'read-only-world'),
            'workspace' => '/tmp/atlas-read-only',
            'base_commit' => str_repeat('b', 40),
            'allowed_scope' => ['README.md'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'read_only'],
            'decision_receipt' => ['hash' => hash('sha256', 'read-only-decision')],
            'operator_contract' => ['presence' => 'intent_and_authority'],
            'role_roster' => $roles,
            'provider_route' => ['provider' => 'none', 'model' => 'none'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => [
                'required' => true,
                'status' => 'verified',
                'fresh' => true,
                'evidence_hash' => $this->evidenceHash(),
                'role_dispositions' => $dispositions,
            ],
            'release_policy' => ['kind' => 'none_read_only'],
            'rollback_policy' => ['kind' => 'none_read_only'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment-read-only',
            'idempotency_key' => 'read-only-key',
            'budget_posture' => 'unbounded_quality_first',
        ];
    }

    private function evidenceHash(): string
    {
        return hash('sha256', 'read-only-evidence');
    }
}
