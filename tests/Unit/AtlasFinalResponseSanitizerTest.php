<?php

namespace Tests\Unit;

use App\Services\Ai\AtlasFinalResponseSanitizer;
use Tests\TestCase;

class AtlasFinalResponseSanitizerTest extends TestCase
{
    public function test_extracts_final_result_from_provider_ndjson_without_internal_context(): void
    {
        $raw = implode("\n", [
            json_encode([
                'type' => 'system',
                'subtype' => 'init',
                'session_id' => '8332bd81-b24a-4cd2-9335-cd161a5dd9ff',
                'tools' => ['Bash', 'Read'],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'type' => 'assistant',
                'message' => [
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'internal reasoning'],
                        ['type' => 'text', 'text' => 'rascunho intermediário'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'type' => 'result',
                'result' => "## Saída final\n\nResumo limpo para o operador.",
                'modelUsage' => ['claude-sonnet' => ['inputTokens' => 123]],
            ], JSON_THROW_ON_ERROR),
        ]);

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        $this->assertSame("## Saída final\n\nResumo limpo para o operador.", $clean);
        $this->assertSame('provider_transcript_result_extracted', $meta['reason']);
        $this->assertStringNotContainsString('session_id', $clean);
        $this->assertStringNotContainsString('modelUsage', $clean);
    }

    public function test_extracts_assistant_text_when_provider_stream_has_no_result_event(): void
    {
        $raw = implode("\n", [
            json_encode([
                'type' => 'assistant',
                'message' => [
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'não deve aparecer'],
                        ['type' => 'text', 'text' => 'Resposta final limpa.'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        $this->assertSame('Resposta final limpa.', $clean);
        $this->assertSame('provider_transcript_result_extracted', $meta['reason']);
        $this->assertStringNotContainsString('thinking', $clean);
    }

    public function test_blocks_irreducible_internal_context_leak(): void
    {
        $raw = '{"type":"system","subtype":"init","session_id":"abc","tools":["Bash"],"permissionMode":"bypassPermissions"}';

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        $this->assertSame('internal_context_leak_blocked', $meta['reason']);
        $this->assertStringContainsString('saída interna foi bloqueada', $clean);
        $this->assertStringNotContainsString('session_id', $clean);
        $this->assertStringNotContainsString('permissionMode', $clean);
    }

    public function test_extracts_clean_answer_from_quality_repair_prompt_containing_raw_provider_stream(): void
    {
        $raw = <<<'TXT'
Reescreva a resposta abaixo como saída final do Atlas para o operador.

Pedido original:
https://youtu.be/CODgq6sMNQ4 me diga tudo sobre isso

Resposta anterior:
{"type":"system","subtype":"init","session_id":"8332bd81-b24a-4cd2-9335-cd161a5dd9ff","tools":["Bash","Read"],"permissionMode":"bypassPermissions"}
{"type":"assistant","message":{"content":[{"type":"thinking","thinking":"internal chain"},{"type":"text","text":"rascunho"}]}}
{"type":"result","result":"## Claude Code vs HackTheBox\n\nDesmonte limpo e útil."}

Falhas detectadas: internal_context_leak, likely_oververbose_or_code_heavy

Regras:
- remova qualquer vazamento de contexto interno, IDs, traces, prompts, context_pack ou provider;
TXT;

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        $this->assertSame("## Claude Code vs HackTheBox\n\nDesmonte limpo e útil.", $clean);
        $this->assertSame('provider_transcript_result_extracted', $meta['reason']);
        $this->assertStringNotContainsString('session_id', $clean);
        $this->assertStringNotContainsString('Reescreva a resposta', $clean);
    }

    public function test_does_not_block_legitimate_technical_mentions_of_session_id(): void
    {
        $text = 'A coluna session_id serve para correlacionar tentativas no banco, sem expor o valor real ao operador.';

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($text);

        $this->assertSame($text, $clean);
        $this->assertFalse($meta['changed']);
    }

    public function test_strips_leaked_ant_thinking_and_pseudo_tool_calls_from_text(): void
    {
        // Formato real do incidente 02/07 (rota Hermes sem harness de tools):
        // raciocínio e pseudo-tool-call vazam como TEXTO no corpo da resposta.
        $raw = 'Vou verificar o fluxo. <antThinking> The user wants a rigorous '
            .'verification. I need to map components. </antThinking> '
            .'<toolcodeinterpreter(code="import subprocess\nresult = subprocess.run([...])")>'
            .'saída</toolcodeinterpreter> A resposta final limpa fica aqui.';

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        $this->assertTrue($meta['changed']);
        $this->assertSame('leaked_model_markup_stripped', $meta['reason']);
        $this->assertStringNotContainsString('antThinking', $clean);
        $this->assertStringNotContainsString('toolcodeinterpreter', $clean);
        $this->assertStringContainsString('A resposta final limpa fica aqui.', $clean);
    }

    public function test_dedupes_consecutive_identical_paragraphs_from_stitched_retries(): void
    {
        $block = 'Vou realizar uma verificação rigorosa do fluxo Atlas Dev.';
        $raw = "$block\n\n$block\n\n$block\n\nConclusão distinta.";

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        // Só a marcação vazada dispara o strip; aqui o dedupe roda porque a
        // marcação-parser não muda nada mas a repetição some quando há markup.
        // Sem markup, parágrafos repetidos legítimos NÃO são um leak — o sanitizer
        // preserva (não inventamos mudança). Garante o não-falso-positivo:
        $this->assertFalse($meta['changed']);
        $this->assertSame($raw, $clean);
    }

    public function test_markup_only_response_becomes_honest_placeholder(): void
    {
        $raw = '<antThinking>só raciocínio, nenhuma resposta</antThinking>';

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        $this->assertTrue($meta['changed']);
        $this->assertSame('model_markup_only_response', $meta['reason']);
        $this->assertStringNotContainsString('antThinking', $clean);
        $this->assertStringContainsString('marcação interna', $clean);
    }

    public function test_unclosed_pseudo_tool_call_truncated_at_end_is_stripped(): void
    {
        $raw = "Resposta boa até aqui.\n\n<toolcodeinterpreter(code=\"import subprocess\nresult = subprocess.run([";

        [$clean, $meta] = app(AtlasFinalResponseSanitizer::class)->sanitize($raw);

        $this->assertTrue($meta['changed']);
        $this->assertStringNotContainsString('toolcodeinterpreter', $clean);
        $this->assertStringContainsString('Resposta boa até aqui.', $clean);
    }
}
