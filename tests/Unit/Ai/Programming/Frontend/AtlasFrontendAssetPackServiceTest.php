<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendAssetPackService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendAssetPackServiceTest extends TestCase
{
    public function test_template_writes_asset_pack_and_placeholder_pack_blocks_until_filled(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-assets-template-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendAssetPackService::class);

        $template = $service->writeTemplate($dir);
        $inspection = $service->inspect($dir.'/frontend-asset-pack.json');

        $this->assertSame(AtlasFrontendAssetPackService::TEMPLATE_SCHEMA_VERSION, $template['schema_version']);
        $this->assertTrue(File::isFile($dir.'/frontend-asset-pack.json'));
        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('task_spec_hash_invalid', $inspection['blockers']);
        $this->assertContains('asset_hash_invalid', $inspection['blockers']);
    }

    public function test_valid_asset_pack_passes_with_provenance_and_dimensions(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-assets-valid-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/frontend-asset-pack.json', json_encode($this->validPack(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendAssetPackService::class)->inspect($dir.'/frontend-asset-pack.json');

        $this->assertSame(AtlasFrontendAssetPackService::SCHEMA_VERSION, $inspection['schema_version']);
        $this->assertSame('passed', $inspection['status']);
        $this->assertTrue((bool) data_get($inspection, 'claim_policy.asset_provenance_claim_allowed'));
        $this->assertContains('logo', $inspection['recommended_asset_kinds']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $inspection['verification_hash']);
    }

    public function test_placeholder_or_unlicensed_asset_blocks_claim(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-assets-placeholder-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $pack = $this->validPack();
        $pack['assets'][0]['path'] = 'assets/placeholder-logo.png';
        $pack['assets'][1]['license'] = 'unknown';
        File::put($dir.'/frontend-asset-pack.json', json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendAssetPackService::class)->inspect($dir.'/frontend-asset-pack.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('placeholder_asset_forbidden', $inspection['blockers']);
        $this->assertContains('asset_license_invalid', $inspection['blockers']);
    }

    public function test_critical_asset_without_dimensions_blocks_claim(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-assets-dimensions-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $pack = $this->validPack();
        unset($pack['assets'][0]['dimensions']);
        File::put($dir.'/frontend-asset-pack.json', json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendAssetPackService::class)->inspect($dir.'/frontend-asset-pack.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('critical_asset_dimensions_missing', $inspection['blockers']);
    }

    public function test_raw_source_or_prompt_blocks_provider_unsafe_pack(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-assets-raw-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $pack = $this->validPack();
        $pack['raw_prompt'] = 'use this private screenshot';
        File::put($dir.'/frontend-asset-pack.json', json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendAssetPackService::class)->inspect($dir.'/frontend-asset-pack.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('forbidden_raw_prompt_source_customer_or_secret_field_present', $inspection['blockers']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validPack(): array
    {
        return [
            'schema_version' => AtlasFrontendAssetPackService::PACK_SCHEMA_VERSION,
            'pack_id' => 'acme-assets-v1',
            'task_spec_hash' => str_repeat('a', 64),
            'assets' => [
                $this->asset('logo', 'assets/logo.png', true),
                $this->asset('product_screenshot', 'assets/app-dashboard.png', true),
                $this->asset('design_reference', 'assets/reference.png', false),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function asset(string $kind, string $path, bool $critical): array
    {
        return [
            'kind' => $kind,
            'path' => $path,
            'sha256' => str_repeat('b', 64),
            'source_ref' => 'approved-'.$kind,
            'license' => 'owned_or_approved',
            'usage_rights' => 'allowed_for_product_ui',
            'critical' => $critical,
            'dimensions' => ['width' => 1440, 'height' => 900],
        ];
    }
}
