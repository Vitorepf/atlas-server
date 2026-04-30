<?php

namespace Tests\Unit;

use App\Jobs\RunScheduledTaskJob;
use App\Models\AiScheduledTask;
use App\Services\Ai\Scheduling\AtlasCliSchedulerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasCliSchedulerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createScheduledTasksTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_scheduled_tasks');

        parent::tearDown();
    }

    public function test_add_task_calculates_next_run_and_normalizes_fields(): void
    {
        $task = app(AtlasCliSchedulerService::class)->addTask(
            title: 'Resumo de foco',
            prompt: 'Resuma meu foco atual.',
            schedule: 'every 30m',
            skillIds: ['Comunicador-Claro', 'comunicador-claro'],
            workspace: base_path(),
            repeatRemaining: 3,
        );

        $this->assertSame('interval', $task->kind);
        $this->assertSame(['comunicador-claro'], $task->skill_ids);
        $this->assertSame(base_path(), $task->workspace);
        $this->assertSame(3, $task->repeat_remaining);
        $this->assertTrue($task->enabled);
        $this->assertNotNull($task->next_run_at);
    }

    public function test_tick_dispatches_due_interval_and_advances_next_run(): void
    {
        Queue::fake();

        $task = $this->task([
            'schedule' => 'every 5m',
            'kind' => 'interval',
            'next_run_at' => now()->subMinute(),
            'repeat_remaining' => 2,
        ]);

        $result = app(AtlasCliSchedulerService::class)->tick();

        $task->refresh();
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame([$task->id], $result['task_ids']);
        $this->assertSame(1, $task->repeat_remaining);
        $this->assertTrue($task->enabled);
        $this->assertTrue($task->next_run_at->greaterThan(now()));
        Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job): bool => $job->scheduledTaskId === $task->id);
    }

    public function test_tick_disables_once_task_after_claim(): void
    {
        Queue::fake();

        $task = $this->task([
            'schedule' => '30m',
            'kind' => 'once',
            'next_run_at' => now()->subMinute(),
            'repeat_remaining' => 1,
        ]);

        app(AtlasCliSchedulerService::class)->tick();

        $task->refresh();
        $this->assertFalse($task->enabled);
        $this->assertSame(0, $task->repeat_remaining);
        $this->assertNull($task->next_run_at);
    }

    public function test_tick_quarantines_invalid_schedule_without_blocking_valid_due_tasks(): void
    {
        Queue::fake();

        $invalid = $this->task([
            'title' => 'Invalid schedule',
            'schedule' => 'not a schedule',
            'kind' => 'interval',
            'next_run_at' => now()->subMinute(),
        ]);
        $valid = $this->task([
            'title' => 'Valid schedule',
            'schedule' => 'every 5m',
            'kind' => 'interval',
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AtlasCliSchedulerService::class)->tick();

        $invalid->refresh();
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame([$valid->id], $result['task_ids']);
        $this->assertFalse($invalid->enabled);
        $this->assertNull($invalid->next_run_at);
        $this->assertSame('failure', $invalid->last_status);
        $this->assertSame('invalid_schedule', $invalid->metadata['scheduler_disabled_reason'] ?? null);
        Queue::assertPushed(RunScheduledTaskJob::class, 1);
        Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job): bool => $job->scheduledTaskId === $valid->id);
    }

    private function task(array $overrides = []): AiScheduledTask
    {
        return AiScheduledTask::query()->create(array_merge([
            'title' => 'Scheduled test',
            'prompt' => 'Diga ok.',
            'schedule' => 'every 5m',
            'kind' => 'interval',
            'skill_ids' => [],
            'target_platform' => 'local',
            'workspace' => base_path(),
            'enabled' => true,
            'next_run_at' => now(),
            'repeat_remaining' => null,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => [],
        ], $overrides));
    }

    private function createScheduledTasksTable(): void
    {
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
    }
}
