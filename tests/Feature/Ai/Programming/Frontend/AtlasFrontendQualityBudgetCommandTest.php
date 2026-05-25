<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendQualityBudgetGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendQualityBudgetCommandTest extends TestCase
{
    public function test_quality_budget_template_command_writes_report(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-quality-budget-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:quality-budget', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendQualityBudgetGateService::TEMPLATE_SCHEMA_VERSION, $output);
        $this->assertTrue(File::isFile($dir.'/quality-budget-report.json'));
    }

    public function test_quality_budget_inspect_command_blocks_over_budget_report(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-quality-budget-command-inspect-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $report = $dir.'/quality-budget-report.json';
        $gate = app(AtlasFrontendQualityBudgetGateService::class);
        $metrics = collect($gate->budgets())->mapWithKeys(fn (array $budget, string $id): array => [$id => 0])->all();
        $metrics['lcp_ms'] = 5000;
        File::put($report, json_encode([
            'schema_version' => AtlasFrontendQualityBudgetGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => str_repeat('a', 64),
            'viewports' => $gate->requiredViewports(),
            'metrics' => $metrics,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $exitCode = Artisan::call('atlas:frontend:quality-budget', [
            'action' => 'inspect',
            '--report' => $report,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendQualityBudgetGateService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('budget_failed_lcp_ms', $output);
    }
}
