<?php

namespace Tests\Unit;

use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiExecutionPlan;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Tests\TestCase;

class AiHarnessContractsTest extends TestCase
{
    public function test_task_request_normalizes_app_payload(): void
    {
        $task = AiTaskRequest::fromInput('Preciso decidir entre duas opcoes.', [
            'source_type' => 'app',
            'provider' => 'claude_cli',
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
                'atlas_workflow_mode' => 'plan',
                'requested_agent' => 'decision-advisor',
                'privacy' => ['sensitivity' => 'private'],
            ],
        ], [
            'agent' => 'decision-advisor',
            'intent' => 'keyword:decidir',
        ])->toArray();

        $this->assertSame(1, $task['schema_version']);
        $this->assertSame('mobile_app', $task['surface']);
        $this->assertSame('planning', $task['task_type']);
        $this->assertSame('decision-advisor', $task['domain']);
        $this->assertSame('plan', $task['desired_mode']);
        $this->assertSame('private', $task['privacy_class']);
    }

    public function test_execution_plan_adds_human_gate_for_high_risk_tasks(): void
    {
        $task = AiTaskRequest::fromInput('Faça deploy em producao com migracao de schema.', [
            'source_type' => 'app',
            'payload' => [
                'atlas_workflow_mode' => 'direct',
            ],
        ], [
            'agent' => 'blackink',
            'intent' => 'keyword:deploy',
        ]);

        $plan = AiExecutionPlan::fromTask($task, 'blackink', 'codex_cli', [])->toArray();

        $this->assertSame('dev', $task->taskType());
        $this->assertSame('high', $task->riskLevel());
        $this->assertTrue($plan['requires_human_confirmation']);
        $this->assertContains('human_confirmation_required', $plan['quality_gates']);
    }

    public function test_context_pack_renders_recent_conversation_for_short_replies(): void
    {
        $pack = new AiContextPack([
            'task' => [
                'type' => 'direct',
                'desired_mode' => 'direct',
                'risk_level' => 'low',
                'domain' => 'unknown',
                'objective' => 'Ambos',
            ],
            'surface' => ['kind' => 'mobile_app', 'workspace' => null],
            'conversation' => [
                'thread_id' => '11111111-1111-4111-8111-111111111111',
                'thread_title' => 'Arquitetura de sessoes',
                'thread_summary' => 'Discussao sobre continuidade do Atlas AI.',
                'source' => 'ai_messages',
                'recent_turns' => [
                    ['role' => 'user', 'text' => 'Escolha A, B ou C.'],
                    ['role' => 'assistant', 'text' => 'A = app, B = backend, C = ambos.', 'provider' => 'claude'],
                ],
            ],
            'constraints' => [],
            'memory' => ['semantic' => []],
            'open_questions' => [],
            'excluded_context' => [],
        ], []);

        $promptSection = $pack->toPromptSection();

        $this->assertStringContainsString('Contexto Conversacional Recente', $promptSection);
        $this->assertStringContainsString('Continuidade da Thread Atlas', $promptSection);
        $this->assertStringContainsString('Arquitetura de sessoes', $promptSection);
        $this->assertStringContainsString('A/B/C', $promptSection);
        $this->assertStringContainsString('C = ambos', $promptSection);
    }

    public function test_context_pack_renders_operational_state_compaction_and_handoff(): void
    {
        $pack = new AiContextPack([
            'task' => [
                'type' => 'dev',
                'desired_mode' => 'direct',
                'risk_level' => 'low',
                'domain' => 'blackink',
                'objective' => 'Continuar implementação longa',
            ],
            'surface' => ['kind' => 'mac_cli', 'workspace' => '/repo'],
            'conversation' => [
                'thread_id' => '22222222-2222-4222-8222-222222222222',
                'thread_title' => 'Dev session Atlas',
                'thread_summary' => 'Resumo anterior preservado.',
                'source' => 'ai_messages',
                'recent_turns' => [],
            ],
            'continuity' => [
                'active_state' => [
                    'objective' => 'Implementar sessão profissional',
                    'current_phase' => 'plan_execute_test_review_summarize',
                    'current_topic' => 'Sessões longas',
                    'user_position' => 'Quer superar Codex e Claude em continuidade.',
                    'decisions' => [['text' => 'Sessão pertence ao Atlas, não ao provider.']],
                    'open_loops' => [['text' => 'Falta compactação automática.']],
                    'next_steps' => [['text' => 'Criar provider handoff.']],
                    'relevant_artifacts' => [['value' => 'atlas-server']],
                    'constraints' => [['text' => 'Não depender da memória do provider.']],
                ],
                'latest_compaction' => [
                    'id' => 'cmp_1',
                    'reason' => 'auto',
                    'quality_gate_status' => 'passed',
                    'summary' => 'Compactação preservou objetivo, decisões e próximo passo.',
                ],
                'latest_provider_handoff' => [
                    'from_provider' => 'claude_cli',
                    'to_provider' => 'codex_cli',
                    'brief_text' => 'Handoff com objetivo e estado atual.',
                ],
            ],
            'constraints' => [],
            'memory' => ['semantic' => []],
            'open_questions' => [],
            'excluded_context' => [],
        ], []);

        $promptSection = $pack->toPromptSection();

        $this->assertStringContainsString('Estado Operacional Atlas', $promptSection);
        $this->assertStringContainsString('Compactacao Atlas Mais Recente', $promptSection);
        $this->assertStringContainsString('Handoff De Provider Atlas', $promptSection);
        $this->assertStringContainsString('Sessão pertence ao Atlas', $promptSection);
    }
}
