<?php

namespace Tests\Unit;

use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Services\Ai\AiQualityActionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiQualityActionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiQualityActionTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiQualityActionTables();

        parent::tearDown();
    }

    public function test_plans_continuity_retry_idempotently(): void
    {
        $trace = $this->trace();
        $evaluation = $this->evaluation($trace, [
            ['code' => 'lost_continuity', 'severity' => 'critical'],
        ]);

        $service = app(AiQualityActionService::class);
        $first = $service->planFor($trace, $evaluation, autoRun: false);
        $second = $service->planFor($trace->refresh(), $evaluation->refresh(), autoRun: false);

        $this->assertSame(1, $first->count());
        $this->assertSame(1, $second->count());
        $this->assertSame(1, AiQualityAction::query()->where('trace_id', $trace->id)->count());
        $this->assertSame('retry_with_continuity', $first->first()->action_type);
        $this->assertSame('queued', $first->first()->status);
        $this->assertSame('claude_codex', $first->first()->payload['provider']);
    }

    public function test_depth_limit_blocks_operator_review_instead_of_auto_loop(): void
    {
        config(['atlas.ai.quality_auto_remediation_max_depth' => 1]);

        $trace = $this->trace([
            'quality_loop' => ['depth' => 1],
        ]);
        $evaluation = $this->evaluation($trace, [
            ['code' => 'internal_context_leak', 'severity' => 'high'],
        ]);

        $actions = app(AiQualityActionService::class)->planFor($trace, $evaluation, autoRun: false);

        $this->assertSame('operator_review', $actions->first()->action_type);
        $this->assertSame('blocked', $actions->first()->status);
    }

    public function test_context_leak_plans_rewrite_for_operator(): void
    {
        $trace = $this->trace();
        $evaluation = $this->evaluation($trace, [
            ['code' => 'internal_context_leak', 'severity' => 'high'],
        ]);

        $actions = app(AiQualityActionService::class)->planFor($trace, $evaluation, autoRun: false);

        $this->assertSame('rewrite_for_operator', $actions->first()->action_type);
        $this->assertSame('queued', $actions->first()->status);
    }

    private function trace(array $metadata = []): AiTrace
    {
        return AiTrace::query()->create([
            'trace_key' => 'trace_'.str_replace('.', '', uniqid('', true)),
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971d231',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971d232',
            'source_type' => 'manual',
            'status' => 'succeeded',
            'operator_input' => 'continua',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'response_text' => 'Não há pergunta anterior nesta sessão.',
            'completed_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    private function evaluation(AiTrace $trace, array $flags): AiQualityEvaluation
    {
        return AiQualityEvaluation::query()->create([
            'trace_id' => $trace->id,
            'thread_id' => $trace->thread_id,
            'session_id' => $trace->session_id,
            'provider' => $trace->provider,
            'agent_slug' => $trace->agent_slug,
            'evaluator_version' => 'heuristic-v1',
            'score' => 52,
            'status' => 'failed',
            'dimensions' => [],
            'flags' => $flags,
            'suggested_actions' => [],
            'metadata' => [],
        ]);
    }

    private function createAiQualityActionTables(): void
    {
        $this->dropAiQualityActionTables();

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

        Schema::create('ai_quality_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('remediation_trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('action_type');
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('priority')->default(50);
            $table->text('reason');
            $table->json('flags')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->string('dedupe_key')->unique();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    private function dropAiQualityActionTables(): void
    {
        foreach ([
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
