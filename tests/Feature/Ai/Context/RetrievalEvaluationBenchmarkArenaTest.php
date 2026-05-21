<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasRetrievalEvaluationBenchmarkArenaService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class RetrievalEvaluationBenchmarkArenaTest extends TestCase
{
    public function test_default_internal_golden_set_returns_ready_without_external_claims(): void
    {
        $payload = app(AtlasRetrievalEvaluationBenchmarkArenaService::class)->evaluate();

        $this->assertSame(AtlasRetrievalEvaluationBenchmarkArenaService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(3, data_get($payload, 'summary.case_count'));
        $this->assertSame(3, data_get($payload, 'summary.passed'));
        $this->assertSame('pass', data_get($payload, 'summary.status'));
        $this->assertGreaterThanOrEqual(0.80, data_get($payload, 'summary.metrics.required_source_recall'));
        $this->assertGreaterThanOrEqual(0.68, data_get($payload, 'summary.metrics.groundedness'));
        $this->assertGreaterThanOrEqual(0.50, data_get($payload, 'summary.metrics.context_roi'));
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
        $this->assertFalse(data_get($payload, 'claims.rivals_run'));
        $this->assertFalse(data_get($payload, 'claims.benchmark_run'));
        $this->assertFalse(data_get($payload, 'claims.raw_text_exposed'));
        $this->assertSame('internal_golden_set', data_get($payload, 'arena.mode'));
        $this->assertSame('pass', data_get($payload, 'promotion_gate.status'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['arena_hash']);
        $this->assertStringNotContainsString('debug repo with failing tests', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_missing_required_source_blocks_promotion(): void
    {
        $payload = app(AtlasRetrievalEvaluationBenchmarkArenaService::class)->evaluate([
            'risk_level' => 'high',
            'cases' => [[
                'case_id' => 'impossible_source_case',
                'query' => 'debug repo with impossible source coverage',
                'domain' => 'developer',
                'task_type' => 'debug',
                'required_sources' => ['source_that_does_not_exist_in_aucri'],
                'expected_outcome' => 'passed',
            ]],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'promotion_gate.status'));
        $this->assertSame(1, data_get($payload, 'summary.failed'));
        $this->assertSame('source_that_does_not_exist_in_aucri', data_get($payload, 'results.0.missed_required_sources.0'));
        $this->assertContains('required_source_missing', array_column(data_get($payload, 'regression_report.regressions'), 'reason'));
        $this->assertFalse(data_get($payload, 'promotion_gate.auto_promote_retrieval_changes'));
    }

    public function test_arena_hash_is_deterministic_for_same_evaluation_content(): void
    {
        $first = app(AtlasRetrievalEvaluationBenchmarkArenaService::class)->evaluate();
        $second = app(AtlasRetrievalEvaluationBenchmarkArenaService::class)->evaluate();

        $this->assertSame($first['arena_hash'], $second['arena_hash']);
        $this->assertSame(data_get($first, 'golden_set.golden_set_hash'), data_get($second, 'golden_set.golden_set_hash'));
    }

    public function test_command_emits_canonical_json(): void
    {
        $exit = Artisan::call('atlas:context:evaluate-retrieval', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasRetrievalEvaluationBenchmarkArenaService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('atlas.aucri.retrieval_eval_summary.v1', data_get($payload, 'summary.schema_version'));
    }
}
