<?php

namespace App\Services\Ai\Cli\Repl;

/**
 * Evento de teclado decodificado a partir de bytes do STDIN.
 *
 * Cada evento tem um `kind` simbolico (LEFT, CTRL_X, CHAR, etc) e payload
 * opcional (texto colado, char digitado).
 */
class KeyEvent
{
    public const CHAR = 'char';

    public const ENTER = 'enter';

    public const INTERRUPT = 'interrupt';

    public const EOF = 'eof';

    public const BACKSPACE = 'backspace';

    public const DELETE = 'delete';

    public const ARROW_LEFT = 'arrow_left';

    public const ARROW_RIGHT = 'arrow_right';

    public const ARROW_UP = 'arrow_up';

    public const ARROW_DOWN = 'arrow_down';

    public const HOME = 'home';

    public const END = 'end';

    public const ALT_LEFT = 'alt_left';

    public const ALT_RIGHT = 'alt_right';

    public const ALT_BACKSPACE = 'alt_backspace';

    public const SHIFT_LEFT = 'shift_left';

    public const SHIFT_RIGHT = 'shift_right';

    public const SHIFT_UP = 'shift_up';

    public const SHIFT_DOWN = 'shift_down';

    public const SHIFT_ALT_LEFT = 'shift_alt_left';

    public const SHIFT_ALT_RIGHT = 'shift_alt_right';

    public const SHIFT_HOME = 'shift_home';

    public const SHIFT_END = 'shift_end';

    public const SELECT_ALL = 'select_all';

    public const SELECT_LINE = 'select_line';

    public const CTRL_G = 'ctrl_g';

    public const CTRL_K = 'ctrl_k';

    public const CTRL_L = 'ctrl_l';

    public const CTRL_R = 'ctrl_r';

    public const CTRL_U = 'ctrl_u';

    public const CTRL_V = 'ctrl_v';

    public const CTRL_W = 'ctrl_w';

    public const CTRL_X = 'ctrl_x';

    public const CTRL_Y = 'ctrl_y';

    public const CTRL_Z = 'ctrl_z';

    public const TAB = 'tab';

    public const BRACKETED_PASTE = 'bracketed_paste';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        public readonly string $payload = '',
    ) {}

    public static function char(string $char): self
    {
        return new self(self::CHAR, $char);
    }

    public static function bracketedPaste(string $payload): self
    {
        return new self(self::BRACKETED_PASTE, $payload);
    }

    public static function of(string $kind): self
    {
        return new self($kind);
    }

    public function isPrintable(): bool
    {
        return $this->kind === self::CHAR;
    }

    public function isSubmit(): bool
    {
        return $this->kind === self::ENTER;
    }
}
