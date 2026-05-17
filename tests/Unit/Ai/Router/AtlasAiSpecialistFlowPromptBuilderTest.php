<?php

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiIntentRouter;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\AiSkillStore;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use Tests\TestCase;

class AtlasAiSpecialistFlowPromptBuilderTest extends TestCase
{
    public function test_specialist_flow_execution_projects_handler_contract_into_prompt(): void
    {
        $section = $this->specialistFlowSection([
            'payload' => [
                'specialist_flow_execution' => [
                    'schema_version' => 'atlas.ai.specialist_flow_execution.v1',
                    'status' => 'ready_for_provider',
                    'flow_id' => 'atlas_explain',
                    'handler_id' => 'atlas_explain_read_only_handler',
                    'runtime_receipt_id' => 'sfr_123',
                    'runtime_contract_hash' => str_repeat('e', 64),
                    'provider_prompt_contract' => ['Explain in plain language using only available context.'],
                    'response_shape' => ['plain_language_explanation', 'assumptions'],
                    'audit_checks' => ['no_side_effect_claims'],
                    'delegation' => ['status' => 'not_delegated'],
                ],
            ],
        ]);

        $this->assertStringContainsString('# Atlas AI Specialist Flow Handler', $section);
        $this->assertStringContainsString('Flow: atlas_explain', $section);
        $this->assertStringContainsString('Handler: atlas_explain_read_only_handler', $section);
        $this->assertStringContainsString('Runtime receipt: sfr_123', $section);
        $this->assertStringContainsString('- Explain in plain language using only available context.', $section);
        $this->assertStringContainsString('- no_side_effect_claims', $section);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function specialistFlowSection(array $options): string
    {
        $builder = new AiPromptBuilder(
            $this->createMock(AiSkillStore::class),
            $this->createMock(AiIntentRouter::class),
            $this->createMock(AiContextPackBuilder::class),
            $this->createMock(SkillDiscoveryService::class),
            $this->createMock(SkillBundleStore::class),
            $this->createMock(SessionSearchService::class),
        );

        $method = new \ReflectionMethod(AiPromptBuilder::class, 'specialistFlowInstructions');
        $method->setAccessible(true);

        return (string) $method->invoke($builder, $options);
    }
}
