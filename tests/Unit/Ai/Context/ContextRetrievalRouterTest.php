<?php

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\ContextRetrievalRouter;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Tests\TestCase;

class ContextRetrievalRouterTest extends TestCase
{
    public function test_builds_provider_safe_retrieval_plan_for_programming_context(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'implementar patch no repo com bug de teste',
            new AiTaskRequest([
                'task_type' => 'dev',
                'desired_mode' => 'dev',
                'risk_level' => 'low',
                'domain' => 'developer',
            ]),
            ['trace_id' => 'trace_1'],
        );

        $this->assertSame(ContextRetrievalRouter::SCHEMA_VERSION, $plan['schema_version']);
        $this->assertSame('deep', $plan['mode']);
        $this->assertSame('atlas.context.retrieval_plan.v1', $plan['schema_version']);
        $this->assertContains('code_intelligence', array_column($plan['selected_sources'], 'type'));
        $this->assertContains('evidence_replay', array_column($plan['selected_sources'], 'type'));
        $this->assertContains('memory_signals', array_column($plan['selected_sources'], 'type'));
        $this->assertTrue(data_get($plan, 'policy.provider_safe_only'));
        $this->assertTrue(data_get($plan, 'policy.do_not_create_parallel_memory'));
        $this->assertFalse(data_get($plan, 'policy.provider_bypass_allowed'));
        $this->assertSame('ready', data_get($plan, 'readiness.status'));
    }

    public function test_marks_evidence_required_for_high_risk_and_graph_for_architecture_questions(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'analisar arquitetura, relacao de dependencias e impacto antes de producao',
            new AiTaskRequest([
                'task_type' => 'planning',
                'desired_mode' => 'plan',
                'risk_level' => 'high',
                'domain' => 'programming',
            ]),
        );

        $this->assertSame('audit_heavy', $plan['mode']);

        $sources = collect($plan['selected_sources'])->keyBy('type');
        $this->assertTrue((bool) data_get($sources->get('evidence_replay'), 'required'));
        $this->assertSame('fail_closed_or_request_review', data_get($sources->get('evidence_replay'), 'unavailable_action'));
        $this->assertTrue($sources->has('graph_retrieval'));
        $this->assertFalse((bool) data_get($sources->get('graph_retrieval'), 'available'));
        $this->assertSame('future_governed', data_get($sources->get('graph_retrieval'), 'status'));
        $this->assertSame('python_ai_data_candidate', data_get($sources->get('graph_retrieval'), 'runtime'));
        $this->assertSame('docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md', data_get($sources->get('graph_retrieval'), 'owner_doc'));
        $this->assertFalse((bool) data_get($sources->get('graph_retrieval'), 'provider_bypass_allowed'));
        $this->assertSame('degraded', data_get($plan, 'readiness.status'));
        $this->assertSame(['graph_retrieval'], data_get($plan, 'readiness.unavailable_selected_sources'));
    }

    public function test_dev_task_emits_model_amplifier_contract_with_required_fields(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'fix routing bug',
            new AiTaskRequest([
                'task_type' => 'dev',
                'desired_mode' => 'dev',
                'risk_level' => 'low',
                'domain' => 'developer',
            ]),
        );

        $this->assertArrayHasKey('model_amplifier_contract', $plan);
        $c = $plan['model_amplifier_contract'];
        $this->assertTrue($c['provider_safe_only']);
        $this->assertArrayHasKey('required_capabilities', $c);
        $this->assertArrayHasKey('must_keep_sources', $c);
        $this->assertArrayHasKey('optional_sources', $c);
        $this->assertArrayHasKey('fail_closed_sources', $c);
    }

    public function test_code_intelligence_and_memory_signals_are_must_keep_for_programming_tasks(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'refactor auth layer',
            new AiTaskRequest([
                'task_type' => 'dev',
                'desired_mode' => 'dev',
                'risk_level' => 'low',
                'domain' => 'developer',
            ]),
        );

        $mustKeep = $plan['model_amplifier_contract']['must_keep_sources'];
        $this->assertContains('code_intelligence', $mustKeep);
        $this->assertContains('memory_signals', $mustKeep);
    }

    public function test_evidence_replay_is_fail_closed_for_high_risk_tasks(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'deploy production change',
            new AiTaskRequest([
                'task_type' => 'dev',
                'desired_mode' => 'dev',
                'risk_level' => 'high',
                'domain' => 'developer',
            ]),
        );

        $this->assertContains('evidence_replay', $plan['model_amplifier_contract']['fail_closed_sources']);
    }

    public function test_evidence_replay_is_fail_closed_for_trace_backed_tasks(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'debug failing request',
            new AiTaskRequest([
                'task_type' => 'debug',
                'desired_mode' => 'debug',
                'risk_level' => 'low',
                'domain' => 'developer',
            ]),
            ['trace_id' => 'trace_abc'],
        );

        $this->assertContains('evidence_replay', $plan['model_amplifier_contract']['fail_closed_sources']);
    }

    public function test_non_amplifier_task_omits_model_amplifier_contract(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'analyze dependencies for planning',
            new AiTaskRequest([
                'task_type' => 'planning',
                'desired_mode' => 'plan',
                'risk_level' => 'low',
                'domain' => 'architecture',
            ]),
        );

        $this->assertArrayNotHasKey('model_amplifier_contract', $plan);
    }

    public function test_existing_plan_fields_remain_backward_compatible(): void
    {
        $plan = app(ContextRetrievalRouter::class)->plan(
            'fix bug',
            new AiTaskRequest(['task_type' => 'dev', 'desired_mode' => 'dev', 'risk_level' => 'low', 'domain' => 'developer']),
        );

        $this->assertArrayHasKey('selected_sources', $plan);
        $this->assertArrayHasKey('skipped_sources', $plan);
        $this->assertArrayHasKey('readiness', $plan);
        $this->assertArrayHasKey('policy', $plan);
    }
}
