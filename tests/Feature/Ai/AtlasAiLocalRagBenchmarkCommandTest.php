<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasAiLocalRagBenchmarkCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_benchmark_reports_attention_when_local_rag_substrate_is_missing(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');
        config()->set('atlas.semantic_memory.embedding_provider', 'local_hash');

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.local_rag_benchmark.v1', $payload['schema_version']);
        $this->assertSame('attention', $payload['status']);
        $this->assertSame('blocked', $payload['readiness_status']);
        $this->assertSame('attention', data_get($payload, 'quality_corpus.status'));
        $this->assertFalse(data_get($payload, 'promotion_gate.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_gate.graph_rag_promotion_allowed'));
        $this->assertSame([], data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('future_graph_rag_python_ap', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('decision_receipt_for_runtime_promotion', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertFalse(data_get($payload, 'guardrails.provider_bypass_allowed'));
        $this->assertFalse(data_get($payload, 'guardrails.parallel_memory_allowed'));
        $this->assertSame('fix_router_or_readiness_gates_before_graph_rag_work', $payload['next_action']);
    }

    public function test_benchmark_passes_router_governance_when_local_rag_substrate_exists(): void
    {
        $this->createLocalRagTables();
        config()->set('atlas.semantic_memory.embedding_provider', 'local_hash');

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('ready', $payload['readiness_status']);
        $this->assertSame(4, $payload['case_count']);
        $this->assertSame(4, $payload['passed_case_count']);
        $this->assertEquals(1.0, $payload['average_score']);
        $this->assertSame('atlas.local_rag_quality_corpus.v1', data_get($payload, 'quality_corpus.schema_version'));
        $this->assertSame('passed', data_get($payload, 'quality_corpus.status'));
        $this->assertSame('local_rag_controlled_router_quality_v1', data_get($payload, 'quality_corpus.corpus_id'));
        $this->assertSame([
            'architecture',
            'finance',
            'personal_development',
            'programming',
        ], data_get($payload, 'quality_corpus.coverage.domain_families'));
        $this->assertSame([], data_get($payload, 'quality_corpus.coverage.missing_domain_families'));
        $this->assertTrue(data_get($payload, 'quality_corpus.checks.privacy_boundary_declared'));
        $this->assertTrue(data_get($payload, 'quality_corpus.checks.graph_future_governed'));
        $this->assertLessThanOrEqual(250.0, data_get($payload, 'quality_corpus.metrics.latency_p95_ms'));
        $this->assertFalse(data_get($payload, 'promotion_gate.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_gate.graph_rag_promotion_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_gate.python_runtime_promotion_allowed'));
        $this->assertSame('draft_only_until_quality_latency_privacy_benchmark', data_get($payload, 'promotion_gate.policy_patch_status'));
        $this->assertSame('LOCAL_RAG_GRAPH_PROMOTION_BLOCKED', data_get($payload, 'promotion_gate.supersedes_event_required'));
        $this->assertSame('human_reviewed_curator_proposal_and_future_ap', data_get($payload, 'promotion_gate.supersede_authority'));
        $this->assertSame('atlas.local_rag_graph_promotion_review.v1', data_get($payload, 'promotion_review_contract.schema_version'));
        $this->assertSame('human_review_required', data_get($payload, 'promotion_review_contract.status'));
        $this->assertSame('local_rag_graph_promotion_review', data_get($payload, 'promotion_review_contract.architecture_operation_id'));
        $this->assertTrue(data_get($payload, 'promotion_review_contract.human_review_required'));
        $this->assertTrue(data_get($payload, 'promotion_review_contract.curator_proposal_required'));
        $this->assertTrue(data_get($payload, 'promotion_review_contract.future_ap_required'));
        $this->assertTrue(data_get($payload, 'promotion_review_contract.decision_receipt_required'));
        $this->assertTrue(data_get($payload, 'promotion_review_contract.rollback_plan_required'));
        $this->assertTrue(data_get($payload, 'promotion_review_contract.policy_patch_review_required'));
        $this->assertFalse(data_get($payload, 'promotion_review_contract.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_review_contract.auto_promotion_allowed'));
        $this->assertSame('atlas.local_rag_graph_promotion_review_packet.v1', data_get($payload, 'promotion_review_contract.review_packet.schema_version'));
        $this->assertSame('blocked_until_human_review_and_future_ap', data_get($payload, 'promotion_review_contract.review_packet.status'));
        $this->assertSame('approve_or_reject_graph_rag_python_future_ap_scope', data_get($payload, 'promotion_review_contract.review_packet.required_human_decision'));
        $this->assertContains('LOCAL_RAG_GRAPH_PROMOTION_BLOCKED', data_get($payload, 'promotion_review_contract.review_packet.evidence_required'));
        $this->assertContains('real_corpus_retrieval_answer_quality', data_get($payload, 'promotion_review_contract.review_packet.evidence_required'));
        $this->assertContains('disable_python_graph_rag_runtime_policy', data_get($payload, 'promotion_review_contract.review_packet.rollback_required'));
        $this->assertContains('enable_python_graph_rag_runtime', data_get($payload, 'promotion_review_contract.review_packet.forbidden_until_review'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.schema_version'));
        $this->assertTrue(data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.kernel_first'));
        $this->assertSame('python_ai_data', data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.selected_runtime_family'));
        $this->assertSame('graph_rag_candidate', data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.runtime_id'));
        $this->assertFalse(data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.promotion_allowed_now'));
        $this->assertFalse(data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.auto_enable_allowed_now'));
        $this->assertContains('decision_receipt_hash', data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.required_fields'));
        $this->assertContains('evidence_sink', data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.required_fields'));
        $this->assertContains('choose_provider_or_model', data_get($payload, 'promotion_review_contract.future_runtime_invocation_contract.forbidden_runtime_authority'));
        $this->assertContains('proposal_only', data_get($payload, 'promotion_review_contract.allowed_outputs'));
        $this->assertContains('python_runtime_auto_enable', data_get($payload, 'promotion_review_contract.forbidden_outputs'));
        $this->assertContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('latency_p95_measurement', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('privacy_redaction_verification', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('evidence_ledger_contract', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertNotContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertNotContains('evidence_ledger_contract', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('human_review_or_curator_proposal', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('future_graph_rag_python_ap', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('decision_receipt_for_runtime_promotion', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('reviewable_policy_patch_with_rollback', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('real_corpus_retrieval_answer_quality', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.required_before_promotion'));
        $this->assertSame('atlas.local_rag.ledger_contract.v1', data_get($payload, 'ledger_contract.schema_version'));
        $this->assertSame('AtlasEvidenceLedger::recordLocalRagEvent', data_get($payload, 'ledger_contract.recording_method'));
        $this->assertContains('LOCAL_RAG_GRAPH_PROMOTION_BLOCKED', data_get($payload, 'ledger_contract.event_types'));
        $this->assertFalse(data_get($payload, 'ledger_contract.payload_contract.raw_context_persistence_allowed'));
        $this->assertSame('atlas.local_rag.evidence_ledger_report.v1', data_get($payload, 'evidence_ledger.schema_version'));
        $this->assertSame('persisted', data_get($payload, 'evidence_ledger.status'));
        $this->assertSame('LOCAL_RAG_*', data_get($payload, 'evidence_ledger.event_family'));
        $this->assertSame(3, data_get($payload, 'evidence_ledger.expected_event_count'));
        $this->assertSame(3, data_get($payload, 'evidence_ledger.recorded_event_count'));
        $this->assertTrue(data_get($payload, 'evidence_ledger.persistence_required_for_promotion'));
        $this->assertTrue(data_get($payload, 'evidence_ledger.promotion_evidence_satisfied'));
        $this->assertNull(data_get($payload, 'evidence_ledger.missing_reason'));
        $this->assertSame([
            LedgerEventType::LocalRagPlanCreated->value,
            LedgerEventType::LocalRagQualityCorpusEvaluated->value,
            LedgerEventType::LocalRagGraphPromotionBlocked->value,
        ], collect(data_get($payload, 'evidence_ledger.events'))->pluck('event_type')->all());
        $this->assertSame('obtain_human_review_or_curator_proposal_before_graph_rag_runtime_promotion', $payload['next_action']);

        $events = AtlasLedgerEvent::query()
            ->whereIn('event_id', collect(data_get($payload, 'evidence_ledger.events'))->pluck('event_id')->all())
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get();

        $this->assertCount(3, $events);
        $this->assertSame([
            LedgerEventType::LocalRagPlanCreated->value,
            LedgerEventType::LocalRagQualityCorpusEvaluated->value,
            LedgerEventType::LocalRagGraphPromotionBlocked->value,
        ], $events->pluck('event_type')->all());
        $this->assertStringNotContainsString('implementar patch no repo', $events->toJson());
        $this->assertFalse((bool) data_get($events->last()?->payload, 'local_rag.promotion_gate.promotion_allowed'));
        $this->assertFalse((bool) data_get($events->last()?->payload, 'local_rag.promotion_gate.graph_rag_promotion_allowed'));
        $this->assertFalse((bool) data_get($events->last()?->payload, 'local_rag.promotion_gate.python_runtime_promotion_allowed'));
        $this->assertSame('LOCAL_RAG_GRAPH_PROMOTION_BLOCKED', data_get($events->last()?->payload, 'local_rag.promotion_gate.supersedes_event_required'));
        $this->assertSame('atlas.local_rag_graph_promotion_review.v1', data_get($events->last()?->payload, 'local_rag.promotion_review_contract.schema_version'));
        $this->assertSame('atlas.local_rag_graph_promotion_review_packet.v1', data_get($events->last()?->payload, 'local_rag.promotion_review_contract.review_packet.schema_version'));
        $this->assertFalse((bool) data_get($events->last()?->payload, 'local_rag.promotion_review_contract.auto_promotion_allowed'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($events->last()?->payload, 'local_rag.promotion_review_contract.future_runtime_invocation_contract.schema_version'));
        $this->assertSame('python_ai_data', data_get($events->last()?->payload, 'local_rag.promotion_review_contract.future_runtime_invocation_contract.selected_runtime_family'));

        $architecture = collect($payload['cases'])->firstWhere('id', 'architecture_relation_context');
        $this->assertSame('passed', $architecture['status']);
        $this->assertSame('architecture', $architecture['domain_family']);
        $this->assertSame('internal', $architecture['privacy_class']);
        $this->assertContains('graph_retrieval', $architecture['selected_sources']);
        $this->assertSame('degraded', $architecture['readiness_status']);
        $this->assertTrue(data_get($architecture, 'checks.graph_rag_governed_when_selected'));
    }

    public function test_benchmark_reports_ledger_unavailable_when_events_cannot_be_persisted(): void
    {
        $this->createLocalRagTables();
        Schema::dropIfExists('atlas_ledger_events');
        config()->set('atlas.semantic_memory.embedding_provider', 'local_hash');

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('unavailable', data_get($payload, 'evidence_ledger.status'));
        $this->assertSame(3, data_get($payload, 'evidence_ledger.expected_event_count'));
        $this->assertSame(0, data_get($payload, 'evidence_ledger.recorded_event_count'));
        $this->assertTrue(data_get($payload, 'evidence_ledger.persistence_required_for_promotion'));
        $this->assertFalse(data_get($payload, 'evidence_ledger.promotion_evidence_satisfied'));
        $this->assertSame('atlas_ledger_events_table_unavailable_or_write_failed', data_get($payload, 'evidence_ledger.missing_reason'));
        $this->assertFalse(data_get($payload, 'evidence_ledger.events.0.recorded'));
        $this->assertFalse(data_get($payload, 'promotion_gate.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'promotion_review_contract.auto_promotion_allowed'));
    }

    private function createLocalRagTables(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');

        Schema::create('semantic_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_attachment_index_entries', function (Blueprint $table): void {
            $table->id();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });
    }
}
