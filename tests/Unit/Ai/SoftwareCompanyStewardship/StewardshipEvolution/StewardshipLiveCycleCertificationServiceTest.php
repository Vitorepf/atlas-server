<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipLiveCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipLiveCycleCertificationServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap762_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_certifies_projection_cycle_end_to_end_without_creating_new_runtime(): void
    {
        $report = $this->service()->certify();

        $this->assertSame(StewardshipLiveCycleCertificationService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(StewardshipLiveCycleCertificationService::STATUS_CERTIFIED, $report['status']);
        $this->assertSame('projection_certification', $report['mode']);
        $this->assertSame([], $report['blockers']);
        $this->assertEveryMatrixStagePassed($report);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_READY, data_get($report, 'stages.owner_queue_consumption.status'));
        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY, data_get($report, 'stages.owner_runtime_execution_adapter.status'));
        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED, data_get($report, 'stages.owner_sandbox_runtime_runner.status'));
        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, data_get($report, 'stages.owner_runtime_result_bridge.status'));
        $this->assertSame(AutonomousExecutiveAllocationHandoffService::STATUS_READY, data_get($report, 'stages.executive_allocation_handoff.status'));
        $this->assertGreaterThan(0, data_get($report, 'counts.product_mode_review_items'));
        $this->assertGreaterThan(0, data_get($report, 'counts.owner_results'));

        $policy = $report['claim_policy'];
        $this->assertTrue($policy['certifier_only']);
        $this->assertFalse($policy['new_os_created']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['direct_provider_call_by_certifier']);
        $this->assertFalse($policy['merge_performed']);
        $this->assertFalse($policy['deploy_performed']);
        $this->assertFalse($policy['secret_access']);
        $this->assertFalse($policy['destructive_change']);
    }

    public function test_certifies_owner_command_execution_inside_certification_sandbox(): void
    {
        $report = $this->service()->certify(['execute_owner_command' => true]);

        $this->assertSame(StewardshipLiveCycleCertificationService::STATUS_CERTIFIED, $report['status']);
        $this->assertSame('owner_command_execution_certification', $report['mode']);
        $this->assertSame([], $report['blockers']);
        $this->assertEveryMatrixStagePassed($report);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY, data_get($report, 'stages.owner_sandbox_runtime_runner.status'));
        $this->assertSame('completed', data_get($report, 'stages.owner_sandbox_runtime_runner.command_result.status'));
        $this->assertSame(0, data_get($report, 'stages.owner_sandbox_runtime_runner.command_result.exit_code'));
        $this->assertStringContainsString('atlas-dev-run-worker-ap762-ok', (string) data_get($report, 'stages.owner_sandbox_runtime_runner.command_result.stdout_excerpt'));
        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, data_get($report, 'stages.owner_runtime_result_bridge.status'));
        $this->assertGreaterThan(0, data_get($report, 'stages.owner_runtime_result_bridge.evidence_check.test_count'));
        $this->assertTrue(data_get($report, 'stages.owner_runtime_result_bridge.identity_check.ok'));
        $this->assertTrue(data_get($report, 'stages.owner_runtime_result_bridge.isolation_check.ok'));
        $this->assertFalse(data_get($report, 'stages.owner_sandbox_runtime_runner.claim_policy.merge_performed_by_runner'));
        $this->assertFalse(data_get($report, 'stages.owner_sandbox_runtime_runner.claim_policy.deploy_performed_by_runner'));
        $this->assertFalse(data_get($report, 'stages.owner_sandbox_runtime_runner.claim_policy.secret_access_by_runner'));
    }

    public function test_blocks_when_any_owner_claims_irreversible_action(): void
    {
        $service = $this->service();
        $seed = $service->certify();
        $ownerResult = $seed['stages']['owner_runtime_result_bridge'];
        $ownerResult['claim_policy']['merge_performed'] = true;

        $report = $service->certify(['owner_runtime_result_bridge' => $ownerResult]);

        $this->assertSame(StewardshipLiveCycleCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse(data_get($report, 'certification_matrix.safety_no_irreversible_actions.passed'));
        $this->assertContains('safety_no_irreversible_actions:owner_runtime_result_bridge.claim_policy.merge_performed', $report['blockers']);
    }

    private function service(): StewardshipLiveCycleCertificationService
    {
        $service = app(StewardshipLiveCycleCertificationService::class);
        $service->setStorageRootForTesting($this->tmp.'/storage');

        return $service;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function assertEveryMatrixStagePassed(array $report): void
    {
        foreach ($report['certification_matrix'] as $key => $stage) {
            $this->assertTrue((bool) ($stage['passed'] ?? false), "Expected {$key} to pass.");
        }
    }
}
