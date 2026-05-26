<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PlanVisible;

use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanProjectionService;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasDevPlanProjectionServiceTest extends TestCase
{
    private AtlasDevPlanProjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasDevPlanProjectionService;
    }

    public function test_project_returns_plan_visible_with_pending_status(): void
    {
        $plan = $this->service->project(
            runId: 'run-1',
            taskContractHash: 'sha256:contract',
            defaultRiskBand: 'medium',
            proposal: [
                'target_files' => ['app/Foo.php'],
                'tests_to_run' => ['tests/FooTest.php'],
                'proposed_diff_summary' => 'add bar()',
            ],
        );

        $this->assertInstanceOf(PlanVisible::class, $plan);
        $this->assertTrue($plan->isPending());
        $this->assertSame('run-1', $plan->runId);
        $this->assertSame('sha256:contract', $plan->taskContractHash);
        $this->assertSame(['app/Foo.php'], $plan->targetFiles);
        $this->assertSame(['tests/FooTest.php'], $plan->testsToRun);
        $this->assertSame(PlanVisible::RISK_BAND_MEDIUM, $plan->riskBand);
        $this->assertNotEmpty($plan->planHash);
    }

    public function test_project_collapses_critical_to_high_risk_band(): void
    {
        $plan = $this->service->project(
            runId: 'run-1',
            taskContractHash: 'sha256:c',
            defaultRiskBand: 'critical',
            proposal: [
                'target_files' => ['app/Foo.php'],
                'tests_to_run' => ['tests/FooTest.php'],
                'proposed_diff_summary' => 'risky change',
            ],
        );

        $this->assertSame(PlanVisible::RISK_BAND_HIGH, $plan->riskBand);
    }

    public function test_project_uses_explicit_risk_band_override(): void
    {
        $plan = $this->service->project(
            runId: 'run-1',
            taskContractHash: 'sha256:c',
            defaultRiskBand: 'high',
            proposal: [
                'target_files' => ['app/Foo.php'],
                'tests_to_run' => [],
                'proposed_diff_summary' => 'tiny tweak',
                'risk_band' => 'low',
            ],
        );

        $this->assertSame(PlanVisible::RISK_BAND_LOW, $plan->riskBand);
    }

    public function test_project_defaults_unknown_risk_band_to_medium(): void
    {
        $plan = $this->service->project(
            runId: 'run-1',
            taskContractHash: 'sha256:c',
            defaultRiskBand: 'wibble',
            proposal: [
                'target_files' => ['app/Foo.php'],
                'tests_to_run' => ['t.php'],
                'proposed_diff_summary' => 'x',
            ],
        );

        $this->assertSame(PlanVisible::RISK_BAND_MEDIUM, $plan->riskBand);
    }

    public function test_project_rejects_empty_run_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('run_id must not be empty');

        $this->service->project(
            runId: '',
            taskContractHash: 'sha256:c',
            defaultRiskBand: 'medium',
            proposal: [
                'target_files' => ['app/Foo.php'],
                'tests_to_run' => ['t.php'],
                'proposed_diff_summary' => 'x',
            ],
        );
    }

    public function test_project_rejects_empty_task_contract_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('task_contract_hash must not be empty');

        $this->service->project(
            runId: 'r',
            taskContractHash: '',
            defaultRiskBand: 'medium',
            proposal: [
                'target_files' => ['app/Foo.php'],
                'tests_to_run' => ['t.php'],
                'proposed_diff_summary' => 'x',
            ],
        );
    }

    public function test_project_rejects_non_string_target_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('target_files[1] must be a non-empty string');

        $this->service->project(
            runId: 'r',
            taskContractHash: 'h',
            defaultRiskBand: 'medium',
            proposal: [
                'target_files' => ['app/Foo.php', 42],
                'tests_to_run' => ['t.php'],
                'proposed_diff_summary' => 'x',
            ],
        );
    }

    public function test_project_rejects_non_array_target_files(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('target_files must be an array');

        $this->service->project(
            runId: 'r',
            taskContractHash: 'h',
            defaultRiskBand: 'medium',
            proposal: [
                'target_files' => 'not-an-array',
                'tests_to_run' => ['t.php'],
                'proposed_diff_summary' => 'x',
            ],
        );
    }

    public function test_project_is_idempotent_for_identical_input(): void
    {
        $proposal = [
            'target_files' => ['app/Foo.php'],
            'tests_to_run' => ['tests/FooTest.php'],
            'proposed_diff_summary' => 'add bar()',
        ];

        $plan1 = $this->service->project('r', 'h', 'medium', $proposal);
        $plan2 = $this->service->project('r', 'h', 'medium', $proposal);

        $this->assertSame($plan1->hash(), $plan2->hash(), 'identical input must produce identical plan hash');
    }

    public function test_project_hash_changes_when_target_files_change(): void
    {
        $base = $this->service->project('r', 'h', 'medium', [
            'target_files' => ['app/Foo.php'],
            'tests_to_run' => ['t.php'],
            'proposed_diff_summary' => 'x',
        ]);
        $changed = $this->service->project('r', 'h', 'medium', [
            'target_files' => ['app/Bar.php'],
            'tests_to_run' => ['t.php'],
            'proposed_diff_summary' => 'x',
        ]);

        $this->assertNotSame($base->hash(), $changed->hash());
    }
}
