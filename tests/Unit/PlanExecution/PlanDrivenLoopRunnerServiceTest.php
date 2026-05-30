<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\DeterministicPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDrivenLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceCycleExecutor;
use PHPUnit\Framework\TestCase;

final class PlanDrivenLoopRunnerServiceTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_plan_driven_run_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storageRoot);
        parent::tearDown();
    }

    private function runner(): PlanDrivenLoopRunnerService
    {
        $tracker = new PlanCompletionTrackerService;
        $tracker->setStorageRootForTesting($this->storageRoot);
        $runner = new PlanDrivenLoopRunnerService;
        $runner->setTrackerForTesting($tracker);

        return $runner;
    }

    /**
     * 6-slice plan: S1->S2->S3 and S4->S5->S6 (mirrors the real forge build-plan).
     *
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        $specs = [
            ['id' => 'S1', 'seq' => 1, 'depends_on' => []],
            ['id' => 'S2', 'seq' => 2, 'depends_on' => ['S1']],
            ['id' => 'S3', 'seq' => 3, 'depends_on' => ['S2']],
            ['id' => 'S4', 'seq' => 4, 'depends_on' => []],
            ['id' => 'S5', 'seq' => 5, 'depends_on' => ['S4']],
            ['id' => 'S6', 'seq' => 6, 'depends_on' => ['S5']],
        ];
        $slices = [];
        foreach ($specs as $s) {
            $slices[] = [
                'slice_id' => $s['id'],
                'sequence' => $s['seq'],
                'depends_on' => $s['depends_on'],
                'acceptance_criteria' => ['aceite '.$s['id']],
                'owner' => 'atlas_dev',
                'finding' => ['finding_id' => $s['id'], 'title' => 'finding '.$s['id']],
                'finding_id' => $s['id'],
            ];
        }

        return [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'forge-real-plan',
            'decomposition_status' => 'complete',
            'slices' => $slices,
            'dependency_graph' => [],
            'plan_hash' => 'sha256:plan',
        ];
    }

    public function test_simulated_run_drives_plan_from_zero_to_full_completion(): void
    {
        $result = $this->runner()->run([
            'decomposed_plan' => $this->plan(),
            'area_id' => 'agentic_engineering_os',
            'executor' => new DeterministicPlanSliceCycleExecutor,
        ]);

        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        $this->assertSame(6, $result['cycles_run']);
        $this->assertSame(6, $result['delivered_count']);
        $this->assertSame(6, $result['total_slices']);
        $this->assertSame(100.0, $result['completion_pct']);
        $this->assertTrue($result['simulated']);
        // A simulated run NEVER claims real delivery, even at 100%.
        $this->assertFalse($result['claim_policy']['real_delivery_claimed']);
        $this->assertCount(6, $result['trace']);
        // Honest dependency order: S1 before S2 before S3.
        $order = array_column($result['trace'], 'slice_id');
        $this->assertLessThan(array_search('S2', $order, true), array_search('S1', $order, true));
        $this->assertLessThan(array_search('S3', $order, true), array_search('S2', $order, true));
    }

    public function test_completion_climbs_one_slice_at_a_time(): void
    {
        $result = $this->runner()->run([
            'decomposed_plan' => $this->plan(),
            'area_id' => 'area_climb',
            'executor' => new DeterministicPlanSliceCycleExecutor,
        ]);
        $afters = array_column($result['trace'], 'delivered_after');
        $this->assertSame([1, 2, 3, 4, 5, 6], $afters);
    }

    public function test_stuck_slice_blocks_honestly_without_spinning(): void
    {
        // S3 always uses the provider router => never provider-proofed => never delivered.
        $executor = new DeterministicPlanSliceCycleExecutor([
            'S3' => DeterministicPlanSliceCycleExecutor::OUTCOME_ROUTER_USED,
        ]);
        $result = $this->runner()->run([
            'decomposed_plan' => $this->plan(),
            'area_id' => 'area_stuck',
            'executor' => $executor,
            'max_no_progress' => 2,
        ]);

        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_BLOCKED, $result['status']);
        // S1 + S2 delivered, S3 stuck.
        $this->assertSame(2, $result['delivered_count']);
        $this->assertNotSame(100.0, $result['completion_pct']);
        $hasNoProgress = false;
        foreach ($result['blockers'] as $b) {
            if (str_contains((string) $b, 'no_progress')) {
                $hasNoProgress = true;
            }
        }
        $this->assertTrue($hasNoProgress, 'expected a no_progress blocker');
    }

    public function test_missing_executor_blocks(): void
    {
        $result = $this->runner()->run([
            'decomposed_plan' => $this->plan(),
            'area_id' => 'area_noexec',
        ]);
        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_BLOCKED, $result['status']);
        $this->assertContains('executor_not_provided', $result['blockers']);
        $this->assertSame(0, $result['cycles_run']);
    }

    public function test_real_unsimulated_executor_claims_real_delivery_on_complete(): void
    {
        // A non-simulated executor that produces honest merged cycles => real delivery claimed.
        $executor = new class implements PlanSliceCycleExecutor
        {
            private int $n = 0;

            public function isSimulated(): bool
            {
                return false;
            }

            public function executeSlice(array $slice, array $context): array
            {
                $this->n++;
                $sid = (string) $slice['slice_id'];

                return [
                    'cycle_id' => 'real_'.$sid.'_'.$this->n,
                    'final_status' => 'cycle_completed',
                    'merge_performed' => true,
                    'blockers' => [],
                    'selected_finding' => ['finding_id' => $sid, 'title' => 'f'.$sid],
                    'changed_files' => ['app/Real/'.$sid.'.php'],
                    'validation' => ['passed' => true, 'commands' => ['php artisan test'], 'results' => [['ok' => true]]],
                    'merge_governance' => ['status' => 'merged', 'merge_commit' => 'real'.$sid],
                    'result_bridge_id' => 'rb_'.$sid,
                    'inbox_item_id' => 'inbox_'.$sid,
                    'owner_flow' => ['provider_router_used' => false],
                    'owner_result' => ['runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 3]]],
                ];
            }
        };

        $result = $this->runner()->run([
            'decomposed_plan' => $this->plan(),
            'area_id' => 'area_real',
            'executor' => $executor,
        ]);

        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        $this->assertFalse($result['simulated']);
        $this->assertTrue($result['claim_policy']['real_delivery_claimed']);
    }

    public function test_owner_flow_adapter_passes_cycle_through_and_advances(): void
    {
        // A blocked owner-flow report yields no delivery; a merged one delivers.
        $ownerFlow = new class implements Ap786OwnerFlowRunner
        {
            public function execute(array $input): array
            {
                $sid = (string) ($input['finding']['finding_id'] ?? '');

                // S1 merges for real; everything else blocks (no provider capacity).
                if ($sid === 'S1') {
                    return [
                        'cycle_id' => 'of_'.$sid,
                        'final_status' => 'cycle_completed',
                        'merge_performed' => true,
                        'blockers' => [],
                        'changed_files' => ['app/Of/'.$sid.'.php'],
                        'validation' => ['passed' => true, 'commands' => ['t'], 'results' => [['ok' => true]]],
                        'merge_governance' => ['status' => 'merged', 'merge_commit' => 'of'.$sid],
                        'result_bridge_id' => 'rb_'.$sid,
                        'owner_flow' => ['provider_router_used' => false],
                        'owner_result' => ['runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 1]]],
                    ];
                }

                return [
                    'cycle_id' => 'of_'.$sid,
                    'final_status' => 'blocked',
                    'merge_performed' => false,
                    'blockers' => ['provider_driver_missing'],
                    'changed_files' => [],
                    'owner_flow' => ['provider_router_used' => false],
                ];
            }
        };

        $executor = new OwnerFlowPlanSliceCycleExecutor($ownerFlow);
        $this->assertFalse($executor->isSimulated());

        $result = $this->runner()->run([
            'decomposed_plan' => $this->plan(),
            'area_id' => 'area_ownerflow',
            'executor' => $executor,
            'max_no_progress' => 2,
        ]);

        // S1 delivered for real; S2 cannot (blocked owner flow) => honest partial/blocked, never complete.
        $this->assertNotSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        $this->assertSame(1, $result['delivered_count']);
        $this->assertFalse($result['simulated']);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
