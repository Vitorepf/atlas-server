<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledTaskJob;
use App\Models\AiScheduledTask;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasCliScheduleCommandTest extends TestCase
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

    public function test_schedule_add_list_show_pause_resume_and_run_now_queue(): void
    {
        $exitCode = Artisan::call('atlas:cli:schedule', [
            'action' => 'add',
            'id' => 'Checar foco',
            '--prompt' => 'Resuma o foco atual.',
            '--schedule' => 'every 30m',
            '--skill' => ['comunicador-claro'],
            '--workspace' => base_path(),
            '--repeat' => '2',
            '--json' => true,
        ]);
        $created = json_decode(Artisan::output(), true);
        $id = data_get($created, 'scheduled_task.id');

        $this->assertSame(0, $exitCode);
        $this->assertIsString($id);
        $this->assertSame('Checar foco', data_get($created, 'scheduled_task.title'));
        $this->assertSame(['comunicador-claro'], data_get($created, 'scheduled_task.skill_ids'));

        Artisan::call('atlas:cli:schedule', [
            'action' => 'list',
            '--json' => true,
        ]);
        $this->assertSame($id, data_get(json_decode(Artisan::output(), true), 'scheduled_tasks.0.id'));

        Artisan::call('atlas:cli:schedule', [
            'action' => 'pause',
            'id' => $id,
            '--json' => true,
        ]);
        $this->assertFalse((bool) data_get(json_decode(Artisan::output(), true), 'scheduled_task.enabled'));

        Artisan::call('atlas:cli:schedule', [
            'action' => 'resume',
            'id' => $id,
            '--json' => true,
        ]);
        $this->assertTrue((bool) data_get(json_decode(Artisan::output(), true), 'scheduled_task.enabled'));

        Queue::fake();
        $exitCode = Artisan::call('atlas:cli:schedule', [
            'action' => 'run-now',
            'id' => $id,
            '--queue' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue((bool) $payload['queued']);
        Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job): bool => $job->scheduledTaskId === $id);
    }

    public function test_scheduler_tick_command_claims_due_task_without_dispatch_for_dry_diagnostics(): void
    {
        $task = AiScheduledTask::query()->create([
            'title' => 'Due task',
            'prompt' => 'Diga ok.',
            'schedule' => 'every 5m',
            'kind' => 'interval',
            'skill_ids' => [],
            'target_platform' => 'local',
            'workspace' => base_path(),
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
            'repeat_remaining' => 1,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:scheduler:tick', [
            '--no-dispatch' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['dispatched']);
        $this->assertSame([$task->id], $payload['task_ids']);
        $this->assertFalse(AiScheduledTask::query()->findOrFail($task->id)->enabled);
    }

    public function test_scheduler_tick_dry_run_previews_due_tasks_without_mutating_or_dispatching(): void
    {
        Queue::fake();
        $task = AiScheduledTask::query()->create([
            'title' => 'Due dry-run task',
            'prompt' => 'Diga ok.',
            'schedule' => 'every 5m',
            'kind' => 'interval',
            'skill_ids' => [],
            'target_platform' => 'local',
            'workspace' => base_path(),
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
            'repeat_remaining' => 1,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:scheduler:tick', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $task->refresh();
        $this->assertSame(0, $exitCode);
        $this->assertTrue((bool) $payload['dry_run']);
        $this->assertSame(1, $payload['would_dispatch']);
        $this->assertSame(0, $payload['dispatched']);
        $this->assertSame([$task->id], $payload['task_ids']);
        $this->assertTrue($task->enabled);
        $this->assertSame(1, $task->repeat_remaining);
        $this->assertTrue($task->next_run_at->lessThanOrEqualTo(now()));
        Queue::assertNothingPushed();
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
