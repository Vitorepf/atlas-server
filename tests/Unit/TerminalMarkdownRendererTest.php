<?php

namespace Tests\Unit;

use App\Support\TerminalMarkdownRenderer;
use Tests\TestCase;

class TerminalMarkdownRendererTest extends TestCase
{
    public function test_plain_render_removes_common_markdown_markers(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render(
            "**Controle:** use `atlas dev`\n- item importante\n> nota",
            decorated: false,
        );

        $this->assertStringContainsString('Controle: use atlas dev', $rendered);
        $this->assertStringContainsString('- item importante', $rendered);
        $this->assertStringContainsString('| nota', $rendered);
        $this->assertStringNotContainsString('**', $rendered);
        $this->assertStringNotContainsString('`atlas dev`', $rendered);
    }

    public function test_decorated_render_styles_common_markdown_markers(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render('**Controle:** use `atlas dev`', decorated: true);

        $this->assertStringContainsString("\033[1mControle:\033[0m", $rendered);
        $this->assertStringContainsString("\033[36matlas dev\033[0m", $rendered);
        $this->assertStringNotContainsString('**', $rendered);
    }

    public function test_italic_renders_with_ansi_italic(): void
    {
        $decorated = app(TerminalMarkdownRenderer::class)->render('uma *citacao* breve', decorated: true);
        $plain = app(TerminalMarkdownRenderer::class)->render('uma *citacao* breve', decorated: false);

        $this->assertStringContainsString("\033[3mcitacao\033[0m", $decorated);
        $this->assertStringContainsString('uma citacao breve', $plain);
        $this->assertStringNotContainsString('*', $plain);
    }

    public function test_italic_does_not_match_inside_bold(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render('**negrito completo**', decorated: true);

        $this->assertStringContainsString("\033[1mnegrito completo\033[0m", $rendered);
        $this->assertStringNotContainsString("\033[3m", $rendered);
    }

    public function test_numbered_lists_render(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render(
            "1. primeiro\n2. segundo\n3. terceiro",
            decorated: false,
        );

        $this->assertStringContainsString('1. primeiro', $rendered);
        $this->assertStringContainsString('2. segundo', $rendered);
        $this->assertStringContainsString('3. terceiro', $rendered);
    }

    public function test_numbered_list_marker_is_decorated(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render('1. passo unico', decorated: true);

        $this->assertStringContainsString("\033[36m1.\033[0m passo unico", $rendered);
    }

    public function test_pipe_table_renders_with_aligned_columns(): void
    {
        $markdown = "| nome | papel |\n| --- | --- |\n| atlas | guia |\n| codex | construtor |";
        $rendered = app(TerminalMarkdownRenderer::class)->render($markdown, decorated: false);

        $this->assertStringContainsString('nome', $rendered);
        $this->assertStringContainsString('papel', $rendered);
        $this->assertStringContainsString('atlas', $rendered);
        $this->assertStringContainsString('codex', $rendered);
        $this->assertStringContainsString('---', $rendered);
        $this->assertStringNotContainsString('|', $rendered);
    }

    public function test_table_alignment_right_pads_left(): void
    {
        $markdown = "| col |\n| ---: |\n| oi |";
        $rendered = app(TerminalMarkdownRenderer::class)->render($markdown, decorated: false);

        $this->assertMatchesRegularExpression('/^  \s+oi$/m', $rendered);
    }

    public function test_canonical_section_header_gets_editorial_treatment(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render("## Plano\n- passo", decorated: true);

        $this->assertStringContainsString("\033[1;36mplano\033[0m", $rendered);
        $this->assertStringContainsString("\033[90m", $rendered);
    }

    public function test_canonical_section_recognizes_proximos_passos_with_accent(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render("### Próximos passos\n- foo", decorated: true);

        $this->assertStringContainsString('próximos passos', $rendered);
    }

    public function test_compact_mode_replaces_code_block_with_summary(): void
    {
        $markdown = "intro\n```php\n<?php echo 1;\necho 2;\n```\nfim";
        $rendered = app(TerminalMarkdownRenderer::class)->render($markdown, decorated: false, compact: true);

        $this->assertStringContainsString('[php - 2 linhas ocultas]', $rendered);
        $this->assertStringNotContainsString('linhas oculta]', $rendered);
        $this->assertStringNotContainsString('echo 1', $rendered);
        $this->assertStringNotContainsString('echo 2', $rendered);
        $this->assertStringContainsString('intro', $rendered);
        $this->assertStringContainsString('fim', $rendered);
    }

    public function test_compact_mode_handles_unlabeled_code_block(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render(
            "```\nlinha unica\n```",
            decorated: false,
            compact: true,
        );

        $this->assertStringContainsString('[codigo - 1 linha oculta]', $rendered);
    }

    public function test_full_mode_keeps_code_block_visible(): void
    {
        $rendered = app(TerminalMarkdownRenderer::class)->render(
            "```php\necho 1;\n```",
            decorated: false,
            compact: false,
        );

        $this->assertStringContainsString('echo 1;', $rendered);
        $this->assertStringContainsString('php', $rendered);
    }
}
