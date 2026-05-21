<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasAgenticRagFrameworkService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgenticRagFrameworkTest extends TestCase
{
    public function test_programming_debug_plan_passes_with_required_sources_and_no_provider_calls(): void
    {
        $payload = app(AtlasAgenticRagFrameworkService::class)->plan([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
        ]);

        $this->assertSame(AtlasAgenticRagFrameworkService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasAgenticRagFrameworkService::PLAN_SCHEMA, data_get($payload, 'agentic_rag_plan.schema_version'));
        $this->assertSame(AtlasAgenticRagFrameworkService::REQUIRED_SOURCES_SCHEMA, data_get($payload, 'agentic_rag_plan.required_sources.schema_version'));
        $this->assertSame(AtlasAgenticRagFrameworkService::GAP_CRITIC_SCHEMA, data_get($payload, 'gap_critic.schema_version'));
        $this->assertSame(AtlasAgenticRagFrameworkService::SUFFICIENCY_SCHEMA, data_get($payload, 'context_sufficiency_gate.schema_version'));

        $required = data_get($payload, 'agentic_rag_plan.required_sources.sources');
        $this->assertContains('code_intelligence', $required);
        $this->assertContains('memory_signals', $required);
        $this->assertContains('vector_retrieval', $required);
        $this->assertContains('evidence_replay', $required);

        $this->assertSame('passed', data_get($payload, 'gap_critic.status'));
        $this->assertSame('passed', data_get($payload, 'context_sufficiency_gate.status'));
        $this->assertSame(1.0, data_get($payload, 'context_sufficiency_gate.required_source_coverage'));
        $this->assertTrue(data_get($payload, 'claims.context_execution_allowed'));
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
        $this->assertFalse(data_get($payload, 'claims.raw_text_exposed'));
        $this->assertStringNotContainsString('corrigir bug no repo', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['agentic_rag_plan_hash']);
    }

    public function test_low_risk_strategy_degrades_for_missing_optional_graph_without_blocking(): void
    {
        $payload = app(AtlasAgenticRagFrameworkService::class)->plan([
            'objective' => 'planejar arquitetura e relacao de dependencias do contexto',
            'task_type' => 'planning',
            'domain' => 'strategy',
            'risk_level' => 'low',
        ]);

        $this->assertSame('degraded', $payload['status']);
        $this->assertSame('degraded', data_get($payload, 'gap_critic.status'));
        $this->assertSame('degraded', data_get($payload, 'context_sufficiency_gate.status'));
        $this->assertContains('graph_retrieval', data_get($payload, 'gap_critic.missing_optional_sources'));
        $this->assertSame([], data_get($payload, 'gap_critic.missing_required_sources'));
        $this->assertFalse(data_get($payload, 'claims.context_execution_allowed'));
    }

    public function test_high_risk_strategy_blocks_when_graph_context_is_missing(): void
    {
        $payload = app(AtlasAgenticRagFrameworkService::class)->plan([
            'objective' => 'decisao critica sobre arquitetura e impacto entre sistemas',
            'task_type' => 'decision',
            'domain' => 'strategy',
            'risk_level' => 'high',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'context_sufficiency_gate.status'));
        $this->assertTrue(data_get($payload, 'context_sufficiency_gate.fail_closed'));
        $this->assertContains('graph_retrieval', data_get($payload, 'gap_critic.missing_optional_sources'));
        $this->assertFalse(data_get($payload, 'claims.context_execution_allowed'));
    }

    public function test_command_emits_json_and_returns_failure_when_blocked(): void
    {
        $exit = Artisan::call('atlas:context:agentic-rag', [
            '--query' => 'decisao critica sobre arquitetura e impacto entre sistemas',
            '--task-type' => 'decision',
            '--domain' => 'strategy',
            '--risk' => 'high',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame(AtlasAgenticRagFrameworkService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertArrayHasKey('agentic_rag_plan_hash', $payload);
    }
}
