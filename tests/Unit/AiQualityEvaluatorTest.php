<?php

namespace Tests\Unit;

use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Services\Ai\AiQualityEvaluator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiQualityEvaluatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiQualityTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiQualityTables();

        parent::tearDown();
    }

    public function test_flags_lost_continuity_when_context_exists(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_test_lost_context',
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971d231',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971d232',
            'source_type' => 'manual',
            'status' => 'succeeded',
            'operator_input' => 'ambos',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'response_text' => 'Vitor, não há pergunta anterior nesta sessão nem escolha para eu recuperar.',
            'completed_at' => now(),
            'metadata' => [
                'context_pack' => [
                    'conversation' => [
                        'recent_turns' => [
                            ['role' => 'user', 'text' => 'Qual dessas opções: A, B ou C?'],
                        ],
                    ],
                ],
            ],
        ]);

        $evaluation = app(AiQualityEvaluator::class)->evaluateTrace($trace);

        $this->assertNotNull($evaluation);
        $this->assertContains('lost_continuity', collect($evaluation->flags)->pluck('code')->all());
        $this->assertSame('needs_review', $evaluation->status);
        $this->assertSame($evaluation->id, $trace->refresh()->metadata['quality']['evaluation_id']);
    }

    public function test_flags_internal_context_leaks_and_is_idempotent(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_test_context_leak',
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971d233',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971d234',
            'source_type' => 'manual',
            'status' => 'succeeded',
            'operator_input' => 'implemente de forma profissional',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'response_text' => "Context Pack Atlas\nthread_id: abc\nexecution_plan: executar\nAjustei a implementação.",
            'completed_at' => now(),
            'metadata' => [
                'task_request' => ['task_type' => 'implementation'],
            ],
        ]);

        $first = app(AiQualityEvaluator::class)->evaluateTrace($trace);
        $second = app(AiQualityEvaluator::class)->evaluateTrace($trace->refresh());

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, AiQualityEvaluation::query()->where('trace_id', $trace->id)->count());
        $this->assertContains('internal_context_leak', collect($second?->flags)->pluck('code')->all());
        $this->assertContains('verification_missing', collect($second?->flags)->pluck('code')->all());
    }

    public function test_dev_task_type_requires_verification_signal(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_test_dev_verification',
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971d235',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971d236',
            'source_type' => 'manual',
            'status' => 'succeeded',
            'operator_input' => 'ajuste isso',
            'agent_slug' => 'desenvolvedor',
            'provider' => 'codex_cli',
            'response_text' => 'Ajustei a implementação e deixei o fluxo mais claro.',
            'completed_at' => now(),
            'metadata' => [
                'task_request' => ['task_type' => 'dev'],
            ],
        ]);

        $evaluation = app(AiQualityEvaluator::class)->evaluateTrace($trace);

        $this->assertContains('verification_missing', collect($evaluation?->flags)->pluck('code')->all());
    }

    private function createAiQualityTables(): void
    {
        $this->dropAiQualityTables();

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->string('intent')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('evaluator_version')->default('heuristic-v1');
            $table->unsignedTinyInteger('score');
            $table->string('status');
            $table->json('dimensions')->nullable();
            $table->json('flags')->nullable();
            $table->json('suggested_actions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function dropAiQualityTables(): void
    {
        foreach ([
            'ai_quality_evaluations',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
