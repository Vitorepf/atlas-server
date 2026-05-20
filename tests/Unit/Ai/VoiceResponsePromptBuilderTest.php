<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiIntentRouter;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\AiSkillStore;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use Tests\TestCase;

class VoiceResponsePromptBuilderTest extends TestCase
{
    public function test_voice_response_contract_projects_short_spoken_answer_rules(): void
    {
        $section = $this->voiceSection([
            'payload' => [
                'voice_response_contract' => [
                    'schema_version' => 'atlas.voice.response_contract.v1',
                    'mode' => 'spoken_concise',
                    'language' => 'pt-BR',
                    'max_sentences' => 3,
                    'target_chars' => 280,
                    'hard_max_chars' => 420,
                ],
            ],
        ]);

        $this->assertStringContainsString('# Contrato de resposta falada Atlas Voice', $section);
        $this->assertStringContainsString('Esta resposta sera falada em voz alta.', $section);
        $this->assertStringContainsString('portugues brasileiro natural', $section);
        $this->assertStringContainsString('Use no maximo 3 frases curtas', $section);
        $this->assertStringContainsString('Mira de tamanho: ate 280 caracteres; limite duro: 420 caracteres.', $section);
        $this->assertStringContainsString('sem markdown', $section);
    }

    public function test_voice_response_contract_is_ignored_outside_spoken_concise_mode(): void
    {
        $section = $this->voiceSection([
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

    /**
     * @param  array<string,mixed>  $options
     */
    private function voiceSection(array $options): string
    {
        $builder = new AiPromptBuilder(
            $this->createMock(AiSkillStore::class),
            $this->createMock(AiIntentRouter::class),
            $this->createMock(AiContextPackBuilder::class),
            $this->createMock(SkillDiscoveryService::class),
            $this->createMock(SkillBundleStore::class),
            $this->createMock(SessionSearchService::class),
        );

        $method = new \ReflectionMethod(AiPromptBuilder::class, 'voiceResponseInstructions');
        $method->setAccessible(true);

        return (string) $method->invoke($builder, $options);
    }
}
