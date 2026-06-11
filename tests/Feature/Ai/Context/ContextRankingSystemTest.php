<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use App\Services\Ai\Context\AtlasContextRankingSystemService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ContextRankingSystemTest extends TestCase
{
    public function test_programming_debug_ranking_is_explainable_and_covers_required_sources(): void
    {
        $payload = app(AtlasContextRankingSystemService::class)->rank([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 8,
        ]);

        $this->assertSame(AtlasContextRankingSystemService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasContextRankingSystemService::RERANK_RESULT_SCHEMA, data_get($payload, 'rerank_result.schema_version'));
        $this->assertSame(AtlasContextRankingSystemService::FEEDBACK_IMPACT_REPORT_SCHEMA, data_get($payload, 'rerank_result.feedback_impact_report.schema_version'));
        $this->assertSame('inactive', data_get($payload, 'rerank_result.feedback_impact_report.status'));
        $this->assertNotEmpty(data_get($payload, 'rerank_result.selected_refs'));
        $this->assertSame([
            'memory_signals' => true,
            'vector_retrieval' => true,
            'code_intelligence' => true,
            'evidence_replay' => true,
        ], data_get($payload, 'rerank_result.metrics.required_source_coverage'));

        foreach (data_get($payload, 'rerank_result.selected_refs') as $ref) {
            $this->assertSame(AtlasContextRankingSystemService::CONTEXT_SCORE_SCHEMA, data_get($ref, 'score_components.schema_version'));
            $this->assertNotEmpty($ref['reasons']);
            $this->assertArrayHasKey('source_ref_hash', $ref);
            $this->assertArrayNotHasKey('source_ref', $ref);
        }

        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'policy.writes'));
        $this->assertFalse(data_get($payload, 'policy.raw_text_exposed'));
        $this->assertStringNotContainsString('corrigir bug no repo', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['rerank_result_hash']);
    }

    public function test_budget_trimmed_refs_are_excluded_with_schema_and_reason(): void
    {
        $payload = app(AtlasContextRankingSystemService::class)->rank([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 2,
        ]);

        $this->assertSame('degraded', $payload['status']);
        $this->assertSame(2, data_get($payload, 'rerank_result.metrics.selected_count'));
        $this->assertNotEmpty(data_get($payload, 'rerank_result.excluded_refs'));

        foreach (data_get($payload, 'rerank_result.excluded_refs') as $excluded) {
            $this->assertSame(AtlasContextRankingSystemService::EXCLUDED_REF_SCHEMA, $excluded['schema_version']);
            $this->assertNotSame('', $excluded['source_ref_hash']);
            $this->assertContains($excluded['reason'], ['budget_trimmed', 'duplicate']);
        }
    }

    public function test_feedback_hint_adjusts_ranking_with_explainable_delta(): void
    {
        $payload = app(AtlasContextRankingSystemService::class)->rank([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 8,
            'feedback_hint_input' => [
                'repromote_source_types' => ['vector_retrieval'],
                'demote_source_types' => ['evidence_replay'],
            ],
        ]);

        $selected = collect(data_get($payload, 'rerank_result.selected_refs'))->keyBy('source_type');

        $this->assertSame('active', data_get($payload, 'source_ranking_inputs.feedback_hint.status'));
        $this->assertSame('input_feedback_hint', data_get($payload, 'source_ranking_inputs.feedback_hint.source'));
        $this->assertGreaterThan(0, data_get($selected->get('vector_retrieval'), 'score_components.feedback_hint_delta'));
        $this->assertLessThan(0, data_get($selected->get('evidence_replay'), 'score_components.feedback_hint_delta'));
        $this->assertContains('feedback_repromote_source_type', data_get($selected->get('vector_retrieval'), 'reasons'));
        $this->assertContains('feedback_demote_source_type', data_get($selected->get('evidence_replay'), 'reasons'));
        $this->assertSame('active', data_get($payload, 'rerank_result.feedback_impact_report.status'));
        $this->assertGreaterThan(0, data_get($payload, 'rerank_result.feedback_impact_report.rank_position_change_count'));
        $this->assertTrue(collect(data_get($payload, 'rerank_result.feedback_impact_report.promoted_refs'))->contains('source_type', 'vector_retrieval'));
        $this->assertTrue(collect(data_get($payload, 'rerank_result.feedback_impact_report.demoted_refs'))->contains('source_type', 'evidence_replay'));
        $this->assertFalse(data_get($payload, 'rerank_result.feedback_impact_report.policy.raw_text_exposed'));
        $this->assertFalse(data_get($payload, 'policy.writes'));
        $this->assertFalse(data_get($payload, 'source_ranking_inputs.feedback_hint.providers_invoked'));
    }

    public function test_flow_id_loads_latest_retrieval_feedback_hint_for_ranking(): void
    {
        $this->bootCompoundingSchema();

        try {
            app(AtlasRagFeedbackService::class)->record([
                'retrieval_receipt_id' => 'receipt-context-ranking-feedback',
                'flow_id' => 'programming.repair',
                'query_plan_hash' => 'plan-context-ranking-feedback',
                'included_sources' => 4,
                'used_sources' => 1,
                'noise_sources' => 1,
                'missed_required_sources' => ['vector_retrieval'],
                'context_sufficiency' => 62,
                'post_execution_utility' => 40,
                'source_utility' => [
                    MissionCanonicalHash::sha256('source://evidence_replay') => 'noise',
                ],
                'outcome_status' => 'partial',
                'failure_reason' => 'retrieval_noise_or_stale_context',
                'next_retrieval_hint' => [
                    'schema_version' => 'atlas.aucri.next_retrieval_hint.v1',
                    'should_repromote_sources' => ['vector_retrieval'],
                    'should_demote_count' => 1,
                    'auto_apply' => false,
                    'advisory' => true,
                ],
            ]);

            $payload = app(AtlasContextRankingSystemService::class)->rank([
                'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
                'task_type' => 'debug',
                'domain' => 'developer',
                'risk_level' => 'low',
                'max_refs' => 8,
                'flow_id' => 'programming.repair',
            ]);

            $selected = collect(data_get($payload, 'rerank_result.selected_refs'))->keyBy('source_type');

            $this->assertSame('active', data_get($payload, 'source_ranking_inputs.feedback_hint.status'));
            $this->assertSame('latest_flow_feedback', data_get($payload, 'source_ranking_inputs.feedback_hint.source'));
            $this->assertTrue(data_get($payload, 'source_ranking_inputs.feedback_hint.event_available'));
            $this->assertGreaterThan(0, data_get($selected->get('vector_retrieval'), 'score_components.feedback_hint_delta'));
            $this->assertLessThan(0, data_get($selected->get('evidence_replay'), 'score_components.feedback_hint_delta'));
            $this->assertContains('feedback_demote_ref_hash', data_get($selected->get('evidence_replay'), 'reasons'));
            $this->assertSame('latest_flow_feedback', data_get($payload, 'rerank_result.feedback_impact_report.source'));
            $this->assertGreaterThan(0, data_get($payload, 'rerank_result.feedback_impact_report.rank_position_change_count'));
        } finally {
            $this->dropCompoundingSchema();
        }
    }

    public function test_high_risk_graph_gap_propagates_blocked_status_from_aarf(): void
    {
        $payload = app(AtlasContextRankingSystemService::class)->rank([
            'objective' => 'decisao critica sobre arquitetura e impacto entre sistemas',
            'task_type' => 'decision',
            'domain' => 'strategy',
            'risk_level' => 'high',
            'max_refs' => 8,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'source_ranking_inputs.sufficiency_gate_status'));
        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:rank', [
            '--query' => 'debug repo with tests',
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--max-refs' => 4,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasContextRankingSystemService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('rerank_result_hash', $payload);
    }

    public function test_command_accepts_feedback_hint_inputs(): void
    {
        $exit = Artisan::call('atlas:context:rank', [
            '--query' => 'debug repo with tests',
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--max-refs' => 4,
            '--feedback-repromote-source' => ['vector_retrieval'],
            '--feedback-demote-source' => ['evidence_replay'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('active', data_get($payload, 'source_ranking_inputs.feedback_hint.status'));
        $this->assertContains('vector_retrieval', data_get($payload, 'source_ranking_inputs.feedback_hint.repromote_source_types'));
        $this->assertTrue(data_get($payload, 'rerank_result.feedback_impact_report.selected_set_changed'));
        $this->assertTrue(collect(data_get($payload, 'rerank_result.feedback_impact_report.newly_selected_refs'))->contains('source_type', 'vector_retrieval'));
        $this->assertContains('vector_retrieval', data_get($payload, 'rerank_result.feedback_impact_report.coverage_delta.gained_required_sources'));
        $this->assertContains('evidence_replay', data_get($payload, 'rerank_result.feedback_impact_report.coverage_delta.lost_required_sources'));
    }

    private function bootCompoundingSchema(): void
    {
        $this->dropCompoundingSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
