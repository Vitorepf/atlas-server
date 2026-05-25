<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendQualityBudgetGateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendQualityBudgetGateServiceTest extends TestCase
{
    public function test_valid_budget_report_passes(): void
    {
        $dir = $this->fixtureDir();
        $report = $dir.'/quality-budget-report.json';
        $this->writeReport($report, [
            'lcp_ms' => 1800,
            'inp_ms' => 90,
            'cls' => 0.02,
            'js_transfer_kb' => 180,
        ]);

        $payload = app(AtlasFrontendQualityBudgetGateService::class)->inspect($report);

        $this->assertSame(AtlasFrontendQualityBudgetGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_quality_budget_claim_allowed'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['quality_budget_hash']);
    }

    public function test_blocks_over_budget_metrics(): void
    {
        $dir = $this->fixtureDir();
        $report = $dir.'/quality-budget-report.json';
        $this->writeReport($report, [
            'critical_a11y_violations' => 1,
            'lcp_ms' => 4200,
            'text_overlap_count' => 2,
        ]);

        $payload = app(AtlasFrontendQualityBudgetGateService::class)->inspect($report);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.frontend_quality_budget_claim_allowed'));
        $this->assertContains('budget_failed_critical_a11y_violations', $payload['blockers']);
        $this->assertContains('budget_failed_lcp_ms', $payload['blockers']);
        $this->assertContains('budget_failed_text_overlap_count', $payload['blockers']);
    }

    public function test_operator_exception_downgrades_budget_failures_but_keeps_world_best_forbidden(): void
    {
        $dir = $this->fixtureDir();
        $report = $dir.'/quality-budget-report.json';
        $this->writeReport($report, [
            'lcp_ms' => 4200,
        ], ['operator_approved_exception' => true]);

        $payload = app(AtlasFrontendQualityBudgetGateService::class)->inspect($report);

        $this->assertSame('warning', $payload['status']);
        $this->assertContains('operator_approved_budget_exception', $payload['warnings']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.operator_exception_does_not_authorize_world_best_claim'));
    }

    public function test_template_is_not_budget_evidence(): void
    {
        $dir = $this->fixtureDir();

        $payload = app(AtlasFrontendQualityBudgetGateService::class)->writeTemplate($dir);

        $this->assertSame(AtlasFrontendQualityBudgetGateService::TEMPLATE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.template_is_not_budget_evidence'));
        $this->assertFileExists($dir.'/quality-budget-report.json');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @param  array<string,mixed>  $extra
     */
    private function writeReport(string $path, array $overrides = [], array $extra = []): void
    {
        $gate = app(AtlasFrontendQualityBudgetGateService::class);
        $metrics = collect($gate->budgets())->mapWithKeys(fn (array $budget, string $id): array => [
            $id => min((float) $budget['warning'], (float) $budget['max']),
        ])->all();

        File::put($path, json_encode([
            'schema_version' => AtlasFrontendQualityBudgetGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => str_repeat('a', 64),
            'viewports' => $gate->requiredViewports(),
            'metrics' => [...$metrics, ...$overrides],
            ...$extra,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-quality-budget-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
