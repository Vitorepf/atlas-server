<?php

namespace App\Services\Ai\Cli\Repl;

/**
 * Constantes de bytes/sequencias do terminal e nomes simbolicos de teclas.
 *
 * Centraliza tudo que era magic string espalhado: \x16, \x18, \x1b[D etc.
 */
class KeyCodes
{
    public const BYTE_NULL = "\x00";

    public const BYTE_CTRL_C = "\x03";

    public const BYTE_CTRL_D = "\x04";

    public const BYTE_CTRL_L = "\x0c";

    public const BYTE_CTRL_U = "\x15";

    public const BYTE_CTRL_V = "\x16";

    public const BYTE_CTRL_W = "\x17";

    public const BYTE_CTRL_X = "\x18";

    public const BYTE_BACKSPACE = "\x7f";

    public const BYTE_BACKSPACE_ALT = "\x08";

    public const BYTE_CTRL_R = "\x12";

    public const BYTE_CTRL_Y = "\x19";

    public const BYTE_CTRL_Z = "\x1a";

    public const BYTE_CTRL_G = "\x07";

    public const BYTE_CTRL_K = "\x0b";

    public const BYTE_TAB = "\x09";

    public const BYTE_ENTER_LF = "\n";

    public const BYTE_ENTER_CR = "\r";

    public const BYTE_ESC = "\x1b";

    public const SEQ_ARROW_LEFT = "\x1b[D";

    public const SEQ_ARROW_RIGHT = "\x1b[C";

    public const SEQ_ARROW_UP = "\x1b[A";

    public const SEQ_ARROW_DOWN = "\x1b[B";

    public const SEQ_HOME = "\x1b[H";

    public const SEQ_HOME_LINUX = "\x1b[1~";

    public const SEQ_END = "\x1b[F";

    public const SEQ_END_LINUX = "\x1b[4~";

    public const SEQ_DELETE = "\x1b[3~";

    public const SEQ_ALT_LEFT = "\x1bb";

    public const SEQ_ALT_RIGHT = "\x1bf";

    public const SEQ_ALT_BACKSPACE = "\x1b\x7f";

    public const SEQ_SHIFT_LEFT = "\x1b[1;2D";

    public const SEQ_SHIFT_RIGHT = "\x1b[1;2C";

    public const SEQ_SHIFT_UP = "\x1b[1;2A";

    public const SEQ_SHIFT_DOWN = "\x1b[1;2B";

    public const SEQ_SHIFT_ALT_LEFT = "\x1b[1;4D";

    public const SEQ_SHIFT_ALT_RIGHT = "\x1b[1;4C";

    public const SEQ_SHIFT_HOME = "\x1b[1;2H";

    public const SEQ_SHIFT_END = "\x1b[1;2F";

    public const BYTE_CTRL_A = "\x01";

    public const BYTE_CTRL_E = "\x05";

    public const BYTE_CTRL_B = "\x02";

    public const BYTE_CTRL_F = "\x06";

    public const SEQ_BRACKETED_PASTE_START = "\x1b[200~";

    public const SEQ_BRACKETED_PASTE_END = "\x1b[201~";

    public const SEQ_BRACKETED_PASTE_ENABLE = "\x1b[?2004h";

    public const SEQ_BRACKETED_PASTE_DISABLE = "\x1b[?2004l";

    public const SEQ_SAVE_CURSOR = "\x1b[s";

    public const SEQ_RESTORE_CURSOR = "\x1b[u";

    public const SEQ_CLEAR_LINE_FROM_CURSOR = "\x1b[0K";

    public const SEQ_CLEAR_FULL_LINE = "\x1b[2K";

    public const SEQ_CLEAR_DOWN = "\x1b[0J";
}
