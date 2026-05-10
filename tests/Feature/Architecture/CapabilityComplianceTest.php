<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\Kernel\Capability\AtlasCapabilityRegistry;
use App\Services\Ai\Kernel\Capability\SurfaceCapabilityParityService;
use App\Services\Ai\Kernel\Surface\SurfaceCapability;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CapabilityComplianceTest extends TestCase
{
    public function test_configured_capabilities_are_architecturally_compliant(): void
    {
        $report = app(AtlasCapabilityRegistry::class)->complianceReport();

        $this->assertTrue($report['valid'], implode("\n", $report['errors']));
    }

    public function test_image_paste_is_required_across_operator_input_surfaces(): void
    {
        $registry = app(AtlasCapabilityRegistry::class);
        $capability = $registry->find('atlas.input.image_paste');

        $this->assertNotNull($capability);
        $this->assertSame(['atlas_cli', 'atlas_app', 'atlas_api'], $capability->requiredSurfaces);
    }

    public function test_configured_capabilities_match_operational_surface_adapters(): void
    {
        $report = app(SurfaceCapabilityParityService::class)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertGreaterThanOrEqual(39, $report['checked']);
        $this->assertSame([], $report['skipped']);
        $this->assertContains('atlas_cli_dev', $report['mapped_adapters']);
        $this->assertContains('atlas_vault', $report['mapped_adapters']);
        $this->assertSame([], $report['unmapped_adapters']);
    }

    public function test_input_boundary_contract_makes_multimodal_surface_ownership_explicit(): void
    {
        $report = app(SurfaceCapabilityParityService::class)->complianceReport();
        $contract = $report['input_boundary_contract'];

        $this->assertSame('atlas.input.surface_capability_boundary.v1', $contract['schema_version']);
        $this->assertSame('atlas_input', $contract['owner']);
        $this->assertFalse($contract['surface_specific_input_capabilities_allowed']);
        $this->assertTrue($contract['adapter_parity_required']);
        $this->assertContains('atlas.input.image_paste', $contract['required_kernel_capabilities']);
        $this->assertContains('atlas.input.file_attachment', $contract['required_kernel_capabilities']);
        $this->assertContains('surface_only_image_paste', $contract['forbidden_patterns']);
        $this->assertContains('provider_direct_attachment_bypass', $contract['forbidden_patterns']);
    }

    public function test_architecture_validate_exposes_surface_capability_parity_as_ap33(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-validate', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'kernel.static_scan.ap33_surface_capability_parity.valid'));
        $this->assertGreaterThanOrEqual(39, data_get($payload, 'kernel.static_scan.ap33_surface_capability_parity.checked'));
        $this->assertSame(
            'atlas.input.surface_capability_boundary.v1',
            data_get($payload, 'kernel.static_scan.ap33_surface_capability_parity.input_boundary_contract.schema_version'),
        );
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap33_surface_capability_parity.violations'));
    }

    public function test_compliance_report_catches_missing_required_surface_implementation(): void
    {
        $registry = new AtlasCapabilityRegistry(
            capabilities: [
                'atlas.input.image_paste' => [
                    'schema_version' => 'atlas.capability.v1',
                    'id' => 'atlas.input.image_paste',
                    'version' => '1.0.0',
                    'title' => 'Image Paste',
                    'owner' => 'atlas.input',
                    'required_surfaces' => ['atlas_cli'],
                    'test_suite' => ['tests/Feature/AiChatCommandPasteImageTest.php'],
                ],
            ],
            surfaces: [
                'atlas_cli' => [
                    'label' => 'Atlas CLI',
                    'capabilities' => ['atlas.input.text'],
                ],
            ],
        );

        $report = $registry->complianceReport();

        $this->assertFalse($report['valid']);
        $this->assertContains(
            'Surface atlas_cli must implement required capability atlas.input.image_paste.',
            $report['errors'],
        );
    }

    public function test_capability_manifest_must_classify_every_known_surface(): void
    {
        $registry = new AtlasCapabilityRegistry(
            capabilities: [
                'atlas.input.text' => [
                    'schema_version' => 'atlas.capability.v1',
                    'id' => 'atlas.input.text',
                    'version' => '1.0.0',
                    'title' => 'Text Input',
                    'owner' => 'atlas.input',
                    'required_surfaces' => ['atlas_cli'],
                    'test_suite' => ['tests/Feature/AtlasCliDevCommandTest.php'],
                ],
            ],
            surfaces: [
                'atlas_cli' => [
                    'label' => 'Atlas CLI',
                    'capabilities' => ['atlas.input.text'],
                ],
                'atlas_vault' => [
                    'label' => 'AtlasVault',
                    'capabilities' => [],
                ],
            ],
        );

        $report = $registry->complianceReport();

        $this->assertFalse($report['valid']);
        $this->assertContains(
            'Capability atlas.input.text must classify surface atlas_vault as required, optional, or not_supported.',
            $report['errors'],
        );
        $this->assertFalse($report['surface_coverage']['valid']);
        $this->assertContains(
            'Capability atlas.input.text must classify surface atlas_vault as required, optional, or not_supported.',
            $report['surface_coverage']['errors'],
        );
    }

    public function test_capability_manifest_rejects_conflicting_surface_classification(): void
    {
        $registry = new AtlasCapabilityRegistry(
            capabilities: [
                'atlas.input.text' => [
                    'schema_version' => 'atlas.capability.v1',
                    'id' => 'atlas.input.text',
                    'version' => '1.0.0',
                    'title' => 'Text Input',
                    'owner' => 'atlas.input',
                    'required_surfaces' => ['atlas_cli'],
                    'optional_surfaces' => ['atlas_cli'],
                    'test_suite' => ['tests/Feature/AtlasCliDevCommandTest.php'],
                ],
            ],
            surfaces: [
                'atlas_cli' => [
                    'label' => 'Atlas CLI',
                    'capabilities' => ['atlas.input.text'],
                ],
            ],
        );

        $report = $registry->complianceReport();

        $this->assertFalse($report['valid']);
        $this->assertContains(
            'Capability atlas.input.text classifies surface atlas_cli more than once.',
            $report['errors'],
        );
        $this->assertFalse($report['surface_coverage']['valid']);
    }

    public function test_capability_manifest_rejects_unknown_not_supported_surface(): void
    {
        $registry = new AtlasCapabilityRegistry(
            capabilities: [
                'atlas.input.text' => [
                    'schema_version' => 'atlas.capability.v1',
                    'id' => 'atlas.input.text',
                    'version' => '1.0.0',
                    'title' => 'Text Input',
                    'owner' => 'atlas.input',
                    'required_surfaces' => ['atlas_cli'],
                    'not_supported' => [
                        [
                            'surface' => 'atlas_unknown',
                            'reason' => 'Unknown test surface.',
                        ],
                    ],
                    'test_suite' => ['tests/Feature/AtlasCliDevCommandTest.php'],
                ],
            ],
            surfaces: [
                'atlas_cli' => [
                    'label' => 'Atlas CLI',
                    'capabilities' => ['atlas.input.text'],
                ],
            ],
        );

        $report = $registry->complianceReport();

        $this->assertFalse($report['valid']);
        $this->assertContains(
            'Capability atlas.input.text not_supported references unknown surface atlas_unknown.',
            $report['errors'],
        );
    }

    public function test_surface_adapter_parity_catches_capability_declared_but_missing_from_adapter(): void
    {
        $registry = new AtlasCapabilityRegistry(
            capabilities: [
                'atlas.input.image_paste' => [
                    'schema_version' => 'atlas.capability.v1',
                    'id' => 'atlas.input.image_paste',
                    'version' => '1.0.0',
                    'title' => 'Image Paste',
                    'owner' => 'atlas.input',
                    'required_surfaces' => ['atlas_cli'],
                    'test_suite' => ['tests/Feature/AiChatCommandPasteImageTest.php'],
                ],
            ],
            surfaces: [
                'atlas_cli' => [
                    'label' => 'Atlas CLI',
                    'capabilities' => ['atlas.input.image_paste'],
                ],
            ],
        );

        $service = new SurfaceCapabilityParityService(
            capabilities: $registry,
            surfaceAdapters: app(SurfaceAdapterRegistry::class),
            surfaceAdapterMap: ['atlas_cli' => ['atlas_api_interaction']],
            capabilityAdapterRequirements: [
                'atlas.input.image_paste' => [
                    'atlas_cli' => [SurfaceCapability::IMAGE_PASTE],
                ],
            ],
        );

        $report = $service->complianceReport();

        $this->assertFalse($report['ok']);
        $this->assertContains(
            'Kernel capability atlas.input.image_paste on atlas_cli requires adapter atlas_api_interaction to support one of [image_paste].',
            $report['errors'],
        );
    }

    public function test_surface_adapter_parity_treats_missing_rules_as_failed_contract(): void
    {
        $registry = new AtlasCapabilityRegistry(
            capabilities: [
                'atlas.custom.unmapped' => [
                    'schema_version' => 'atlas.capability.v1',
                    'id' => 'atlas.custom.unmapped',
                    'version' => '1.0.0',
                    'title' => 'Unmapped Capability',
                    'owner' => 'atlas.test',
                    'required_surfaces' => ['atlas_cli'],
                    'test_suite' => ['tests/Feature/AiChatCommandPasteImageTest.php'],
                ],
            ],
            surfaces: [
                'atlas_cli' => [
                    'label' => 'Atlas CLI',
                    'capabilities' => ['atlas.custom.unmapped'],
                ],
            ],
        );

        $service = new SurfaceCapabilityParityService(
            capabilities: $registry,
            surfaceAdapters: app(SurfaceAdapterRegistry::class),
            surfaceAdapterMap: [
                'atlas_cli' => ['atlas_cli_dev', 'atlas_cli_chat', 'atlas_cli_forge'],
                'atlas_app' => ['atlas_app'],
                'atlas_api' => ['atlas_api_interaction'],
                'atlas_worker' => ['atlas_worker'],
                'atlas_mcp_readonly' => ['atlas_mcp_readonly'],
                'atlas_vault' => ['atlas_vault'],
                'atlas_voice' => ['voice_realtime'],
            ],
            capabilityAdapterRequirements: [],
        );

        $report = $service->complianceReport();

        $this->assertFalse($report['ok']);
        $this->assertSame([], $report['errors']);
        $this->assertContains(
            'Capability atlas.custom.unmapped on atlas_cli has no adapter parity rule.',
            $report['skipped'],
        );
    }

    public function test_surface_adapter_parity_fails_when_registered_adapter_is_not_mapped(): void
    {
        $registry = new AtlasCapabilityRegistry(
            capabilities: [
                'atlas.input.text' => [
                    'schema_version' => 'atlas.capability.v1',
                    'id' => 'atlas.input.text',
                    'version' => '1.0.0',
                    'title' => 'Text Input',
                    'owner' => 'atlas.input',
                    'required_surfaces' => ['atlas_cli'],
                    'test_suite' => ['tests/Feature/AtlasCliDevCommandTest.php'],
                ],
            ],
            surfaces: [
                'atlas_cli' => [
                    'label' => 'Atlas CLI',
                    'capabilities' => ['atlas.input.text'],
                ],
            ],
        );

        $service = new SurfaceCapabilityParityService(
            capabilities: $registry,
            surfaceAdapters: app(SurfaceAdapterRegistry::class),
            surfaceAdapterMap: ['atlas_cli' => ['atlas_cli_dev']],
            capabilityAdapterRequirements: [
                'atlas.input.text' => [
                    '*' => [SurfaceCapability::TEXT],
                ],
            ],
        );

        $report = $service->complianceReport();

        $this->assertFalse($report['ok']);
        $this->assertContains('atlas_vault', $report['unmapped_adapters']);
        $this->assertContains(
            'Surface adapter atlas_vault is registered but missing from capability parity map.',
            $report['errors'],
        );
    }
}
