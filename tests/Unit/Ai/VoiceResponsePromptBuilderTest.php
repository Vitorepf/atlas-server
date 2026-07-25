<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Support\AiPromptInstructionSupport;
use Tests\TestCase;

class VoiceResponsePromptBuilderTest extends TestCase
{
    public function test_voice_response_contract_projects_spoken_result_rules_without_reducing_scope(): void
    {
        $section = AiPromptInstructionSupport::voiceResponseInstructions([
            'payload' => [
                'voice_response_contract' => [
                    'schema_version' => 'atlas.voice.response_contract.v1',
                    'mode' => 'spoken_result',
                    'language' => 'pt-BR',
                    'max_sentences' => 6,
                    'target_chars' => 650,
                    'hard_max_chars' => 1000,
                ],
            ],
        ]);

        $this->assertStringContainsString('# Contrato de resposta falada Atlas Voice', $section);
        $this->assertStringContainsString('Esta resposta sera falada em voz alta.', $section);
        $this->assertStringContainsString('portugues brasileiro natural', $section);
        $this->assertStringContainsString('Nao reduza o escopo do pedido por ser voz', $section);
        $this->assertStringContainsString('Use no maximo 5 frases curtas', $section);
        $this->assertStringContainsString('Mira de tamanho: ate 650 caracteres; limite duro: 1000 caracteres.', $section);
        $this->assertStringContainsString('sem markdown', $section);
    }

    public function test_voice_response_contract_is_ignored_outside_spoken_concise_mode(): void
    {
        $section = AiPromptInstructionSupport::voiceResponseInstructions([
            'payload' => [
                'voice_response_contract' => [
                    'schema_version' => 'atlas.voice.response_contract.v1',
                    'mode' => 'text_only',
                    'target_chars' => 360,
                ],
            ],
        ]);

        $this->assertSame('', $section);
    }
}
