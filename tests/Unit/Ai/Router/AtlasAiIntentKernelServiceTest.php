<?php

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\Router\AtlasAiIntentKernelService;
use Tests\TestCase;

class AtlasAiIntentKernelServiceTest extends TestCase
{
    public function test_classifies_ambiguous_patch_without_workspace_as_plan(): void
    {
        $intent = app(AtlasAiIntentKernelService::class)->classify([
            'input_text' => 'Implemente um sistema de login',
            'payload' => ['surface_id' => 'atlas_desktop_ai'],
        ]);

        $this->assertSame('atlas.ai.intent_kernel.v1', $intent['schema_version']);
        $this->assertSame('plan', $intent['intent_class']);
        $this->assertSame('medium', $intent['confidence']);
        $this->assertTrue($intent['planning_required']);
        $this->assertTrue($intent['research_required']);
    }

    public function test_classifies_workspace_patch_as_dev(): void
    {
        $intent = app(AtlasAiIntentKernelService::class)->classify([
            'input_text' => 'Corrija o bug de billing',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
            ],
        ]);

        $this->assertSame('dev', $intent['intent_class']);
        $this->assertSame('medium', $intent['risk_level']);
        $this->assertTrue($intent['workspace_present']);
    }
}
