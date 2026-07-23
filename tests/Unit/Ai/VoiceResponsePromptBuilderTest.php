<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Context\AiContextPackBuilder;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\Router\AiIntentRouter;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\AiSkillStore;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use Tests\TestCase;

class VoiceResponsePromptBuilderTest extends TestCase
{
    public function test_voice_response_contract_projects_spoken_result_rules_without_reducing_scope(): void
    {
        $section = $this->voiceSection([
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
