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
        $this->assertTrue(data_get($packet, 'rules.learning_domain_is_not_core_learning_plane'));
        $this->assertContains('confuse_domain_learning_with_core_learning_plane', $packet['forbidden_actions']);
    }
}
