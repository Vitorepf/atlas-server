<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyDesignProfileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendCompanyDesignProfileServiceTest extends TestCase
{
    public function test_ready_profile_can_drive_multi_company_frontend(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-company-profile-ready-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $path = $dir.'/company-design-profile.json';
        File::put($path, json_encode($this->readyProfile(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendCompanyDesignProfileService::class)->inspect($path);

        $this->assertSame('atlas.frontend.company_design_profile.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'readiness.can_drive_multi_company_frontend'));
        $this->assertTrue((bool) data_get($payload, 'readiness.can_claim_brand_adaptation'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['profile_certification_hash']);
    }

    public function test_profile_blocks_missing_brand_and_design_refs(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-company-profile-blocked-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $path = $dir.'/company-design-profile.json';
        $profile = $this->readyProfile();
        unset($profile['brand_system'], $profile['design_system_refs']);
        File::put($path, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendCompanyDesignProfileService::class)->inspect($path);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('missing_brand_system', $payload['blockers']);
        $this->assertContains('missing_design_system_refs', $payload['blockers']);
    }

    public function test_profile_blocks_raw_prompt_or_customer_source(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-company-profile-raw-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $path = $dir.'/company-design-profile.json';
        $profile = $this->readyProfile();
        $profile['raw_prompt'] = 'secret prompt';
        File::put($path, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendCompanyDesignProfileService::class)->inspect($path);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('forbidden_raw_prompt_or_customer_source_field_present', $payload['blockers']);
    }

    public function test_template_writes_profile_skeleton(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-company-profile-template-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendCompanyDesignProfileService::class)->writeTemplate($dir);

        $this->assertSame('atlas.frontend.company_design_profile_template.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertFileExists($dir.'/company-design-profile.json');
        $this->assertContains('brand_system', $payload['required_sections']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyProfile(): array
    {
        return [
            'schema_version' => AtlasFrontendCompanyDesignProfileService::SCHEMA_VERSION,
            'profile_id' => 'acme-web-app-v1',
            'company_context' => [
                'company_name_hash' => hash('sha256', 'Acme'),
                'industry' => 'saas',
                'audience_segments' => ['operators', 'admins'],
                'product_jobs' => ['monitor operations', 'complete repeated workflows'],
            ],
            'brand_system' => [
                'tone_keywords' => ['clear', 'calm', 'precise'],
                'visual_principles' => ['dense but readable', 'low decoration'],
                'palette_tokens' => ['primary', 'surface', 'accent'],
                'typography_tokens' => ['body', 'heading', 'mono'],
                'spacing_radius_tokens' => ['space-2', 'space-4', 'radius-sm'],
            ],
            'design_system_refs' => [
                ['kind' => 'tokens', 'ref' => 'design-tokens.json', 'sha256' => hash('sha256', 'tokens')],
                ['kind' => 'screenshots', 'ref' => 'screenshots.zip', 'sha256' => hash('sha256', 'screenshots')],
            ],
            'frontend_constraints' => [
                'framework' => 'react',
                'supported_viewports' => ['desktop', 'tablet', 'mobile'],
                'accessibility_baseline' => 'wcag_aa_or_reason',
                'performance_budget' => 'project budget or reason',
            ],
            'quality_policy' => [
                'forbidden_tropes' => ['generic gradient hero', 'nested cards'],
                'required_evidence' => ['visual_smoke_multi_viewport', 'anti_slop_report'],
                'review_policy' => 'senior_review_for_broad_visual_change',
            ],
        ];
    }
}
