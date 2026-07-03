<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Streaming;

use App\Services\Ai\Streaming\CodexJsonlEventParser;
use PHPUnit\Framework\TestCase;

final class CodexJsonlEventParserTest extends TestCase
{
    public function test_command_execution_becomes_shell_tool_event(): void
    {
        $parser = new CodexJsonlEventParser;
        $events = $parser->feed(
            '{"type":"item.completed","item":{"item_type":"command_execution","command":"rg -n TODO app/","exit_code":0,"status":"completed","aggregated_output":"app/Foo.php:12: // TODO"}}'."\n",
        );

        $this->assertCount(1, $events);
        $this->assertSame('tool', $events[0]['type']);
        $this->assertSame('shell', $events[0]['name']);
        $this->assertSame('rg -n TODO app/', $events[0]['content']);
        $this->assertSame('activity', $events[0]['channel']);
        $this->assertSame(0, $events[0]['metadata']['exit_code']);
    }

    public function test_reasoning_and_agent_message_map_to_thinking_and_response(): void
    {
        $parser = new CodexJsonlEventParser;
        $events = $parser->feed(
            '{"type":"item.completed","item":{"item_type":"reasoning","text":"vou olhar o roteador"}}'."\n"
            .'{"type":"item.completed","item":{"item_type":"agent_message","text":"Pronto: o bug era X."}}'."\n",
        );

        $this->assertSame(['thinking', 'response'], [$events[0]['type'], $events[1]['type']]);
        $this->assertSame('assistant', $events[1]['channel']);
        $this->assertSame('Pronto: o bug era X.', $events[1]['content']);
    }

    public function test_partial_line_buffers_until_newline_arrives(): void
    {
        $parser = new CodexJsonlEventParser;
        $this->assertSame([], $parser->feed('{"type":"turn.st'));
        $events = $parser->feed('arted"}'."\n");

        $this->assertCount(1, $events);
        $this->assertSame('lifecycle', $events[0]['type']);
        $this->assertSame('turn_started', $events[0]['name']);
    }

    public function test_non_json_line_falls_back_to_raw_stdout_never_swallowed(): void
    {
        $parser = new CodexJsonlEventParser;
        $events = $parser->feed("aviso humano fora do protocolo\n");

        $this->assertSame('stdout', $events[0]['type']);
        $this->assertSame('aviso humano fora do protocolo', $events[0]['content']);
    }

    public function test_flush_remainder_emits_trailing_line_without_newline(): void
    {
        $parser = new CodexJsonlEventParser;
        $parser->feed('{"type":"item.completed","item":{"item_type":"agent_message","text":"final"}}');
        $flushed = $parser->flushRemainder();

        $this->assertNotNull($flushed);
        $this->assertSame('response', $flushed['type']);
        $this->assertNull($parser->flushRemainder());
    }

    public function test_web_search_and_file_change_map_to_named_tools(): void
    {
        $parser = new CodexJsonlEventParser;
        $events = $parser->feed(
            '{"type":"item.completed","item":{"item_type":"web_search","query":"laravel queue best practices"}}'."\n"
            .'{"type":"item.completed","item":{"item_type":"file_change","status":"completed","changes":[{"path":"app/Foo.php"},{"path":"tests/FooTest.php"}]}}'."\n",
        );

        $this->assertSame(['search', 'edit'], [$events[0]['name'], $events[1]['name']]);
        $this->assertSame('laravel queue best practices', $events[0]['content']);
        $this->assertSame('app/Foo.php, tests/FooTest.php', $events[1]['content']);
    }
}
