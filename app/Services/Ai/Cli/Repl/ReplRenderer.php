<?php

namespace App\Services\Ai\Cli\Repl;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pinta o composer no terminal sem flicker.
 *
 * Layout:
 *   atlas [imagem 1, imagem 2]:
 *   > texto antes do cursor█texto depois do cursor
 *
 * Quando ha imagens pendentes, o tag e renderizado com OSC 8 (clicavel pra
 * Preview do macOS). Quando ha imagem selecionada, ela aparece em inverse video.
 *
 * Re-renderiza:
 *   - apaga 2 linhas (label + prompt) com \x1b[2K + cursor up
 *   - reimprime tudo
 *   - posiciona cursor onde corresponde
 */
class ReplRenderer
{
    private bool $painted = false;

    private int $paintedLineCount = 0;

    /** @var (\Closure(ReplComposer):?string)|null */
    private ?\Closure $statusBarProducer = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $supportsAnsi = true,
    ) {}

    /**
     * @param  (\Closure(ReplComposer):?string)|null  $producer
     */
    public function setStatusBarProducer(?\Closure $producer): self
    {
        $this->statusBarProducer = $producer;

        return $this;
    }

    public function statusBarProducer(): ?\Closure
    {
        return $this->statusBarProducer;
    }

    public function paint(string $baseLabel, ReplComposer $composer, ?string $statusBarLine = null): void
    {
        $this->resetForRepaint();

        $statusBarLine ??= $this->statusBarProducer !== null
            ? ($this->statusBarProducer)($composer)
            : null;

        $linesPrinted = 0;
        if ($statusBarLine !== null && trim($statusBarLine) !== '') {
            $line = $this->supportsAnsi ? "\x1b[2m".$statusBarLine."\x1b[22m" : $statusBarLine;
            $this->output->writeln($line);
            $linesPrinted++;
        }

        $label = $this->formatLabel($baseLabel, $composer);
        $this->output->writeln($label.':');
        $linesPrinted++;

        $this->output->write($this->formatPromptLine($composer));
        $this->repositionCursor($composer);

        $this->painted = true;
        $this->paintedLineCount = $linesPrinted;
    }

    public function feedback(string $line): void
    {
        $this->output->write("\n");
        $this->output->writeln($line);
    }

    public function newline(): void
    {
        $this->output->write("\n");
    }

    public function reset(): void
    {
        $this->painted = false;
    }

    private function resetForRepaint(): void
    {
        if (! $this->painted || ! $this->supportsAnsi) {
            return;
        }
        $this->output->write("\r");
        $this->output->write(KeyCodes::SEQ_CLEAR_FULL_LINE);
        for ($i = 0; $i < $this->paintedLineCount; $i++) {
            $this->output->write("\x1b[A");
            $this->output->write("\r");
            $this->output->write(KeyCodes::SEQ_CLEAR_FULL_LINE);
        }
    }

    private function formatLabel(string $base, ReplComposer $composer): string
    {
        $stripped = preg_replace('/ \[.*?\]$/u', '', $base) ?? $base;
        $images = $composer->images();
        if ($images === []) {
            return $stripped;
        }

        return $stripped.' ['.$this->formatImageTokens($composer).']';
    }

    private function formatImageTokens(ReplComposer $composer): string
    {
        $images = $composer->images();
        $selected = $composer->selectedImageIndex();
        $tokens = [];
        foreach (array_values($images) as $index => $attachment) {
            $tokenLabel = 'imagem '.($index + 1);
            $path = is_array($attachment) && is_string($attachment['path'] ?? null)
                ? (string) $attachment['path']
                : '';
            $isSelected = $selected === $index;
            $rendered = $tokenLabel;
            if ($isSelected && $this->supportsAnsi) {
                $rendered = "\x1b[7m".$tokenLabel."\x1b[27m";
            }
            if ($path !== '') {
                $rendered = $this->wrapOsc8($rendered, 'file://'.$path);
            }
            $tokens[] = $rendered;
        }

        return implode(', ', $tokens);
    }

    private function formatPromptLine(ReplComposer $composer): string
    {
        $range = $composer->selectionRange();
        if ($range === null || ! $this->supportsAnsi) {
            return '> '.$composer->text();
        }

        [$start, $end] = $range;
        $before = mb_substr($composer->text(), 0, $start);
        $selected = mb_substr($composer->text(), $start, $end - $start);
        $after = mb_substr($composer->text(), $end);

        return '> '.$before."\x1b[7m".$selected."\x1b[27m".$after;
    }

    private function repositionCursor(ReplComposer $composer): void
    {
        if (! $this->supportsAnsi) {
            return;
        }
        $cursorOffsetInChars = $composer->cursor();
        $textLength = $composer->textLength();
        if ($cursorOffsetInChars >= $textLength) {
            return;
        }
        $stepsBack = $textLength - $cursorOffsetInChars;
        if ($stepsBack <= 0) {
            return;
        }
        $this->output->write("\x1b[".$stepsBack.'D');
    }

    private function wrapOsc8(string $text, string $url): string
    {
        if (! $this->supportsAnsi) {
            return $text;
        }

        return "\x1b]8;;".$url."\x1b\\".$text."\x1b]8;;\x1b\\";
    }
}
