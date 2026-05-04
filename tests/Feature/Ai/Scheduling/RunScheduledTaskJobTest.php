<?php

namespace Tests\Feature\Ai\Scheduling;

use App\Jobs\RunScheduledTaskJob;
use App\Models\AiJob;
use App\Models\AiScheduledTask;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiWorker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

class RunScheduledTaskJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('ai_scheduled_tasks');

        parent::tearDown();
    }

    public function test_job_creates_guarded_interaction_waits_and_writes_local_output(): void
    {
        $task = AiScheduledTask::query()->create([
            'title' => 'Resumo de foco',
            'prompt' => 'Diga o estado atual.',
            'schedule' => '30m',
            'kind' => 'once',
            'skill_ids' => ['comunicador-claro'],
            'target_platform' => 'local',
            'workspace' => base_path(),
            'enabled' => true,
            'next_run_at' => now(),
            'repeat_remaining' => 1,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => ['timeout_seconds' => 30],
        ]);
        $trace = $this->trace(['status' => 'queued']);

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($task, $trace): void {
            $mock->shouldReceive('enqueueInteraction')
                ->once()
                ->withArgs(function (string $prompt, array $options) use ($task): bool {
                    $this->assertStringContainsString('Anti-recursion guard', $prompt);
                    $this->assertStringContainsString('atlas schedule', $prompt);
                    $this->assertSame('scheduled', $options['source_type']);
                    $this->assertSame($task->id, $options['source_id']);
                    $this->assertSame(['comunicador-claro'], data_get($options, 'payload.activated_skills'));
                    $this->assertSame('read', data_get($options, 'payload.tool_permissions.mode'));

                    return true;
                })
                ->andReturn($trace);
        });

        $this->mock(AiWorker::class, function (MockInterface $mock) use ($trace): void {
            $mock->shouldReceive('runNextForTrace')
                ->once()
                ->withArgs(fn (string $traceId): bool => $traceId === $trace->id)
                ->andReturnUsing(function () use ($trace) {
                    $trace->update([
                        'status' => 'succeeded',
                        'response_text' => 'Resposta agendada pronta.',
                        'completed_at' => now(),
                    ]);

                    return null;
                });
        });

        RunScheduledTaskJob::dispatchSync($task->id);

        $task->refresh();
        $this->assertSame('success', $task->last_status);
        $this->assertNotNull($task->last_run_at);
        $this->assertIsString($task->last_output_path);
        $this->assertFileExists($task->last_output_path);
        $this->assertStringContainsString('Atlas - Scheduled Task: Resumo de foco', File::get($task->last_output_path));
        $this->assertStringContainsString('Resposta agendada pronta.', File::get($task->last_output_path));
        $this->assertFalse((bool) data_get($task->metadata, 'last_delivery_suppressed'));
    }

    public function test_job_records_deferred_status_when_mac_background_readiness_delays_trace(): void
    {
        $task = AiScheduledTask::query()->create([
            'title' => 'Refatoracao madrugada',
            'prompt' => 'Refatore com seguranca.',
            'schedule' => 'daily',
            'kind' => 'recurring',
            'skill_ids' => [],
            'target_platform' => 'local',
            'workspace' => base_path(),
            'enabled' => true,
            'next_run_at' => now(),
            'repeat_remaining' => null,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => ['timeout_seconds' => 30],
        ]);
        $trace = $this->trace(['status' => 'queued']);

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($trace): void {
            $mock->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturn($trace);
        });
        $this->mock(AiWorker::class, function (MockInterface $mock) use ($trace): void {
            $mock->shouldReceive('runNextForTrace')
                ->once()
                ->andReturnUsing(function () use ($trace) {
                    AiJob::query()->create([
                        'trace_id' => $trace->id,
                        'status' => 'queued',
                        'available_at' => now()->addMinutes(5),
                        'metadata' => [
                            'mac_background_readiness' => [
                                'reason' => 'mac_background_not_ready',
                                'readiness' => [
                                    'blockers' => [
                                        ['code' => 'power_helper_not_ready', 'message' => 'Power Helper root ainda nao esta pronto.'],
                                    ],
                                ],
                            ],
                        ],
                    ]);
                    $trace->update([
                        'status' => 'queued',
                        'metadata' => [
                            'mac_background_readiness' => [
                                'reason' => 'mac_background_not_ready',
                            ],
                        ],
                    ]);

                    return null;
                });
        });

        RunScheduledTaskJob::dispatchSync($task->id);

        $task->refresh();
        $this->assertSame('deferred', $task->last_status);
        $this->assertSame('deferred_until_ready', data_get($task->metadata, 'last_delivery_status'));
        $this->assertFileExists($task->last_output_path);
        $output = File::get($task->last_output_path);
        $this->assertStringContainsString('Scheduled task deferred.', $output);
        $this->assertStringContainsString('mac_background_not_ready', $output);
        $this->assertStringContainsString('power_helper_not_ready', $output);
    }

    private function trace(array $overrides = []): AiTrace
    {
        return AiTrace::query()->create(array_merge([
            'trace_key' => 'trace_'.str_replace('.', '', uniqid('', true)),
            'source_type' => 'scheduled',
            'status' => 'queued',
            'operator_input' => 'pedido agendado',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => [],
        ], $overrides));
    }

    private function createTables(): void
    {
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('ai_scheduled_tasks');

        Schema::create('ai_scheduled_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('prompt');
            $table->string('schedule');
            $table->string('kind', 16);
            $table->json('skill_ids')->nullable();
            $table->string('target_platform', 32)->default('local');
            $table->uuid('target_device_id')->nullable();
            $table->text('workspace')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_output_path')->nullable();
            $table->integer('repeat_remaining')->nullable();
            $table->json('context_from_task_ids')->nullable();
            $table->boolean('wrap_response')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('manual');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('status')->default('queued');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
