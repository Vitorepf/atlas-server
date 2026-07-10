<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\EnterpriseReportBuilder;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EnterpriseReportBuilderTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_enterprise_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_schema_requires_ten_suite_rows_and_rejects_claim_allowed_true(): void
    {
        $payload = $this->minimalEnterprisePayload(suiteCount: 9);
        $violations = SchemaContract::validate($payload, SchemaContract::ENTERPRISE_REPORT);
        $this->assertContains('enterprise_suite_rows_count:9', $violations);

        $payload = $this->minimalEnterprisePayload(suiteCount: 10);
        $payload['claim_allowed'] = true;
        $violations = SchemaContract::validate($payload, SchemaContract::ENTERPRISE_REPORT);
        $this->assertContains('enterprise_claim_allowed_must_be_false', $violations);

        $payload = $this->minimalEnterprisePayload(suiteCount: 10);
        $this->assertSame([], SchemaContract::validate($payload, SchemaContract::ENTERPRISE_REPORT));
    }

    public function test_always_emits_ten_suite_rows_when_storage_empty(): void
    {
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertSame(SchemaContract::ENTERPRISE_REPORT, $report['schema_version']);
        $this->assertCount(10, $report['suite_rows']);
        $this->assertFalse($report['claim_allowed']);
        $this->assertContains('aggregate_view_claims_live_per_run', $report['claim_blockers']);
        foreach ($report['suite_rows'] as $row) {
            $this->assertSame('not_run', $row['status']);
        }
        $this->assertSame(
            (new SuiteRegistry)->externalSuiteIds(),
            array_column($report['suite_rows'], 'suite_id'),
        );
    }

    public function test_marks_pipeline_valid_run_as_ok_and_others_not_run(): void
    {
        $runId = $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true);

        $report = (new EnterpriseReportBuilder)->build();
        $bySuite = [];
        foreach ($report['suite_rows'] as $row) {
            $bySuite[$row['suite_id']] = $row;
        }
        $this->assertSame('ok', $bySuite['bfcl']['status']);
        $this->assertSame($runId, $bySuite['bfcl']['run_id']);
        $this->assertTrue($bySuite['bfcl']['pipeline_valid']);
        $this->assertSame('not_run', $bySuite['tau2_bench']['status']);
        $this->assertContains($runId, $report['included_run_ids']);
    }

    public function test_claim_allowed_is_always_false(): void
    {
        $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true, internalClaim: true);
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertFalse($report['claim_allowed']);
    }

    public function test_writes_json_markdown_csv(): void
    {
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertFileExists(RunPaths::enterpriseReportPath());
        $this->assertFileExists(RunPaths::enterpriseMarkdownPath());
        $this->assertFileExists(RunPaths::enterpriseCsvPath());
        $md = file_get_contents(RunPaths::enterpriseMarkdownPath());
        $this->assertStringContainsString('Rivals Fase A — Relatório Empresarial', $md);
        $this->assertStringContainsString('bfcl', $md);
        $this->assertStringContainsString('claim_allowed: false', $md);
        $csv = file_get_contents(RunPaths::enterpriseCsvPath());
        $this->assertStringContainsString('suite_id', $csv);
        $this->assertSame($report['report_hash'], json_decode(
            (string) file_get_contents(RunPaths::enterpriseReportPath()),
            true,
        )['report_hash']);
    }

    public function test_missing_tokens_surface_as_missing_data_status(): void
    {
        $this->seedSuiteRun('live_code_bench', pipelineValid: true, tokensPresent: false);
        $report = (new EnterpriseReportBuilder)->build();
        $row = collect($report['suite_rows'])->firstWhere('suite_id', 'live_code_bench');
        $this->assertSame('missing_data', $row['status']);
        $this->assertContains('tokens_in', $row['missing_fields']);
        $this->assertNotEmpty(array_filter(
            $report['gaps'],
            fn (string $gap): bool => str_contains($gap, 'missing_data:live_code_bench'),
        ));
    }

    public function test_uplift_section_lists_five_families(): void
    {
        $report = (new EnterpriseReportBuilder)->build();
        $families = (array) config('atlas_rivals.uplift_families', []);
        $this->assertCount(count($families), $report['atlas_uplift']['families']);
        $this->assertSame(5, count($report['atlas_uplift']['families']));
        foreach ($report['atlas_uplift']['families'] as $family) {
            $this->assertSame('not_run', $family['status']);
        }
    }

    public function test_single_model_battery_when_only_one_bare_model(): void
    {
        $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true);
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertSame('single_model_battery', $report['model_matrix']['mode']);
        $this->assertNotEmpty($report['model_matrix']['model_id']);
    }

    /** @return array<string, mixed> */
    private function minimalEnterprisePayload(int $suiteCount): array
    {
        $ids = array_slice((new SuiteRegistry)->externalSuiteIds(), 0, $suiteCount);
        while (count($ids) < $suiteCount) {
            $ids[] = 'suite_'.count($ids);
        }
        $rows = array_map(fn (string $id): array => [
            'suite_id' => $id,
            'status' => 'not_run',
            'run_id' => null,
            'success_rate_itt' => null,
            'median_wall_ms' => null,
            'tokens_in_avg' => null,
            'tokens_out_avg' => null,
            'cost_per_task' => null,
            'cost_basis' => null,
            'env_failure_rate' => null,
            'missing_fields' => [],
            'pipeline_valid' => false,
            'internal_claim_allowed' => false,
        ], $ids);

        return [
            'schema_version' => SchemaContract::ENTERPRISE_REPORT,
            'built_at' => '2026-07-10T00:00:00Z',
            'report_hash' => 'pending',
            'claim_allowed' => false,
            'claim_blockers' => ['aggregate_view_claims_live_per_run'],
            'executive_summary' => [
                'primary_model' => 'verboo_kimi_k2_7',
                'suites_ok' => 0,
                'suites_not_run' => $suiteCount,
                'suites_failed' => 0,
                'suites_missing_data' => 0,
                'suites_blocked' => 0,
                'provider_binding' => 'hermes+verboo',
            ],
            'suite_rows' => $rows,
            'model_matrix' => ['mode' => 'single_model_battery', 'model_id' => 'verboo_kimi_k2_7', 'rows' => []],
            'atlas_uplift' => ['families' => []],
            'gaps' => [],
            'included_run_ids' => [],
            'excluded_run_ids' => [],
        ];
    }

    private function seedSuiteRun(
        string $suiteId,
        bool $pipelineValid,
        bool $tokensPresent,
        bool $internalClaim = false,
    ): string {
        $arm = (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', $suiteId);
        $plan = RunPlan::make(
            $suiteId,
            ['case_a', 'case_b', 'case_c'],
            [$arm],
            3,
            ['max_usd' => 10.0, 'max_minutes' => 30],
            1,
        );
        $runId = $plan->persist();
        for ($i = 0; $i < 3; $i++) {
            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => ['case_a', 'case_b', 'case_c'][$i],
                'task_type' => 'tool_use_function_calling',
                'arm_id' => $arm['arm_id'],
                'repetition' => 1,
                'status' => 'success',
                'failure_class' => null,
                'wall_ms' => 1000 * ($i + 1),
                'tokens_in' => $tokensPresent ? 100 * ($i + 1) : 0,
                'tokens_out' => $tokensPresent ? 20 * ($i + 1) : 0,
                'cost_usd' => 0.0,
                'field_presence' => [
                    'wall_ms' => ['present' => true, 'reason' => null],
                    'tokens_in' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : 'lcb_omits_usage',
                    ],
                    'tokens_out' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : 'lcb_omits_usage',
                    ],
                    'cost_usd' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? 'verboo_subscription_marginal' : 'usage_missing',
                    ],
                ],
                'claim_tier' => 'production',
                'harness_only' => false,
                'artifacts' => [],
                'started_at' => null,
                'finished_at' => null,
            ])->append();
        }

        $reportRows = [[
            'task_type' => 'tool_use_function_calling',
            'arm_id' => $arm['arm_id'],
            'success_rate_itt' => 1.0,
            'median_wall_ms' => 2000.0,
            'avg_tokens_in' => $tokensPresent ? 200.0 : null,
            'avg_tokens_out' => $tokensPresent ? 40.0 : null,
            'cost_per_task' => 0.0,
            'environment_failure_rate' => 0.0,
            'tokens_coverage' => [
                'in' => $tokensPresent ? 3 : 0,
                'out' => $tokensPresent ? 3 : 0,
                'n' => 3,
                'in_rate' => $tokensPresent ? 1.0 : 0.0,
                'out_rate' => $tokensPresent ? 1.0 : 0.0,
            ],
            'stability' => 1.0,
        ]];
        file_put_contents(RunPaths::reportPath($runId), json_encode([
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => $reportRows,
            'pipeline_valid' => $pipelineValid,
            'claim_tier' => 'production',
            'internal_claim_allowed' => $internalClaim,
            'public_claim_allowed' => false,
            'not_ready_reasons' => [],
            'claim_allowed' => $internalClaim,
            'claim_blockers' => [],
            'claim_scope' => ['suite' => $suiteId, 'models' => ['verboo_kimi_k2_7'], 'runtimes' => ['bare']],
            'statistical_analysis' => ['adequate' => true, 'blockers' => [], 'segments' => []],
            'missing_data_policy' => [],
            'report_hash' => 'fixture',
            'built_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));

        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => $pipelineValid,
            'claim_tier' => 'production',
            'internal_claim_allowed' => $internalClaim,
            'public_claim_allowed' => false,
            'internal_claim_blockers' => $internalClaim ? [] : ['fixture'],
            'not_ready_reasons' => $internalClaim ? [] : ['fixture'],
            'claim_scope' => [
                'suite' => $suiteId,
                'models' => ['verboo_kimi_k2_7'],
                'runtimes' => ['bare'],
                'task_types' => ['tool_use_function_calling'],
            ],
            'statistical_analysis' => ['adequate' => true, 'blockers' => [], 'segments' => []],
            'adjudicated_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));

        return $runId;
    }
}
