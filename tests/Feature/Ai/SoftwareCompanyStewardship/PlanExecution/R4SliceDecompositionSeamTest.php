<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship\PlanExecution;

use App\Services\Ai\Programming\AtlasDev\Pipeline\AtomicSemanticDecomposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtomicStep;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDrivenLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceDecompositionService;
use Tests\TestCase;

/**
 * FASE 1 wiring proof: a large multi-layer (R4) slice, run through the LIVE
 * PlanDrivenLoopRunnerService selection path, is decomposed into its FIRST atomic
 * <=R3 self-contained step BEFORE it reaches the executor — never the whole slice.
 *
 * The first case captures exactly what the live loop hands to the executor and
 * asserts atomicity (single real layer, <=5 files, contract-first kind). The
 * second drives that decomposed slice through the REAL AutonomousEvolutionSessionService
 * owner-flow machinery in dry-run (execute=false, no provider invoked) to prove the
 * atomic step flows through the proven path. The third pins the pass-through invariant.
 */
final class R4SliceDecompositionSeamTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_r4_decomp_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storageRoot);
        parent::tearDown();
    }

    /**
     * A single R4 slice touching 3 real layers across 6 implementation files plus a test.
     *
     * @return array<string,mixed>
     */
    private function r4Plan(): array
    {
        return [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'r4-multilayer-plan',
            'decomposition_status' => 'complete',
            'slices' => [[
                'slice_id' => 'S1',
                'sequence' => 1,
                'depends_on' => [],
                'objective' => 'Add a feature spanning service, db and api layers',
                'delivery' => 'service + migration + controller',
                'owner' => 'atlas_dev',
                'finding' => ['finding_id' => 'S1', 'title' => 'big slice', 'affected_files' => []],
                'finding_id' => 'S1',
                'acceptance_criteria' => ['behaviour proven by a test'],
                'allowed_files' => [
                    'app/Services/FeatureService.php',
                    'app/Services/FeatureHelper.php',
                    'database/migrations/2026_01_01_000000_add_feature.php',
                    'app/Models/Feature.php',
                    'app/Http/Controllers/FeatureController.php',
                    'routes/feature.php',
                    'tests/Unit/FeatureServiceTest.php',
                ],
            ]],
            'dependency_graph' => [],
            'plan_hash' => 'sha256:r4plan',
        ];
    }

    private function runner(PlanSliceCycleExecutor $executor): array
    {
        $tracker = new PlanCompletionTrackerService;
        $tracker->setStorageRootForTesting($this->storageRoot);
        $runner = new PlanDrivenLoopRunnerService;
        $runner->setTrackerForTesting($tracker);

        return $runner->run([
            'decomposed_plan' => $this->r4Plan(),
            'area_id' => 'r4_decomp_test',
            'executor' => $executor,
            'max_cycles' => 1,
        ]);
    }

    public function test_live_loop_hands_executor_an_atomic_r3_step_not_the_whole_slice(): void
    {
        $captured = [];
        $executor = new class($captured) implements PlanSliceCycleExecutor
        {
            /** @param array<int,array<string,mixed>> $captured */
            public function __construct(public array &$captured) {}

            public function isSimulated(): bool
            {
                return false;
            }

            public function executeSlice(array $slice, array $context): array
            {
                $this->captured[] = $slice;

                return [
                    'cycle_id' => 'cap_'.(string) ($slice['slice_id'] ?? ''),
                    'selected_finding' => ['finding_id' => (string) ($slice['finding_id'] ?? '')],
                    'final_status' => 'dry_run_planned',
                    'merge_performed' => false,
                    'blockers' => [],
                ];
            }
        };

        $this->runner($executor);

        $this->assertCount(1, $captured, 'exactly one slice executed this cycle');
        $atomic = $captured[0];

        // The whole R4 slice (7 allowed_files / 3 real layers) was NOT handed over.
        $this->assertNotSame(7, count($atomic['allowed_files']));

        // It is an atomic step of the parent S1 — slice_id preserved for the tracker join.
        $this->assertSame('S1', $atomic['slice_id']);
        $this->assertSame('S1', $atomic['atomic_step_of'] ?? null);
        $this->assertArrayHasKey('atomic_step', $atomic);
        $this->assertArrayHasKey('parent_slice', $atomic);

        // <=R3 invariants: <=5 files, single real layer.
        $files = $atomic['allowed_files'];
        $this->assertLessThanOrEqual(AtomicSemanticDecomposer::MAX_FILES_PER_STEP, count($files));
        $this->assertLessThan(
            PlanSliceDecompositionService::R4_LAYER_THRESHOLD,
            (new PlanSliceDecompositionService)->isR4($files) ? 3 : 1,
        );
        $this->assertFalse(
            (new PlanSliceDecompositionService)->isR4($files),
            'the atomic step must NOT itself be R4',
        );

        // First step is the contract (service layer, ordered first).
        $this->assertSame(AtomicStep::KIND_CONTRACT, $atomic['atomic_step']['kind']);
        $this->assertSame(0, $atomic['atomic_step']['order']);
        foreach ($files as $f) {
            $this->assertStringContainsString('app/Services/', $f, 'first atomic step is single-layer service');
        }
    }

    public function test_atomic_step_flows_through_real_owner_flow_dry_run(): void
    {
        $session = app(AutonomousEvolutionSessionService::class);
        $executor = new OwnerFlowPlanSliceCycleExecutor($session, false);

        $tracker = new PlanCompletionTrackerService;
        $tracker->setStorageRootForTesting($this->storageRoot);
        $runner = new PlanDrivenLoopRunnerService;
        $runner->setTrackerForTesting($tracker);

        $result = $runner->run([
            'decomposed_plan' => $this->r4Plan(),
            'area_id' => 'r4_decomp_test',
            'executor' => $executor,
            'max_cycles' => 1,
        ]);

        // It ran a real (non-simulated) cycle through the owner-flow machinery.
        $this->assertFalse($result['simulated']);
        $this->assertSame(1, $result['cycles_run']);
        $trace = $result['trace'][0] ?? [];
        // The cycle is keyed to the parent S1 (tracker join preserved).
        $this->assertSame('S1', $trace['slice_id'] ?? null);
        // Dry-run never blocks on the handoff gate the raw owner-flow path tripped on.
        $this->assertNotContains('ap726_handoff_hash_required', (array) ($result['blockers'] ?? []));
    }

    public function test_small_r3_slice_passes_through_unchanged(): void
    {
        $svc = new PlanSliceDecompositionService;
        $slice = [
            'slice_id' => 'S9',
            'allowed_files' => ['app/Services/SmallService.php', 'tests/Unit/SmallServiceTest.php'],
            'objective' => 'small change',
        ];

        $resolved = $svc->resolveExecutableSlice($slice);

        $this->assertSame($slice, $resolved, 'a <=R3 slice is returned verbatim, no decomposition');
        $this->assertArrayNotHasKey('atomic_step', $resolved);
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
