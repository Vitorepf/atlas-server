<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\Kernel\Capability\AtlasCapabilityRegistry;
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
}
