<?php

namespace Tests\Unit\Ai\Cli\Repl;

use App\Services\Ai\Cli\Repl\ReplComposer;
use App\Services\Ai\Cli\Repl\ReplRenderer;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ReplRendererTest extends TestCase
{
    public function test_first_paint_emits_label_and_prompt_line(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: false);
        $composer = (new ReplComposer())->insertText('hi');

        $renderer->paint('atlas', $composer);
        $rendered = $output->fetch();

        $this->assertStringContainsString('atlas:', $rendered);
        $this->assertStringContainsString('> hi', $rendered);
    }

    public function test_label_includes_image_tokens(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: false);

        $composer = new ReplComposer();
        $composer->attachImage(['path' => '/tmp/a.png']);
        $composer->attachImage(['path' => '/tmp/b.png']);

        $renderer->paint('atlas', $composer);
        $rendered = $output->fetch();

        $this->assertStringContainsString('atlas [imagem 1, imagem 2]:', $rendered);
    }

    public function test_label_marks_selected_image_with_inverse_video_when_ansi_is_supported(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: true);

        $composer = new ReplComposer();
        $composer->attachImage(['path' => '/tmp/a.png']);
        $composer->attachImage(['path' => '/tmp/b.png']);
        $composer->moveCursorUp();
        $composer->moveCursorLeft();

        $renderer->paint('atlas', $composer);
        $rendered = $output->fetch();

        $this->assertStringContainsString("\x1b[7mimagem 1\x1b[27m", $rendered);
    }

    public function test_paint_repositions_cursor_when_text_has_chars_after(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: true);

        $composer = (new ReplComposer())->insertText('hello world');
        $composer->moveCursorWordLeft();

        $renderer->paint('atlas', $composer);
        $rendered = $output->fetch();

        $this->assertStringContainsString("\x1b[5D", $rendered, 'Deve voltar 5 chars (world).');
    }

    public function test_repaint_clears_previous_lines_when_already_painted(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: true);

        $composer = (new ReplComposer())->insertText('first');
        $renderer->paint('atlas', $composer);
        $output->fetch();

        $composer->clearLine()->insertText('second');
        $renderer->paint('atlas', $composer);
        $rendered = $output->fetch();

        $this->assertStringContainsString("\x1b[2K", $rendered, 'Deve limpar a linha do prompt.');
        $this->assertStringContainsString("\x1b[A", $rendered, 'Deve subir uma linha pra apagar o label.');
        $this->assertStringContainsString('> second', $rendered);
    }

    public function test_paint_renders_selection_in_inverse_video(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: true);
        $composer = (new ReplComposer())->insertText('hello world');
        $composer->moveCursorToLineStart();
        $composer->extendSelectionRight();
        $composer->extendSelectionRight();
        $composer->extendSelectionRight();
        $composer->extendSelectionRight();
        $composer->extendSelectionRight();

        $renderer->paint('atlas', $composer);
        $rendered = $output->fetch();

        $this->assertStringContainsString("\x1b[7mhello\x1b[27m", $rendered);
        $this->assertStringContainsString(' world', $rendered);
    }

    public function test_paint_skips_selection_styling_without_ansi(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: false);
        $composer = (new ReplComposer())->insertText('hello');
        $composer->selectAll();

        $renderer->paint('atlas', $composer);
        $rendered = $output->fetch();

        $this->assertStringNotContainsString("\x1b[7m", $rendered);
        $this->assertStringContainsString('> hello', $rendered);
    }

    public function test_feedback_prints_message_with_newline(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $renderer = new ReplRenderer($output, supportsAnsi: false);

        $renderer->feedback('Imagem removida: foto.png');
        $rendered = $output->fetch();

        $this->assertStringContainsString('Imagem removida: foto.png', $rendered);
    }
}

class_alias(\Symfony\Component\Console\Output\OutputInterface::class, 'Tests\\Unit\\Ai\\Cli\\Repl\\OutputInterface');
