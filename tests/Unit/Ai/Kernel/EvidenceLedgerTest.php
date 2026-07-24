<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairRequestFactory;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use Illuminate\Support\Facades\DB;
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

        $events = app(AtlasEvidenceLedger::class)->eventsForEnvelope($envelope->envelopeId, 'tenant-a');

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

    public function test_v2_seals_the_full_tenant_chain_envelope_and_dual_reads_v1_as_legacy_unverified(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $event = $ledger->record(LedgerEventType::ContextComposed, ['proof' => ['b' => 2, 'a' => 1]], [
            'event_id' => '01JAAEOSLEDGERV2EVENT000001',
            'tenant_id' => 'tenant-v2',
            'operator_id' => 'operator-v2',
            'envelope_id' => 'envelope-v2',
            'correlation_id' => 'correlation-v2',
            'scope_type' => 'aaeos_cycle',
            'scope_id' => 'cycle-v2',
            'occurred_at' => '2026-07-24T12:34:56.987654Z',
        ]);

        self::assertNotNull($event);
        self::assertSame(AtlasEvidenceLedger::SCHEMA_VERSION_V2, $event->schema_version);
        self::assertSame(AtlasEvidenceLedger::CHAIN_BASIS_FULL_ENVELOPE_V2, $event->chain_basis);
        self::assertSame(1, $event->chain_position);
        self::assertSame('verified', $ledger->eventIntegrityStatus($event));
        self::assertSame(
            $event->event_hash,
            $this->independentV2EnvelopeHash($this->independentV2Envelope($event)),
        );

        $legacyHash = hash('sha256', 'historical-v1-bytes');
        DB::table('atlas_ledger_events')->insert([
            'event_id' => '01JAAEOSLEGACYV1EVENT00001',
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'tenant-v2',
            'operator_id' => 'operator-v2',
            'envelope_id' => 'legacy-envelope',
            'correlation_id' => 'legacy-correlation',
            'event_type' => LedgerEventType::ContextComposed->value,
            'emitter_stage' => 'legacy.writer',
            'emitter_version' => 'v1',
            'payload' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', '{"legacy":true}'),
            'event_hash' => $legacyHash,
            'occurred_at' => '2026-07-23 12:00:00',
            'created_at' => '2026-07-23 12:00:00',
            'updated_at' => '2026-07-23 12:00:00',
        ]);

        $legacy = AtlasLedgerEvent::query()->findOrFail('01JAAEOSLEGACYV1EVENT00001');
        self::assertSame(AtlasEvidenceLedger::INTEGRITY_LEGACY_UNVERIFIED, $ledger->eventIntegrityStatus($legacy));
        self::assertFalse($ledger->eventIntegrityValid($legacy));
        self::assertSame($legacyHash, $legacy->event_hash);
    }

    public function test_v2_independent_oracle_preserves_null_empty_tenant_and_principal_distinctions(): void
    {
        $base = [
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION_V2,
            'event_id' => 'event-null-empty',
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
            'envelope_id' => 'envelope-a',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'correlation-a',
            'causation_id' => null,
            'event_type' => LedgerEventType::ContextComposed->value,
            'emitter_stage' => 'oracle',
            'emitter_version' => 'v2',
            'scope_type' => null,
            'scope_id' => null,
            'chain_basis' => AtlasEvidenceLedger::CHAIN_BASIS_FULL_ENVELOPE_V2,
            'chain_key_hash' => str_repeat('a', 64),
            'chain_position' => 1,
            'prev_event_hash' => null,
            'payload' => ['empty' => '', 'nullable' => null],
            'payload_hash' => str_repeat('b', 64),
            'occurred_at' => '2026-07-24T12:34:56Z',
        ];

        $nullReceipt = $this->independentV2EnvelopeHash($base);
        $emptyReceipt = $this->independentV2EnvelopeHash([...$base, 'receipt_id' => '']);
        $otherTenant = $this->independentV2EnvelopeHash([...$base, 'tenant_id' => 'tenant-b']);
        $otherPrincipal = $this->independentV2EnvelopeHash([...$base, 'operator_id' => 'operator-b']);

        self::assertNotSame($nullReceipt, $emptyReceipt);
        self::assertNotSame($nullReceipt, $otherTenant);
        self::assertNotSame($nullReceipt, $otherPrincipal);
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

    public function test_records_kernel_pipeline_accepted_without_raw_prompt(): void
    {
        $event = app(AtlasEvidenceLedger::class)->recordKernelPipelineAccepted($this->kernelPipelinePlan(), [
            'tenant_id' => 'tenant-kernel',
            'operator_id' => 'operator-kernel',
            'surface_contract' => [
                'required' => true,
                'source' => 'KernelPipelineDevPlanBuilder',
                'surface_must_not_decide' => true,
                'provider_execution_blocked_until_runtime_migration' => true,
                'runtime_execution_blocked_until_runtime_migration' => true,
            ],
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::KernelPipelineAccepted->value, $event->event_type);
        $this->assertSame('atlas.kernel_pipeline_guard', $event->emitter_stage);
        $this->assertSame('tenant-kernel', $event->tenant_id);
        $this->assertSame('operator-kernel', $event->operator_id);
        $this->assertSame('kernel_pipeline:pipe_test_01', $event->envelope_id);
        $this->assertSame('pipe_test_01', $event->correlation_id);
        $this->assertSame('accepted', data_get($event->payload, 'status'));
        $this->assertSame([], data_get($event->payload, 'violations'));
        $this->assertSame('atlas.kernel.pipeline.scaffold.v1', data_get($event->payload, 'pipeline.schema_version'));
        $this->assertSame('programming.dev', data_get($event->payload, 'routing.flow'));
        $this->assertSame('KernelPipelineDevPlanBuilder', data_get($event->payload, 'surface_contract.source'));
        $this->assertTrue(data_get($event->payload, 'surface_contract.surface_must_not_decide'));
        $this->assertSame('hash-only', data_get($event->payload, 'input.primary_text_hash'));
        $this->assertArrayNotHasKey('primary_text', data_get($event->payload, 'input'));
    }

    public function test_records_kernel_pipeline_rejected_with_violations(): void
    {
        $plan = $this->kernelPipelinePlan();
        $plan['canonical_flow_hash'] = 'tampered';

        $event = app(AtlasEvidenceLedger::class)->recordKernelPipelineRejected($plan, [
            'kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.',
        ], [
            'tenant_id' => 'tenant-kernel',
            'operator_id' => 'operator-kernel',
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::KernelPipelineRejected->value, $event->event_type);
        $this->assertSame('atlas.ai_chat.kernel_pipeline_guard', $event->emitter_stage);
        $this->assertSame('rejected', data_get($event->payload, 'status'));
        $this->assertSame(['kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.'], data_get($event->payload, 'violations'));
        $this->assertSame('tampered', data_get($event->payload, 'pipeline.canonical_flow_hash'));
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
            'rivals_arm' => 'direct_provider_baseline',
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
        $this->assertSame('direct_provider_baseline', data_get($event->payload, 'dimensions.rivals_arm'));
    }

    public function test_records_voice_event_without_persisting_raw_audio_or_transcript(): void
    {
        $event = app(AtlasEvidenceLedger::class)->recordVoiceEvent(LedgerEventType::VoiceTurnTranscribed, [
            'envelope_id' => 'env_voice_01',
            'receipt_id' => 'receipt_voice_01',
            'session_id' => 'voice_session_01',
            'turn_id' => 'voice_turn_01',
            'surface_id' => 'voice_realtime',
            'audio_hash' => hash('sha256', 'audio-bytes'),
            'audio_bytes' => 'raw audio must not be stored',
            'raw_audio' => 'raw audio must not be stored',
            'transcript' => 'texto sensivel falado pelo usuario',
            'response_text' => 'resposta sensivel falada pelo atlas',
            'latency_ms' => 312,
            'session_lease' => [
                'token_status' => 'issued',
                'access_token' => 'must-not-persist',
                'nested' => ['livekit_token' => 'also-forbidden'],
            ],
        ], [
            'tenant_id' => 'tenant-voice',
            'operator_id' => 'operator-voice',
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::VoiceTurnTranscribed->value, $event->event_type);
        $this->assertSame('atlas.voice_realtime', $event->emitter_stage);
        $this->assertSame('tenant-voice', $event->tenant_id);
        $this->assertSame('operator-voice', $event->operator_id);
        $this->assertSame('env_voice_01', $event->envelope_id);
        $this->assertSame('receipt_voice_01', $event->receipt_id);
        $this->assertSame('voice_session_01', $event->correlation_id);
        $this->assertSame('atlas.voice.ledger_event.v1', data_get($event->payload, 'schema_version'));
        $this->assertSame('p3_audio', data_get($event->payload, 'privacy_class'));
        $this->assertSame(hash('sha256', 'texto sensivel falado pelo usuario'), data_get($event->payload, 'voice.transcript_hash'));
        $this->assertSame(34, data_get($event->payload, 'voice.transcript_length'));
        $this->assertSame(hash('sha256', 'resposta sensivel falada pelo atlas'), data_get($event->payload, 'voice.response_text_hash'));
        $this->assertSame(35, data_get($event->payload, 'voice.response_text_length'));
        $this->assertArrayNotHasKey('audio_bytes', data_get($event->payload, 'voice'));
        $this->assertArrayNotHasKey('raw_audio', data_get($event->payload, 'voice'));
        $this->assertArrayNotHasKey('transcript', data_get($event->payload, 'voice'));
        $this->assertArrayNotHasKey('response_text', data_get($event->payload, 'voice'));
        $this->assertSame('issued', data_get($event->payload, 'voice.session_lease.token_status'));
        $this->assertArrayNotHasKey('access_token', data_get($event->payload, 'voice.session_lease'));
        $this->assertArrayNotHasKey('livekit_token', data_get($event->payload, 'voice.session_lease.nested'));
    }

    public function test_rejects_non_voice_event_in_voice_recorder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AtlasEvidenceLedger::class)->recordVoiceEvent(LedgerEventType::DecisionIssued, [
            'envelope_id' => 'env_voice_bad',
        ]);
    }

    public function test_records_local_rag_event_without_persisting_raw_context_or_query(): void
    {
        $event = app(AtlasEvidenceLedger::class)->recordLocalRagEvent(LedgerEventType::LocalRagQualityCorpusEvaluated, [
            'envelope_id' => 'env_rag_01',
            'receipt_id' => 'receipt_rag_01',
            'benchmark_id' => 'local_rag_controlled_router_quality_v1',
            'query' => 'consulta privada que nao deve persistir',
            'raw_context' => 'contexto bruto que nao deve persistir',
            'documents' => [['content' => 'documento bruto que nao deve persistir']],
            'sources' => [
                ['source_id' => 'doc_01', 'source_hash' => hash('sha256', 'doc')],
            ],
            'quality_corpus' => [
                'status' => 'passed',
                'latency_p95_ms' => 0.42,
            ],
            'privacy_class' => 'restricted',
            'api_key' => 'must-not-persist',
        ], [
            'tenant_id' => 'tenant-rag',
            'operator_id' => 'operator-rag',
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::LocalRagQualityCorpusEvaluated->value, $event->event_type);
        $this->assertSame('atlas.context_retrieval', $event->emitter_stage);
        $this->assertSame('tenant-rag', $event->tenant_id);
        $this->assertSame('operator-rag', $event->operator_id);
        $this->assertSame('env_rag_01', $event->envelope_id);
        $this->assertSame('receipt_rag_01', $event->receipt_id);
        $this->assertSame('local_rag_controlled_router_quality_v1', $event->correlation_id);
        $this->assertSame('atlas.local_rag.ledger_event.v1', data_get($event->payload, 'schema_version'));
        $this->assertSame('restricted', data_get($event->payload, 'privacy_class'));
        $this->assertSame(hash('sha256', 'consulta privada que nao deve persistir'), data_get($event->payload, 'local_rag.query_hash'));
        $this->assertSame(mb_strlen('consulta privada que nao deve persistir'), data_get($event->payload, 'local_rag.query_length'));
        $this->assertSame('doc_01', data_get($event->payload, 'local_rag.sources.0.source_id'));
        $this->assertSame('passed', data_get($event->payload, 'local_rag.quality_corpus.status'));
        $this->assertArrayNotHasKey('query', data_get($event->payload, 'local_rag'));
        $this->assertArrayNotHasKey('raw_context', data_get($event->payload, 'local_rag'));
        $this->assertArrayNotHasKey('documents', data_get($event->payload, 'local_rag'));
        $this->assertArrayNotHasKey('api_key', data_get($event->payload, 'local_rag'));
    }

    public function test_rejects_non_local_rag_event_in_local_rag_recorder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AtlasEvidenceLedger::class)->recordLocalRagEvent(LedgerEventType::DecisionIssued, [
            'envelope_id' => 'env_rag_bad',
        ]);
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

    /**
     * @return array<string,mixed>
     */
    private function kernelPipelinePlan(): array
    {
        return [
            'pipeline_id' => 'pipe_test_01',
            'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
            'mode' => KernelPipelineContract::MODE,
            'status' => KernelPipelineContract::STATUS,
            'canonical_flow_hash' => KernelPipelineContract::canonicalFlowHash(),
            'stage_order' => KernelPipelineStage::orderedValues(),
            'stage_count' => count(KernelPipelineStage::orderedValues()),
            'provider_execution_allowed' => false,
            'runtime_execution_allowed' => false,
            'execution_guards' => [
                'dry_run_effective' => true,
                'provider_execution_allowed' => false,
                'runtime_execution_allowed' => false,
                'surface_runtime_migration_allowed' => false,
            ],
            'input' => [
                'surface_id' => 'atlas_ai_chat',
                'tenant_id' => 'tenant-input',
                'operator_id' => 'operator-input',
                'primary_text_hash' => 'hash-only',
                'input_fingerprint' => 'fingerprint-only',
                'hints_hash' => 'hints-only',
                'metadata_keys' => ['workspace'],
                'safe_hints' => [
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'mode' => 'dev',
                    'runtime' => 'dev_repair_executor',
                ],
            ],
            'surface_binding' => [
                'surface' => 'atlas_ai_chat',
                'command' => 'atlas:ai:chat',
                'input_mode' => 'declared_dev_plan',
            ],
        ];
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
    }

    /** @return array<string,mixed> */
    private function independentV2Envelope(AtlasLedgerEvent $event): array
    {
        return [
            'schema_version' => $event->schema_version,
            'event_id' => $event->event_id,
            'tenant_id' => $event->tenant_id,
            'operator_id' => $event->operator_id,
            'envelope_id' => $event->envelope_id,
            'receipt_id' => $event->receipt_id,
            'trace_id' => $event->trace_id,
            'correlation_id' => $event->correlation_id,
            'causation_id' => $event->causation_id,
            'event_type' => $event->event_type,
            'emitter_stage' => $event->emitter_stage,
            'emitter_version' => $event->emitter_version,
            'scope_type' => $event->scope_type,
            'scope_id' => $event->scope_id,
            'chain_basis' => $event->chain_basis,
            'chain_key_hash' => $event->chain_key_hash,
            'chain_position' => $event->chain_position,
            'prev_event_hash' => $event->prev_event_hash,
            'payload' => $event->payload,
            'payload_hash' => $event->payload_hash,
            'occurred_at' => $event->occurred_at?->utc()->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }

    /** @param array<string,mixed> $envelope */
    private function independentV2EnvelopeHash(array $envelope): string
    {
        return hash('sha256', json_encode(
            $this->independentCanonicalize($envelope),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function independentCanonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $list = array_is_list($value);
        if (! $list) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->independentCanonicalize($item);
        }

        return $list ? array_values($value) : $value;
    }
}
