<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\CodexCliProvider;
use App\Services\Ai\GeminiCliProvider;
use Tests\TestCase;

class AiProviderManagerTest extends TestCase
{
    public function test_default_provider_comes_from_runtime_settings(): void
    {
        $claude = $this->createMock(ClaudeCliProvider::class);
        $codex = $this->createMock(CodexCliProvider::class);
        $gemini = $this->createMock(GeminiCliProvider::class);
        $settings = $this->createMock(AtlasAiRuntimeSettings::class);
        $settings->method('defaultProvider')->willReturn('gemini_cli');

        $manager = new AiProviderManager($claude, $codex, $gemini, $settings);

        $this->assertSame($gemini, $manager->get());
        $this->assertSame($codex, $manager->get('codex_cli'));
    }
}
