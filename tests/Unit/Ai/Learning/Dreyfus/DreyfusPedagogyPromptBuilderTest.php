<?php

namespace Tests\Unit\Ai\Cognitive\Dreyfus;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusPedagogyPromptBuilder;
use Tests\TestCase;

class DreyfusPedagogyPromptBuilderTest extends TestCase
{
    public function test_prompt_builder_contrasts_novato_and_expert_modes(): void
    {
        $builder = app(DreyfusPedagogyPromptBuilder::class);

        $novato = $builder->build([
            'dreyfus_stage_resolved' => 1,
            'pedagogy_mode_resolved' => 'novato',
        ], ['topic' => 'Laravel queues']);
        $expert = $builder->build([
            'dreyfus_stage_resolved' => 4,
            'pedagogy_mode_resolved' => 'expert',
        ], ['topic' => 'Laravel queues']);

        $this->assertSame('atlas.cognitive.dreyfus_prompt_builder.v1', $novato['schema_version']);
        $this->assertContains('glossary', $novato['output_shape']);
        $this->assertContains('ill_structured_case', $expert['output_shape']);
        $this->assertSame('high', data_get($novato, 'quality_bar.scaffolding_density'));
        $this->assertSame('low', data_get($expert, 'quality_bar.scaffolding_density'));
        $this->assertTrue(data_get($expert, 'evidence_requirements.must_request_transfer_proof'));
    }
}
