<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContinuousStewardshipDayStartServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap778_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): ContinuousStewardshipDayStartService
    {
        $service = app(ContinuousStewardshipDayStartService::class);
        $service->setStorageRootForTesting($this->tmp.'/start');

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function readyInput(array $overrides = []): array
    {
        return array_merge([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'duration_hours' => 24,
            'enabled' => true,
            'execute_first_tick' => true,
            'record' => true,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 60,
            'lock_ttl_seconds' => 600,
            'active_operation_report' => $this->activeOperation(),
        ], $overrides);
    }

    public function test_readiness_blocked_prevents_start_and_tick(): void
    {
        $payload = $this->service()->start($this->readyInput(['enabled' => false]));

        $this->assertSame(ContinuousStewardshipDayStartService::STATUS_BLOCKED, $payload['final_status']);
        $this->assertSame('blocked', $payload['readiness_report']['status']);
        $this->assertFalse($payload['tick_admitted']);
        $this->assertSame('not_attempted', $payload['tick_status']);
        $this->assertNull($payload['runner_receipt']);
        $this->assertContains('continuous_runner_not_enabled', $payload['blockers']);
    }

    public function test_ready_start_calls_runner_once_and_records_receipt(): void
    {
        $service = $this->service();

        $payload = $service->start($this->readyInput());

        $this->assertSame(ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED, $payload['final_status']);
        $this->assertSame('ready_for_24h_run', $payload['readiness_report']['status']);
        $this->assertIsArray($payload['runner_receipt']);
        $this->assertTrue($payload['tick_admitted']);
        $this->assertContains($payload['tick_status'], ['tick_completed', 'tick_recorded']);
        $this->assertSame('recorded', $payload['start_storage_status']);
        $this->assertFileExists($payload['receipt_path']);
        $this->assertCount(1, file($payload['receipt_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['branch_created']);
        $this->assertFalse($payload['claim_policy']['scheduler_installed']);
    }

    public function test_kill_switch_blocks_before_runner_invocation(): void
    {
        $payload = $this->service()->start($this->readyInput(['global_kill_switch' => true]));

        $this->assertSame(ContinuousStewardshipDayStartService::STATUS_BLOCKED, $payload['final_status']);
        $this->assertFalse($payload['tick_admitted']);
        $this->assertSame('not_attempted', $payload['tick_status']);
        $this->assertNull($payload['runner_receipt']);
        $this->assertContains('global_kill_switch_active', $payload['blockers']);
    }

    public function test_low_interval_blocks_as_readiness_policy(): void
    {
        $payload = $this->service()->start($this->readyInput(['min_interval_seconds' => 0]));

        $this->assertSame(ContinuousStewardshipDayStartService::STATUS_BLOCKED, $payload['final_status']);
        $this->assertContains('min_interval_too_low_for_24h_run', $payload['blockers']);
        $this->assertFalse($payload['tick_admitted']);
    }

    public function test_receipt_list_and_replay_return_recorded_start(): void
    {
        $service = $this->service();
        $payload = $service->start($this->readyInput());

        $records = $service->list(['area_id' => 'agentic_engineering_os']);
        $replay = $service->replay((string) $payload['start_receipt_id'], ['area_id' => 'agentic_engineering_os']);

        $this->assertCount(1, $records);
        $this->assertSame($payload['start_receipt_id'], $records[0]['start_receipt_id']);
        $this->assertSame($payload['start_receipt_id'], $replay['start_receipt_id']);
    }

    public function test_command_smoke_json_blocks_low_interval(): void
    {
        $this->artisan('atlas:software-company-stewardship', [
            'action' => 'continuous-24h-start',
            '--enable-continuous-runner' => true,
            '--execute-first-tick' => true,
            '--record' => true,
            '--min-interval-seconds' => 0,
            '--json' => true,
        ])->assertExitCode(Command::FAILURE);
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
            'operation_id' => 'ap778_operation_fixture',
            'operation_hash' => 'sha256:ap778_operation_fixture',
            'counts' => [
                'findings' => 1,
                'work_orders' => 1,
                'spec_drafts' => 1,
                'ready_branch_handoffs' => 1,
            ],
            'operation_queue' => [
                'schema_version' => 'atlas.area_stewardship.active_operation_queue.v1',
                'operator_decision_count' => 1,
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
