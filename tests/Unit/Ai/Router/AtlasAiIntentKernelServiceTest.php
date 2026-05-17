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

    public function test_classifies_pesquise_prompt_as_research(): void
    {
        $intent = app(AtlasAiIntentKernelService::class)->classify([
            'input_text' => 'Pesquise o melhor caminho tecnico e separe fato de inferencia',
            'payload' => ['surface_id' => 'atlas_desktop_ai'],
        ]);

        $this->assertSame('research', $intent['intent_class']);
        $this->assertTrue($intent['research_required']);
    }

    public function test_classifies_debug_workspace_prompt_as_debug(): void
    {
        $intent = app(AtlasAiIntentKernelService::class)->classify([
            'input_text' => 'Debug esse stacktrace no workspace atlas',
            'payload' => ['workspace' => '/repo'],
        ]);

        $this->assertSame('debug', $intent['intent_class']);
        $this->assertSame('medium', $intent['risk_level']);
    }
}
