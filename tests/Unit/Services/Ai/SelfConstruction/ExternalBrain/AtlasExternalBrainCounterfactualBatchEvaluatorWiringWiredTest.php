<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainCounterfactualBatchEvaluator is wired into
 * atlas:external-brain:post-commit-learning as an optional counterfactual_batch
 * section, following the command's established optional-section convention.
 */
final class AtlasExternalBrainCounterfactualBatchEvaluatorWiringWiredTest extends TestCase
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
        $this->inputPath = tempnam(sys_get_temp_dir(), 'counterfactual_batch_wiring_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        Artisan::call('atlas:external-brain:post-commit-learning', ['--input' => $this->inputPath]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    public function test_alternative_beating_chosen_on_unlocks_and_risk_yields_high_regret(): void
    {
        $decoded = $this->callCommand([
            'counterfactual_batch' => [
                'chosen_batch' => ['leverage_score' => 5.0, 'risk_score' => 6.0, 'downstream_unlocks' => 1],
                'alternatives' => [
                    ['id' => 'alt-1', 'leverage_score' => 5.0, 'risk_score' => 2.0, 'downstream_unlocks' => 4],
                ],
            ],
        ]);

        $this->assertArrayHasKey('counterfactual_evaluation', $decoded);
        $evaluation = $decoded['counterfactual_evaluation'];
        $this->assertSame('atlas.external_brain.counterfactual_batch_evaluator.v1', $evaluation['schema_version']);
        $this->assertSame('high', $evaluation['decision_regret_level']);
        $this->assertSame('alt-1', $evaluation['learned_from_alternative_id']);
    }

    public function test_strong_chosen_batch_with_no_better_alternative_yields_low_regret(): void
    {
        $decoded = $this->callCommand([
            'counterfactual_batch' => [
                'chosen_batch' => [
                    'leverage_score' => 9.0, 'risk_score' => 1.0, 'downstream_unlocks' => 5,
                    'evidence_strength' => 8.0, 'implementability' => 8.0,
                ],
                'alternatives' => [
                    ['id' => 'alt-1', 'leverage_score' => 3.0, 'risk_score' => 5.0, 'downstream_unlocks' => 1],
                ],
            ],
        ]);

        $this->assertSame('low', $decoded['counterfactual_evaluation']['decision_regret_level']);
        $this->assertNull($decoded['counterfactual_evaluation']['learned_from_alternative_id']);
    }

    public function test_empty_counterfactual_batch_section_produces_default_low_regret_output(): void
    {
        $decoded = $this->callCommand([]);

        $this->assertArrayHasKey('counterfactual_evaluation', $decoded);
        $this->assertSame(0, $decoded['counterfactual_evaluation']['comparison_count']);
        $this->assertSame('low', $decoded['counterfactual_evaluation']['decision_regret_level']);
    }
}
