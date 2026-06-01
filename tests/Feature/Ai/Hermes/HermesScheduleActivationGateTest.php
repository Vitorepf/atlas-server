<?php

namespace Tests\Feature\Ai\Hermes;

use App\Jobs\RunScheduledTaskJob;
use App\Models\AiScheduledTask;
use App\Services\Ai\Hermes\HermesScheduleActivationGate;
use App\Services\Ai\Scheduling\AtlasCliSchedulerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HermesScheduleActivationGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_scheduled_tasks');

        parent::tearDown();
    }

    public function test_candidate_is_invisible_to_previewDueTasks_and_tick(): void
    {
        $this->candidate();

        $preview = app(AtlasCliSchedulerService::class)->previewDueTasks();
        $this->assertSame([], $preview['task_ids']);
        $this->assertSame(0, $preview['would_dispatch']);

        $tick = app(AtlasCliSchedulerService::class)->tick();
        $this->assertSame(0, $tick['dispatched']);

        Queue::assertNothingPushed();
    }

    public function test_activation_without_confirmation_fails_closed(): void
    {
        $task = $this->candidate();

        $receipt = app(HermesScheduleActivationGate::class)->activate($task, []);

        $this->assertSame('rejected_activation_not_confirmed', $receipt['status']);
        $this->assertFalse((bool) $receipt['activation_allowed_now']);
        $this->assertNull($receipt['activated_task_id']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $task->refresh();
        $this->assertSame('candidate', $task->kind);
        $this->assertFalse((bool) $task->enabled);
        $this->assertNull($task->next_run_at);
    }

    public function test_activation_rejects_when_stop_conditions_empty(): void
    {
        $task = $this->candidate([
            'metadata' => $this->metadata(['hermes_schedule_candidate' => ['stop_conditions' => []]]),
        ]);

        $receipt = app(HermesScheduleActivationGate::class)->activate($task, ['operator_confirmed' => true]);

        $this->assertSame('rejected_missing_stop_conditions', $receipt['status']);
        $this->assertFalse((bool) $receipt['activation_allowed_now']);
        $this->assertNull($receipt['activated_task_id']);

        $task->refresh();
        $this->assertSame('candidate', $task->kind);
        $this->assertFalse((bool) $task->enabled);
        $this->assertNull($task->next_run_at);
    }

    public function test_activation_rejects_when_evidence_or_idempotency_not_required(): void
    {
        $noEvidence = $this->candidate([
            'metadata' => $this->metadata(['evidence_required' => false]),
        ]);

        $receipt = app(HermesScheduleActivationGate::class)->activate($noEvidence, ['operator_confirmed' => true]);
        $this->assertSame('rejected_evidence_or_idempotency_required', $receipt['status']);
        $this->assertFalse((bool) $receipt['activation_allowed_now']);
        $noEvidence->refresh();
        $this->assertSame('candidate', $noEvidence->kind);
        $this->assertFalse((bool) $noEvidence->enabled);

        $noIdempotency = $this->candidate([
            'metadata' => $this->metadata(['idempotency_required' => false]),
        ]);

        $receipt = app(HermesScheduleActivationGate::class)->activate($noIdempotency, ['operator_confirmed' => true]);
        $this->assertSame('rejected_evidence_or_idempotency_required', $receipt['status']);
        $this->assertFalse((bool) $receipt['activation_allowed_now']);
        $noIdempotency->refresh();
        $this->assertSame('candidate', $noIdempotency->kind);
        $this->assertFalse((bool) $noIdempotency->enabled);
    }

    public function test_invalid_cadence_fails_closed(): void
    {
        $task = $this->candidate([
            'metadata' => $this->metadata(['hermes_schedule_candidate' => ['cadence' => 'not a schedule']]),
        ]);

        $receipt = app(HermesScheduleActivationGate::class)->activate($task, ['operator_confirmed' => true]);

        $this->assertSame('rejected_invalid_cadence', $receipt['status']);
        $this->assertFalse((bool) $receipt['activation_allowed_now']);
        $this->assertNull($receipt['activated_task_id']);

        $task->refresh();
        $this->assertSame('candidate', $task->kind);
        $this->assertFalse((bool) $task->enabled);
        $this->assertNull($task->next_run_at);
    }

    public function test_activation_with_operator_confirmed_promotes_cron_candidate(): void
    {
        $task = $this->candidate();

        $receipt = app(HermesScheduleActivationGate::class)->activate($task, [
            'operator_confirmed' => true,
            'activated_by' => 'operator',
        ]);

        $this->assertSame('activated', $receipt['status']);
        $this->assertTrue((bool) $receipt['activation_allowed_now']);
        $this->assertSame($task->id, $receipt['activated_task_id']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $task->refresh();
        $this->assertSame('cron', $task->kind);
        $this->assertSame('0 9 * * *', $task->schedule);
        $this->assertTrue((bool) $task->enabled);
        $this->assertNotNull($task->next_run_at);
        $this->assertSame($receipt['receipt_hash'], data_get($task->metadata, 'activation_receipt_hash'));
        $this->assertSame('approved', data_get($task->metadata, 'review_status'));
        $this->assertSame('activated_by_atlas_schedule_gate', data_get($task->metadata, 'hermes_schedule_candidate.gate_status'));
        $this->assertSame('hermes_schedule_candidate_test', data_get($task->metadata, 'hermes_schedule_candidate.candidate_id'));
    }

    public function test_activation_via_review_status_approved_without_explicit_flag(): void
    {
        $task = $this->candidate([
            'metadata' => $this->metadata(['review_status' => 'approved']),
        ]);

        $receipt = app(HermesScheduleActivationGate::class)->activate($task, []);

        $this->assertSame('activated', $receipt['status']);
        $this->assertTrue((bool) $receipt['activation_allowed_now']);
        $this->assertSame($task->id, $receipt['activated_task_id']);
    }

    public function test_activated_task_appears_in_previewDueTasks_and_tick_when_due(): void
    {
        $task = $this->candidate([
            'metadata' => $this->metadata(['hermes_schedule_candidate' => ['cadence' => 'every 5m']]),
        ]);

        $receipt = app(HermesScheduleActivationGate::class)->activate($task, ['operator_confirmed' => true]);
        $this->assertSame('activated', $receipt['status']);

        $task->refresh();
        $this->assertSame('interval', $task->kind);

        $task->update(['next_run_at' => now()->subMinute()]);

        $preview = app(AtlasCliSchedulerService::class)->previewDueTasks();
        $this->assertContains($task->id, $preview['task_ids']);

        $tick = app(AtlasCliSchedulerService::class)->tick();
        $this->assertSame([$task->id], $tick['task_ids']);

        Queue::assertPushed(RunScheduledTaskJob::class);
    }

    public function test_reactivating_already_active_task_is_rejected_not_a_candidate(): void
    {
        $task = $this->candidate([
            'kind' => 'interval',
            'enabled' => true,
            'next_run_at' => now()->addMinutes(5),
        ]);

        $receipt = app(HermesScheduleActivationGate::class)->activate($task, ['operator_confirmed' => true]);

        $this->assertSame('rejected_not_a_candidate', $receipt['status']);
        $this->assertFalse((bool) $receipt['activation_allowed_now']);
        $this->assertNull($receipt['activated_task_id']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function candidate(array $overrides = []): AiScheduledTask
    {
        return AiScheduledTask::query()->create(array_merge([
            'title' => 'Daily review',
            'prompt' => 'review',
            'schedule' => 'candidate:cron:0 9 * * *',
            'kind' => 'candidate',
            'skill_ids' => [],
            'target_platform' => 'local',
            'target_device_id' => null,
            'workspace' => null,
            'enabled' => false,
            'next_run_at' => null,
            'last_run_at' => null,
            'last_status' => null,
            'last_output_path' => null,
            'repeat_remaining' => null,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => $this->metadata(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function metadata(array $overrides = []): array
    {
        $base = [
            'schema_version' => 'atlas.hermes.schedule_candidate_task.v1',
            'review_status' => 'pending',
            'evidence_required' => true,
            'idempotency_required' => true,
            'hermes_schedule_candidate' => [
                'candidate_id' => 'hermes_schedule_candidate_test',
                'cadence' => '0 9 * * *',
                'stop_conditions' => ['budget_exhausted', 'operator_paused'],
                'trigger' => 'cron',
                'name' => 'Daily review',
                'objective' => 'review',
                'gate_status' => 'persisted_for_atlas_schedule_review',
            ],
        ];

        $candidateOverrides = $overrides['hermes_schedule_candidate'] ?? [];
        unset($overrides['hermes_schedule_candidate']);
        $base['hermes_schedule_candidate'] = array_merge($base['hermes_schedule_candidate'], $candidateOverrides);

        return array_merge($base, $overrides);
    }
}
