<?php

namespace Tests\Unit\Ai\Cognitive\WorkedExample;

use App\Services\Ai\Cognitive\WorkedExample\ProcessFadingScheduler;
use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleRenderer;
use Tests\TestCase;

class WorkedExampleRendererTest extends TestCase
{
    public function test_renderer_hides_steps_according_to_fading_schedule(): void
    {
        $example = [
            'id' => 7,
            'domain' => 'programming',
            'title' => 'Review de PR',
            'problem_context' => 'PR com migration.',
            'source' => 'canonical_library',
            'solution_full' => [
                ['step' => 1, 'action' => 'Ler diff'],
                ['step' => 2, 'action' => 'Rodar rollback'],
                ['step' => 3, 'action' => 'Agrupar findings'],
            ],
        ];

        $rendered = app(WorkedExampleRenderer::class)->render($example, app(ProcessFadingScheduler::class)->schedule(2));

        $this->assertSame('atlas.cognitive.worked_example_render.v1', $rendered['schema_version']);
        $this->assertTrue($rendered['steps'][0]['visible']);
        $this->assertFalse($rendered['steps'][1]['visible']);
        $this->assertSame('operator_fills_this_step', $rendered['steps'][1]['prompt']);
    }
}
