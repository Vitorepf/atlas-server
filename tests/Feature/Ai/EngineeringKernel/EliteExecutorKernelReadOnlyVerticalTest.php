<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\OutcomeObservation;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

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
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $changed = $this->orderData();
        $changed['spec_hash'] = hash('sha256', 'changed-spec');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key_reused_with_changed_order');
        $kernel->execute(ExecutionOrder::fromArray($changed));
    }

    public function test_caller_verified_flags_without_real_acceptance_bundle_never_promote(): void
    {
        $data = $this->orderData();
        $data['evidence_policy']['acceptance_bundle'] = $this->honestAcceptanceBundle();

        $this->expectException(InvalidArgumentException::class);
        ExecutionOrder::fromArray($data);
    }

    public function test_same_idempotency_key_with_different_delivery_is_globally_refused(): void
    {
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));
        $changed = $this->orderData(false);
        $changed['delivery_id'] = 'other-delivery';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key_reused_with_changed_order');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($changed));
    }

    public function test_concurrent_execution_cannot_cross_atomic_idempotency_section(): void
    {
        $data = $this->orderData();
        $lock = Cache::lock('atlas:engineering-kernel:idempotency:'.hash('sha256', $data['idempotency_key']), 30);
        $this->assertTrue($lock->get());
        try {
            app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
            $this->fail('Concurrent execution must not enter the idempotency critical section.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('engineering_execution_idempotency_lock_unavailable', $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    public function test_final_outcome_is_never_returned_when_canonical_append_fails(): void
    {
        $data = $this->orderData();
        $this->app->instance(AtlasEvidenceLedger::class, new FinalAppendFailingEvidenceLedger(app(AtlasEvidenceLedger::class)));
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('engineering_outcome_ledger_append_failed');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_invented_roster_is_refused_against_decision_event(): void
    {
        $data = $this->orderData();
        $firstRole = array_key_first($data['role_roster']);
        $data['role_roster'][$firstRole]['depth'] = 'invented_unbound_depth';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_evidence_event_binding_invalid');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_replay_is_reconstructed_from_canonical_ledger_after_new_kernel_instance(): void
    {
        $first = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $replay = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));

        $this->assertSame($first->outcomeHash, $replay->outcomeHash);
        $this->assertDatabaseHas('atlas_ledger_events', ['scope_type' => 'engineering_delivery', 'scope_id' => 'delivery-read-only']);
    }

    public function test_missing_or_stale_evidence_never_completes_read_only(): void
    {
        $data = $this->orderData();
        $data['evidence_policy']['fresh'] = false;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence_policy_caller_narrative_forbidden');
        ExecutionOrder::fromArray($data);
    }

    public function test_dispositions_for_a_different_roster_are_held_not_implicitly_accepted(): void
    {
        $data = $this->orderData();
        $eventIds = $data['evidence_policy']['role_disposition_event_ids'];
        $roles = array_keys($eventIds);
        $data['evidence_policy']['role_disposition_event_ids'][$roles[0]] = $eventIds[$roles[1]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_evidence_event_binding_invalid');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_observe_outcome_returns_typed_non_claiming_learning_receipt(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $outcome = $kernel->execute(ExecutionOrder::fromArray($this->orderData()));
        $receipt = $kernel->observeOutcome(OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => 'run-read-only',
            'delivery_id' => 'delivery-read-only',
            'release_hash' => $outcome->correlatedHashes['release'],
            'order_hash' => $outcome->correlatedHashes['order'],
            'outcome_hash' => $outcome->outcomeHash,
            'window' => '0h',
            'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['status' => 'read_only'],
            'provenance' => ['source' => 'kernel_test'],
        ]));

        $this->assertSame('held_for_causal_adjudication', $receipt->status);
        $this->assertNotNull($receipt->ledgerEventRef);
    }

    public function test_observe_outcome_refuses_unknown_correlation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outcome_observation_unknown_correlation');

        app(EliteExecutorKernel::class)->observeOutcome(OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => 'unknown', 'delivery_id' => 'unknown',
            'release_hash' => hash('sha256', 'unknown-release'),
            'order_hash' => hash('sha256', 'unknown-order'),
            'outcome_hash' => hash('sha256', 'unknown-outcome'),
            'window' => '0h', 'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['status' => 'unknown'], 'provenance' => ['source' => 'kernel_test'],
        ]));
    }

    /** @return array<string,mixed> */
    private function orderData(bool $seedEvidence = true): array
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
        $authority = ['kind' => 'read_only'];

        $data = [
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
            'authority_envelope' => $authority,
            'decision_receipt' => ['decision_event_id' => 'decision-read-only'],
            'operator_contract' => ['presence' => 'intent_and_authority'],
            'role_roster' => $roles,
            'provider_route' => ['provider' => 'none', 'model' => 'none'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => ['acceptance_event_id' => 'acceptance-read-only', 'role_disposition_event_ids' => array_combine(self::ROLE_IDS, array_map(static fn (string $role): string => 'role-'.substr(hash('sha256', $role), 0, 20), self::ROLE_IDS))],
            'release_policy' => ['kind' => 'none_read_only'],
            'rollback_policy' => ['kind' => 'none_read_only'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment-read-only',
            'idempotency_key' => 'read-only-key',
            'budget_posture' => 'unbounded_quality_first',
        ];
        if ($seedEvidence) {
            $this->seedCanonicalEvidence($data, $dispositions);
        }

        return $data;
    }

    private function evidenceHash(): string
    {
        return CanonicalKernelPayload::hash($this->honestAcceptanceBundle());
    }

    /** @return array<string,mixed> */
    private function honestAcceptanceBundle(): array
    {
        return [
            'criteria_hash' => 'frozen-hash-001', 'frozen_hash' => 'frozen-hash-001',
            'changed_files' => ['README.md'],
            'changed_public_symbols' => [['symbol' => 'README', 'has_criterion' => true, 'has_test' => true]],
            'execution' => ['commands' => ['php artisan test tests/Unit/ExampleTest.php'], 'claimed_status' => 'passed', 'tests_run' => 3, 'assertions_executed' => 3, 'selected_tests' => ['tests/Unit/ExampleTest.php'], 'artifacts' => []],
            'mutation_report' => ['kill_ratio' => 0.8, 'mutants_generated' => 3, 'decision_surface_added' => true],
            'security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0],
            'judges' => [['name' => 'a', 'provider_family' => 'anthropic', 'approved' => true], ['name' => 'b', 'provider_family' => 'openai', 'approved' => true]],
            'context_sufficiency' => 90,
        ];
    }

    /** @param array<string,mixed> $orderData @param array<string,array<string,mixed>> $dispositions */
    private function seedCanonicalEvidence(array $orderData, array $dispositions): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        if ($ledger->eventById('decision-read-only') !== null) {
            return;
        }
        $orderHash = ExecutionOrder::fromArray($orderData)->canonicalHash();
        $context = ['envelope_id' => $orderData['run_id'], 'correlation_id' => $orderData['idempotency_key'], 'scope_type' => 'engineering_delivery', 'scope_id' => $orderData['delivery_id'], 'emitter_stage' => 'test.fixture'];
        $ledger->record(LedgerEventType::DecisionIssued, [
            'event_name' => 'decision.issued', 'delivery_id' => $orderData['delivery_id'], 'order_hash' => $orderHash,
            'spec_hash' => $orderData['spec_hash'], 'authority_hash' => CanonicalKernelPayload::hash($orderData['authority_envelope']),
            'role_roster' => $orderData['role_roster'], 'role_roster_catalog_hash' => CanonicalKernelPayload::hash($orderData['role_roster']),
        ], ['event_id' => 'decision-read-only'] + $context);
        $ledger->record(LedgerEventType::GateEvaluated, [
            'event_name' => 'acceptance.evidence.recorded', 'delivery_id' => $orderData['delivery_id'], 'order_hash' => $orderHash,
            'spec_hash' => $orderData['spec_hash'], 'role_roster_catalog_hash' => CanonicalKernelPayload::hash($orderData['role_roster']),
            'acceptance_bundle' => $this->honestAcceptanceBundle(),
        ], ['event_id' => 'acceptance-read-only'] + $context);
        foreach ($orderData['evidence_policy']['role_disposition_event_ids'] as $role => $eventId) {
            $ledger->record(LedgerEventType::GateEvaluated, [
                'event_name' => 'role.disposition.recorded', 'delivery_id' => $orderData['delivery_id'], 'order_hash' => $orderHash,
                'spec_hash' => $orderData['spec_hash'], 'role_roster_catalog_hash' => CanonicalKernelPayload::hash($orderData['role_roster']),
                'role' => $role, 'disposition' => $dispositions[$role],
            ], ['event_id' => $eventId] + $context);
        }
    }
}

final class FinalAppendFailingEvidenceLedger extends AtlasEvidenceLedger
{
    public function __construct(private readonly AtlasEvidenceLedger $inner) {}

    public function record(LedgerEventType $type, array $payload, array $context = []): ?AtlasLedgerEvent
    {
        return $type === LedgerEventType::OperationCompleted ? null : $this->inner->record($type, $payload, $context);
    }

    public function eventById(string $eventId): ?AtlasLedgerEvent
    {
        return $this->inner->eventById($eventId);
    }

    public function latestForCorrelation(string $correlationId, ?string $eventName = null): ?AtlasLedgerEvent
    {
        return $this->inner->latestForCorrelation($correlationId, $eventName);
    }

    public function eventIntegrityValid(AtlasLedgerEvent $event): bool
    {
        return $this->inner->eventIntegrityValid($event);
    }
}
