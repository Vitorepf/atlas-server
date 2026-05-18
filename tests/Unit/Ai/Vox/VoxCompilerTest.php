<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxCompiler;
use App\Services\Ai\Vox\VoxIntentExtractor;
use App\Services\Ai\Vox\VoxPromptCompiler;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxRiskClassifier;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxCompilerTest extends TestCase
{
    private function makeCompiler(): VoxCompiler
    {
        $polisher = new VoxPromptPolisher();

        return new VoxCompiler(
            new VoxRiskClassifier(),
            $polisher,
            new VoxIntentExtractor($polisher),
            new VoxPromptCompiler(),
        );
    }

    public function test_dictation_packet_obeys_v1_contract_exactly(): void
    {
        $compiler = $this->makeCompiler();
        $transcript = [
            'session_id' => 'b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c',
            'transcript_id' => 'f3e1d2c4-5b6a-7890-abcd-ef1234567890',
            'text' => 'manda o Codex olhar esse módulo do voice sem mexer',
        ];

        $packet = $compiler->compile($transcript, VoxSchema::MODE_DICTATION);

        $this->assertSame(VoxSchema::INTENT_PACKET, $packet['schema']);
        $this->assertSame(VoxSchema::MODE_DICTATION, $packet['mode']);
        $this->assertSame('', $packet['goal']);
        $this->assertSame([], $packet['constraints']);
        $this->assertSame(
            [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            $packet['context_refs']
        );
        $this->assertSame('local', $packet['provider_hint']);
        $this->assertSame('none', $packet['executor_hint']);
        $this->assertSame('text', $packet['output_format']);
        $this->assertSame('R0', $packet['risk_class']);
        $this->assertSame($transcript['text'], $packet['human_input_text']);
        $this->assertNull($packet['compiled_prompt']);
        $this->assertNull($packet['compiled_prompt_template']);
        $this->assertNull($packet['discordance_hint']);
        $this->assertNull($packet['memory_candidate']);
        $this->assertSame($transcript['session_id'], $packet['session_id']);
        $this->assertSame($transcript['transcript_id'], $packet['transcript_ref']);
        $this->assertSame(VoxSchema::COMPILER_VERSION, $packet['compiler_version']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $packet['intent_id']
        );
    }

    public function test_compiler_refuses_unsupported_modes(): void
    {
        $compiler = $this->makeCompiler();
        $this->expectException(\LogicException::class);
        // governed_execute is supported now (V3 / Wave 6); use a sentinel
        // mode the compiler does not know to assert the LogicException path.
        $compiler->compile([
            'session_id' => 'x',
            'transcript_id' => 'y',
            'text' => 'z',
        ], 'voice_realtime_streaming_v6');
    }

    public function test_compiler_supports_governed_execute_with_intent_compile_pipeline(): void
    {
        $compiler = $this->makeCompiler();
        $packet = $compiler->compile([
            'session_id' => 'b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c',
            'transcript_id' => 'f3e1d2c4-5b6a-7890-abcd-ef1234567890',
            'text' => 'manda o codex olhar esse modulo do voice sem mexer',
        ], VoxSchema::MODE_GOVERNED_EXECUTE);

        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $packet['mode']);
        $this->assertNotNull($packet['compiled_prompt']);
        $this->assertSame(VoxSchema::INTENT_PACKET, $packet['schema']);
    }

    public function test_prompt_polish_packet_has_non_null_compiled_prompt_and_template(): void
    {
        $compiler = $this->makeCompiler();
        $packet = $compiler->compile([
            'session_id' => 'b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c',
            'transcript_id' => 'f3e1d2c4-5b6a-7890-abcd-ef1234567890',
            'text' => 'manda o codex olhar esse trem do voice mas não mexer',
        ], VoxSchema::MODE_PROMPT_POLISH);

        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $packet['mode']);
        $this->assertSame('R0', $packet['risk_class']);
        $this->assertIsString($packet['compiled_prompt']);
        $this->assertNotSame('', $packet['compiled_prompt']);
        $this->assertSame(VoxPromptPolisher::TEMPLATE_ID, $packet['compiled_prompt_template']);
        $this->assertSame('codex_cli', $packet['provider_hint']);
        $this->assertSame('manda o codex olhar esse trem do voice mas não mexer', $packet['human_input_text']);
        $this->assertNull($packet['discordance_hint']);
        $this->assertNull($packet['memory_candidate']);
        $this->assertSame(
            [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            $packet['context_refs']
        );
        $this->assertContains('não mexer', $packet['constraints']);
    }

    public function test_prompt_polish_packet_does_not_invent_provider_when_none_mentioned(): void
    {
        $compiler = $this->makeCompiler();
        $packet = $compiler->compile([
            'session_id' => 's',
            'transcript_id' => 't',
            'text' => 'quero limpar esse texto pra inserir no inbox',
        ], VoxSchema::MODE_PROMPT_POLISH);

        $this->assertSame('local', $packet['provider_hint']);
        $this->assertSame('text', $packet['output_format']);
        $this->assertSame('none', $packet['executor_hint']);
    }
}
