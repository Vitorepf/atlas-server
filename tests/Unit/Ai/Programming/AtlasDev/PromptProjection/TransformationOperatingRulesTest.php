<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use Tests\TestCase;

/**
 * Operating Rules task-aware (03/07): objetivo de TRANSFORMAÇÃO (refator/
 * simplificação/otimização) recebe rules que exigem o diff — as rules default
 * ("no_patch_needed quando o teste já prova o objetivo" + "nada de refator
 * oportunista") instruíam o modelo fraco a devolver no_patch_needed exatamente
 * nessas tarefas (fire test em repo real).
 */
final class TransformationOperatingRulesTest extends TestCase
{
    use PromptProjectionFixtures;

    private function render(string $intent): string
    {
        $builder = new ProviderPromptBuilder(
            new PromptSectionsMapper,
            new PromptRenderer,
            new PromptQualityChecker,
        );

        $projection = $builder->build(
            envelope: $this->envelope(['normalized_intent' => $intent]),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract([
                'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'hermes_cli_default', 'fallback_allowed' => false],
            ]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        return $projection->renderedPromptText;
    }

    public function test_transformation_intent_gets_must_produce_diff_rules(): void
    {
        $text = $this->render('simplifique o TaskClassifier unificando o matching duplicado');

        $this->assertStringContainsString('E uma transformacao', $text);
        $this->assertStringContainsString('Voce DEVE produzir o diff da transformacao pedida', $text);
        $this->assertStringNotContainsString('nada de refator oportunista', $text);
    }

    public function test_non_transformation_intent_keeps_default_rules(): void
    {
        $text = $this->render('corrija o bug do parser que quebra no input vazio');

        $this->assertStringContainsString('nada de refator oportunista', $text);
        $this->assertStringNotContainsString('Voce DEVE produzir o diff da transformacao pedida', $text);
    }
}
