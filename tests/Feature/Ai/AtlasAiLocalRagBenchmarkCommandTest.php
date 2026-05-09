<?php

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasAiLocalRagBenchmarkCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');

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
        $this->assertFalse(data_get($payload, 'promotion_gate.graph_rag_promotion_allowed'));
        $this->assertSame([], data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.remaining_prerequisites'));
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
        $this->assertFalse(data_get($payload, 'promotion_gate.graph_rag_promotion_allowed'));
        $this->assertSame('draft_only_until_quality_latency_privacy_benchmark', data_get($payload, 'promotion_gate.policy_patch_status'));
        $this->assertContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('latency_p95_measurement', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('privacy_redaction_verification', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertContains('evidence_ledger_contract', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertNotContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertNotContains('evidence_ledger_contract', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('human_review_or_curator_proposal', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertContains('retrieval_quality_corpus', data_get($payload, 'promotion_gate.required_before_promotion'));
        $this->assertSame('atlas.local_rag.ledger_contract.v1', data_get($payload, 'ledger_contract.schema_version'));
        $this->assertSame('AtlasEvidenceLedger::recordLocalRagEvent', data_get($payload, 'ledger_contract.recording_method'));
        $this->assertContains('LOCAL_RAG_GRAPH_PROMOTION_BLOCKED', data_get($payload, 'ledger_contract.event_types'));
        $this->assertFalse(data_get($payload, 'ledger_contract.payload_contract.raw_context_persistence_allowed'));
        $this->assertSame('obtain_human_review_or_curator_proposal_before_graph_rag_runtime_promotion', $payload['next_action']);

        $architecture = collect($payload['cases'])->firstWhere('id', 'architecture_relation_context');
        $this->assertSame('passed', $architecture['status']);
        $this->assertSame('architecture', $architecture['domain_family']);
        $this->assertSame('internal', $architecture['privacy_class']);
        $this->assertContains('graph_retrieval', $architecture['selected_sources']);
        $this->assertSame('degraded', $architecture['readiness_status']);
        $this->assertTrue(data_get($architecture, 'checks.graph_rag_governed_when_selected'));
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
