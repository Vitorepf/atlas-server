<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasCodexPlannerService;
use PHPUnit\Framework\TestCase;

final class AtlasCodexPlannerServiceTest extends TestCase
{
    public function test_planning_prompt_carries_detail_anchor_and_quality_guardrails(): void
    {
        $service = new AtlasCodexPlannerService();
        $method = new \ReflectionMethod($service, 'buildPlanningPrompt');

        $prompt = $method->invoke($service, [
            'title' => 'Wire bounded policy signal',
            'detail' => 'Wire only aPerClassChangedFileCeilingSignalWiring() and prove it with the focused test.',
            'target_method' => 'aPerClassChangedFileCeilingSignalWiring',
            'surgical_anchor' => 'file:app/Policy.php; target_method:aPerClassChangedFileCeilingSignalWiring',
        ], [
            'app/Policy.php',
            'tests/Unit/PolicyTest.php',
        ], [
            'php artisan test tests/Unit/PolicyTest.php',
        ]);

        $this->assertStringContainsString('Wire only aPerClassChangedFileCeilingSignalWiring()', $prompt);
        $this->assertStringContainsString('target_method=aPerClassChangedFileCeilingSignalWiring', $prompt);
        $this->assertStringContainsString('surgical_anchor=file:app/Policy.php; target_method:aPerClassChangedFileCeilingSignalWiring', $prompt);
        $this->assertStringContainsString('preserve existing methods/tests', $prompt);
        $this->assertStringContainsString('no large test deletion', $prompt);
    }
}
