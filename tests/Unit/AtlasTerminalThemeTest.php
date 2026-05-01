<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasTerminalTheme;
use Tests\TestCase;

class AtlasTerminalThemeTest extends TestCase
{
    public function test_decorated_helpers_emit_expected_ansi_codes(): void
    {
        $this->assertSame("\033[32mok\033[0m", AtlasTerminalTheme::ok('ok', true));
        $this->assertSame("\033[31mfail\033[0m", AtlasTerminalTheme::error('fail', true));
        $this->assertSame("\033[33mrisk\033[0m", AtlasTerminalTheme::risk('risk', true));
        $this->assertSame("\033[2mmuted\033[0m", AtlasTerminalTheme::muted('muted', true));
        $this->assertSame("\033[36maccent\033[0m", AtlasTerminalTheme::accent('accent', true));
        $this->assertSame("\033[1mbold\033[0m", AtlasTerminalTheme::bold('bold', true));
        $this->assertSame("\033[3mit\033[0m", AtlasTerminalTheme::italic('it', true));
        $this->assertSame("\033[2;3mwhisper\033[0m", AtlasTerminalTheme::dimItalic('whisper', true));
    }

    public function test_undecorated_returns_plain_text(): void
    {
        $this->assertSame('ok', AtlasTerminalTheme::ok('ok', false));
        $this->assertSame('fail', AtlasTerminalTheme::error('fail', false));
    }

    public function test_status_aliases_resolve_to_semantic_colors(): void
    {
        $this->assertStringContainsString("\033[32m", AtlasTerminalTheme::status('passed', 'x', true));
        $this->assertStringContainsString("\033[31m", AtlasTerminalTheme::status('failed', 'x', true));
        $this->assertStringContainsString("\033[33m", AtlasTerminalTheme::status('needs_review', 'x', true));
        $this->assertStringContainsString("\033[2m", AtlasTerminalTheme::status('unknown', 'x', true));
    }

    public function test_wrap_is_idempotent_for_undecorated_inputs(): void
    {
        $this->assertSame('foo', AtlasTerminalTheme::wrap('1', 'foo', false));
    }
}
