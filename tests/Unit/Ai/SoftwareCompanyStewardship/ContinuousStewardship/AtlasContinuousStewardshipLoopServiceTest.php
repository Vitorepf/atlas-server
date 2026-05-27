<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasContinuousStewardshipLoopServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap745_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AtlasContinuousStewardshipLoopService
    {
        $service = app(AtlasContinuousStewardshipLoopService::class);
        $service->setStorageRootForTesting($this->tmp.'/continuous');

        return $service;
    }

    public function test_project_is_paused_by_default_and_does_not_run_operation(): void
    {
        $state = $this->service()->project(['area_id' => 'agentic_engineering_os']);

        $this->assertSame(AtlasContinuousStewardshipLoopService::STATE_SCHEMA, $state['schema_version']);
        $this->assertSame(AtlasContinuousStewardshipLoopService::STATUS_PAUSED, $state['status']);
        $this->assertSame('AP-745', $state['ap_contract']);
        $this->assertSame(['continuous_loop_disabled_by_default'], $state['blockers']);
        $this->assertFalse($state['claim_policy']['active_operation_called_when_admitted']);
        $this->assertFalse($state['claim_policy']['provider_invoked']);
        $this->assertFalse($state['claim_policy']['dev_invoked']);
        $this->assertFalse($state['claim_policy']['branch_created']);
    }

    public function test_enabled_tick_completes_one_active_operation_without_recording_by_default(): void
    {
        $tick = $this->service()->tick([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 0,
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipLoopService::TICK_SCHEMA, $tick['schema_version']);
        $this->assertSame(AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED, $tick['status']);
        $this->assertSame('AP-745', $tick['ap_contract']);
        $this->assertSame(['AP-711', 'AP-744'], $tick['source_ap_contracts']);
        $this->assertSame(AreaStewardshipActiveOperatingService::STATUS_READY, $tick['active_operation_status']);
        $this->assertSame('asop_fixture', $tick['active_operation_id']);
        $this->assertSame('projected', $tick['loop_storage_status']);
        $this->assertTrue($tick['claim_policy']['active_operation_called_when_admitted']);
        $this->assertFalse($tick['claim_policy']['records_cycle_when_requested']);
        $this->assertFalse($tick['claim_policy']['mutates_target_repo']);
        $this->assertFileDoesNotExist($this->service()->cycleFilePath('agentic_engineering_os'));
    }

    public function test_kill_switch_blocks_even_when_enabled(): void
    {
        $tick = $this->service()->tick([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'kill_switch' => true,
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipLoopService::STATUS_PAUSED, $tick['status']);
        $this->assertSame(['continuous_loop_kill_switch_active'], $tick['blockers']);
        $this->assertFalse($tick['claim_policy']['active_operation_called_when_admitted']);
    }

    public function test_lock_lease_blocks_concurrent_tick(): void
    {
        $service = $this->service();
        File::ensureDirectoryExists(dirname($service->lockFilePath('agentic_engineering_os')));
        file_put_contents($service->lockFilePath('agentic_engineering_os'), json_encode([
            'schema_version' => 'atlas.continuous_stewardship.loop_lock.v1',
            'area_id' => 'agentic_engineering_os',
            'lock_id' => 'lock_fixture',
            'acquired_at' => gmdate(DATE_ATOM),
            'expires_at' => gmdate(DATE_ATOM, time() + 600),
        ], JSON_THROW_ON_ERROR));

        $tick = $service->tick([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipLoopService::STATUS_LOCKED, $tick['status']);
        $this->assertSame(['continuous_loop_lock_active'], $tick['blockers']);
        $this->assertSame('lock_fixture', $tick['lock']['lock_id']);
        $this->assertFalse($tick['claim_policy']['active_operation_called_when_admitted']);
    }

    public function test_recorded_tick_is_append_only_and_idempotent(): void
    {
        $input = [
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 0,
            'record_continuous_cycle' => true,
            'active_operation_report' => $this->activeOperation(),
        ];

        $first = $this->service()->tick($input);
        $second = $this->service()->tick($input);

        $this->assertSame(AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['loop_storage_status']);
        $this->assertSame('existing', $second['loop_storage_status']);
        $this->assertSame($first['tick_id'], $second['tick_id']);
        $this->assertFileExists($this->service()->cycleFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->cycleFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertTrue($first['claim_policy']['records_cycle_when_requested']);
    }

    public function test_min_interval_rate_limits_repeated_recorded_ticks(): void
    {
        $service = $this->service();
        $first = $service->tick([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 60,
            'record_continuous_cycle' => true,
            'active_operation_report' => $this->activeOperation(),
        ]);
        $second = $service->tick([
            'area_id' => 'agentic_engineering_os',
            'enabled' => true,
            'min_interval_seconds' => 60,
            'record_continuous_cycle' => true,
            'active_operation_report' => $this->activeOperation(),
        ]);

        $this->assertSame(AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED, $first['status']);
        $this->assertSame(AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED, $second['status']);
        $this->assertSame(['continuous_loop_min_interval_not_elapsed'], $second['blockers']);
        $this->assertNotNull($second['next_allowed_at']);
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
