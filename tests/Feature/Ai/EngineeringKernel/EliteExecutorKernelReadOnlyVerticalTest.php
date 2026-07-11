<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionTestRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\EngineeringKernel\OutcomeObservation;
use App\Services\Ai\EngineeringKernel\ReadOnlyQualityCourt;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EliteExecutorKernelReadOnlyVerticalTest extends TestCase
{
    private const ROLE_IDS = EngineeringRoleRoster::OFFICIAL_ROLES;

    private ?string $canonicalRunId = null;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_05_17_230000_create_ai_real_engineering_execution_kernel_tables.php'))->up();
        (require database_path('migrations/2026_05_17_232000_create_ai_engineering_company_runtime_tables.php'))->up();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    #[DataProvider('historicalReplayWindows')]
    public function test_historical_outcome_replay_does_not_expire(string $window): void
    {
        $outcome = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->add($window));
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $replay = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData(false)));

        $this->assertSame($outcome->outcomeHash, $replay->outcomeHash);
    }

    /** @return iterable<string,array{string}> */
    public static function historicalReplayWindows(): iterable
    {
        yield '25 hours' => ['25 hours'];
        yield '150 days' => ['150 days'];
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
        $this->assertSame(3, AiEngineeringCompanyRoleRun::query()->where('status', 'passed')->count());
        $this->assertSame(19, AiEngineeringCompanyRoleRun::query()->where('status', 'not_applicable')->count());
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

    public function test_raw_ledger_spoof_with_test_emitter_is_not_authoritative(): void
    {
        $data = $this->orderData();
        $event = app(AtlasEvidenceLedger::class)->eventById('decision-read-only');
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::DecisionIssued, (array) $event?->payload, [
            'event_id' => 'spoof-decision', 'envelope_id' => $data['run_id'], 'correlation_id' => $data['idempotency_key'],
            'scope_type' => 'engineering_delivery', 'scope_id' => $data['delivery_id'], 'emitter_stage' => 'test.fixture',
        ]);
        $data['decision_receipt']['decision_event_id'] = 'spoof-decision';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_evidence_authority_invalid');
        app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));
    }

    public function test_unsaved_role_run_cannot_become_authoritative(): void
    {
        $data = $this->orderData(seedEvidence: false);
        $order = ExecutionOrder::fromArray($data);
        $roleRun = new AiEngineeringCompanyRoleRun;
        $roleRun->forceFill(['role_id' => self::ROLE_IDS[0], 'status' => 'passed', 'role_hash' => str_repeat('a', 64),
            'evidence_refs' => ['fabricated'], 'output' => ['disposition' => ['status' => 'pass']]]);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueRoleDisposition($roleRun, $order, []);
    }

    public function test_direct_fillable_role_row_without_canonical_producer_is_refused(): void
    {
        $data = $this->orderData(seedEvidence: false);
        $order = ExecutionOrder::fromArray($data);
        $company = app(AtlasRealEngineeringCompanyRuntimeService::class);
        $engagement = $company->createEngagement('forged quality role');
        $cycle = $company->createCycle($engagement);
        $disposition = ['status' => 'pass', 'evidence_hash' => hash('sha256', 'forged'), 'signature' => hash('sha256', 'forged-signature')];
        $output = ['disposition' => $disposition];
        $receipt = ['role_id' => self::ROLE_IDS[0], 'status' => 'passed', 'evidence_refs' => ['forged'], 'output' => $output,
            'binding' => $this->ownerBinding($order) + ['engagement_record_id' => (string) $engagement->getKey(), 'cycle_record_id' => (string) $cycle->getKey()],
            'disposition' => $disposition];
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);
        $forged = AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(), 'role_run_id' => 'forged-'.Str::uuid(),
            'role_id' => self::ROLE_IDS[0], 'status' => 'passed', 'output' => $output, 'evidence_refs' => ['forged'],
            'receipt' => $receipt, 'role_hash' => $receipt['hash'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueRoleDisposition($forged, $order, []);
    }

    public function test_bundle_without_persisted_test_receipt_cannot_become_authoritative(): void
    {
        $data = $this->orderData(seedEvidence: false);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueEvidenceBundle(
            new AiRealExecutionTestRun,
            [],
            ExecutionOrder::fromArray($data),
            [],
        );
    }

    public function test_verification_producer_refuses_caller_selected_command_target(): void
    {
        $method = new \ReflectionMethod(AtlasRealEngineeringExecutionKernelService::class, 'produceKernelVerification');
        $names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());
        $this->assertNotContains('command', $names);
        $this->assertNotContains('acceptanceFacts', $names);
        $data = $this->orderData();
        $goal = AiAutonomousEngineeringGoal::query()->latest('created_at')->firstOrFail();
        $patch = AiRealExecutionPatchRun::query()->latest('created_at')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        app(AtlasRealEngineeringExecutionKernelService::class)->produceKernelVerification(
            $goal, $patch, ExecutionOrder::fromArray($data), 'php -r "exit(0);"',
        );
    }

    public function test_public_role_writer_exposes_no_caller_disposition_or_evidence_parameters(): void
    {
        $method = new \ReflectionMethod(AtlasRealEngineeringCompanyRuntimeService::class, 'executeQualityRole');
        $names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());

        $this->assertNotContains('disposition', $names);
        $this->assertNotContains('evidenceRefs', $names);
        $this->assertFalse(method_exists(AtlasRealEngineeringCompanyRuntimeService::class, 'recordQualityDisposition'));
    }

    public function test_absent_one_role_cannot_produce_sovereign_bundle(): void
    {
        $data = $this->orderData();
        $roles = AiEngineeringCompanyRoleRun::query()->whereIn('role_id', self::ROLE_IDS)->get()->all();
        array_pop($roles);

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueEvidenceBundle(
            AiRealExecutionTestRun::query()->latest('created_at')->firstOrFail(),
            $roles,
            ExecutionOrder::fromArray($data),
            [],
        );
    }

    public function test_court_refuses_missing_junit_and_stale_order(): void
    {
        $data = $this->orderData();
        $order = ExecutionOrder::fromArray($data);
        $test = AiRealExecutionTestRun::query()->latest('created_at')->firstOrFail();
        $junit = (string) data_get($test->receipt, 'junit_artifact.path');
        $junitContent = (string) file_get_contents($junit);
        unlink($junit);
        $this->assertFalse(app(ReadOnlyQualityCourt::class)->dispositionValid($order, $test, 'evidence_audit',
            (array) data_get(AiEngineeringCompanyRoleRun::query()->where('role_id', 'evidence_audit')->first()?->output, 'disposition')));
        file_put_contents($junit, $junitContent);

        $changed = $data;
        $changed['spec_hash'] = hash('sha256', 'stale-spec');
        $this->assertFalse(app(ReadOnlyQualityCourt::class)->dispositionValid(ExecutionOrder::fromArray($changed), $test, 'evidence_audit', []));
    }

    public function test_court_refuses_forged_not_applicable_and_author_as_judge(): void
    {
        $data = $this->orderData();
        $order = ExecutionOrder::fromArray($data);
        $test = AiRealExecutionTestRun::query()->latest('created_at')->firstOrFail();
        $valid = app(ReadOnlyQualityCourt::class)->adjudicateRole($order, $test, 'appsec_privacy');
        $forged = $valid;
        $forged['justification'] = 'caller_waived';
        $this->assertFalse(app(ReadOnlyQualityCourt::class)->dispositionValid($order, $test, 'appsec_privacy', $forged));

        $author = $valid;
        $author['signer_context'] = AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER;
        $this->assertFalse(app(ReadOnlyQualityCourt::class)->dispositionValid($order, $test, 'appsec_privacy', $author));
    }

    public function test_previous_keyring_verifies_seal_after_app_key_rotation(): void
    {
        $this->orderData();
        $event = app(AtlasEvidenceLedger::class)->eventById('acceptance-read-only');
        $this->assertNotNull($event);
        $oldKey = (string) config('app.key');
        $oldKeyId = (string) data_get($event->payload, '_authority.key_id');
        config()->set('app.key', 'rotated-kernel-key');
        config()->set('atlas.engineering_kernel.evidence_authority.previous_keys', [$oldKeyId => $oldKey]);

        $this->assertTrue(app(KernelEvidenceAuthority::class)->verifyEvent($event, 'evidence_bundle'));
    }

    public function test_unrelated_decision_receipt_is_refused(): void
    {
        $data = $this->orderData(seedEvidence: false);
        $receipt = $this->decisionReceipt($data);
        $data['run_id'] = 'run-unrelated';

        $this->expectException(InvalidArgumentException::class);
        app(KernelEvidenceAuthority::class)->issueDecision($receipt, ExecutionOrder::fromArray($data), []);
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
            'run_id' => $this->canonicalRunId ?? 'run-read-only',
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
            'run_id' => $this->canonicalRunId ?? 'run-read-only',
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
        $event = app(AtlasEvidenceLedger::class)->eventById('acceptance-read-only');

        return CanonicalKernelPayload::hash((array) data_get($event?->payload, 'acceptance_bundle', []));
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
    private function seedCanonicalEvidence(array &$orderData, array $dispositions): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $existingDecision = $ledger->eventById('decision-read-only');
        if ($existingDecision !== null) {
            $orderData['run_id'] = (string) $existingDecision->envelope_id;

            return;
        }
        $decision = $this->decisionReceipt($orderData);
        $context = ['envelope_id' => $orderData['run_id'], 'correlation_id' => $orderData['idempotency_key'], 'scope_type' => 'engineering_delivery', 'scope_id' => $orderData['delivery_id'], 'emitter_stage' => 'test.fixture'];
        $authority = app(KernelEvidenceAuthority::class);
        $order = ExecutionOrder::fromArray($orderData);
        $authority->issueDecision($decision, $order, ['event_id' => 'decision-read-only'] + $context);
        $autonomous = app(AtlasAutonomousEngineeringService::class)->run('Hermetic kernel verification fixture', ['step_status' => 'passed']);
        $goal = AiAutonomousEngineeringGoal::query()->findOrFail((string) data_get($autonomous, 'goal.id'));
        $real = app(AtlasRealEngineeringExecutionKernelService::class);
        $worktree = $real->createWorktree($goal, $autonomous);
        $patch = $real->executePatch($goal, $worktree, $autonomous);
        $testRun = $real->produceKernelVerification($goal, $patch, $order, 'typed_contract_smoke');
        $company = app(AtlasRealEngineeringCompanyRuntimeService::class);
        $engagement = $company->createEngagement('Quality Foundry kernel verification');
        $cycle = $company->createCycle($engagement);
        $roleRuns = [];
        foreach ($orderData['evidence_policy']['role_disposition_event_ids'] as $role => $eventId) {
            $roleRun = $company->executeQualityRole($engagement, $cycle, $order, $role, $testRun);
            $roleRuns[] = $roleRun;
            $authority->issueRoleDisposition($roleRun, $order, ['event_id' => $eventId] + $context);
        }
        $authority->issueEvidenceBundle($testRun, $roleRuns, $order, ['event_id' => 'acceptance-read-only'] + $context);
    }

    /** @return array<string,string> */
    private function ownerBinding(ExecutionOrder $order): array
    {
        return ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId,
            'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash];
    }

    /** @param array<string,mixed> $orderData */
    private function decisionReceipt(array &$orderData): DecisionReceipt
    {
        $envelope = app(OperationEnvelopeFactory::class)->create([
            'operator' => ['operator_id' => 'kernel-e2e', 'tenant_id' => 'atlas'],
            'origin' => ['surface_id' => 'kernel-test', 'session_id' => 'kernel-test'],
            'input' => ['kind' => 'engineering', 'payload' => ['delivery_id' => $orderData['delivery_id']]],
        ]);
        $orderData['run_id'] = $envelope->envelopeId;
        $this->canonicalRunId = $envelope->envelopeId;
        $metadata = ['delivery_id' => $orderData['delivery_id'], 'order_hash' => ExecutionOrder::fromArray($orderData)->canonicalHash(),
            'spec_hash' => $orderData['spec_hash'], 'roster_hash' => CanonicalKernelPayload::hash($orderData['role_roster']), 'mode' => $orderData['mode']];
        $risk = in_array($orderData['risk_class'], ['R0', 'R1'], true) ? 'low' : 'medium';

        return app(DecisionReceiptIssuer::class)->issue($envelope, ['ttl_seconds' => 3600, 'domain' => 'programming',
            'flow' => 'atlas.'.$orderData['mode'], 'risk' => $risk, 'required_evidence' => ['summary'], 'metadata' => $metadata]);
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
