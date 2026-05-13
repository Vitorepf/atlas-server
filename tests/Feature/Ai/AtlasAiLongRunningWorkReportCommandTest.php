<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AiScheduledTask;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiLongRunningWorkReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ai_scheduled_tasks');
        $this->createScheduledTasksTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_scheduled_tasks');

        parent::tearDown();
    }

    public function test_command_reports_scheduled_work_and_autonomy_receipts_without_dispatching(): void
    {
        AiScheduledTask::query()->create([
            'title' => 'Nightly review',
            'prompt' => 'Review safely',
            'schedule' => 'daily 02:00',
            'kind' => 'daily',
            'skill_ids' => [],
            'target_platform' => 'local',
            'enabled' => true,
            'next_run_at' => now()->addHour(),
            'last_run_at' => now()->subMinutes(5),
            'last_status' => 'succeeded',
            'repeat_remaining' => null,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => ['last_run_receipt' => $this->receipt()],
        ]);

        $exit = Artisan::call('atlas:ai:long-running-work-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.long_running_work_report.v1', data_get($payload, 'long_running_work.schema_version'));
        $this->assertSame('ok', data_get($payload, 'long_running_work.status'));
        $this->assertSame(1, data_get($payload, 'long_running_work.scheduled_task_count'));
        $this->assertSame(0, data_get($payload, 'long_running_work.baseline_schedule_declaration_count'));
        $this->assertSame(5, data_get($payload, 'long_running_work.baseline_schedule_missing_family_count'));
        $this->assertSame(1, data_get($payload, 'long_running_work.recent_run_count'));
        $this->assertSame(1, data_get($payload, 'long_running_work.autonomy_contract_count'));
        $this->assertSame(0, data_get($payload, 'long_running_work.unsafe_autonomy_receipt_count'));
        $this->assertSame('atlas.long_running_work.autonomy_contract.v1', data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.contract_hash'));
        $this->assertTrue(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.present'));
        $this->assertFalse(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.unsafe'));
        $this->assertSame('low', data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.autonomy_level'));
        $this->assertTrue(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.single_run_only'));
        $this->assertTrue(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.operator_review_required_for_escalation'));
        $this->assertFalse(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.schedule_mutation_allowed'));
        $this->assertFalse(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.recursive_schedule_execution_allowed'));
        $this->assertFalse(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.autonomous_followup_allowed'));
        $this->assertSame('atlas.long_running_work.baseline_contract.v1', data_get($payload, 'long_running_work.baseline_contract.schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'long_running_work.baseline_contract.contract_hash'));
        $this->assertFalse(data_get($payload, 'long_running_work.baseline_contract.execution_authority.dispatch_allowed_by_report'));
        $this->assertFalse(data_get($payload, 'long_running_work.baseline_contract.execution_authority.schedule_mutation_allowed_by_report'));
        $this->assertContains('memory_open_brain_retrieval_snapshot', data_get($payload, 'long_running_work.baseline_contract.minimum_schedule_families'));
        $this->assertContains('atlas.long_running_work.autonomy_contract.v1', data_get($payload, 'long_running_work.baseline_contract.required_receipts'));
        $this->assertFalse(data_get($payload, 'long_running_work.writes'));
        $this->assertSame('continue_long_running_work_monitoring', data_get($payload, 'long_running_work.review_signal.recommended_action'));
    }

    public function test_command_warns_when_no_structure_mother_schedule_is_declared(): void
    {
        $exit = Artisan::call('atlas:ai:long-running-work-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'long_running_work.status'));
        $this->assertSame(0, data_get($payload, 'long_running_work.scheduled_task_count'));
        $this->assertSame(5, data_get($payload, 'long_running_work.baseline_schedule_missing_family_count'));
        $this->assertContains('memory_open_brain_retrieval_snapshot', data_get($payload, 'long_running_work.baseline_schedule_missing_families'));
        $this->assertContains('no_long_running_work_schedule_declared', data_get($payload, 'long_running_work.review_signal.reasons'));
        $this->assertSame('declare_minimal_structure_mother_schedule_plan_before_autonomy_promotion', data_get($payload, 'long_running_work.review_signal.recommended_action'));
        $this->assertSame('atlas.long_running_work.baseline_contract.v1', data_get($payload, 'long_running_work.baseline_contract.schema_version'));
        $this->assertFalse(data_get($payload, 'long_running_work.baseline_contract.writes'));
        $this->assertFalse(data_get($payload, 'long_running_work.baseline_contract.raw_prompt_persisted'));
        $this->assertFalse(data_get($payload, 'long_running_work.baseline_contract.raw_output_in_metadata'));
        $this->assertFalse(data_get($payload, 'long_running_work.baseline_contract.workspace_path_exposed'));
    }

    public function test_command_can_emit_baseline_review_inbox_without_schedule_mutation_or_dispatch(): void
    {
        $capturedPayload = null;
        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000606';
        $inboxItem->title = 'Revisar baseline de Long-Running Work da estrutura mae';
        $inboxItem->payload = [
            'proposal_contract' => [
                'review_signal' => [
                    'recommended_action' => 'discuss',
                ],
            ],
        ];

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, &$capturedPayload): void {
            $mock->shouldReceive('emit')
                ->once()
                ->andReturnUsing(function (array $payload) use ($inboxItem, &$capturedPayload): AiInboxItem {
                    $capturedPayload = $payload;

                    return $inboxItem;
                });
        });

        $exit = Artisan::call('atlas:ai:long-running-work-report', [
            '--hours' => 24,
            '--emit-baseline-inbox' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(0, AiScheduledTask::query()->count());
        $this->assertSame('emitted', data_get($payload, 'emitted_baseline_inbox_item.status'));
        $this->assertSame($inboxItem->id, data_get($payload, 'emitted_baseline_inbox_item.id'));
        $this->assertSame('atlas.long_running_work.baseline_inbox.v1', data_get($capturedPayload, 'metadata.schema_version'));
        $this->assertNull(data_get($capturedPayload, 'source_id'));
        $this->assertSame('discuss', data_get($capturedPayload, 'metadata.review_signal.recommended_action'));
        $this->assertContains('no_long_running_work_schedule_declared', data_get($capturedPayload, 'metadata.review_signal.reasons'));
        $this->assertSame('discuss', data_get($capturedPayload, 'available_actions.0.id'));
        $this->assertSame('atlas.long_running_work.baseline_contract.v1', data_get($capturedPayload, 'payload.long_running_work_baseline_contract.schema_version'));
        $this->assertFalse(data_get($capturedPayload, 'payload.long_running_work_baseline_contract.execution_authority.dispatch_allowed_by_report'));
        $this->assertFalse(data_get($capturedPayload, 'payload.long_running_work_baseline_contract.execution_authority.schedule_mutation_allowed_by_report'));
        $this->assertFalse(data_get($capturedPayload, 'payload.current_report_summary.writes'));
    }

    public function test_declare_baseline_command_dry_run_does_not_create_schedule(): void
    {
        $exit = Artisan::call('atlas:ai:long-running-work-declare-baseline', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('dry_run', data_get($payload, 'status'));
        $this->assertFalse(data_get($payload, 'writes'));
        $this->assertFalse(data_get($payload, 'dispatches_jobs'));
        $this->assertSame(0, AiScheduledTask::query()->count());
        $this->assertSame('every 1d', data_get($payload, 'baseline_schedule.schedule'));
        $this->assertFalse(data_get($payload, 'baseline_schedule.enabled'));
        $this->assertNull(data_get($payload, 'baseline_schedule.next_run_at'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'baseline_schedule.contract_hash'));
    }

    public function test_declare_baseline_command_applies_idempotent_disabled_schedule_declarations(): void
    {
        $firstExit = Artisan::call('atlas:ai:long-running-work-declare-baseline', [
            '--apply' => true,
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $firstPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $secondExit = Artisan::call('atlas:ai:long-running-work-declare-baseline', [
            '--apply' => true,
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $secondPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $firstExit);
        $this->assertSame(0, $secondExit);
        $this->assertSame('applied', data_get($firstPayload, 'status'));
        $this->assertTrue(data_get($firstPayload, 'writes'));
        $this->assertFalse(data_get($firstPayload, 'dispatches_jobs'));
        $this->assertSame(5, AiScheduledTask::query()->count());
        $this->assertSame(
            data_get($firstPayload, 'scheduled_tasks.0.id'),
            data_get($secondPayload, 'scheduled_tasks.0.id')
        );
        $this->assertSame('atlas.long_running_work.baseline_schedule.v1', data_get($firstPayload, 'scheduled_tasks.0.metadata_schema_version'));
        $this->assertFalse(data_get($firstPayload, 'scheduled_tasks.0.enabled'));
        $this->assertNull(data_get($firstPayload, 'scheduled_tasks.0.next_run_at'));
        $this->assertFalse(data_get($firstPayload, 'scheduled_tasks.0.dispatch_authorized_by_declaration'));
        $this->assertTrue(data_get($firstPayload, 'scheduled_tasks.0.requires_operator_enablement'));
        $this->assertTrue(data_get($firstPayload, 'scheduled_tasks.0.operator_review_required_for_escalation'));

        $reportExit = Artisan::call('atlas:ai:long-running-work-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $reportPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $reportExit);
        $this->assertSame('ok', data_get($reportPayload, 'long_running_work.status'));
        $this->assertSame(5, data_get($reportPayload, 'long_running_work.scheduled_task_count'));
        $this->assertSame(0, data_get($reportPayload, 'long_running_work.enabled_task_count'));
        $this->assertSame(5, data_get($reportPayload, 'long_running_work.disabled_task_count'));
        $this->assertSame(5, data_get($reportPayload, 'long_running_work.baseline_schedule_declaration_count'));
        $this->assertSame(0, data_get($reportPayload, 'long_running_work.baseline_schedule_missing_family_count'));
        $this->assertSame(0, data_get($reportPayload, 'long_running_work.due_task_count'));
        $this->assertSame(0, data_get($reportPayload, 'long_running_work.unsafe_autonomy_receipt_count'));
        $this->assertSame('review_and_enable_baseline_schedules_when_operator_ready', data_get($reportPayload, 'long_running_work.review_signal.recommended_action'));
    }

    public function test_command_warns_when_scheduled_task_receipt_escalates_autonomy(): void
    {
        $receipt = $this->receipt(['autonomy_level' => 'high']);
        AiScheduledTask::query()->create([
            'title' => 'Unsafe review',
            'prompt' => 'Review too much',
            'schedule' => 'daily 02:00',
            'kind' => 'daily',
            'skill_ids' => [],
            'target_platform' => 'local',
            'enabled' => true,
            'next_run_at' => now()->addHour(),
            'last_run_at' => now()->subMinutes(5),
            'last_status' => 'succeeded',
            'repeat_remaining' => null,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => ['last_run_receipt' => $receipt],
        ]);

        $exit = Artisan::call('atlas:ai:long-running-work-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'long_running_work.status'));
        $this->assertSame(1, data_get($payload, 'long_running_work.unsafe_autonomy_receipt_count'));
        $this->assertTrue(data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.unsafe'));
        $this->assertSame('high', data_get($payload, 'long_running_work.recent_runs.0.autonomy_contract_summary.autonomy_level'));
        $this->assertSame('inspect_scheduled_task_receipts_before_autonomy_promotion', data_get($payload, 'long_running_work.review_signal.recommended_action'));
    }

    public function test_command_warns_when_tasks_are_overdue(): void
    {
        AiScheduledTask::query()->create([
            'title' => 'Due review',
            'prompt' => 'Review now',
            'schedule' => 'daily 02:00',
            'kind' => 'daily',
            'skill_ids' => [],
            'target_platform' => 'local',
            'enabled' => true,
            'next_run_at' => now()->subHour(),
            'last_run_at' => null,
            'last_status' => null,
            'repeat_remaining' => null,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => [],
        ]);

        $exit = Artisan::call('atlas:ai:long-running-work-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'long_running_work.status'));
        $this->assertSame(1, data_get($payload, 'long_running_work.overdue_task_count'));
        $this->assertSame('run_scheduler_tick_dry_run_and_check_worker', data_get($payload, 'long_running_work.review_signal.recommended_action'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function receipt(array $overrides = []): array
    {
        return [
            'schema_version' => 'atlas.scheduled_task_run_receipt.v1',
            'status' => 'succeeded',
            'autonomy_contract' => array_merge([
                'schema_version' => 'atlas.long_running_work.autonomy_contract.v1',
                'autonomy_level' => 'low',
                'single_run_only' => true,
                'operator_review_required_for_escalation' => true,
                'schedule_mutation_allowed' => false,
                'recursive_schedule_execution_allowed' => false,
                'autonomous_followup_allowed' => false,
            ], $overrides),
        ];
    }

    private function createScheduledTasksTable(): void
    {
        Schema::create('ai_scheduled_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('prompt');
            $table->string('schedule');
            $table->string('kind', 16);
            $table->json('skill_ids');
            $table->string('target_platform', 32)->default('local');
            $table->uuid('target_device_id')->nullable();
            $table->text('workspace')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_output_path')->nullable();
            $table->integer('repeat_remaining')->nullable();
            $table->json('context_from_task_ids');
            $table->boolean('wrap_response')->default(true);
            $table->json('metadata');
            $table->timestamps();
        });
    }
}
