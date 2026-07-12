<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\FailureClass;
use App\Services\Ai\Rivals\Core\Preregistration;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Core\StatisticalPolicy;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StatisticalPolicyTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_stats_policy_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_rivals.claim.min_distinct_cases_internal', 3);
        config()->set('atlas_rivals.claim.max_ci_width_internal', 0.5);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_preregistered_three_case_sample_passes_precision_gate(): void
    {
        [$plan, $receipts] = $this->sample(['c1', 'c2', 'c3']);
        $analysis = (new StatisticalPolicy)->evaluate($plan, $receipts);

        $this->assertTrue($analysis['adequate'], implode(',', $analysis['blockers']));
        $this->assertSame(0.90, $analysis['preregistration']['target_power']);
        $this->assertSame(9, $analysis['segments'][0]['n']);
        $this->assertLessThanOrEqual(0.5, $analysis['segments'][0]['wilson_95']['width']);
    }

    public function test_attrition_and_duplicate_case_repetition_are_not_collapsed_into_a_green_rate(): void
    {
        [$plan, $receipts] = $this->sample(['c1', 'c2', 'c3']);
        array_pop($receipts);
        $receipts[] = $receipts[0];

        $analysis = (new StatisticalPolicy)->evaluate($plan, $receipts);

        $this->assertFalse($analysis['adequate']);
        $this->assertNotEmpty(preg_grep('/itt_denominator_incomplete/', $analysis['blockers']));
        $this->assertNotEmpty(preg_grep('/pseudoreplication_duplicate_unit/', $analysis['blockers']));
    }

    public function test_holm_controls_a_family_of_comparisons_before_significance_is_reported(): void
    {
        [$plan, $receipts] = $this->sample(['c1', 'c2', 'c3'], [
            ['id' => 'atlas_vs_bare', 'p_value' => 0.010],
            ['id' => 'atlas_vs_frontier', 'p_value' => 0.049],
            ['id' => 'atlas_vs_human', 'p_value' => 0.200],
        ]);

        $analysis = (new StatisticalPolicy)->evaluate($plan, $receipts, [
            ['id' => 'atlas_vs_bare', 'p_value' => 0.010],
            ['id' => 'atlas_vs_frontier', 'p_value' => 0.049],
            ['id' => 'atlas_vs_human', 'p_value' => 0.200],
        ]);

        $this->assertFalse($analysis['adequate']);
        $this->assertSame('holm', $analysis['multiplicity']['method']);
        $this->assertSame(0.03, $analysis['comparisons'][0]['adjusted_p_value']);
        $this->assertFalse($analysis['comparisons'][1]['significant']);
        $this->assertNotEmpty(preg_grep('/multiplicity_not_significant_after_holm/', $analysis['blockers']));
    }

    public function test_small_or_environment_dominated_sample_is_not_ready(): void
    {
        [$plan, $receipts] = $this->sample(['only_case']);
        $receipts[0] = $this->receipt($plan->runId(), 'only_case', 1, 'error', FailureClass::ENVIRONMENT);
        $analysis = (new StatisticalPolicy)->evaluate($plan, $receipts);

        $this->assertFalse($analysis['adequate']);
        $this->assertNotEmpty(preg_grep('/distinct_cases_below_min/', $analysis['blockers']));
        $this->assertNotEmpty(preg_grep('/environment_failure_rate_exceeded/', $analysis['blockers']));
    }

    /** @return array{RunPlan, list<RunReceipt>} */
    private function sample(array $cases, array $comparisons = []): array
    {
        $plan = RunPlan::make(
            'tau2_bench',
            $cases,
            [(new ArmRegistry)->parse('claude_sonnet_5@bare', 'tau2_bench')],
            3,
            ['max_usd' => 1.0, 'max_minutes' => 5],
            1,
            null,
            null,
            $comparisons,
        );
        $preregistration = Preregistration::fromPlan($plan);
        $data = $plan->data;
        $data['preregistration_hash'] = $preregistration->hash();
        $plan = RunPlan::fromArray($data);
        $plan->persist();
        $preregistration->persist();
        $receipts = [];
        foreach ($cases as $case) {
            for ($rep = 1; $rep <= 3; $rep++) {
                $receipts[] = $this->receipt($plan->runId(), $case, $rep);
            }
        }

        return [$plan, $receipts];
    }

    private function receipt(
        string $runId,
        string $case,
        int $rep,
        string $status = 'success',
        ?string $failureClass = null,
    ): RunReceipt {
        return RunReceipt::fromArray([
            'schema_version' => SchemaContract::RUN_RECEIPT,
            'run_id' => $runId,
            'case_id' => $case,
            'task_type' => 'tool_use_function_calling',
            'arm_id' => 'claude_sonnet_5@bare',
            'repetition' => $rep,
            'status' => $status,
            'failure_class' => $failureClass,
            'wall_ms' => 1,
            'tokens_in' => 10,
            'tokens_out' => 2,
            'cost_usd' => 0.01,
            'field_presence' => [
                'wall_ms' => ['present' => true, 'reason' => null],
                'tokens_in' => ['present' => true, 'reason' => null],
                'tokens_out' => ['present' => true, 'reason' => null],
                'cost_usd' => ['present' => true, 'reason' => null],
            ],
            'claim_tier' => 'production',
            'harness_only' => false,
            'artifacts' => [],
            'started_at' => null,
            'finished_at' => null,
        ]);
    }
}
