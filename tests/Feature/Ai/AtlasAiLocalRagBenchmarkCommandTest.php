<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryQualitySnapshot;
use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\AtlasVerbatimMemoryService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Carbon\CarbonImmutable;
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
        Schema::dropIfExists('atlas_memory_quality_snapshots');
        Schema::dropIfExists('atlas_ledger_events');
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_benchmark_schedule_plan_is_inspectable_and_disabled_by_default(): void
    {
        config()->set('atlas_ai.local_rag_benchmark.schedule_enabled', false);
        config()->set('atlas_ai.local_rag_benchmark.schedule_time', '02:30');
        config()->set('atlas_ai.local_rag_benchmark.schedule_workspace', null);

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--schedule-plan' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.local_rag_benchmark.schedule.v1', $payload['schema_version']);
        $this->assertFalse($payload['enabled']);
        $this->assertFalse($payload['schedulable']);
        $this->assertSame('skipped', data_get($payload, 'scheduler_registration.status'));
        $this->assertSame('local_rag_benchmark_schedule_disabled', data_get($payload, 'scheduler_registration.skipped_reason'));
        $this->assertSame('atlas:ai:local-rag-benchmark --record-memory-quality --json', $payload['command']);
        $this->assertFalse($payload['raw_query_persisted']);
        $this->assertFalse($payload['raw_context_persisted']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['plan_hash']);
    }

    public function test_benchmark_schedule_plan_registers_daily_snapshot_command_when_enabled(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05 02:00:00', 'America/Sao_Paulo'));
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.local_rag_benchmark.schedule_enabled', true);
        config()->set('atlas_ai.local_rag_benchmark.schedule_time', '02:30');
        config()->set('atlas_ai.local_rag_benchmark.schedule_workspace', '/tmp/atlas workspace');

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--schedule-plan' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['enabled']);
        $this->assertTrue($payload['schedulable']);
        $this->assertSame('registered', data_get($payload, 'scheduler_registration.status'));
        $this->assertSame(1, data_get($payload, 'scheduler_registration.registered_command_count'));
        $this->assertSame('healthy', data_get($payload, 'health.status'));
        $this->assertSame('daily', $payload['cadence']);
        $this->assertSame('2026-05-05T05:30:00.000000Z', $payload['next_run_at']);
        $this->assertSame("atlas:ai:local-rag-benchmark --record-memory-quality --json --workspace='/tmp/atlas workspace'", $payload['command']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['plan_hash']);
    }

    public function test_benchmark_rivals_shadow_plan_is_blocked_and_hash_only(): void
    {
        $exit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--rivals-shadow-plan' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.local_rag_benchmark.rivals_shadow_plan.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('proposal_only_no_runtime_execution', $payload['mode']);
        $this->assertSame('current_governed_hybrid_memory_recall', $payload['baseline_strategy_id']);
        $this->assertSame('docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md', $payload['review_ap']);
        $this->assertTrue($payload['proposal_only']);
        $this->assertFalse($payload['auto_action_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['runtime_execution_allowed']);
        $this->assertFalse($payload['policy_auto_apply_allowed']);
        $this->assertFalse($payload['raw_query_persisted']);
        $this->assertFalse($payload['raw_context_persisted']);
        $this->assertSame('atlas.retrieval_rivals.safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertTrue(data_get($payload, 'safety.proposal_only'));
        $this->assertFalse(data_get($payload, 'safety.provider_call_allowed'));
        $this->assertFalse(data_get($payload, 'safety.runtime_execution_allowed'));
        $this->assertFalse(data_get($payload, 'safety.policy_auto_apply_allowed'));
        $this->assertFalse(data_get($payload, 'safety.memory_write_allowed'));
        $this->assertFalse(data_get($payload, 'safety.raw_capture_exposed'));
        $this->assertSame('current_governed_hybrid_memory_recall', data_get($payload, 'strategy_candidates.0.id'));
        $this->assertSame('lexical_keyword_fallback_candidate', data_get($payload, 'strategy_candidates.1.id'));
        $this->assertSame('future_graph_rag_python_candidate', data_get($payload, 'strategy_candidates.2.id'));
        $this->assertFalse(data_get($payload, 'strategy_candidates.2.shadow_execution_allowed_now'));
        $this->assertContains('docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md', data_get($payload, 'strategy_candidates.2.evidence_required'));
        $this->assertSame('blocked', data_get($payload, 'execution_gate.status'));
        $this->assertContains('shadow_case_contract', data_get($payload, 'execution_gate.required_before_any_shadow_run'));
        $this->assertContains('no_runtime_invocation_contract_for_alternatives', data_get($payload, 'execution_gate.blocked_reasons'));
        $this->assertSame('atlas.memory_retrieval_rivals_shadow_plan_review_packet.v1', data_get($payload, 'review_packet.schema_version'));
        $this->assertContains('docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md', data_get($payload, 'review_packet.evidence_required'));
        $this->assertContains('execute_python_graph_rag', data_get($payload, 'review_packet.forbidden_actions'));
        $this->assertContains('send_raw_capture_to_provider', data_get($payload, 'review_packet.forbidden_actions'));
        $this->assertSame('atlas.rivals.evaluation_contract.v1', data_get($payload, 'evaluation_contract.schema_version'));
        $this->assertSame('memory_open_brain_retrieval', data_get($payload, 'evaluation_contract.benchmark_family'));
        $this->assertSame('current_governed_hybrid_memory_recall', data_get($payload, 'evaluation_contract.baseline_strategy_id'));
        $this->assertContains('precision_at_k', data_get($payload, 'evaluation_contract.required_metrics'));
        $this->assertContains('provider_safe_context_rate', data_get($payload, 'evaluation_contract.required_metrics'));
        $this->assertContains('rival_strategy_is_better', data_get($payload, 'evaluation_contract.forbidden_claims_without_evidence'));
        $this->assertFalse(data_get($payload, 'evaluation_contract.raw_query_persisted'));
        $this->assertFalse(data_get($payload, 'evaluation_contract.raw_context_persisted'));
        $this->assertFalse(data_get($payload, 'evaluation_contract.auto_promotion_allowed'));
        $this->assertSame('draft_ap_for_retrieval_shadow_comparison_before_execution', $payload['next_action']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['plan_hash']);
        $this->assertStringNotContainsString('Bearer', Artisan::output());
        $this->assertStringNotContainsString('raw capture', Artisan::output());
    }

    public function test_benchmark_rivals_shadow_plan_can_emit_scope_review_to_inbox_without_runtime(): void
    {
        $capturedPayload = null;
        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000693';
        $inboxItem->title = 'Revisar escopo shadow de retrieval Memory/Open Brain';
        $inboxItem->payload = [
            'proposal_contract' => [
                'review_signal' => [
                    'recommended_action' => 'review_retrieval_shadow_scope',
                ],
            ],
        ];

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, &$capturedPayload): void {
            $mock->shouldReceive('emit')
                ->once()
                ->andReturnUsing(function (array $payload) use ($inboxItem, &$capturedPayload): AiInboxItem {
                    $capturedPayload = $payload;

                    return $inboxItem;
                });
        });

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--rivals-shadow-plan' => true,
            '--emit-rivals-shadow-inbox' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('emitted', data_get($payload, 'emitted_inbox_item.status'));
        $this->assertSame($inboxItem->id, data_get($payload, 'emitted_inbox_item.id'));
        $this->assertSame('Revisar escopo shadow de retrieval Memory/Open Brain', data_get($capturedPayload, 'title'));
        $this->assertSame('atlas.memory_retrieval_rivals_shadow_inbox.v1', data_get($capturedPayload, 'metadata.schema_version'));
        $this->assertSame('review_retrieval_shadow_scope', data_get($capturedPayload, 'metadata.review_signal.recommended_action'));
        $this->assertSame('review_retrieval_shadow_scope', data_get($capturedPayload, 'available_actions.0.id'));
        $this->assertSame('atlas.local_rag_benchmark.rivals_shadow_plan.v1', data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.schema_version'));
        $this->assertSame('atlas.retrieval_rivals.safety.v1', data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.safety.schema_version'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.safety.provider_call_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.safety.runtime_execution_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.safety.memory_write_allowed'));
        $this->assertSame('docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md', data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.review_ap'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.raw_query_persisted'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.raw_context_persisted'));
        $this->assertContains('execute_python_graph_rag', data_get($capturedPayload, 'payload.retrieval_rivals_shadow_plan.review_packet.forbidden_actions'));
        $this->assertStringNotContainsString('Exact local capture evidence', Artisan::output());
        $this->assertStringNotContainsString('Bearer', json_encode($capturedPayload, JSON_THROW_ON_ERROR));
    }

    public function test_benchmark_rivals_report_requires_two_hash_only_snapshots(): void
    {
        $this->createMemoryQualitySnapshotTable();

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--rivals-report' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.local_rag_benchmark.rivals_report.v1', $payload['schema_version']);
        $this->assertSame('insufficient_history', $payload['status']);
        $this->assertTrue($payload['proposal_only']);
        $this->assertFalse($payload['auto_action_allowed']);
        $this->assertFalse($payload['raw_query_persisted']);
        $this->assertFalse($payload['raw_context_persisted']);
        $this->assertSame('atlas.retrieval_rivals.safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertFalse(data_get($payload, 'safety.provider_call_allowed'));
        $this->assertFalse(data_get($payload, 'safety.runtime_execution_allowed'));
        $this->assertFalse(data_get($payload, 'safety.policy_auto_apply_allowed'));
        $this->assertFalse(data_get($payload, 'safety.memory_write_allowed'));
        $this->assertFalse(data_get($payload, 'safety.raw_capture_exposed'));
        $this->assertSame('needs_more_evidence', data_get($payload, 'review_signal.status'));
        $this->assertContains('need_at_least_two_local_rag_benchmark_snapshots', data_get($payload, 'review_signal.reasons'));
        $this->assertSame('atlas.memory_retrieval_rivals_review_packet.v1', data_get($payload, 'review_packet.schema_version'));
        $this->assertContains('persist_raw_query', data_get($payload, 'review_packet.forbidden_actions'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['report_hash']);
    }

    public function test_benchmark_rivals_report_detects_stable_and_regressed_retrieval_snapshots_without_raw_context(): void
    {
        $this->createMemoryQualitySnapshotTable();

        $this->createRetrievalBenchmarkSnapshot(
            score: 90,
            snapshotAt: now()->subDays(2),
            metrics: [
                'precision_at_3' => 1.0,
                'precision_at_5' => 1.0,
                'missed_critical_context_count' => 0,
                'context_contamination_count' => 0,
                'provider_safe_violation_count' => 0,
                'stale_context_use_count' => 0,
                'budget_truncation_count' => 0,
                'reason_coverage' => 1.0,
            ],
        );
        $this->createRetrievalBenchmarkSnapshot(
            score: 92,
            snapshotAt: now()->subDay(),
            metrics: [
                'precision_at_3' => 1.0,
                'precision_at_5' => 1.0,
                'missed_critical_context_count' => 0,
                'context_contamination_count' => 0,
                'provider_safe_violation_count' => 0,
                'stale_context_use_count' => 0,
                'budget_truncation_count' => 0,
                'reason_coverage' => 1.0,
            ],
            secretMarker: 'Exact local capture evidence must stay hidden.',
        );

        $stableExit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--rivals-report' => true,
            '--json' => true,
        ]);
        $stablePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $stableExit);
        $this->assertSame('ready', $stablePayload['status']);
        $this->assertSame('stable', data_get($stablePayload, 'comparison.outcome'));
        $this->assertSame(2, data_get($stablePayload, 'comparison.score_delta'));
        $this->assertSame('monitor', data_get($stablePayload, 'review_signal.status'));
        $this->assertSame('continue_scheduled_retrieval_snapshots', data_get($stablePayload, 'review_signal.recommended_action'));
        $this->assertSame(2, data_get($stablePayload, 'history_summary.total'));
        $this->assertNotEmpty(data_get($stablePayload, 'comparison.latest.source_id_hash'));
        $this->assertSame('atlas.retrieval_rivals.packet.v1', data_get($stablePayload, 'comparison.latest.retrieval_rivals_schema_version'));
        $this->assertSame('atlas.retrieval_rivals.safety.v1', data_get($stablePayload, 'safety.schema_version'));
        $this->assertTrue(data_get($stablePayload, 'safety.latest_snapshot_hashed'));
        $this->assertTrue(data_get($stablePayload, 'safety.previous_snapshot_hashed'));
        $this->assertFalse(data_get($stablePayload, 'safety.provider_call_allowed'));
        $this->assertFalse(data_get($stablePayload, 'safety.runtime_execution_allowed'));
        $this->assertSame('passed', data_get($stablePayload, 'comparison.latest.retrieval_rivals_status'));
        $this->assertSame('proposal_only_no_runtime_execution', data_get($stablePayload, 'comparison.latest.retrieval_rivals_mode'));
        $this->assertSame('current_governed_hybrid_memory_recall', data_get($stablePayload, 'comparison.latest.retrieval_rivals_winner'));
        $this->assertFalse(data_get($stablePayload, 'comparison.latest.retrieval_rivals_delta_measured'));
        $this->assertArrayNotHasKey('source_id', data_get($stablePayload, 'comparison.latest'));
        $this->assertStringNotContainsString('Exact local capture evidence', Artisan::output());

        $this->createRetrievalBenchmarkSnapshot(
            score: 70,
            snapshotAt: now(),
            benchmarkStatus: 'passed',
            recallStatus: 'attention',
            metrics: [
                'precision_at_3' => 0.5,
                'precision_at_5' => 0.5,
                'missed_critical_context_count' => 1,
                'context_contamination_count' => 1,
                'provider_safe_violation_count' => 1,
                'stale_context_use_count' => 1,
                'budget_truncation_count' => 0,
                'reason_coverage' => 0.5,
            ],
            secretMarker: 'Bearer abcdefghijklmno',
        );

        $regressedExit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--rivals-report' => true,
            '--json' => true,
        ]);
        $regressedPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $regressedOutput = Artisan::output();

        $this->assertSame(0, $regressedExit);
        $this->assertSame('attention', $regressedPayload['status']);
        $this->assertSame('regressed', data_get($regressedPayload, 'comparison.outcome'));
        $this->assertSame(-22, data_get($regressedPayload, 'comparison.score_delta'));
        $this->assertSame('review_required', data_get($regressedPayload, 'review_signal.status'));
        $this->assertSame('high', data_get($regressedPayload, 'review_signal.severity'));
        $this->assertSame('open_memory_retrieval_regression_review', data_get($regressedPayload, 'review_signal.recommended_action'));
        $this->assertContains('memory_quality_score_regressed', data_get($regressedPayload, 'review_signal.reasons'));
        $this->assertContains('memory_recall_corpus_not_passed', data_get($regressedPayload, 'review_signal.reasons'));
        $this->assertContains('provider_safe_violation_count', data_get($regressedPayload, 'review_signal.reasons'));
        $this->assertSame('proposal_only_attention', data_get($regressedPayload, 'comparison.latest.retrieval_rivals_status'));
        $this->assertSame('no_measured_winner_yet', data_get($regressedPayload, 'comparison.latest.retrieval_rivals_winner'));
        $this->assertFalse(data_get($regressedPayload, 'comparison.latest.retrieval_rivals_delta_measured'));
        $this->assertTrue(data_get($regressedPayload, 'review_packet.human_review_required'));
        $this->assertContains('enable_python_runtime', data_get($regressedPayload, 'review_packet.forbidden_actions'));
        $this->assertFalse($regressedPayload['auto_action_allowed']);
        $this->assertStringNotContainsString('Bearer abcdefghijklmno', $regressedOutput);
    }

    public function test_benchmark_rivals_report_can_emit_regression_review_to_inbox_without_raw_context(): void
    {
        $this->createMemoryQualitySnapshotTable();
        $latest = null;
        $capturedPayload = null;
        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000456';
        $inboxItem->title = 'Revisar regressao de retrieval Memory/Open Brain';
        $inboxItem->payload = [
            'proposal_contract' => [
                'review_signal' => [
                    'recommended_action' => 'open_memory_retrieval_regression_review',
                ],
            ],
        ];

        $this->createRetrievalBenchmarkSnapshot(
            score: 95,
            snapshotAt: now()->subDay(),
            metrics: [
                'precision_at_3' => 1.0,
                'precision_at_5' => 1.0,
                'missed_critical_context_count' => 0,
                'context_contamination_count' => 0,
                'provider_safe_violation_count' => 0,
                'stale_context_use_count' => 0,
                'budget_truncation_count' => 0,
                'reason_coverage' => 1.0,
            ],
        );
        $latest = $this->createRetrievalBenchmarkSnapshot(
            score: 60,
            snapshotAt: now(),
            recallStatus: 'attention',
            metrics: [
                'precision_at_3' => 0.4,
                'precision_at_5' => 0.4,
                'missed_critical_context_count' => 1,
                'context_contamination_count' => 1,
                'provider_safe_violation_count' => 1,
                'stale_context_use_count' => 0,
                'budget_truncation_count' => 0,
                'reason_coverage' => 0.4,
            ],
            secretMarker: 'Exact local capture evidence should not leave metadata.',
        );

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, &$capturedPayload): void {
            $mock->shouldReceive('emit')
                ->once()
                ->andReturnUsing(function (array $payload) use ($inboxItem, &$capturedPayload): AiInboxItem {
                    $capturedPayload = $payload;

                    return $inboxItem;
                });
        });

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--rivals-report' => true,
            '--emit-rivals-inbox' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('attention', $payload['status']);
        $this->assertSame('emitted', data_get($payload, 'emitted_inbox_item.status'));
        $this->assertSame($inboxItem->id, data_get($payload, 'emitted_inbox_item.id'));
        $this->assertSame('Revisar regressao de retrieval Memory/Open Brain', data_get($capturedPayload, 'title'));
        $this->assertSame('memory_quality', data_get($capturedPayload, 'category'));
        $this->assertSame($latest->id, data_get($capturedPayload, 'source_id'));
        $this->assertSame('open_memory_retrieval_regression_review', data_get($capturedPayload, 'metadata.review_signal.recommended_action'));
        $this->assertSame('review_retrieval_regression', data_get($capturedPayload, 'available_actions.0.id'));
        $this->assertSame('atlas.retrieval_rivals.safety.v1', data_get($capturedPayload, 'payload.retrieval_rivals.safety.schema_version'));
        $this->assertTrue(data_get($capturedPayload, 'payload.retrieval_rivals.safety.latest_snapshot_hashed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals.safety.provider_call_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals.safety.runtime_execution_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals.safety.memory_write_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals.raw_query_persisted'));
        $this->assertFalse(data_get($capturedPayload, 'payload.retrieval_rivals.raw_context_persisted'));
        $this->assertContains('enable_python_runtime', data_get($capturedPayload, 'payload.retrieval_rivals.review_packet.forbidden_actions'));
        $this->assertStringNotContainsString('Exact local capture evidence', Artisan::output());
        $this->assertStringNotContainsString('Exact local capture evidence', json_encode($capturedPayload, JSON_THROW_ON_ERROR));
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
        $this->assertSame('atlas.memory_recall_real_corpus.v1', data_get($payload, 'memory_recall_corpus.schema_version'));
        $this->assertSame('attention', data_get($payload, 'memory_recall_corpus.status'));
        $this->assertSame('memory_tables_missing', data_get($payload, 'memory_recall_corpus.missing_reason'));
        $this->assertSame('atlas.memory_recall_golden_set.v1', data_get($payload, 'memory_recall_corpus.golden_set.schema_version'));
        $this->assertSame(0, data_get($payload, 'memory_recall_corpus.golden_set.case_count'));
        $this->assertSame('atlas.retrieval_rivals.packet.v1', data_get($payload, 'retrieval_rivals_packet.schema_version'));
        $this->assertSame('proposal_only_attention', data_get($payload, 'retrieval_rivals_packet.status'));
        $this->assertSame('proposal_only_no_runtime_execution', data_get($payload, 'retrieval_rivals_packet.mode'));
        $this->assertSame('current_governed_hybrid_memory_recall', data_get($payload, 'retrieval_rivals_packet.baseline_strategy_id'));
        $this->assertSame('no_measured_winner_yet', data_get($payload, 'retrieval_rivals_packet.comparison.winner'));
        $this->assertFalse(data_get($payload, 'retrieval_rivals_packet.comparison.delta_measured'));
        $this->assertTrue(data_get($payload, 'retrieval_rivals_packet.checks.proposal_only'));
        $this->assertTrue(data_get($payload, 'retrieval_rivals_packet.checks.no_provider_execution'));
        $this->assertTrue(data_get($payload, 'retrieval_rivals_packet.checks.no_external_runtime_execution'));
        $this->assertFalse(data_get($payload, 'retrieval_rivals_packet.checks.current_strategy_measured'));
        $this->assertContains('python_runtime_execution', data_get($payload, 'retrieval_rivals_packet.forbidden_outputs'));
        $this->assertSame('seed_provider_safe_memory_recall_corpus_before_rivals_comparison', data_get($payload, 'retrieval_rivals_packet.next_action'));
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

    public function test_benchmark_scores_promoted_memory_recall_real_corpus_without_provider_contamination(): void
    {
        $this->createLocalRagTables();
        $this->createMemoryTables();
        config()->set('atlas.semantic_memory.embedding_provider', 'local_hash');

        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'strategic_insight',
            'scope_type' => 'global',
            'title' => 'Capture promoted memory retrieval rule',
            'body' => 'Promoted capture memory must be recalled through the governed hybrid memory path.',
            'summary' => 'Promoted capture memory uses governed hybrid recall.',
            'priority' => 99,
            'importance' => 5,
            'confidence' => 0.94,
            'privacy_class' => 'normal',
            'source_type' => 'ai_memory_delta',
            'source_id' => 'delta-memory-benchmark',
            'metadata' => [
                'promotion_receipt' => ['schema_version' => 'atlas.memory.promotion_receipt.v1'],
            ],
        ]);
        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Blocked capture token',
            'body' => 'Bearer abcdefghijklmno must never enter provider recall.',
            'summary' => 'Blocked sensitive capture memory.',
            'privacy_class' => 'secret',
            'source_type' => 'ai_memory_delta',
            'source_id' => 'delta-sensitive-benchmark',
        ]);
        app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'evidence',
            'scope_type' => 'global',
            'title' => 'Capture promoted verbatim evidence',
            'verbatim_text' => 'Exact local capture evidence with source text retained locally.',
            'redacted_text' => 'Exact provider-safe capture evidence retained with redaction.',
            'summary' => 'Provider-safe promoted capture evidence.',
            'privacy_class' => 'normal',
            'source_type' => 'capture',
            'source_id' => 'capture-memory-benchmark',
            'metadata' => [
                'promotion_receipt' => ['schema_version' => 'atlas.verbatim_memory.promotion_receipt.v1'],
            ],
            'link_registry' => false,
        ]);

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'memory_recall_corpus.status'));
        $this->assertSame(2, data_get($payload, 'memory_recall_corpus.case_count'));
        $this->assertEquals(1.0, data_get($payload, 'memory_recall_corpus.metrics.precision_at_3'));
        $this->assertEquals(1.0, data_get($payload, 'memory_recall_corpus.metrics.precision_at_5'));
        $this->assertSame(0, data_get($payload, 'memory_recall_corpus.metrics.context_contamination_count'));
        $this->assertSame(0, data_get($payload, 'memory_recall_corpus.metrics.provider_safe_violation_count'));
        $this->assertSame(0, data_get($payload, 'memory_recall_corpus.metrics.stale_context_use_count'));
        $this->assertSame(0, data_get($payload, 'memory_recall_corpus.metrics.budget_truncation_count'));
        $this->assertEquals(1.0, data_get($payload, 'memory_recall_corpus.metrics.reason_coverage'));
        $this->assertSame('atlas.memory_recall_golden_set.v1', data_get($payload, 'memory_recall_corpus.golden_set.schema_version'));
        $this->assertSame(2, data_get($payload, 'memory_recall_corpus.golden_set.case_count'));
        $this->assertFalse(data_get($payload, 'memory_recall_corpus.golden_set.raw_query_persisted'));
        $this->assertFalse(data_get($payload, 'memory_recall_corpus.golden_set.raw_context_persisted'));
        $this->assertSame('memory_recall', data_get($payload, 'memory_recall_corpus.golden_set.cases.0.surface'));
        $this->assertNotEmpty(data_get($payload, 'memory_recall_corpus.golden_set.cases.0.objective_hash'));
        $this->assertNotEmpty(data_get($payload, 'memory_recall_corpus.golden_set.cases.0.must_include.0.source_ref_hash'));
        $this->assertContains('provider_safe_only', data_get($payload, 'memory_recall_corpus.golden_set.cases.0.critical_invariants'));
        $this->assertContains('unsafe_token_or_password', data_get($payload, 'memory_recall_corpus.golden_set.cases.0.must_exclude'));
        $this->assertTrue(data_get($payload, 'memory_recall_corpus.checks.no_provider_safe_violation'));
        $this->assertTrue(data_get($payload, 'memory_recall_corpus.checks.budget_truncation_explained'));
        $this->assertSame('passed', data_get($payload, 'retrieval_rivals_packet.status'));
        $this->assertSame(2, data_get($payload, 'retrieval_rivals_packet.case_count'));
        $this->assertSame('current_governed_hybrid_memory_recall', data_get($payload, 'retrieval_rivals_packet.comparison.winner'));
        $this->assertFalse(data_get($payload, 'retrieval_rivals_packet.comparison.delta_measured'));
        $this->assertEquals(1.0, data_get($payload, 'retrieval_rivals_packet.comparison.current_metrics.precision_at_3'));
        $this->assertEquals(1.0, data_get($payload, 'retrieval_rivals_packet.comparison.current_metrics.precision_at_5'));
        $this->assertSame('measured_passed', data_get($payload, 'retrieval_rivals_packet.strategies.0.status'));
        $this->assertSame('proposal_only_not_executed', data_get($payload, 'retrieval_rivals_packet.strategies.1.status'));
        $this->assertSame('blocked_until_human_review_and_future_ap', data_get($payload, 'retrieval_rivals_packet.strategies.2.status'));
        $this->assertFalse(data_get($payload, 'retrieval_rivals_packet.strategies.2.promotion_allowed'));
        $this->assertContains('docs/ap/AP-683-local-rag-graph-promotion-review.md', data_get($payload, 'retrieval_rivals_packet.strategies.2.required_before_execution'));
        $this->assertTrue(data_get($payload, 'retrieval_rivals_packet.checks.current_strategy_measured'));
        $this->assertSame('open_human_review_before_executing_any_retrieval_rival_strategy', data_get($payload, 'retrieval_rivals_packet.next_action'));
        $this->assertContains('real_corpus_retrieval_answer_quality', data_get($payload, 'promotion_gate.completed_prerequisites'));
        $this->assertNotContains('real_corpus_retrieval_answer_quality', data_get($payload, 'promotion_gate.remaining_prerequisites'));
        $this->assertStringNotContainsString('Capture promoted memory retrieval rule', $output);
        $this->assertStringNotContainsString('Exact local capture evidence', $output);
        $this->assertStringNotContainsString('abcdefghijklmno', $output);
    }

    public function test_benchmark_can_record_memory_quality_snapshot_for_longitudinal_retrieval_eval(): void
    {
        $this->createLocalRagTables();
        $this->createMemoryTables();
        $this->createMemoryQualitySnapshotTable();
        config()->set('atlas.semantic_memory.embedding_provider', 'local_hash');

        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'strategic_insight',
            'scope_type' => 'global',
            'title' => 'Longitudinal promoted memory benchmark',
            'body' => 'Promoted memory benchmark should create a longitudinal quality snapshot.',
            'summary' => 'Promoted memory benchmark creates longitudinal quality evidence.',
            'priority' => 99,
            'importance' => 5,
            'confidence' => 0.94,
            'privacy_class' => 'normal',
            'source_type' => 'ai_memory_delta',
            'source_id' => 'delta-memory-longitudinal-benchmark',
        ]);
        app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'evidence',
            'scope_type' => 'global',
            'title' => 'Longitudinal promoted verbatim',
            'verbatim_text' => 'Exact local capture evidence should stay out of benchmark snapshots.',
            'redacted_text' => 'Provider-safe longitudinal capture evidence.',
            'summary' => 'Provider-safe longitudinal capture evidence.',
            'privacy_class' => 'normal',
            'source_type' => 'capture',
            'source_id' => 'capture-memory-longitudinal-benchmark',
            'link_registry' => false,
        ]);

        $exit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--record-memory-quality' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $snapshot = AtlasMemoryQualitySnapshot::query()->firstOrFail();
        $snapshotJson = $snapshot->toJson();

        $this->assertSame(0, $exit);
        $this->assertSame('local_rag_benchmark', data_get($payload, 'memory_quality_snapshot.source_type'));
        $this->assertSame($snapshot->id, data_get($payload, 'memory_quality_snapshot.id'));
        $this->assertSame('ready', data_get($payload, 'retrieval_benchmark_history.status'));
        $this->assertSame(1, data_get($payload, 'retrieval_benchmark_history.summary.total'));
        $this->assertSame($snapshot->score, data_get($payload, 'retrieval_benchmark_history.summary.latest_score'));
        $this->assertSame('local_rag_benchmark', data_get($payload, 'retrieval_benchmark_history.snapshots.0.source_type'));
        $this->assertSame('passed', data_get($snapshot->metadata, 'benchmark_status'));
        $this->assertSame('passed', data_get($snapshot->metadata, 'memory_recall_corpus.status'));
        $this->assertEquals(1.0, data_get($snapshot->metadata, 'memory_recall_corpus.metrics.precision_at_3'));
        $this->assertSame('atlas.memory_recall_golden_set.v1', data_get($snapshot->metadata, 'memory_recall_corpus.golden_set.schema_version'));
        $this->assertFalse(data_get($snapshot->metadata, 'memory_recall_corpus.golden_set.raw_query_persisted'));
        $this->assertFalse(data_get($snapshot->metadata, 'memory_recall_corpus.golden_set.raw_context_persisted'));
        $this->assertSame('atlas.retrieval_rivals.packet.v1', data_get($snapshot->metadata, 'retrieval_rivals_packet.schema_version'));
        $this->assertSame('passed', data_get($snapshot->metadata, 'retrieval_rivals_packet.status'));
        $this->assertSame('proposal_only_no_runtime_execution', data_get($snapshot->metadata, 'retrieval_rivals_packet.mode'));
        $this->assertSame('current_governed_hybrid_memory_recall', data_get($snapshot->metadata, 'retrieval_rivals_packet.baseline_strategy_id'));
        $this->assertFalse(data_get($snapshot->metadata, 'retrieval_rivals_packet.raw_query_persisted'));
        $this->assertFalse(data_get($snapshot->metadata, 'retrieval_rivals_packet.raw_context_persisted'));
        $this->assertSame('current_governed_hybrid_memory_recall', data_get($snapshot->metadata, 'retrieval_rivals_packet.comparison.winner'));
        $this->assertFalse(data_get($snapshot->metadata, 'retrieval_rivals_packet.comparison.delta_measured'));
        $this->assertSame('future_graph_rag_python_candidate', data_get($snapshot->metadata, 'retrieval_rivals_packet.strategy_statuses.2.id'));
        $this->assertSame('blocked_until_human_review_and_future_ap', data_get($snapshot->metadata, 'retrieval_rivals_packet.strategy_statuses.2.status'));
        $this->assertFalse(data_get($snapshot->metadata, 'retrieval_rivals_packet.strategy_statuses.2.promotion_allowed'));
        $this->assertSame('persisted', data_get($snapshot->metadata, 'evidence_ledger.status'));
        $this->assertNotEmpty(data_get($snapshot->metadata, 'evidence_ledger.payload_hashes'));
        $this->assertStringNotContainsString('Longitudinal promoted memory benchmark', Artisan::output());
        $this->assertStringNotContainsString('Exact local capture evidence', $snapshotJson);
        $this->assertStringNotContainsString('abcdefghijklmno', $snapshotJson);

        $snapshot->forceFill(['snapshot_at' => now()->subDay()])->save();

        $secondExit = Artisan::call('atlas:ai:local-rag-benchmark', [
            '--record-memory-quality' => true,
            '--json' => true,
        ]);
        $secondPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $secondExit);
        $this->assertSame(2, AtlasMemoryQualitySnapshot::query()->count());
        $this->assertSame(2, data_get($secondPayload, 'retrieval_benchmark_history.summary.total'));
        $this->assertContains(data_get($secondPayload, 'retrieval_benchmark_history.summary.trend_status'), [
            'stable',
            'improved',
            'regressed',
        ]);
        $this->assertSame(
            data_get($secondPayload, 'memory_quality_snapshot.id'),
            data_get($secondPayload, 'retrieval_benchmark_history.snapshots.0.id'),
        );
        $this->assertSame('atlas.retrieval_rivals.packet.v1', data_get($secondPayload, 'retrieval_benchmark_history.snapshots.0.metadata.retrieval_rivals_packet.schema_version'));
        $this->assertStringNotContainsString('Exact local capture evidence', Artisan::output());
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

    private function createMemoryTables(): void
    {
        Schema::dropIfExists('atlas_verbatim_memories');
        Schema::dropIfExists('atlas_memory_entry_usages');
        Schema::dropIfExists('atlas_memory_entries');

        (require database_path('migrations/2026_05_02_000000_create_atlas_memory_entries_table.php'))->up();
        (require database_path('migrations/2026_05_02_003000_create_atlas_memory_entry_relations_table.php'))->up();
        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();
        (require database_path('migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php'))->up();
    }

    private function createMemoryQualitySnapshotTable(): void
    {
        Schema::dropIfExists('atlas_memory_quality_snapshots');

        (require database_path('migrations/2026_05_03_190000_create_atlas_memory_quality_snapshots_table.php'))->up();
    }

    /**
     * @param  array<string,float|int>  $metrics
     */
    private function createRetrievalBenchmarkSnapshot(
        int $score,
        mixed $snapshotAt,
        array $metrics,
        string $benchmarkStatus = 'passed',
        string $recallStatus = 'passed',
        string $secretMarker = ''
    ): AtlasMemoryQualitySnapshot {
        return AtlasMemoryQualitySnapshot::query()->create([
            'workspace' => null,
            'workspace_hash' => null,
            'source_type' => 'local_rag_benchmark',
            'source_id' => 'local_rag_benchmark:'.hash('sha256', (string) $snapshotAt.$score.$secretMarker),
            'status' => $benchmarkStatus,
            'score' => $score,
            'components_json' => [],
            'counts_json' => [],
            'ratios_json' => [],
            'issues_json' => [],
            'recommendations_json' => [],
            'metadata' => [
                'benchmark_status' => $benchmarkStatus,
                'memory_recall_corpus' => [
                    'status' => $recallStatus,
                    'case_count' => 2,
                    'metrics' => $metrics,
                    'golden_set' => [
                        'schema_version' => 'atlas.memory_recall_golden_set.v1',
                        'case_count' => 2,
                        'raw_query_persisted' => false,
                        'raw_context_persisted' => false,
                    ],
                ],
                'retrieval_rivals_packet' => [
                    'schema_version' => 'atlas.retrieval_rivals.packet.v1',
                    'status' => $recallStatus === 'passed' ? 'passed' : 'proposal_only_attention',
                    'mode' => 'proposal_only_no_runtime_execution',
                    'comparison' => [
                        'winner' => $recallStatus === 'passed' ? 'current_governed_hybrid_memory_recall' : 'no_measured_winner_yet',
                        'delta_measured' => false,
                    ],
                ],
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
                'secret_hash' => $secretMarker !== '' ? hash('sha256', $secretMarker) : null,
            ],
            'snapshot_at' => $snapshotAt,
        ]);
    }
}
