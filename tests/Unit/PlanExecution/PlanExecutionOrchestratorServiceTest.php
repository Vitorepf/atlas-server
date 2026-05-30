<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDeliveryCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanExecutionOrchestratorService;
use PHPUnit\Framework\TestCase;

/**
 * Duck-typed double for the `final` operational cert (certify(array): array).
 */
final class OrchestratorFakeOperationalCert
{
    public function __construct(public string $verdict = AreaFocusLoopOperationalCertificationService::STATUS_OPERATIONAL) {}

    public function certify(array $input = []): array
    {
        return ['status' => $this->verdict];
    }
}

/**
 * Duck-typed double for the `final` AP-786 real-cycle cert. Returns TOP-LEVEL
 * cycles[] carrying selected_finding.finding_id + status, mirroring the real
 * join key the certification service uses.
 */
final class OrchestratorFakeRealCycleCert
{
    /** @var array<string,array<string,string>> session_id => [finding_id => status] */
    public array $bySession = [];

    public function certify(array $input = []): array
    {
        $sessionId = (string) ($input['session_id'] ?? '');
        $cycles = [];
        foreach ($this->bySession[$sessionId] ?? [] as $findingId => $status) {
            $cycles[] = [
                'cycle_id' => 'c-'.$findingId,
                'status' => $status,
                'selected_finding' => ['finding_id' => $findingId],
            ];
        }

        return ['status' => Ap786RealCycleCertificationService::STATUS_CERTIFIED, 'cycles' => $cycles];
    }
}

final class PlanExecutionOrchestratorServiceTest extends TestCase
{
    private string $storageDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/atlas_plan_exec_orch_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->storageDir !== '' && is_dir($this->storageDir)) {
            $this->rrmdir($this->storageDir);
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Build-plan markdown with a parseable section 6 table + section 10 arrows,
     * so the decomposer yields STATUS_COMPLETE with two ordered slices.
     */
    private function buildPlanMd(): string
    {
        return "---\nid: ORCH-PLAN-1\ntitle: Orchestrator E2E plan\n---\n\n"
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."|---|---|---|---|\n"
            ."| S1 | Build the foundation service | Tests green for S1 | atlas_dev |\n"
            ."| S2 | Build the wiring on top | Tests green for S2 | atlas_dev |\n\n"
            ."## 10. Sequenciamento\n\n"
            ."S1 -> S2\n";
    }

    /**
     * @return callable(array<string,mixed>):array<string,mixed>
     */
    private function slicedPlanner(): callable
    {
        return static function (array $input): array {
            return [
                'schema_version' => FindingSlicePlannerService::PLAN_SCHEMA,
                'finding_id' => (string) ($input['finding']['finding_id'] ?? ''),
                'decomposition_status' => FindingSlicePlannerService::STATUS_SLICED,
                'slices' => [[
                    'slice_id' => 'x',
                    'allowed_files' => ['app/Services/Foo.php'],
                ]],
                'blockers' => [],
            ];
        };
    }

    private function decomposer(): BuildPlanDecomposerService
    {
        $d = new BuildPlanDecomposerService;
        $d->setSlicePlannerCallableForTesting($this->slicedPlanner());

        return $d;
    }

    private function tracker(): PlanCompletionTrackerService
    {
        $t = new PlanCompletionTrackerService;
        $t->setStorageRootForTesting($this->storageDir);

        return $t;
    }

    private function certification(
        OrchestratorFakeOperationalCert $op,
        OrchestratorFakeRealCycleCert $cert,
        PlanCompletionTrackerService $tracker,
    ): PlanDeliveryCertificationService {
        $c = new PlanDeliveryCertificationService;
        $c->setOperationalCertForTesting($op);
        $c->setRealCycleCertForTesting($cert);
        // Bridge the real tracker's rollup(string,string,array) into the cert's
        // rollup(array) seam, exactly as the orchestrator does in production.
        $c->setTrackerForTesting(new class($tracker)
        {
            public function __construct(private PlanCompletionTrackerService $tracker) {}

            public function rollup(array $input): array
            {
                $plan = is_array($input['decomposed_plan'] ?? null) ? $input['decomposed_plan'] : [];

                return $this->tracker->rollup((string) ($plan['plan_id'] ?? ''), (string) ($input['area_id'] ?? ''), $plan);
            }
        });

        return $c;
    }

    private function orchestrator(
        ?PlanCompletionTrackerService $tracker = null,
        ?OrchestratorFakeOperationalCert $op = null,
        ?OrchestratorFakeRealCycleCert $cert = null,
    ): PlanExecutionOrchestratorService {
        $tracker ??= $this->tracker();
        $op ??= new OrchestratorFakeOperationalCert;
        $cert ??= new OrchestratorFakeRealCycleCert;

        $o = new PlanExecutionOrchestratorService;
        $o->setDecomposerForTesting($this->decomposer());
        $o->setTrackerForTesting($tracker);
        $o->setCertificationForTesting($this->certification($op, $cert, $tracker));

        return $o;
    }

    public function test_decompose_stage_delegates_and_returns_decomposed_plan(): void
    {
        $plan = $this->orchestrator()->decompose(['build_plan_md' => $this->buildPlanMd()]);

        $this->assertSame(BuildPlanDecomposerService::PLAN_SCHEMA, $plan['schema_version']);
        $this->assertSame('ORCH-PLAN-1', $plan['plan_id']);
        $this->assertSame(BuildPlanDecomposerService::STATUS_COMPLETE, $plan['decomposition_status']);
        $this->assertCount(2, $plan['slices']);
    }

    public function test_e2e_blocked_decomposition_stops_pipeline_no_track_no_certify(): void
    {
        // No source => decomposer returns blocked.
        $out = $this->orchestrator()->execute(['build_plan_md' => '', 'area_id' => 'a']);

        $this->assertSame(PlanExecutionOrchestratorService::STATUS_BLOCKED, $out['orchestration_status']);
        $this->assertSame(PlanExecutionOrchestratorService::STAGE_DECOMPOSE, $out['stage_reached']);
        $this->assertNull($out['completion']);
        $this->assertNull($out['certification']);
        $this->assertNotEmpty($out['blockers']);
        $this->assertStringStartsWith('decompose:', $out['blockers'][0]);
    }

    public function test_e2e_no_delivered_slices_is_partial_not_complete(): void
    {
        // Operational gate green, real plan decomposed, but no loop cycles recorded
        // => tracker reports planned slices => certification partial.
        $out = $this->orchestrator()->execute([
            'build_plan_md' => $this->buildPlanMd(),
            'area_id' => 'agentic_engineering_os',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true],
        ]);

        $this->assertSame(PlanExecutionOrchestratorService::STATUS_PARTIAL, $out['orchestration_status']);
        $this->assertSame(PlanExecutionOrchestratorService::STAGE_CERTIFY, $out['stage_reached']);
        $this->assertSame('partial', $out['certification']['status']);
        $this->assertIsArray($out['completion']);
        $this->assertSame(0, $out['certification']['delivered_slices']);
    }

    public function test_e2e_operational_gate_blocked_surfaces_blocked(): void
    {
        $op = new OrchestratorFakeOperationalCert(AreaFocusLoopOperationalCertificationService::STATUS_BLOCKED);

        $out = $this->orchestrator(null, $op)->execute([
            'build_plan_md' => $this->buildPlanMd(),
            'area_id' => 'a',
            'integration_check' => ['green' => true],
        ]);

        $this->assertSame(PlanExecutionOrchestratorService::STATUS_BLOCKED, $out['orchestration_status']);
        $this->assertSame('blocked', $out['certification']['status']);
        $hasGateBlocker = false;
        foreach ($out['blockers'] as $b) {
            if (str_contains((string) $b, 'operational_gate_not_operational')) {
                $hasGateBlocker = true;
            }
        }
        $this->assertTrue($hasGateBlocker);
    }

    public function test_e2e_full_delivery_with_recorded_cycles_is_complete(): void
    {
        $tracker = $this->tracker();
        $op = new OrchestratorFakeOperationalCert;
        $cert = new OrchestratorFakeRealCycleCert;
        $cert->bySession['sess-1'] = [
            'S1' => Ap786RealCycleCertificationService::STATUS_CERTIFIED,
            'S2' => Ap786RealCycleCertificationService::STATUS_CERTIFIED,
        ];

        $orch = $this->orchestrator($tracker, $op, $cert);

        // First decompose so we have the plan to record cycles against.
        $plan = $orch->decompose(['build_plan_md' => $this->buildPlanMd()]);

        // Record one REAL merged cycle per slice via the orchestrator's tracker seam.
        foreach (['S1', 'S2'] as $sliceId) {
            $orch->recordCycle([
                'decomposed_plan' => $plan,
                'area_id' => 'agentic_engineering_os',
                'cycle' => $this->mergedCycle($sliceId),
            ]);
        }

        $out = $orch->execute([
            'build_plan_md' => $this->buildPlanMd(),
            'area_id' => 'agentic_engineering_os',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true],
        ]);

        $this->assertSame(PlanExecutionOrchestratorService::STATUS_COMPLETE, $out['orchestration_status']);
        $this->assertSame('complete', $out['certification']['status']);
        $this->assertSame(2, $out['certification']['delivered_slices']);
        $this->assertSame(2, $out['certification']['total_slices']);
    }

    public function test_e2e_result_hash_is_deterministic(): void
    {
        $make = function (): array {
            return $this->orchestrator()->execute([
                'build_plan_md' => $this->buildPlanMd(),
                'area_id' => 'agentic_engineering_os',
                'session_ids' => ['sess-1'],
                'integration_check' => ['green' => true],
            ]);
        };
        $a = $make();
        $b = $make();
        $this->assertStringStartsWith('sha256:', $a['orchestration_hash']);
        $this->assertSame($a['orchestration_hash'], $b['orchestration_hash']);
        $this->assertSame(PlanExecutionOrchestratorService::RESULT_SCHEMA, $a['schema_version']);
    }

    public function test_track_stage_reads_ledger_for_plan_area(): void
    {
        $tracker = $this->tracker();
        $orch = $this->orchestrator($tracker);
        $plan = $orch->decompose(['build_plan_md' => $this->buildPlanMd()]);

        $orch->recordCycle([
            'decomposed_plan' => $plan,
            'area_id' => 'agentic_engineering_os',
            'cycle' => $this->mergedCycle('S1'),
        ]);

        $ledger = $orch->track('ORCH-PLAN-1', 'agentic_engineering_os', $plan);

        $this->assertSame(PlanCompletionTrackerService::LEDGER_SCHEMA, $ledger['schema_version']);
        $this->assertSame(2, $ledger['total_slices']);
        $this->assertSame(1, $ledger['status_counts']['delivered']);
    }

    /**
     * A real merged cycle shape the tracker derives provider_proof + acceptance
     * from (router unused, real diff, validation passed with evidence).
     *
     * @return array<string,mixed>
     */
    private function mergedCycle(string $sliceId): array
    {
        return [
            'cycle_id' => 'cyc-'.$sliceId,
            'final_status' => 'cycle_completed',
            'merge_performed' => true,
            'merge_governance' => ['status' => 'merged', 'merge_commit' => 'deadbeef'.$sliceId],
            'changed_files' => ['app/Services/Foo.php'],
            'owner_flow' => ['provider_router_used' => false],
            'selected_finding' => ['finding_id' => $sliceId, 'title' => 'F '.$sliceId],
            'validation' => ['passed' => true, 'commands' => ['php artisan test'], 'results' => ['ok']],
            'result_bridge_id' => 'rb-'.$sliceId,
            'owner_result' => [
                'runtime_invocation' => [
                    'command_result' => ['owner_cli_provider_calls' => 1],
                ],
            ],
        ];
    }
}
