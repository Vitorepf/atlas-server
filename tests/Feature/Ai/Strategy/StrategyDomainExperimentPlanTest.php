<?php

namespace Tests\Feature\Ai\Strategy;

use App\Services\Ai\Strategy\ExperimentPlanService;
use App\Services\Ai\Strategy\StrategyDomainException;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainExperimentPlanTest extends TestCase
{
    use CreatesStrategyRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    public function test_experiment_plan_requires_hypothesis_metric_design(): void
    {
        /** @var ExperimentPlanService $svc */
        $svc = app(ExperimentPlanService::class);
        $plan = $svc->create([
            'hypothesis' => 'Adding X increases conversion 20%',
            'hypothesis_kind' => ExperimentPlanService::KIND_DEMAND,
            'success_metric' => ['name' => 'conversion_rate', 'target' => '+20%'],
            'design' => ['type' => 'ab_test', 'arms' => ['control', 'treatment']],
        ]);
        $this->assertSame(ExperimentPlanService::STATUS_PLANNED, $plan->status);
        $this->assertNotEmpty($plan->experiment_hash);
    }

    public function test_experiment_plan_rejects_metric_without_target(): void
    {
        /** @var ExperimentPlanService $svc */
        $svc = app(ExperimentPlanService::class);
        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'hypothesis' => 'h',
            'success_metric' => ['name' => 'conversion_rate'],
            'design' => ['type' => 'ab_test'],
        ]);
    }

    public function test_experiment_record_result_transitions_to_decided_with_decision_kind(): void
    {
        /** @var ExperimentPlanService $svc */
        $svc = app(ExperimentPlanService::class);
        $plan = $svc->create([
            'hypothesis' => 'Adding X increases conversion 20%',
            'hypothesis_kind' => ExperimentPlanService::KIND_DEMAND,
            'success_metric' => ['name' => 'conversion_rate', 'target' => '+20%'],
            'design' => ['type' => 'ab_test'],
        ]);

        $updated = $svc->recordResult(
            $plan,
            ['outcome' => 'positive', 'metric' => 'conversion_rate', 'observed' => '+22%'],
            ['kind' => ExperimentPlanService::DECISION_SCALE, 'rationale' => 'metric beat target'],
        );

        $this->assertSame(ExperimentPlanService::STATUS_DECIDED, $updated->status);
        $this->assertSame(ExperimentPlanService::DECISION_SCALE, $updated->decision['kind']);
        $this->assertSame('positive', $updated->result['outcome']);
    }

    public function test_experiment_inconclusive_outcome_results_in_inconclusive_status(): void
    {
        /** @var ExperimentPlanService $svc */
        $svc = app(ExperimentPlanService::class);
        $plan = $svc->create([
            'hypothesis' => 'Adding X increases conversion 20%',
            'hypothesis_kind' => ExperimentPlanService::KIND_DEMAND,
            'success_metric' => ['name' => 'conversion_rate', 'target' => '+20%'],
            'design' => ['type' => 'ab_test'],
        ]);

        $updated = $svc->recordResult(
            $plan,
            ['outcome' => 'inconclusive', 'metric' => 'conversion_rate'],
            ['kind' => ExperimentPlanService::DECISION_CONTINUE, 'rationale' => 'sample too small'],
        );

        $this->assertSame(ExperimentPlanService::STATUS_INCONCLUSIVE, $updated->status);
    }

    public function test_experiment_rejects_invalid_decision_kind(): void
    {
        /** @var ExperimentPlanService $svc */
        $svc = app(ExperimentPlanService::class);
        $plan = $svc->create([
            'hypothesis' => 'h',
            'success_metric' => ['name' => 'x', 'target' => 'y'],
            'design' => ['type' => 'ab_test'],
        ]);

        $this->expectException(StrategyDomainException::class);
        $svc->recordResult(
            $plan,
            ['outcome' => 'positive'],
            ['kind' => 'iterate-and-pray'],
        );
    }
}
