<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\LearningPlanService;
use Tests\TestCase;

class LearningPlanServiceTest extends TestCase
{
    public function test_learning_packet_separates_human_learning_from_core_learning_plane(): void
    {
        $packet = app(LearningPlanService::class)->packet('learning.spaced_review', [
            'objective' => 'Aprender arquitetura do Atlas AI sem confundir dominios.',
            'topic' => 'Atlas Kernel Pipeline',
            'current_level' => 'intermediate',
            'target_level' => 'can_implement_without_duplication',
            'resources' => ['START_HERE.md', 'atlas-ai-flow-visual-map.md'],
        ]);

        $this->assertSame('atlas.learning.packet.v1', $packet['schema_version']);
        $this->assertSame('learning', $packet['domain']);
        $this->assertSame('learning.spaced_review', $packet['flow']);
        $this->assertSame('spaced_review_plan', $packet['mode']);
        $this->assertSame([], data_get($packet, 'brief.missing_inputs'));
        $this->assertTrue(data_get($packet, 'learning_contract.practice_loop_required'));
        $this->assertSame('atlas.cognitive.dreyfus_pedagogy_resolution.v1', data_get($packet, 'dreyfus.resolution.schema_version'));
        $this->assertSame('atlas.cognitive.dreyfus_prompt_builder.v1', data_get($packet, 'dreyfus.prompt_builder.schema_version'));
        $this->assertSame('passed', collect($packet['gates'])->firstWhere('id', 'pedagogy_matches_stage')['status']);
        $this->assertTrue(data_get($packet, 'rules.learning_domain_is_not_core_learning_plane'));
        $this->assertContains('confuse_domain_learning_with_core_learning_plane', $packet['forbidden_actions']);
    }
}
