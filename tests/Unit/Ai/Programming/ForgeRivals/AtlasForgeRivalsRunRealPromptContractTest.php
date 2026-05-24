<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunRealService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use ReflectionClass;
use Tests\TestCase;

final class AtlasForgeRivalsRunRealPromptContractTest extends TestCase
{
    public function test_ceiling_360_real_run_prompt_carries_human_ticket_and_l5pp_contract(): void
    {
        $case = (new AtlasForgeRivalsProviderArenaCorpusService)
            ->casesForCaseSet(AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360)[0];
        $case['prompt_mode'] = 'enterprise-change';

        $prompt = $this->renderCasePrompt($case);

        $this->assertStringContainsString('Ticket humano canonico do caso:', $prompt);
        $this->assertStringContainsString('Pressao L5++', $prompt);
        $this->assertStringContainsString('Contrato Rivals 360 obrigatorio:', $prompt);
        $this->assertStringContainsString('Pressure level: L5++', $prompt);
        $this->assertStringContainsString('Required sections:', $prompt);
        $this->assertStringContainsString('counterfactual_check', $prompt);
        $this->assertStringContainsString('blast_radius', $prompt);
        $this->assertStringContainsString('confidence_calibration', $prompt);
        $this->assertStringContainsString('Invalid if missing:', $prompt);
        $this->assertStringContainsString('Rivals 360 Evidence', $prompt);
        $this->assertStringContainsString('Counterfactual Check', $prompt);
        $this->assertStringContainsString('Blast Radius quantificado', $prompt);
        $this->assertStringContainsString('Confidence Calibration', $prompt);
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function renderCasePrompt(array $case): string
    {
        $reflection = new ReflectionClass(AtlasForgeRivalsRunRealService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('casePrompt');
        $method->setAccessible(true);

        return (string) $method->invoke(
            $service,
            $case,
            'Você é o braço Atlas Forge. Use evidência, respeite escopo e rode testes.',
        );
    }
}
