<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasContinuousStewardshipRecurringSchedulerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap746_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AtlasContinuousStewardshipRecurringSchedulerService
    {
        $service = app(AtlasContinuousStewardshipRecurringSchedulerService::class);
        $service->setStorageRootForTesting($this->tmp.'/scheduler');

        return $service;
    }

    public function test_project_is_paused_by_default_and_never_installs_scheduler(): void
    {
        $state = $this->service()->project(['area_id' => 'agentic_engineering_os']);

        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::SCHEDULE_SCHEMA, $state['schema_version']);
        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_PAUSED, $state['status']);
        $this->assertSame('AP-746', $state['ap_contract']);
        $this->assertSame(['continuous_scheduler_disabled_by_default'], $state['blockers']);
        $this->assertFalse($state['claim_policy']['installs_scheduler']);
        $this->assertFalse($state['claim_policy']['ap745_tick_called_when_due']);
        $this->assertFalse($state['claim_policy']['provider_invoked']);
        $this->assertFalse($state['claim_policy']['branch_created']);
    }

    public function test_enabled_projection_is_scheduled_when_ap745_is_ready(): void
    {
        $state = $this->service()->project([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 0,
        ]);

        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED, $state['status']);
        $this->assertSame('ready_to_tick', $state['continuous_loop']['status']);
        $this->assertSame([], $state['blockers']);
    }

    public function test_enabled_run_calls_ap745_once_without_scheduler_recording_by_default(): void
    {
        $run = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 0,
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::RUN_SCHEMA, $run['schema_version']);
        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED, $run['status']);
        $this->assertSame('AP-746', $run['ap_contract']);
        $this->assertSame('tick_completed', $run['tick_status']);
        $this->assertSame('projected', $run['scheduler_storage_status']);
        $this->assertTrue($run['claim_policy']['ap745_tick_called_when_due']);
        $this->assertFalse($run['claim_policy']['records_scheduler_run_when_requested']);
        $this->assertFalse($run['claim_policy']['mutates_target_repo']);
        $this->assertFalse($run['claim_policy']['dev_invoked']);
        $this->assertFileDoesNotExist($this->service()->schedulerFilePath('agentic_engineering_os'));
    }

    public function test_pause_until_blocks_recurring_scheduler_even_when_enabled(): void
    {
        $run = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'pause_until' => gmdate(DATE_ATOM, time() + 3600),
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_PAUSED, $run['status']);
        $this->assertSame(['continuous_scheduler_pause_until_active'], $run['blockers']);
        $this->assertFalse($run['claim_policy']['ap745_tick_called_when_due']);
    }

    public function test_recorded_scheduler_run_is_append_only_and_idempotent(): void
    {
        $input = [
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 0,
            'record_scheduler_run' => true,
            'record_continuous_cycle' => true,
            'active_operation_report' => $this->activeOperation(),
        ];

        $first = $this->service()->run($input);
        $second = $this->service()->run($input);

        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['scheduler_storage_status']);
        $this->assertSame('existing', $second['scheduler_storage_status']);
        $this->assertSame($first['scheduler_run_id'], $second['scheduler_run_id']);
        $this->assertFileExists($this->service()->schedulerFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->schedulerFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertTrue($first['claim_policy']['records_scheduler_run_when_requested']);
    }

    public function test_ap745_rate_limit_turns_recurring_scheduler_into_not_due(): void
    {
        $service = $this->service();
        $first = $service->run([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 60,
            'record_scheduler_run' => true,
            'record_continuous_cycle' => true,
            'active_operation_report' => $this->activeOperation(),
        ]);
        $second = $service->run([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 60,
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED, $first['status']);
        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_NOT_DUE, $second['status']);
        $this->assertSame(['continuous_loop_next_tick_not_due'], $second['blockers']);
        $this->assertFalse($second['claim_policy']['ap745_tick_called_when_due']);
    }

    public function test_kill_switch_pauses_scheduler(): void
    {
        $run = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'kill_switch' => true,
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipRecurringSchedulerService::STATUS_PAUSED, $run['status']);
        $this->assertSame(['continuous_scheduler_kill_switch_active'], $run['blockers']);
        $this->assertFalse($run['claim_policy']['ap745_tick_called_when_due']);
    }

    /**
     * @return array<string,mixed>
     */
    private function activeOperation(): array
    {
        return [
            'schema_version' => AreaStewardshipActiveOperatingService::REPORT_SCHEMA,
            'status' => AreaStewardshipActiveOperatingService::STATUS_READY,
            'ap_contract' => 'AP-744',
            'area_id' => 'agentic_engineering_os',
            'operation_id' => 'asop_fixture',
            'operation_hash' => 'sha256:operation_fixture',
            'counts' => [
                'findings' => 2,
                'work_orders' => 2,
                'spec_drafts' => 1,
                'ready_branch_handoffs' => 1,
            ],
            'operation_queue' => [
                'schema_version' => 'atlas.area_stewardship.active_operation_queue.v1',
                'operator_decision_count' => 2,
                'ready_dev_forge_handoff_count' => 1,
            ],
            'blockers' => [],
            'next_actions' => [
                'Review AP-724 operator decisions for emitted Area Focus work orders.',
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
                'work_dispatched' => false,
                'branch_created' => false,
                'mutates_target_repo' => false,
            ],
        ];
    }
}
