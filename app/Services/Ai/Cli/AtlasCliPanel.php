<?php

namespace App\Services\Ai\Cli;

class AtlasCliPanel
{
    public const TOP_LEFT = '╭';

    public const TOP_RIGHT = '╮';

    public const BOTTOM_LEFT = '╰';

    public const BOTTOM_RIGHT = '╯';

    public const HORIZONTAL = '─';

    public const VERTICAL = '│';

    private bool $decorated;

    private int $width;

    /**
     * @var list<string>
     */
    private array $lines = [];

    public function __construct(bool $decorated = true, int $width = 72)
    {
        $this->decorated = $decorated;
        $this->width = max(40, $width);
    }

    public static function make(bool $decorated = true, int $width = 72): self
    {
        return new self($decorated, $width);
    }

    public function open(string $title, ?string $tag = null): self
    {
        $titleColored = $this->ansi('1;36', ' '.$title.' ');
        $tagColored = $tag !== null ? $this->ansi('2', ' '.$tag.' ') : '';
        $left = self::TOP_LEFT.self::HORIZONTAL.self::HORIZONTAL;

        $titlePlainLen = mb_strlen(' '.$title.' ', 'UTF-8');
        $tagPlainLen = $tag !== null ? mb_strlen(' '.$tag.' ', 'UTF-8') : 0;
        $fillerLen = max(2, $this->width - 3 - $titlePlainLen - $tagPlainLen - 1);
        $filler = $this->ansi('36', str_repeat(self::HORIZONTAL, $fillerLen));

        $right = $this->ansi('36', self::HORIZONTAL).self::TOP_RIGHT;

        $this->lines[] = $this->ansi('36', $left).$titleColored.$tagColored.$filler.$right;

        return $this;
    }

    public function blank(): self
    {
        $this->lines[] = $this->wrapLine('');

        return $this;
    }

    public function line(string $content, int $indent = 2): self
    {
        $this->lines[] = $this->wrapLine(str_repeat(' ', $indent).$content);

        return $this;
    }

    public function section(string $label): self
    {
        $colored = $this->ansi('1;36', $label);
        $this->lines[] = $this->wrapLine('  '.$colored);

        return $this;
    }

    public function kv(string $key, string $value, int $keyWidth = 11): self
    {
        $padded = str_pad($key, $keyWidth, ' ', STR_PAD_RIGHT);
        $keyDim = $this->ansi('2', $padded);
        $this->lines[] = $this->wrapLine('   '.$keyDim.' '.$value);

        return $this;
    }

    public function divider(): self
    {
        $inner = str_repeat(self::HORIZONTAL, $this->width - 4);
        $this->lines[] = $this->ansi('2', self::VERTICAL).' '.$this->ansi('2', $inner).' '.$this->ansi('2', self::VERTICAL);

        return $this;
    }

    public function close(): self
    {
        $inner = str_repeat(self::HORIZONTAL, $this->width - 2);
        $this->lines[] = $this->ansi('36', self::BOTTOM_LEFT.$inner.self::BOTTOM_RIGHT);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function build(): array
    {
        return $this->lines;
    }

    private function wrapLine(string $content): string
    {
        $visibleLen = $this->visibleLength($content);
        $padding = max(0, $this->width - 2 - $visibleLen);
        $border = $this->ansi('36', self::VERTICAL);

        return $border.$content.str_repeat(' ', $padding).$border;
    }

    private function visibleLength(string $text): int
    {
        $stripped = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        return mb_strlen($stripped, 'UTF-8');
    }

    private function ansi(string $code, string $text): string
    {
        if (! $this->decorated) {
            return $text;
        }

        return "\033[".$code.'m'.$text."\033[0m";
    }
}
