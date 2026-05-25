<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemDriftGateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDesignSystemDriftGateServiceTest extends TestCase
{
    public function test_template_writes_report_and_placeholder_report_blocks_until_filled(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-system-drift-template-'.bin2hex(random_bytes(4));
        $gate = app(AtlasFrontendDesignSystemDriftGateService::class);

        $template = $gate->writeTemplate($dir);
        $inspection = $gate->inspect($dir.'/design-system-drift-report.json');

        $this->assertSame(AtlasFrontendDesignSystemDriftGateService::TEMPLATE_SCHEMA_VERSION, $template['schema_version']);
        $this->assertTrue(File::isFile($dir.'/design-system-drift-report.json'));
        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('profile_hash_invalid', $inspection['blockers']);
        $this->assertContains('artifact_hash_invalid', $inspection['blockers']);
    }

    public function test_valid_report_passes_with_hashed_refs_and_reused_components(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-system-drift-valid-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/design-system-drift-report.json', json_encode($this->validReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendDesignSystemDriftGateService::class)->inspect($dir.'/design-system-drift-report.json');

        $this->assertSame(AtlasFrontendDesignSystemDriftGateService::SCHEMA_VERSION, $inspection['schema_version']);
        $this->assertSame('passed', $inspection['status']);
        $this->assertTrue((bool) data_get($inspection, 'claim_policy.design_system_adaptation_claim_allowed'));
        $this->assertContains('palette_tokens', $inspection['required_token_categories']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $inspection['gate_hash']);
    }

    public function test_unapproved_new_token_and_component_blocks_claim(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-system-drift-unapproved-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $report = $this->validReport();
        $report['used_tokens']['palette_tokens'][] = 'brand-new-blue';
        $report['component_usage']['new_components'] = ['MegaHero'];
        File::put($dir.'/design-system-drift-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendDesignSystemDriftGateService::class)->inspect($dir.'/design-system-drift-report.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('unexpected_palette_tokens', $inspection['blockers']);
        $this->assertContains('new_components_without_approval', $inspection['blockers']);
    }

    public function test_raw_source_or_prompt_blocks_provider_unsafe_report(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-system-drift-raw-source-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $report = $this->validReport();
        $report['customer_source'] = '<div>private app</div>';
        File::put($dir.'/design-system-drift-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendDesignSystemDriftGateService::class)->inspect($dir.'/design-system-drift-report.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('forbidden_raw_prompt_source_customer_or_token_field_present', $inspection['blockers']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validReport(): array
    {
        $gate = app(AtlasFrontendDesignSystemDriftGateService::class);

        return [
            'schema_version' => AtlasFrontendDesignSystemDriftGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'profile_hash' => str_repeat('a', 64),
            'task_spec_hash' => str_repeat('b', 64),
            'changed_files' => ['src/components/BillingPanel.tsx'],
            'expected_tokens' => [
                'palette_tokens' => ['primary', 'surface', 'accent'],
                'typography_tokens' => ['body', 'heading'],
                'spacing_radius_tokens' => ['space-2', 'space-4', 'radius-sm'],
            ],
            'used_tokens' => [
                'palette_tokens' => ['primary', 'surface'],
                'typography_tokens' => ['body'],
                'spacing_radius_tokens' => ['space-2', 'radius-sm'],
            ],
            'component_usage' => [
                'reused_components' => ['Button', 'Card'],
                'new_components' => [],
            ],
            'approved_exceptions' => [],
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => str_repeat('c', 64),
            ], $gate->requiredArtifactKinds()),
        ];
    }
}
