<?php

namespace Tests\Unit\Ai\Cli;

use App\Services\Ai\Cli\AtlasCliModelCatalogService;
use Tests\TestCase;

class AtlasCliModelCatalogServiceTest extends TestCase
{
    public function test_premium_claude_and_codex_aliases_are_available(): void
    {
        config()->set('atlas.ai.providers.claude_cli.premium_model', 'claude-opus-4-7');
        config()->set('atlas.ai.providers.claude_cli.premium_model_label', 'Claude Opus 4.7');
        config()->set('atlas.ai.providers.codex_cli.premium_model', 'gpt-5.5');
        config()->set('atlas.ai.providers.codex_cli.premium_model_label', 'GPT-5.5');

        $catalog = app(AtlasCliModelCatalogService::class);

        $opus = $catalog->select('opus-4.7');
        $codex = $catalog->select('codex-5.5');

        $this->assertSame('claude_cli', $opus['provider'] ?? null);
        $this->assertSame('claude-opus-4-7', $opus['model'] ?? null);
        $this->assertSame('Claude Opus 4.7', $opus['label'] ?? null);
        $this->assertSame('premium', $opus['tier'] ?? null);

        $this->assertSame('codex_cli', $codex['provider'] ?? null);
        $this->assertSame('gpt-5.5', $codex['model'] ?? null);
        $this->assertSame('GPT-5.5', $codex['label'] ?? null);
        $this->assertSame('premium', $codex['tier'] ?? null);
    }
}
