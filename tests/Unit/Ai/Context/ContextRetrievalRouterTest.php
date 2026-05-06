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
    }
}
