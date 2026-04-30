<?php

namespace Tests\Unit;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiTrace;
use App\Services\Ai\AiStreamRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiStreamRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach (['ai_stream_events', 'ai_job_attempts', 'ai_jobs', 'ai_traces'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_records_ordered_stream_events_for_job(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_stream_test',
            'source_type' => 'manual',
            'status' => 'processing',
            'operator_input' => 'pedido',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => [],
        ]);

        $job = AiJob::query()->create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'processing',
            'priority' => 50,
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'input_text' => 'pedido',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [],
            'available_at' => now(),
            'attempts' => 1,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        $attempt = AiJobAttempt::query()->create([
            'ai_job_id' => $job->id,
            'attempt_number' => 1,
            'worker_id' => 'test-worker',
            'provider' => 'codex_cli',
            'command' => [],
            'prompt_hash' => 'hash',
            'status' => 'processing',
            'metadata' => [],
        ]);

        $recorder = app(AiStreamRecorder::class);

        $first = $recorder->record($job, $attempt, 'token', 'ola', ['source' => 'test'], 'assistant');
        $second = $recorder->recordProviderEvent($job, $attempt, [
            'type' => 'response',
            'name' => 'final',
            'content' => 'feito',
            'metadata' => ['source' => 'provider'],
        ]);

        $this->assertSame(1, $first?->sequence);
        $this->assertSame(2, $second?->sequence);
        $this->assertSame('final', $second?->metadata['name']);
        $this->assertSame('feito', $second?->content);
    }

    private function createTables(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->string('source_type')->default('manual');
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_job_id');
            $table->integer('attempt_number');
            $table->string('worker_id');
            $table->string('provider');
            $table->json('command')->nullable();
            $table->string('prompt_hash');
            $table->string('status')->default('processing');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_stream_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id');
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->integer('sequence');
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->text('content')->default('');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
