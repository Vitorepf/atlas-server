<?php

namespace App\Services\Ai\Cli\Repl;

use Closure;

/**
 * Decodifica bytes do terminal em KeyEvent simbolicos.
 *
 * O parser e stateless: voce passa o primeiro byte e dois callbacks (peekTail
 * e readUntilMarker). Ele decide se precisa ler mais e devolve o KeyEvent.
 *
 * - $peekTail(int $maxBytes): le ate $maxBytes adicionais com timeout curto, util
 *   para terminar sequencias ESC.
 * - $readUntilMarker(string $marker): le indefinidamente ate encontrar $marker
 *   (usado em bracketed paste).
 */
class KeySequenceParser
{
    public function parse(string $firstByte, Closure $peekTail, Closure $readUntilMarker): KeyEvent
    {
        if ($firstByte === '') {
            return KeyEvent::of(KeyEvent::UNKNOWN);
        }

        $event = $this->parseControlByte($firstByte);
        if ($event !== null) {
            return $event;
        }

        if ($firstByte === KeyCodes::BYTE_ESC) {
            return $this->parseEscapeSequence($peekTail, $readUntilMarker);
        }

        if ($this->isPrintableChar($firstByte)) {
            return KeyEvent::char($firstByte);
        }

        return KeyEvent::char($firstByte);
    }

    private function parseControlByte(string $byte): ?KeyEvent
    {
        return match ($byte) {
            KeyCodes::BYTE_ENTER_LF, KeyCodes::BYTE_ENTER_CR => KeyEvent::of(KeyEvent::ENTER),
            KeyCodes::BYTE_CTRL_A => KeyEvent::of(KeyEvent::SELECT_ALL),
            KeyCodes::BYTE_CTRL_B => KeyEvent::of(KeyEvent::ARROW_LEFT),
            KeyCodes::BYTE_CTRL_C => KeyEvent::of(KeyEvent::INTERRUPT),
            KeyCodes::BYTE_CTRL_D => KeyEvent::of(KeyEvent::EOF),
            KeyCodes::BYTE_CTRL_E => KeyEvent::of(KeyEvent::END),
            KeyCodes::BYTE_CTRL_F => KeyEvent::of(KeyEvent::ARROW_RIGHT),
            KeyCodes::BYTE_CTRL_G => KeyEvent::of(KeyEvent::CTRL_G),
            KeyCodes::BYTE_CTRL_K => KeyEvent::of(KeyEvent::CTRL_K),
            KeyCodes::BYTE_CTRL_L => KeyEvent::of(KeyEvent::CTRL_L),
            KeyCodes::BYTE_CTRL_R => KeyEvent::of(KeyEvent::CTRL_R),
            KeyCodes::BYTE_CTRL_U => KeyEvent::of(KeyEvent::CTRL_U),
            KeyCodes::BYTE_CTRL_V => KeyEvent::of(KeyEvent::CTRL_V),
            KeyCodes::BYTE_CTRL_W => KeyEvent::of(KeyEvent::CTRL_W),
            KeyCodes::BYTE_CTRL_X => KeyEvent::of(KeyEvent::CTRL_X),
            KeyCodes::BYTE_CTRL_Y => KeyEvent::of(KeyEvent::CTRL_Y),
            KeyCodes::BYTE_CTRL_Z => KeyEvent::of(KeyEvent::CTRL_Z),
            KeyCodes::BYTE_TAB => KeyEvent::of(KeyEvent::TAB),
            KeyCodes::BYTE_BACKSPACE, KeyCodes::BYTE_BACKSPACE_ALT => KeyEvent::of(KeyEvent::BACKSPACE),
            default => null,
        };
    }

    private function parseEscapeSequence(Closure $peekTail, Closure $readUntilMarker): KeyEvent
    {
        $tail = (string) $peekTail(8);
        $sequence = KeyCodes::BYTE_ESC.$tail;

        if (str_starts_with($sequence, KeyCodes::SEQ_BRACKETED_PASTE_START)) {
            $payload = (string) $readUntilMarker(KeyCodes::SEQ_BRACKETED_PASTE_END);

            return KeyEvent::bracketedPaste($payload);
        }

        return match ($sequence) {
            KeyCodes::SEQ_ARROW_LEFT => KeyEvent::of(KeyEvent::ARROW_LEFT),
            KeyCodes::SEQ_ARROW_RIGHT => KeyEvent::of(KeyEvent::ARROW_RIGHT),
            KeyCodes::SEQ_ARROW_UP => KeyEvent::of(KeyEvent::ARROW_UP),
            KeyCodes::SEQ_ARROW_DOWN => KeyEvent::of(KeyEvent::ARROW_DOWN),
            KeyCodes::SEQ_HOME, KeyCodes::SEQ_HOME_LINUX => KeyEvent::of(KeyEvent::HOME),
            KeyCodes::SEQ_END, KeyCodes::SEQ_END_LINUX => KeyEvent::of(KeyEvent::END),
            KeyCodes::SEQ_DELETE => KeyEvent::of(KeyEvent::DELETE),
            KeyCodes::SEQ_ALT_LEFT => KeyEvent::of(KeyEvent::ALT_LEFT),
            KeyCodes::SEQ_ALT_RIGHT => KeyEvent::of(KeyEvent::ALT_RIGHT),
            KeyCodes::SEQ_ALT_BACKSPACE => KeyEvent::of(KeyEvent::ALT_BACKSPACE),
            KeyCodes::SEQ_SHIFT_LEFT => KeyEvent::of(KeyEvent::SHIFT_LEFT),
            KeyCodes::SEQ_SHIFT_RIGHT => KeyEvent::of(KeyEvent::SHIFT_RIGHT),
            KeyCodes::SEQ_SHIFT_UP => KeyEvent::of(KeyEvent::SHIFT_UP),
            KeyCodes::SEQ_SHIFT_DOWN => KeyEvent::of(KeyEvent::SHIFT_DOWN),
            KeyCodes::SEQ_SHIFT_ALT_LEFT => KeyEvent::of(KeyEvent::SHIFT_ALT_LEFT),
            KeyCodes::SEQ_SHIFT_ALT_RIGHT => KeyEvent::of(KeyEvent::SHIFT_ALT_RIGHT),
            KeyCodes::SEQ_SHIFT_HOME => KeyEvent::of(KeyEvent::SHIFT_HOME),
            KeyCodes::SEQ_SHIFT_END => KeyEvent::of(KeyEvent::SHIFT_END),
            default => KeyEvent::of(KeyEvent::UNKNOWN),
        };
    }

    private function isPrintableChar(string $byte): bool
    {
        if ($byte === '') {
            return false;
        }
        $code = ord($byte[0]);

        return $code >= 0x20 && $code !== 0x7f;
    }
}
