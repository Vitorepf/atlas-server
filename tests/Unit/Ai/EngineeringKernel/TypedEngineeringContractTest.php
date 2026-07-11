<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\EngineeringKernel\OutcomeLearningReceipt;
use App\Services\Ai\EngineeringKernel\OutcomeObservation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TypedEngineeringContractTest extends TestCase
{
    private const ROLE_IDS = EngineeringRoleRoster::OFFICIAL_ROLES;

    public function test_legacy_nine_role_company_roster_is_not_quality_foundry_roster(): void
    {
        $this->assertNotSame(EngineeringRoleRoster::OFFICIAL_ROLES, AtlasRealEngineeringCompanyRuntimeService::ROLES);
        $this->assertCount(9, AtlasRealEngineeringCompanyRuntimeService::ROLES, 'Phase 1 migration remains explicit.');
    }

    public function test_execution_order_is_complete_canonical_and_deterministic(): void
    {
        $order = ExecutionOrder::fromArray($this->validOrder());

        $this->assertSame('atlas.execution_order.v2', $order->schemaVersion);
        $this->assertSame('unbounded_quality_first', $order->budgetPosture);
        $this->assertSame(self::ROLE_IDS, array_keys($order->roleRoster));
        $this->assertSame(EngineeringRoleRoster::OFFICIAL_ROLES, array_keys($order->roleRoster));
        $this->assertSame($order->canonicalHash(), ExecutionOrder::fromArray(array_reverse($this->validOrder(), true))->canonicalHash());
        $this->assertSame($order->toArray(), ExecutionOrder::fromArray($order->toArray())->toArray());
    }

    #[DataProvider('invalidOrderProvider')]
    public function test_execution_order_refuses_invalid_or_missing_contract_fields(callable $mutate): void
    {
        $data = $this->validOrder();
        $mutate($data);

        $this->expectException(InvalidArgumentException::class);
        ExecutionOrder::fromArray($data);
    }

    /** @return iterable<string,array{callable(array<string,mixed>&):void}> */
    public static function invalidOrderProvider(): iterable
    {
        yield 'invalid mode' => [static function (array &$data): void {
            $data['mode'] = 'chat';
        }];
        yield 'invalid risk' => [static function (array &$data): void {
            $data['risk_class'] = 'R6';
        }];
        yield 'missing authority' => [static function (array &$data): void {
            $data['authority_envelope'] = [];
        }];
        yield 'missing scope' => [static function (array &$data): void {
            $data['allowed_scope'] = [];
        }];
        yield 'invalid hash' => [static function (array &$data): void {
            $data['spec_hash'] = 'not-a-hash';
        }];
        yield 'bounded posture' => [static function (array &$data): void {
            $data['budget_posture'] = 'cheap_first';
        }];
        yield 'incomplete roster' => [static function (array &$data): void {
            array_pop($data['role_roster']);
        }];
        yield 'invented unbound roster' => [static function (array &$data): void {
            $data['role_roster']['invented_role'] = array_pop($data['role_roster']);
            $data['role_roster_catalog_hash'] = CanonicalKernelPayload::hash($data['role_roster']);
        }];
        yield 'coercive scope item' => [static function (array &$data): void {
            $data['allowed_scope'] = [123];
        }];
        yield 'unknown field' => [static function (array &$data): void {
            $data['caller_claim'] = 'trusted';
        }];
        yield 'absolute scope' => [static function (array &$data): void {
            $data['allowed_scope'] = ['/tmp/escape'];
        }];
        yield 'traversal scope' => [static function (array &$data): void {
            $data['allowed_scope'] = ['../escape'];
        }];
        yield 'overlapping scope' => [static function (array &$data): void {
            $data['forbidden_scope'] = ['docs/README.md'];
        }];
        yield 'embedded caller evidence' => [static function (array &$data): void {
            $data['evidence_policy']['acceptance_bundle'] = ['claimed' => 'green'];
        }];
    }

    public function test_engineering_outcome_refuses_unknown_status_and_claim_eligibility(): void
    {
        $data = $this->validOutcome();
        $data['status'] = 'success';

        $this->expectException(InvalidArgumentException::class);
        EngineeringOutcome::fromArray($data);
    }

    public function test_engineering_outcome_accepts_only_the_seven_fixed_statuses(): void
    {
        foreach (EngineeringOutcome::STATUSES as $status) {
            $data = $this->validOutcome();
            $data['status'] = $status;
            $this->assertSame($status, EngineeringOutcome::fromArray($data)->status);
        }

        $this->assertCount(7, EngineeringOutcome::STATUSES);
    }

    public function test_engineering_outcome_is_correlated_has_all_roles_and_is_not_claim_eligible(): void
    {
        $outcome = EngineeringOutcome::fromArray($this->validOutcome());

        $this->assertSame('completed_read_only', $outcome->status);
        $this->assertFalse($outcome->claimEligible);
        $this->assertSame(self::ROLE_IDS, array_keys($outcome->roleDispositions));
        $this->assertSame(['0h', '24h', '7d', '30d', '90d', '150d'], array_keys($outcome->observationSchedule));
        $this->assertSame($outcome->outcomeHash, EngineeringOutcome::fromArray($outcome->toArray())->outcomeHash);

        $claimable = $this->validOutcome();
        $claimable['claim_eligible'] = true;
        $this->expectException(InvalidArgumentException::class);
        EngineeringOutcome::fromArray($claimable);
    }

    public function test_completed_read_only_cannot_hide_block_or_receipt_hash_mismatch(): void
    {
        $blocked = $this->validOutcome();
        $blocked['role_dispositions'][self::ROLE_IDS[0]]['status'] = 'block';
        try {
            EngineeringOutcome::fromArray($blocked);
            $this->fail('A blocked disposition must not complete read-only.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('completed_read_only_requires_unblocked_certain_dispositions', $exception->getMessage());
        }

        $mismatch = $this->validOutcome();
        $mismatch['release_receipt']['hash'] = hash('sha256', 'different-release');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outcome_receipt_hash_mismatch');
        EngineeringOutcome::fromArray($mismatch);
    }

    public function test_direct_completed_outcome_without_authority_seal_is_refused(): void
    {
        $data = $this->validOutcome();
        unset($data['evidence_bundle']['authority']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('completed_outcome_authority_invalid');
        EngineeringOutcome::fromArray($data);
    }

    public function test_postgres_uses_transaction_scoped_advisory_lock_without_ttl(): void
    {
        $this->assertSame('postgres_advisory_xact_lock', EliteExecutorKernel::idempotencyLockStrategy('pgsql'));
        $this->assertSame('test_cache_lock', EliteExecutorKernel::idempotencyLockStrategy('sqlite'));
    }

    public function test_observation_and_learning_receipt_are_typed_and_hash_bound(): void
    {
        $observation = OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => 'run-001',
            'delivery_id' => 'delivery-001',
            'release_hash' => hash('sha256', 'release'),
            'order_hash' => hash('sha256', 'order'),
            'outcome_hash' => hash('sha256', 'outcome'),
            'window' => '24h',
            'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['escaped_defects' => 0],
            'provenance' => ['source' => 'production'],
        ]);
        $receipt = OutcomeLearningReceipt::fromObservation($observation);

        $this->assertSame($observation->canonicalHash(), $receipt->observationHash);
        $this->assertSame('held_for_causal_adjudication', $receipt->status);
    }

    public function test_observation_refuses_non_canonical_iso_timestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('observed_at_invalid');
        OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1', 'run_id' => 'run', 'delivery_id' => 'delivery',
            'release_hash' => hash('sha256', 'release'), 'order_hash' => hash('sha256', 'order'), 'outcome_hash' => hash('sha256', 'outcome'),
            'window' => '0h', 'observed_at' => '2026-07-11T00:00:00Z',
            'metrics' => ['status' => 'ok'], 'provenance' => ['source' => 'test'],
        ]);
    }

    /** @return array<string,mixed> */
    private function validOrder(): array
    {
        $roles = [];
        $dispositions = [];
        foreach (self::ROLE_IDS as $role) {
            $roles[$role] = ['depth' => 'standard', 'independent' => true];
            $dispositions[$role] = ['status' => 'pass', 'evidence_hash' => hash('sha256', $role), 'signature' => hash('sha256', 'sign-'.$role)];
        }
        $rosterHash = CanonicalKernelPayload::hash($roles);
        $authority = ['kind' => 'read_only'];

        $data = [
            'schema_version' => 'atlas.execution_order.v2',
            'run_id' => 'run-001',
            'delivery_id' => 'delivery-001',
            'mode' => 'dev',
            'risk_class' => 'R2',
            'complexity_band' => 'C2',
            'duration_regime' => 'interactive',
            'work_topology' => 'single',
            'product_intent_verdict_hash' => hash('sha256', 'intent'),
            'spec_hash' => hash('sha256', 'spec'),
            'world_model_snapshot_hash' => hash('sha256', 'world'),
            'workspace' => '/tmp/atlas-fixture',
            'base_commit' => str_repeat('a', 40),
            'allowed_scope' => ['docs/README.md'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => $authority,
            'decision_receipt' => ['decision_event_id' => 'decision-event-unit'],
            'operator_contract' => ['presence' => 'intent_and_authority'],
            'role_roster' => $roles,
            'provider_route' => ['provider' => 'none', 'model' => 'none'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => ['acceptance_event_id' => 'acceptance-event-unit', 'role_disposition_event_ids' => array_fill_keys(array_keys($dispositions), 'role-event-unit')],
            'release_policy' => ['kind' => 'none_read_only'],
            'rollback_policy' => ['kind' => 'none_read_only'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment-001',
            'idempotency_key' => 'idempotency-001',
            'budget_posture' => 'unbounded_quality_first',
        ];

        return $data;
    }

    /** @return array<string,mixed> */
    private function validOutcome(): array
    {
        $dispositions = [];
        foreach (self::ROLE_IDS as $role) {
            $dispositions[$role] = [
                'status' => 'pass',
                'evidence_hash' => hash('sha256', $role),
                'signature' => hash('sha256', 'sign-'.$role),
                'receipt_ref' => 'role-event-'.$role,
                'receipt_event_hash' => hash('sha256', 'event-'.$role),
            ];
        }

        $hashes = [
            'order' => hash('sha256', 'order'),
            'intent' => hash('sha256', 'intent'),
            'spec' => hash('sha256', 'spec'),
            'baseline' => hash('sha256', 'baseline'),
            'diff' => hash('sha256', 'diff'),
            'evidence' => hash('sha256', 'evidence'),
            'release' => hash('sha256', 'release'),
        ];

        $data = [
            'schema_version' => 'atlas.engineering_outcome.v2',
            'run_id' => 'run-001',
            'delivery_id' => 'delivery-001',
            'status' => 'completed_read_only',
            'correlated_hashes' => $hashes,
            'role_dispositions' => $dispositions,
            'evidence_bundle' => ['hash' => $hashes['evidence'], 'status' => 'accepted', 'gate_verdict' => ['status' => 'promote']],
            'provider_receipt' => ['status' => 'not_applicable_read_only'],
            'sandbox_receipt' => ['status' => 'not_applicable_read_only'],
            'release_receipt' => ['status' => 'not_applicable_read_only', 'hash' => $hashes['release']],
            'canary_rollback_receipt' => ['status' => 'not_applicable_read_only'],
            'operator_effort' => ['active_seconds' => 0],
            'cost' => ['amount' => 0, 'currency' => 'USD'],
            'tokens' => ['input' => 0, 'output' => 0],
            'elapsed_ms' => 1,
            'uncertainties' => [],
            'observation_schedule' => array_fill_keys(['0h', '24h', '7d', '30d', '90d', '150d'], 'pending'),
            'claim_eligible' => false,
        ];
        $data['evidence_bundle']['authority'] = app(KernelEvidenceAuthority::class)->sealOutcome($data);

        return $data;
    }
}
