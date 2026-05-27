<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipNativeObraRunnerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipNativeObraRunnerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap764_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_blocks_when_no_active_operation_is_available(): void
    {
        $report = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'scheduler_run' => [
                'status' => 'not_due',
                'blockers' => ['continuous_loop_next_tick_not_due'],
            ],
        ]);

        $this->assertSame(StewardshipNativeObraRunnerService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(StewardshipNativeObraRunnerService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('continuous_loop_next_tick_not_due', $report['blockers']);
        $this->assertFalse(data_get($report, 'claim_policy.codex_app_automation_used'));
        $this->assertTrue(data_get($report, 'claim_policy.atlas_server_native'));
    }

    public function test_projects_native_obra_handoff_from_ready_dev_forge_handoff(): void
    {
        $report = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'scheduler_run' => ['status' => 'run_completed'],
            'active_operation_report' => $this->activeOperation('forge'),
        ]);

        $this->assertSame(StewardshipNativeObraRunnerService::STATUS_READY, $report['status']);
        $this->assertSame(1, $report['native_obra_handoff_count']);
        $handoff = $report['native_obra_handoffs'][0];

        $this->assertSame(StewardshipNativeObraRunnerService::HANDOFF_SCHEMA, $handoff['schema_version']);
        $this->assertSame('forge', $handoff['target_owner']);
        $this->assertSame('Atlas Forge / Obras long-horizon owner queue', $handoff['target_obra_system']);
        $this->assertFalse(data_get($handoff, 'owner_queue_boundary.uses_codex_app_automation'));
        $this->assertTrue(data_get($handoff, 'owner_queue_boundary.requires_ap747_release'));
        $this->assertTrue(data_get($handoff, 'owner_queue_boundary.requires_ap759_owner_command_receipt'));
        $this->assertFalse(data_get($handoff, 'execution_authority.provider_call_allowed_now'));
    }

    public function test_provider_choreography_is_declared_but_execution_stays_gated(): void
    {
        $report = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'scheduler_run' => ['status' => 'run_completed'],
            'active_operation_report' => $this->activeOperation('atlas_dev'),
            'provider_execution_authorized' => true,
        ]);

        $roles = array_column($report['provider_choreography'], 'role');
        $this->assertSame([
            'context_scout',
            'architect',
            'implementer',
            'reviewer',
            'quality_certifier',
        ], $roles);
        $this->assertSame('claude-opus-4-7', data_get($report, 'provider_choreography.1.model'));
        $this->assertSame('claude-sonnet-4-6', data_get($report, 'provider_choreography.2.model'));
        $this->assertSame('gemini-3.5-flash', data_get($report, 'provider_choreography.3.model'));
        $this->assertSame('gpt-5.3-codex-spark', data_get($report, 'provider_choreography.4.model'));
        $this->assertTrue(data_get($report, 'claim_policy.provider_execution_authorized'));
        $this->assertFalse(data_get($report, 'claim_policy.provider_invoked'));
        $this->assertFalse(data_get($report, 'native_obra_handoffs.0.execution_authority.provider_call_allowed_now'));
    }

    public function test_records_append_only_native_runner_cycle(): void
    {
        $service = $this->service();
        $report = $service->run([
            'area_id' => 'agentic_engineering_os',
            'scheduler_run' => ['status' => 'run_completed'],
            'active_operation_report' => $this->activeOperation('atlas_dev'),
            'record_native_obra_run' => true,
        ]);

        $this->assertSame(StewardshipNativeObraRunnerService::STATUS_RECORDED, $report['status']);
        $this->assertSame('recorded', $report['native_obra_runner_storage_status']);
        $this->assertFileExists($service->runFilePath('agentic_engineering_os'));
        $lines = file($service->runFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($report['native_obra_run_id'], $record['native_obra_run_id']);
    }

    private function service(): StewardshipNativeObraRunnerService
    {
        $service = app(StewardshipNativeObraRunnerService::class);
        $service->setStorageRootForTesting($this->tmp.'/native');

        return $service;
    }

    /**
     * @return array<string,mixed>
     */
    private function activeOperation(string $targetOwner): array
    {
        return [
            'schema_version' => 'atlas.area_stewardship.active_operation.v1',
            'status' => 'active_cycle_partial',
            'operation_id' => 'asop_test_001',
            'operation_hash' => 'sha256:'.str_repeat('a', 64),
            'branch_sandbox_handoff' => [
                'handoffs' => [
                    [
                        'handoff_hash' => 'sha256:'.str_repeat('b', 64),
                        'handoff_status' => 'ready_for_handoff',
                        'route' => $targetOwner,
                        'target_owner' => $targetOwner,
                        'work_order_id' => 'afwo_test_001',
                        'work_order_hash' => 'sha256:'.str_repeat('c', 64),
                        'title' => 'Improve AAEOS Dev/Forge flow',
                        'risk_level' => 'medium',
                        'branch_plan' => [
                            'proposed_branch_name' => 'area-focus/agentic_engineering_os/'.$targetOwner.'/test',
                            'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                            'forbidden_paths' => ['.env'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
