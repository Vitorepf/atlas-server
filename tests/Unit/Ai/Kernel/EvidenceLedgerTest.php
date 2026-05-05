<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairRequestFactory;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EvidenceLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateLedger();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_records_decision_receipt_as_append_only_events(): void
    {
        $receipt = $this->receipt();

        $result = app(AtlasEvidenceLedger::class)->recordDecisionIssued($receipt, [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
        ]);

        $this->assertNull($result['envelope_created']);
        $this->assertInstanceOf(AtlasLedgerEvent::class, $result['decision_issued']);
        $this->assertSame(LedgerEventType::DecisionIssued->value, $result['decision_issued']->event_type);
        $this->assertNull($result['decision_issued']->causation_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['decision_issued']->payload_hash);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_factory_records_envelope_and_decision_replays_in_order(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create([
            'operator' => ['tenant_id' => 'tenant-a', 'operator_id' => 'operator-a'],
            'input' => ['text' => 'planeje'],
        ]);
        $receipt = array_merge($this->receipt(), [
            'envelope_id' => $envelope->envelopeId,
        ]);

        app(AtlasEvidenceLedger::class)->recordDecisionIssued($receipt, [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
        ]);

        $events = app(AtlasEvidenceLedger::class)->eventsForEnvelope($envelope->envelopeId);

        $this->assertCount(2, $events);
        $this->assertSame('ENVELOPE_CREATED', $events[0]['event_type']);
        $this->assertSame('DECISION_ISSUED', $events[1]['event_type']);
        $this->assertSame('tenant-a', $events[1]['tenant_id']);
        $this->assertSame('operator-a', $events[1]['operator_id']);
    }

    public function test_payload_hash_is_canonical_for_same_payload_order(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);

        $first = $ledger->record(LedgerEventType::ContextComposed, [
            'b' => ['z' => 1, 'a' => 2],
            'a' => 'value',
        ], [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
            'envelope_id' => 'env_02',
        ]);

        $second = $ledger->record(LedgerEventType::ContextComposed, [
            'a' => 'value',
            'b' => ['a' => 2, 'z' => 1],
        ], [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
            'envelope_id' => 'env_02',
        ]);

        $this->assertSame($first?->payload_hash, $second?->payload_hash);
    }

    public function test_records_classified_failure_as_canonical_ledger_event(): void
    {
        $result = app(AtlasEvidenceLedger::class)->recordFailure('failed', [
            'message' => 'quality gate failed during release check',
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
            'envelope_id' => 'env_failure_01',
            'receipt_id' => 'receipt_failure_01',
        ]);

        $this->assertSame('gate.failed', data_get($result, 'classification.failure_domain'));
        $this->assertTrue(data_get($result, 'handling.retryable'));
        $this->assertFalse(data_get($result, 'handling.requires_human_review'));
        $this->assertInstanceOf(AtlasLedgerEvent::class, $result['event']);
        $this->assertSame(LedgerEventType::OperationFailed->value, $result['event']->event_type);
        $this->assertSame('gate.failed', data_get($result['event']->payload, 'classification.failure_domain'));
    }

    public function test_failure_requiring_review_uses_needs_review_event(): void
    {
        $result = app(AtlasEvidenceLedger::class)->recordFailure([
            'status_code' => 403,
            'message' => 'privacy violation provider safe leak',
        ], [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
            'envelope_id' => 'env_failure_02',
        ]);

        $this->assertSame('privacy.violation', data_get($result, 'classification.failure_domain'));
        $this->assertTrue(data_get($result, 'handling.requires_human_review'));
        $this->assertSame(LedgerEventType::OperationNeedsReview->value, $result['event']->event_type);
    }

    public function test_records_policy_contract_block_as_operation_blocked_event(): void
    {
        $event = app(AtlasEvidenceLedger::class)->recordPolicyContractBlocked('programming.tools', [
            'status' => 'execution_tier_hot_path_blocked',
            'requested_execution_tier' => 'T2',
            'max_execution_tier' => 'T1',
            'hot_path' => true,
        ], [
            'client_id' => 'client-ap14',
            'provider' => 'codex_cli',
            'payload' => [
                'tenant_id' => 'tenant-a',
                'operator_id' => 'operator-a',
                'decision_receipt' => [
                    'envelope_id' => 'env_ap14',
                    'receipt_id' => 'receipt_ap14',
                ],
            ],
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::OperationBlocked->value, $event->event_type);
        $this->assertSame('atlas.policy_contract', $event->emitter_stage);
        $this->assertSame('tenant-a', $event->tenant_id);
        $this->assertSame('operator-a', $event->operator_id);
        $this->assertSame('env_ap14', $event->envelope_id);
        $this->assertSame('receipt_ap14', $event->receipt_id);
        $this->assertSame('client-ap14', $event->correlation_id);
        $this->assertSame('programming.tools.execution_tier_hot_path_blocked', data_get($event->payload, 'violation_code'));
        $this->assertSame('T2', data_get($event->payload, 'receipt.requested_execution_tier'));
    }

    public function test_records_provider_memory_privacy_block_without_content(): void
    {
        $entry = new AtlasMemoryEntry([
            'memory_type' => 'technical_context',
            'scope_type' => 'project',
            'scope_id' => 'atlas',
            'source_type' => 'manual',
            'source_id' => 'note-01',
            'trace_id' => 'trace-memory-01',
            'session_id' => 'session-memory-01',
            'title' => 'Bearer should never appear',
            'body' => 'secret body should never appear',
            'metadata' => [
                'operator' => [
                    'tenant_id' => 'tenant-memory',
                    'operator_id' => 'operator-memory',
                ],
            ],
        ]);
        $entry->id = 'memory-secret-01';

        $event = app(AtlasEvidenceLedger::class)->recordProviderMemoryBlocked($entry, [
            'allowed' => false,
            'privacy_class' => 'secret',
            'external_ai_allowed' => false,
            'metadata_external_ai_allowed' => true,
            'reason' => 'external_ai_blocked_by_privacy_class',
        ], 'claude');

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::OperationBlocked->value, $event->event_type);
        $this->assertSame('atlas.memory_provider_privacy', $event->emitter_stage);
        $this->assertSame('tenant-memory', $event->tenant_id);
        $this->assertSame('operator-memory', $event->operator_id);
        $this->assertSame('trace-memory-01', $event->trace_id);
        $this->assertSame('memory.provider_projection.external_ai_blocked_by_privacy_class', data_get($event->payload, 'violation_code'));
        $this->assertSame('memory-secret-01', data_get($event->payload, 'memory_entry.id'));
        $this->assertSame('secret', data_get($event->payload, 'privacy.class'));
        $this->assertArrayNotHasKey('title', data_get($event->payload, 'memory_entry'));
        $this->assertArrayNotHasKey('body', data_get($event->payload, 'memory_entry'));
    }

    public function test_records_slo_observation_as_canonical_ledger_event(): void
    {
        $assessment = app(KernelSloTargets::class)->assess('decide.issue', 900, true);

        $event = app(AtlasEvidenceLedger::class)->recordSloObservation($assessment, [
            'tenant_id' => 'tenant-slo',
            'operator_id' => 'operator-slo',
            'envelope_id' => 'env_slo',
            'receipt_id' => 'receipt_slo',
            'trace_id' => 'trace_slo',
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::SloObserved->value, $event->event_type);
        $this->assertSame('atlas.slo', $event->emitter_stage);
        $this->assertSame('tenant-slo', $event->tenant_id);
        $this->assertSame('operator-slo', $event->operator_id);
        $this->assertSame('env_slo', $event->envelope_id);
        $this->assertSame('receipt_slo', $event->receipt_id);
        $this->assertSame('trace_slo', $event->trace_id);
        $this->assertSame('decide.issue', data_get($event->payload, 'stage'));
        $this->assertSame('breach', data_get($event->payload, 'status'));
        $this->assertSame(['latency_above_p99'], data_get($event->payload, 'violations'));
        $this->assertSame(900, data_get($event->payload, 'slo.duration_ms'));
        $this->assertSame(750, data_get($event->payload, 'slo.target.p99_ms'));
    }

    public function test_records_repair_decision_as_canonical_ledger_event(): void
    {
        $request = app(RepairRequestFactory::class)->fromKernelContext(
            envelopeId: 'engineering_run:run-repair-01',
            receiptId: 'receipt-repair-01',
            failure: new FailureClassification(
                domain: FailureDomain::HarnessFailed,
                source: 'engineering_harness',
                signals: ['status:blocked'],
            ),
            policy: app(RepairRequestFactory::class)->defaultPolicy(maxAttempts: 2),
            evidenceRefs: ['engineering_run:run-repair-01'],
            dryRun: false,
        );
        $decision = app(AtlasRepairOrchestrator::class)->plan($request);

        $event = app(AtlasEvidenceLedger::class)->recordRepairDecision($decision, [
            'tenant_id' => 'tenant-repair',
            'operator_id' => 'operator-repair',
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::RepairInitiated->value, $event->event_type);
        $this->assertSame('atlas.repair', $event->emitter_stage);
        $this->assertSame('tenant-repair', $event->tenant_id);
        $this->assertSame('operator-repair', $event->operator_id);
        $this->assertSame('engineering_run:run-repair-01', $event->envelope_id);
        $this->assertSame('receipt-repair-01', $event->receipt_id);
        $this->assertSame('repair_allowed', data_get($event->payload, 'decision.status'));
        $this->assertSame('rerun_harness', data_get($event->payload, 'decision.strategy'));
        $this->assertSame('harness.failed', data_get($event->payload, 'failure_classification.failure_domain'));
        $this->assertFalse((bool) data_get($event->payload, 'repair_executed'));
    }

    public function test_records_repair_result_as_canonical_completed_event(): void
    {
        $request = app(RepairRequestFactory::class)->fromKernelContext(
            envelopeId: 'engineering_run:run-repair-02',
            receiptId: 'receipt-repair-02',
            failure: new FailureClassification(
                domain: FailureDomain::ProviderTimeout,
                source: 'provider_runtime',
                signals: ['timeout'],
            ),
            policy: app(RepairRequestFactory::class)->defaultPolicy(maxAttempts: 2),
            evidenceRefs: ['provider_call:call-01'],
            dryRun: true,
        );
        $result = app(AtlasRepairOrchestrator::class)->attempt($request);

        $event = app(AtlasEvidenceLedger::class)->recordRepairResult($result, [
            'tenant_id' => 'tenant-repair',
            'operator_id' => 'operator-repair',
            'causation_id' => data_get($result->decision->evidencePayload, 'decision_hash'),
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::RepairCompleted->value, $event->event_type);
        $this->assertSame('atlas.repair', $event->emitter_stage);
        $this->assertSame('tenant-repair', $event->tenant_id);
        $this->assertSame('operator-repair', $event->operator_id);
        $this->assertSame('engineering_run:run-repair-02', $event->envelope_id);
        $this->assertSame('receipt-repair-02', $event->receipt_id);
        $this->assertSame(data_get($result->decision->evidencePayload, 'decision_hash'), $event->causation_id);
        $this->assertSame('repair_allowed', data_get($event->payload, 'decision.status'));
        $this->assertSame('retry_provider', data_get($event->payload, 'decision.strategy'));
        $this->assertSame('execution_blocked_by_dry_run', data_get($event->payload, 'attempt.reasons.0'));
        $this->assertFalse((bool) data_get($event->payload, 'repair_executed'));
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(): array
    {
        return [
            'receipt_id' => 'receipt_01',
            'envelope_id' => 'env_01',
            'schema_version' => 'atlas.decide.v2',
            'issued_at' => '2026-05-05T01:00:00.000000Z',
            'expires_at' => '2026-05-05T01:00:30.000000Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => ['primary' => 'codex_cli', 'selection_mode' => 'auto_best_allowed'],
            'budgets' => [],
            'required_gates' => ['tests'],
            'required_evidence' => ['provider_selection'],
            'repair_policy' => ['enabled' => true, 'max_attempts' => 2],
            'inputs_hash' => hash('sha256', 'input'),
            'receipt_hash' => hash('sha256', 'receipt'),
            'chain_hash' => hash('sha256', 'chain'),
        ];
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }
}
