<?php

namespace Tests\Unit\Ai\Cli\Repl;

use App\Services\Ai\Cli\Repl\KeyCodes;
use App\Services\Ai\Cli\Repl\KeyEvent;
use App\Services\Ai\Cli\Repl\KeySequenceParser;
use Tests\TestCase;

class KeySequenceParserTest extends TestCase
{
    private KeySequenceParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new KeySequenceParser();
    }

    public function test_parses_printable_char(): void
    {
        $event = $this->parse('a');

        $this->assertSame(KeyEvent::CHAR, $event->kind);
        $this->assertSame('a', $event->payload);
    }

    public function test_parses_enter_as_submit(): void
    {
        $this->assertSame(KeyEvent::ENTER, $this->parse("\n")->kind);
        $this->assertSame(KeyEvent::ENTER, $this->parse("\r")->kind);
    }

    public function test_parses_control_keys(): void
    {
        $this->assertSame(KeyEvent::INTERRUPT, $this->parse(KeyCodes::BYTE_CTRL_C)->kind);
        $this->assertSame(KeyEvent::EOF, $this->parse(KeyCodes::BYTE_CTRL_D)->kind);
        $this->assertSame(KeyEvent::CTRL_L, $this->parse(KeyCodes::BYTE_CTRL_L)->kind);
        $this->assertSame(KeyEvent::CTRL_U, $this->parse(KeyCodes::BYTE_CTRL_U)->kind);
        $this->assertSame(KeyEvent::CTRL_V, $this->parse(KeyCodes::BYTE_CTRL_V)->kind);
        $this->assertSame(KeyEvent::CTRL_W, $this->parse(KeyCodes::BYTE_CTRL_W)->kind);
        $this->assertSame(KeyEvent::CTRL_X, $this->parse(KeyCodes::BYTE_CTRL_X)->kind);
        $this->assertSame(KeyEvent::BACKSPACE, $this->parse(KeyCodes::BYTE_BACKSPACE)->kind);
        $this->assertSame(KeyEvent::BACKSPACE, $this->parse(KeyCodes::BYTE_BACKSPACE_ALT)->kind);
    }

    public function test_parses_arrow_keys(): void
    {
        $this->assertSame(KeyEvent::ARROW_LEFT, $this->parseEscape("[D")->kind);
        $this->assertSame(KeyEvent::ARROW_RIGHT, $this->parseEscape("[C")->kind);
        $this->assertSame(KeyEvent::ARROW_UP, $this->parseEscape("[A")->kind);
        $this->assertSame(KeyEvent::ARROW_DOWN, $this->parseEscape("[B")->kind);
    }

    public function test_parses_home_end_delete(): void
    {
        $this->assertSame(KeyEvent::HOME, $this->parseEscape("[H")->kind);
        $this->assertSame(KeyEvent::HOME, $this->parseEscape("[1~")->kind);
        $this->assertSame(KeyEvent::END, $this->parseEscape("[F")->kind);
        $this->assertSame(KeyEvent::END, $this->parseEscape("[4~")->kind);
        $this->assertSame(KeyEvent::DELETE, $this->parseEscape("[3~")->kind);
    }

    public function test_parses_alt_navigation(): void
    {
        $this->assertSame(KeyEvent::ALT_LEFT, $this->parseEscape('b')->kind);
        $this->assertSame(KeyEvent::ALT_RIGHT, $this->parseEscape('f')->kind);
        $this->assertSame(KeyEvent::ALT_BACKSPACE, $this->parseEscape("\x7f")->kind);
    }

    public function test_parses_shift_arrows(): void
    {
        $this->assertSame(KeyEvent::SHIFT_LEFT, $this->parseEscape('[1;2D')->kind);
        $this->assertSame(KeyEvent::SHIFT_RIGHT, $this->parseEscape('[1;2C')->kind);
        $this->assertSame(KeyEvent::SHIFT_UP, $this->parseEscape('[1;2A')->kind);
        $this->assertSame(KeyEvent::SHIFT_DOWN, $this->parseEscape('[1;2B')->kind);
        $this->assertSame(KeyEvent::SHIFT_HOME, $this->parseEscape('[1;2H')->kind);
        $this->assertSame(KeyEvent::SHIFT_END, $this->parseEscape('[1;2F')->kind);
    }

    public function test_parses_shift_alt_arrows(): void
    {
        $this->assertSame(KeyEvent::SHIFT_ALT_LEFT, $this->parseEscape('[1;4D')->kind);
        $this->assertSame(KeyEvent::SHIFT_ALT_RIGHT, $this->parseEscape('[1;4C')->kind);
    }

    public function test_parses_select_all_and_emacs_navigation(): void
    {
        $this->assertSame(KeyEvent::SELECT_ALL, $this->parse(KeyCodes::BYTE_CTRL_A)->kind);
        $this->assertSame(KeyEvent::END, $this->parse(KeyCodes::BYTE_CTRL_E)->kind);
        $this->assertSame(KeyEvent::ARROW_LEFT, $this->parse(KeyCodes::BYTE_CTRL_B)->kind);
        $this->assertSame(KeyEvent::ARROW_RIGHT, $this->parse(KeyCodes::BYTE_CTRL_F)->kind);
    }

    public function test_parses_bracketed_paste_payload(): void
    {
        $marker = KeyCodes::SEQ_BRACKETED_PASTE_END;
        $payload = 'algum texto colado';
        $event = $this->parser->parse(
            KeyCodes::BYTE_ESC,
            fn () => '[200~',
            function (string $end) use ($payload, $marker, &$readEnd): string {
                $readEnd = $end;

                return $payload;
            },
        );

        $this->assertSame(KeyEvent::BRACKETED_PASTE, $event->kind);
        $this->assertSame($payload, $event->payload);
        $this->assertSame($marker, $readEnd);
    }

    public function test_parses_unknown_escape_sequence(): void
    {
        $event = $this->parseEscape('Z');
        $this->assertSame(KeyEvent::UNKNOWN, $event->kind);
    }

    public function test_parses_utf8_char(): void
    {
        $event = $this->parse('á');
        $this->assertSame(KeyEvent::CHAR, $event->kind);
        $this->assertSame('á', $event->payload);
    }

    private function parse(string $byte): KeyEvent
    {
        return $this->parser->parse($byte, fn () => '', fn () => '');
    }

    private function parseEscape(string $tail): KeyEvent
    {
        return $this->parser->parse(KeyCodes::BYTE_ESC, fn () => $tail, fn () => '');
    }
}
