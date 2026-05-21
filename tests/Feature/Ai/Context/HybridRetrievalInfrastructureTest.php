<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasHybridRetrievalInfrastructureService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class HybridRetrievalInfrastructureTest extends TestCase
{
    public function test_programming_report_collects_code_memory_evidence_and_semantic_candidates(): void
    {
        $payload = app(AtlasHybridRetrievalInfrastructureService::class)->report([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'context_refs' => ['repo://tests/failing-test'],
        ]);

        $this->assertSame(AtlasHybridRetrievalInfrastructureService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('atlas.aucri.retrieval_report.v1', data_get($payload, 'retrieval_report.schema_version'));

        $sourceTypes = collect(data_get($payload, 'retrieval_report.source_plan.selected_sources'))->pluck('type')->all();
        $this->assertContains('code_intelligence', $sourceTypes);
        $this->assertContains('memory_signals', $sourceTypes);
        $this->assertContains('evidence_replay', $sourceTypes);

        $candidateTypes = collect(data_get($payload, 'retrieval_report.candidates'))->pluck('source_type')->all();
        $this->assertContains('semantic_candidate', $candidateTypes);
        $this->assertContains('explicit_context_ref', $candidateTypes);
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
        $this->assertFalse(data_get($payload, 'retrieval_report.policy.raw_text_exposed'));
        $this->assertStringNotContainsString('corrigir bug no repo', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_graph_unavailable_degrades_but_does_not_block_when_not_required(): void
    {
        $payload = app(AtlasHybridRetrievalInfrastructureService::class)->report([
            'objective' => 'planejar arquitetura e relacao de dependencias do contexto',
            'task_type' => 'planning',
            'domain' => 'strategy',
            'risk_level' => 'low',
        ]);

        $this->assertSame('degraded', $payload['status']);
        $this->assertContains('graph_retrieval', collect(data_get($payload, 'retrieval_report.misses'))->pluck('source_type')->all());
        $this->assertSame(['graph_retrieval'], data_get($payload, 'retrieval_report.source_plan.readiness.unavailable_selected_sources'));
        $this->assertSame([], data_get($payload, 'retrieval_report.source_plan.readiness.required_unavailable_sources'));
    }

    public function test_secret_objective_excludes_non_provider_safe_semantic_ref(): void
    {
        $payload = app(AtlasHybridRetrievalInfrastructureService::class)->report([
            'objective' => 'api_key=abc password=secret analisar',
            'task_type' => 'direct',
            'domain' => 'atlas',
            'risk_level' => 'low',
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertNotEmpty(data_get($payload, 'retrieval_report.excluded_refs'));
        $semantic = collect(data_get($payload, 'retrieval_report.candidates'))
            ->firstWhere('source_type', 'semantic_candidate');
        $this->assertIsArray($semantic);
        $this->assertFalse((bool) $semantic['provider_safe']);
        $this->assertSame('secret', $semantic['privacy_class']);
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:hybrid-retrieval', [
            '--query' => 'debug repo with tests',
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasHybridRetrievalInfrastructureService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('retrieval_report_hash', $payload);
    }
}
