<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendCompetitiveRubricCommandTest extends TestCase
{
    public function test_rubric_command_emits_canonical_payload(): void
    {
        $exitCode = Artisan::call('atlas:frontend:rubric', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.competitive_rubric.v1', $output);
        $this->assertStringContainsString('product_intent_fit', $output);
        $this->assertStringContainsString('same_rubric_required_across_systems', $output);
    }
}
