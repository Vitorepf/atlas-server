<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContinuousStewardshipRunnerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap765_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): ContinuousStewardshipRunnerService
    {
        $service = app(ContinuousStewardshipRunnerService::class);
        $service->setStorageRootForTesting($this->tmp.'/runner');

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function executeInput(array $overrides = []): array
    {
        return array_merge([
            'area_id' => 'agentic_engineering_os',
            'mode' => ContinuousStewardshipRunnerService::MODE_EXECUTE,
            'enabled' => true,
            'min_interval_seconds' => 0,
            'active_operation_report' => $this->activeOperation(),
        ], $overrides);
    }

    public function test_dry_run_is_paused_and_safe_by_default(): void
    {
        $payload = $this->service()->run(['area_id' => 'agentic_engineering_os', 'mode' => 'dry-run']);

        $this->assertSame(ContinuousStewardshipRunnerService::RECEIPT_SCHEMA, $payload['schema_version']);
        $this->assertSame('AP-766', $payload['ap_contract']);
        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_PAUSED, $payload['status']);
        $this->assertFalse($payload['tick_attempted']);
        $this->assertSame('not_attempted', $payload['tick_status']);
        $this->assertContains('continuous_runner_disabled_by_default', $payload['blockers']);
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['branch_created']);
        $this->assertFalse($payload['claim_policy']['ap746_tick_called_when_admitted']);
        $this->assertFalse($payload['claim_policy']['installs_scheduler']);
        // Every probed bridge is deferred in dry-run; nothing is invoked.
        foreach ($payload['invoked_components'] as $component) {
            $this->assertFalse($component['invoked']);
        }
    }

    public function test_execute_tick_allowed_delegates_one_ap746_tick_and_extracts_refs(): void
    {
        $payload = $this->service()->run($this->executeInput());

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_RAN, $payload['status']);
        $this->assertTrue($payload['tick_attempted']);
        $this->assertTrue($payload['tick_admitted']);
        $this->assertContains($payload['tick_status'], ['tick_completed', 'tick_recorded']);
        $this->assertSame('acquired_released', $payload['lock_status']);
        $this->assertTrue($payload['claim_policy']['ap746_tick_called_when_admitted']);
        $this->assertNotEmpty($payload['evidence_refs']);
        // The runner never creates branches, calls providers or dispatches work.
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
        $this->assertFalse($payload['claim_policy']['dev_invoked']);
        $this->assertFalse($payload['claim_policy']['forge_invoked']);
        $this->assertFalse($payload['claim_policy']['merges']);
        $this->assertFalse($payload['claim_policy']['deploys']);
    }

    public function test_execute_with_injected_operation_marks_finding_engine_simulated_and_governs_the_rest(): void
    {
        $payload = $this->service()->run($this->executeInput());

        $byKey = [];
        foreach ($payload['invoked_components'] as $component) {
            $byKey[$component['key']] = $component;
        }

        $this->assertSame('simulated_via_injected_operation', $byKey['finding_engine']['status']);
        $this->assertFalse($byKey['finding_engine']['invoked']);
        // Branch materializer, Dev/Forge and Evidence bridges stay operator-gated.
        $this->assertSame('deferred_by_governance', $byKey['branch_materializer']['status']);
        $this->assertSame('deferred_by_governance', $byKey['dev_forge_bridge']['status']);
        $this->assertSame('deferred_by_governance', $byKey['evidence_bridge']['status']);
        foreach (['branch_materializer', 'dev_forge_bridge', 'evidence_bridge'] as $key) {
            $this->assertFalse($byKey[$key]['runner_may_invoke']);
            $this->assertFalse($byKey[$key]['invoked']);
        }
    }

    public function test_lock_prevents_duplicate_runner_invocation(): void
    {
        $service = $this->service();

        // Simulate another live invocation holding the area lock.
        File::ensureDirectoryExists($this->tmp.'/runner');
        file_put_contents(
            $service->lockFilePath('agentic_engineering_os'),
            json_encode([
                'schema_version' => 'atlas.software_company_stewardship.continuous_runner_lock.v1',
                'area_id' => 'agentic_engineering_os',
                'lock_id' => 'csr_lock_other',
                'acquired_at' => gmdate(DATE_ATOM),
                'expires_at' => gmdate(DATE_ATOM, time() + 600),
            ], JSON_THROW_ON_ERROR),
        );

        $payload = $service->run($this->executeInput());

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_LOCKED, $payload['status']);
        $this->assertSame('held_by_other', $payload['lock_status']);
        $this->assertFalse($payload['tick_attempted']);
        $this->assertContains('continuous_runner_lock_held_by_other', $payload['blockers']);
        $this->assertFalse($payload['claim_policy']['ap746_tick_called_when_admitted']);
    }

    public function test_global_kill_switch_blocks_before_any_tick(): void
    {
        $payload = $this->service()->run($this->executeInput(['global_kill_switch' => true]));

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_PAUSED, $payload['status']);
        $this->assertSame('global_active', $payload['kill_switch_status']);
        $this->assertFalse($payload['tick_attempted']);
        $this->assertContains('continuous_runner_global_kill_switch_active', $payload['blockers']);
    }

    public function test_area_kill_switch_blocks_before_any_tick(): void
    {
        $payload = $this->service()->run($this->executeInput(['area_kill_switch' => true]));

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_PAUSED, $payload['status']);
        $this->assertSame('area_active', $payload['kill_switch_status']);
        $this->assertFalse($payload['tick_attempted']);
        $this->assertContains('continuous_runner_area_kill_switch_active', $payload['blockers']);
    }

    public function test_pause_until_blocks_before_any_tick(): void
    {
        $payload = $this->service()->run($this->executeInput(['pause_until' => gmdate(DATE_ATOM, time() + 3600)]));

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_PAUSED, $payload['status']);
        $this->assertSame('paused_until', $payload['pause_status']);
        $this->assertFalse($payload['tick_attempted']);
        $this->assertContains('continuous_runner_pause_until_active', $payload['blockers']);
    }

    public function test_daily_budget_exhausted_blocks_before_any_tick(): void
    {
        $payload = $this->service()->run($this->executeInput(['max_runs_per_day' => 0]));

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_BUDGET_EXHAUSTED, $payload['status']);
        $this->assertSame('exhausted', $payload['budget_status']);
        $this->assertFalse($payload['tick_attempted']);
        $this->assertContains('continuous_runner_daily_budget_exhausted', $payload['blockers']);
        $this->assertSame(0, $payload['budget']['max_runs_per_day']);
    }

    public function test_budget_counts_admitted_execute_runs_today(): void
    {
        $service = $this->service();
        // First admitted run consumes one unit of the daily budget.
        $service->run($this->executeInput(['max_runs_per_day' => 1]));
        // Second run is over budget and must be blocked before any tick.
        $second = $service->run($this->executeInput(['max_runs_per_day' => 1]));

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_BUDGET_EXHAUSTED, $second['status']);
        $this->assertSame(1, $second['budget']['used_today']);
        $this->assertSame(0, $second['budget']['remaining']);
        $this->assertFalse($second['tick_attempted']);
    }

    public function test_receipt_is_appended_and_idempotent(): void
    {
        $service = $this->service();
        $path = $service->runFilePath('agentic_engineering_os');

        $first = $service->run($this->executeInput());
        $second = $service->run($this->executeInput());

        $this->assertSame('recorded', $first['runner_storage_status']);
        $this->assertSame('existing', $second['runner_storage_status']);
        $this->assertSame($first['runner_run_id'], $second['runner_run_id']);
        $this->assertFileExists($path);
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_dry_run_never_writes_a_receipt_unless_requested(): void
    {
        $service = $this->service();
        $service->run(['area_id' => 'agentic_engineering_os', 'mode' => 'dry-run', 'enabled' => true, 'min_interval_seconds' => 0]);

        $this->assertFileDoesNotExist($service->runFilePath('agentic_engineering_os'));
    }

    public function test_execute_is_not_due_when_ap745_rate_limit_holds(): void
    {
        $service = $this->service();
        $first = $service->run($this->executeInput(['min_interval_seconds' => 60]));
        $second = $service->run($this->executeInput(['min_interval_seconds' => 60]));

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_RAN, $first['status']);
        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_NOT_DUE, $second['status']);
        $this->assertFalse($second['tick_admitted']);
    }

    public function test_status_read_model_reports_gates_budget_and_last_run(): void
    {
        $service = $this->service();
        $service->run($this->executeInput());

        $status = $service->status(['area_id' => 'agentic_engineering_os', 'enabled' => true]);

        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_SCHEMA, $status['schema_version']);
        $this->assertSame('AP-766', $status['ap_contract']);
        $this->assertSame('clear', $status['kill_switch_status']);
        $this->assertSame('within_budget', $status['budget_status']);
        $this->assertSame(1, $status['budget']['used_today']);
        $this->assertNotNull($status['last_run']);
        $this->assertSame(ContinuousStewardshipRunnerService::STATUS_RAN, $status['last_run']['status']);
        $this->assertFalse($status['claim_policy']['provider_invoked']);
    }

    public function test_probe_lists_the_four_named_bridges(): void
    {
        $payload = $this->service()->run(['area_id' => 'agentic_engineering_os', 'mode' => 'dry-run']);

        $keys = array_map(static fn (array $c): string => $c['key'], $payload['invoked_components']);
        $this->assertSame(
            ['finding_engine', 'branch_materializer', 'dev_forge_bridge', 'evidence_bridge'],
            $keys,
        );
        // Each probe declares whether the class is present so a missing bridge
        // would surface as deferred_component_missing rather than silently pass.
        foreach ($payload['invoked_components'] as $component) {
            $this->assertArrayHasKey('present', $component);
            $this->assertArrayHasKey('status', $component);
        }
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
            'operational_cycle_id' => 'afcycle_fixture',
            'operational_cycle_hash' => 'sha256:cycle_fixture',
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
