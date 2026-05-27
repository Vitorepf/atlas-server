<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalInboxReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeRuntimeResultEventService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ProductModeOperationalInboxReadModelServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_op_inbox_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): ProductModeOperationalInboxReadModelService
    {
        $service = app(ProductModeOperationalInboxReadModelService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    public function test_empty_state_when_no_items_and_sources_ok(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'budget_ms' => 0,
        ]);

        $this->assertSame(ProductModeOperationalInboxReadModelService::SCHEMA, $payload['schema_version']);
        $this->assertSame(0, $payload['item_count']);
        $this->assertIsArray($payload['empty_state']);
        $this->assertTrue($payload['empty_state']['honest']);
        $this->assertFalse($payload['empty_state']['loading']);
        $this->assertSame(ProductModeOperationalInboxReadModelService::STATUS_DEGRADED, $payload['status']);
    }

    public function test_honest_ready_projection_without_recorded_receipts(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
        ]);

        $this->assertContains($payload['status'], [
            ProductModeOperationalInboxReadModelService::STATUS_READY,
            ProductModeOperationalInboxReadModelService::STATUS_EMPTY,
        ]);
        $this->assertFalse($payload['claim_policy']['fabricates_receipts']);
        $this->assertNull($payload['empty_state']);
    }

    public function test_readiness_blocked_becomes_alert(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'kill_switch' => true,
            'enabled' => true,
            'duration_hours' => 24,
        ]);

        $kinds = array_column($payload['items'], 'kind');
        $this->assertContains('continuous_24h_readiness_blocked', $kinds);
        $this->assertGreaterThanOrEqual(1, $payload['counters']['alerts']);
        $this->assertGreaterThanOrEqual(1, $payload['counters']['blocked']);
    }

    public function test_readiness_ready_becomes_recommendation(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'enabled' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 900,
            'lock_ttl_seconds' => 600,
        ]);

        $this->assertContains('continuous_24h_readiness_ready', array_column($payload['items'], 'kind'));
        $this->assertGreaterThanOrEqual(1, $payload['counters']['recommendations']);
    }

    public function test_runner_executed_becomes_insight(): void
    {
        $service = $this->service();
        $runner = app(ContinuousStewardshipRunnerService::class);
        $runner->setStorageRootForTesting($this->tmp.'/runner');
        $runner->run([
            'area_id' => 'agentic_engineering_os',
            'mode' => ContinuousStewardshipRunnerService::MODE_EXECUTE,
            'enabled' => true,
            'record_runner_run' => true,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 900,
            'lock_ttl_seconds' => 600,
        ]);

        $payload = $service->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'enabled' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 900,
        ]);

        $this->assertContains('continuous_runner_tick_executed', array_column($payload['items'], 'kind'));
        $this->assertGreaterThanOrEqual(1, $payload['counters']['insights']);
        $this->assertGreaterThanOrEqual(1, $payload['counters']['executed']);
    }

    public function test_first_tick_executed_receipt_surfaces_in_latest_receipts(): void
    {
        $service = $this->service();
        $start = app(ContinuousStewardshipDayStartService::class);
        $start->setStorageRootForTesting($this->tmp);

        $startPayload = $start->start([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'enabled' => true,
            'execute_first_tick' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 60,
            'lock_ttl_seconds' => 600,
            'record' => true,
            'active_operation_report' => $this->activeOperationFixture(),
        ]);
        $this->assertSame(ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED, $startPayload['final_status']);

        $payload = $service->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'enabled' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 900,
        ]);

        $sources = array_column($payload['latest_receipts'], 'source');
        $this->assertContains('ap_778_first_tick', $sources);
    }

    public function test_broken_source_returns_degraded_state(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'budget_ms' => 0,
        ]);

        $this->assertSame(ProductModeOperationalInboxReadModelService::STATUS_DEGRADED, $payload['status']);
        $this->assertIsArray($payload['degraded_state']);
        $this->assertNotSame([], $payload['degraded_state']['failed_sources']);
    }

    public function test_counters_track_buckets_and_cycle_states(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'enabled' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 900,
            'kill_switch' => true,
        ]);

        $this->assertArrayHasKey('approvals', $payload['counters']);
        $this->assertArrayHasKey('recommendations', $payload['counters']);
        $this->assertArrayHasKey('insights', $payload['counters']);
        $this->assertArrayHasKey('alerts', $payload['counters']);
        $this->assertArrayHasKey('blocked', $payload['counters']);
        $this->assertArrayHasKey('executed', $payload['counters']);
        $this->assertArrayHasKey('deferred', $payload['counters']);
        $this->assertGreaterThan(0, $payload['counters']['alerts'] + $payload['counters']['recommendations']);
    }

    public function test_runtime_events_surface_as_insights(): void
    {
        $service = $this->service();
        $events = app(ProductModeRuntimeResultEventService::class);
        $events->setStorageRootForTesting($this->tmp.'/pm_events');
        $events->record([
            'area_id' => 'agentic_engineering_os',
            'owner' => 'atlas_dev',
            'result_bridge_id' => 'rb_test_1',
            'result' => ['result_status' => 'completed'],
            'evidence' => ['evidence_pack_id' => 'ep_test_1'],
        ]);

        $payload = $service->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
        ]);

        $this->assertContains('runtime_result_evidence_emitted', array_column($payload['items'], 'kind'));
        $this->assertGreaterThanOrEqual(1, $payload['counters']['insights']);
    }

    /**
     * @return array<string,mixed>
     */
    private function activeOperationFixture(): array
    {
        return [
            'schema_version' => AreaStewardshipActiveOperatingService::REPORT_SCHEMA,
            'status' => AreaStewardshipActiveOperatingService::STATUS_READY,
            'ap_contract' => 'AP-744',
            'area_id' => 'agentic_engineering_os',
            'operation_id' => 'operational_inbox_fixture',
            'operation_hash' => 'sha256:operational_inbox_fixture',
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
            'next_actions' => ['Review active operation queue.'],
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
