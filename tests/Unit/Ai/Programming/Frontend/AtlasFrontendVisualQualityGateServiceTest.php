<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendVisualQualityGateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendVisualQualityGateServiceTest extends TestCase
{
    public function test_template_writes_report_and_placeholder_report_blocks_until_filled(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-visual-quality-template-'.bin2hex(random_bytes(4));
        $gate = app(AtlasFrontendVisualQualityGateService::class);

        $template = $gate->writeTemplate($dir);
        $inspection = $gate->inspect($dir.'/visual-quality-report.json');

        $this->assertSame(AtlasFrontendVisualQualityGateService::TEMPLATE_SCHEMA_VERSION, $template['schema_version']);
        $this->assertTrue(File::isFile($dir.'/visual-quality-report.json'));
        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('task_spec_hash_invalid', $inspection['blockers']);
        $this->assertContains('artifact_hash_invalid', $inspection['blockers']);
    }

    public function test_valid_report_passes(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-visual-quality-valid-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $reportPath = $dir.'/visual-quality-report.json';
        File::put($reportPath, json_encode($this->validReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendVisualQualityGateService::class)->inspect($reportPath);

        $this->assertSame(AtlasFrontendVisualQualityGateService::SCHEMA_VERSION, $inspection['schema_version']);
        $this->assertSame('passed', $inspection['status']);
        $this->assertTrue((bool) data_get($inspection, 'claim_policy.visual_completion_claim_allowed'));
        $this->assertContains('desktop', $inspection['required_viewports']);
        $this->assertContains('text_overlap', $inspection['required_checks']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $inspection['gate_hash']);
    }

    public function test_missing_required_viewport_blocks_without_exception_reason(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-visual-quality-missing-viewport-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $report = $this->validReport();
        $report['viewports'] = ['desktop', 'mobile'];
        File::put($dir.'/visual-quality-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendVisualQualityGateService::class)->inspect($dir.'/visual-quality-report.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('missing_viewport_tablet', $inspection['blockers']);
    }

    public function test_raw_prompt_or_source_data_blocks_provider_unsafe_report(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-visual-quality-raw-prompt-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $report = $this->validReport();
        $report['raw_prompt'] = 'make this pop';
        File::put($dir.'/visual-quality-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendVisualQualityGateService::class)->inspect($dir.'/visual-quality-report.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('forbidden_raw_prompt_source_or_customer_field_present', $inspection['blockers']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validReport(): array
    {
        $gate = app(AtlasFrontendVisualQualityGateService::class);

        return [
            'schema_version' => AtlasFrontendVisualQualityGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => str_repeat('a', 64),
            'routes' => ['/', '/settings'],
            'viewports' => $gate->requiredViewports(),
            'checks' => array_fill_keys($gate->requiredChecks(), 'passed'),
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => str_repeat('b', 64),
            ], $gate->requiredArtifactKinds()),
        ];
    }
}
