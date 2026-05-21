<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextRankingSystemService;
use Illuminate\Support\Facades\Artisan;
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
}
