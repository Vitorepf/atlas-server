<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\FailureClass;
use App\Services\Ai\Rivals\Core\ReportBuilder;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReportBuilderV2Test extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_report_v2_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_report_has_explicit_denominators_intervals_costs_and_deterministic_exports(): void
    {
        $plan = RunPlan::make(
            'tau2_bench',
            ['c1', 'c2'],
            [(new ArmRegistry)->parse('claude_sonnet_5@bare', 'tau2_bench')],
            3,
            ['max_usd' => 30.0, 'max_minutes' => 5],
            1,
        );
        $runId = $plan->persist();
        $statuses = [
            ['success', null],
            ['success', null],
            ['failure', FailureClass::MODEL],
            ['error', FailureClass::ENVIRONMENT],
            ['timeout', FailureClass::TIMEOUT],
            ['success', null],
        ];
        foreach ($statuses as $index => [$status, $failureClass]) {
            $case = $index < 3 ? 'c1' : 'c2';
            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => $case,
                'task_type' => 'tool_use_function_calling',
                'arm_id' => 'claude_sonnet_5@bare',
                'repetition' => ($index % 3) + 1,
                'status' => $status,
                'failure_class' => $failureClass,
                'wall_ms' => ($index + 1) * 1000,
                'tokens_in' => ($index + 1) * 10,
                'tokens_out' => ($index + 1) * 2,
                'cost_usd' => (float) ($index + 1),
                'field_presence' => [
                    'wall_ms' => ['present' => true, 'reason' => null],
                    'tokens_in' => ['present' => $index !== 5, 'reason' => $index === 5 ? 'missing' : null],
                    'tokens_out' => ['present' => $index !== 5, 'reason' => $index === 5 ? 'missing' : null],
                    'cost_usd' => ['present' => true, 'reason' => null],
                ],
                'claim_tier' => 'production',
                'harness_only' => false,
                'artifacts' => [],
                'started_at' => null,
                'finished_at' => null,
            ])->append();
        }
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => true,
            'claim_tier' => 'production',
            'internal_claim_allowed' => false,
            'public_claim_allowed' => false,
            'internal_claim_blockers' => ['sample_inadequate'],
            'not_ready_reasons' => ['sample_inadequate'],
            'claim_scope' => ['suite' => 'tau2_bench'],
            'statistical_analysis' => ['adequate' => false, 'blockers' => ['sample_inadequate'], 'segments' => []],
            'adjudicated_at' => '2026-07-09T00:00:00Z',
        ]));

        $report = (new ReportBuilder)->build($runId);
        $row = $report['rows'][0];
        $this->assertSame(6, $row['planned_attempts']);
        $this->assertSame(6, $row['observed_attempts']);
        $this->assertSame(3, $row['successes']);
        $this->assertSame(0.5, $row['success_rate_itt']);
        $this->assertLessThan(1.0, $row['success_rate_wilson_95']['high']);
        $this->assertGreaterThan(0.0, $row['success_rate_wilson_95']['low']);
        $this->assertSame(21.0, $row['total_cost_usd']);
        $this->assertSame(10.5, $row['cost_per_task']);
        $this->assertSame(3.5, $row['median_cost_usd']);
        $this->assertSame(3500.0, $row['median_wall_ms']);
        $this->assertEqualsWithDelta(1 / 6, $row['environment_failure_rate'], 0.0001);
        $this->assertSame(5, $row['tokens_coverage']['in']);
        $this->assertFileExists(RunPaths::reportPath($runId));
        $this->assertFileExists(RunPaths::reportMarkdownPath($runId));
        $this->assertFileExists(RunPaths::reportCsvPath($runId));

        $firstHash = $report['report_hash'];
        $second = (new ReportBuilder)->build($runId);
        $this->assertSame($firstHash, $second['report_hash']);
        $this->assertStringContainsString('NOT READY FOR PRODUCTION CLAIM', file_get_contents(RunPaths::reportMarkdownPath($runId)));
        $markdown = (string) file_get_contents(RunPaths::reportMarkdownPath($runId));
        $this->assertStringContainsString('## Statistical analysis', $markdown);
        $this->assertStringContainsString('tokens_cov_in/out', $markdown);
        $csv = (string) file_get_contents(RunPaths::reportCsvPath($runId));
        $this->assertStringContainsString('tokens_coverage_in', $csv);
        $this->assertStringContainsString('internal_claim_allowed', $csv);

        $all = (new ReportBuilder)->buildAll();
        $this->assertFalse($all['claim_allowed']);
        $this->assertCount(1, $all['segments']);
    }
}
