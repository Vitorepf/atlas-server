<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainAmplifierComplexityBudget is wired into
 * AtlasExternalBrainOriginatorQualityCommand via an optional complexity_budget
 * input section, distinct from the per-opportunity coverage-audit pipeline.
 */
final class AtlasExternalBrainAmplifierComplexityBudgetWiringWiredTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function callCommand(array $payload): array
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'originator_quality_complexity_budget_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $this->inputPath]);
        $decoded = json_decode(trim(Artisan::output()), true);

        return (array) $decoded;
    }

    public function test_complexity_budget_section_is_evaluated_when_present(): void
    {
        $decoded = $this->callCommand([
            'opportunities' => [],
            'complexity_budget' => [
                'components' => [
                    ['id' => 'gate-1', 'type' => 'gate', 'prompt_length' => 100, 'dependency_count' => 1],
                ],
            ],
        ]);

        $this->assertArrayHasKey('complexity_budget', $decoded);
        $this->assertSame('atlas.external_brain.amplifier_complexity_budget.v1', $decoded['complexity_budget']['schema_version']);
    }

    public function test_complexity_budget_section_absent_when_not_supplied(): void
    {
        $decoded = $this->callCommand(['opportunities' => []]);

        $this->assertArrayNotHasKey('complexity_budget', $decoded);
    }
}
