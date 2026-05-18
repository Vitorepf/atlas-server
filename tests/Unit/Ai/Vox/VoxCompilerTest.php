<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxCompiler;
use App\Services\Ai\Vox\VoxRiskClassifier;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxCompilerTest extends TestCase
{
    public function test_dictation_packet_obeys_v1_contract_exactly(): void
    {
        $compiler = new VoxCompiler(new VoxRiskClassifier());
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

    public function test_compiler_refuses_non_dictation_modes_in_v0(): void
    {
        $compiler = new VoxCompiler(new VoxRiskClassifier());
        $this->expectException(\LogicException::class);
        $compiler->compile([
            'session_id' => 'x',
            'transcript_id' => 'y',
            'text' => 'z',
        ], VoxSchema::MODE_INTENT_COMPILE);
    }
}
